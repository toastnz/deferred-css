<?php

namespace Toast\Injectors;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Core\Path;
use SilverStripe\View\HTML;
use SilverStripe\View\Requirements_Backend;

/**
 * Takes the stylesheets out of the way of the first paint.
 *
 * - A stylesheet asked for with Requirements::css() (or <% require css(...) %>) is loaded without blocking the render:
 *   <link rel="preload" as="style" onload="this.rel='stylesheet'"> with a <noscript> link for when there is no script.
 * - A stylesheet asked for with addStylesheet() (what $getCSS("path") in a template calls) is decided here: it is
 *   written into the head of the page as a <style> while the inline budget lasts, in the order the page is rendered
 *   in so what is nearest the top gets it first, and loaded as above once the budget is spent.
 * - A stylesheet that has to be in the page whatever it costs (what is needed for the first paint) is written where it
 *   is asked for with inlineStylesheet().
 *
 * The CMS is left alone: its stylesheets are linked as usual.
 */
class ToastEnhancedBackend extends Requirements_Backend
{
    /**
     * How many bytes of css addStylesheet() may write into a page before the rest is loaded. 0 loads all of it.
     */
    private static int $inline_budget = 20000;

    /**
     * How long (seconds) the css of a file is kept. A file is kept under its modified time and size, so a new build is
     * a new entry straight away, this is only for the entries of old builds to go.
     */
    private static int $cache_lifetime = 604800;

    private const INLINE = 'inline';
    private const DEFER = 'defer';
    private const FORCED = 'forced';

    /**
     * What addStylesheet() and inlineStylesheet() were asked for in this page, in the order they were asked for
     * (resolved path => inline, defer or forced)
     *
     * @var array<string, string>
     */
    protected array $stylesheets = [];

    /**
     * The css of what is inline (resolved path => css)
     *
     * @var array<string, string>
     */
    protected array $inlineCSS = [];

    protected int $inlineBytes = 0;

    /**
     * Modified time and size of the files looked at in this page, so each is looked at once
     *
     * @var array<string, string|null>
     */
    protected array $versions = [];

    protected ?CacheInterface $cache = null;

    /**
     * Asks for a stylesheet and lets the page decide how it gets there: written into the head if it fits the budget,
     * loaded without blocking the render if not. Asking again for the same file does nothing.
     *
     * @param string $path as it would be given to Requirements::css() (themes/cffc/dist/styles/elements/x.css)
     */
    public function addStylesheet(string $path, ?string $media = null): void
    {
        $file = $this->resolveStylesheet($path);

        if (isset($this->stylesheets[$file])) {
            return;
        }

        if ($this->isFrontEnd()) {
            $css = $this->readStylesheet($file);

            if ($css !== null && $this->fitsBudget($css)) {
                $this->stylesheets[$file] = self::INLINE;
                $this->inlineCSS[$file] = $css;
                $this->inlineBytes += strlen($css);

                return;
            }
        }

        $this->css($file, $media);
        $this->stylesheets[$file] = self::DEFER;
    }

    /**
     * The <style> of a stylesheet that has to be in the page (the first paint needs it), for where it is asked for.
     * Null when the file is not there or is already in the page.
     */
    public function inlineStylesheet(string $path): ?string
    {
        $file = $this->resolveStylesheet($path);

        if (in_array($this->stylesheets[$file] ?? null, [self::INLINE, self::FORCED], true)) {
            return null;
        }

        $css = $this->readStylesheet($file);

        if ($css === null) {
            return null;
        }

        // If it was asked for before as a file to load, this is the page having it now
        $this->stylesheets[$file] = self::FORCED;

        return $this->styleTag($file, $css);
    }

    public function clear($fileOrID = null)
    {
        if ($fileOrID === null) {
            $this->stylesheets = [];
            $this->inlineCSS = [];
            $this->inlineBytes = 0;
            $this->versions = [];
        }

        parent::clear($fileOrID);
    }

    public function includeInHTML($content)
    {
        // The CMS is not made for stylesheets that arrive after the page does
        if (!$this->isFrontEnd()) {
            return parent::includeInHTML($content);
        }

        // Skip if content isn't injectable, or there is nothing to inject
        $tagsAvailable = preg_match('#</head\b#', $content ?? '');
        $hasFiles = $this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags || $this->inlineCSS;
        if (!$tagsAvailable || !$hasFiles) {
            return $content;
        }
        $requirements = '';
        $jsRequirements = '';

        // Combine files - updates $this->javascript and $this->css
        $this->processCombinedFiles();

        // Script tags for js links
        foreach ($this->getJavascript() as $file => $attributes) {
            $attributes['src'] = $this->pathForFile($file);
            $jsRequirements .= HTML::createTag('script', $attributes);
            $jsRequirements .= "\n";
        }

        // Add all inline JavaScript *after* including external files they might rely on
        foreach ($this->getCustomScripts() as $key => $script) {
            // Build html attributes
            $customHtmlAttributes = [];
            if (isset($this->customScriptAttributes[$key])) {
                foreach ($this->customScriptAttributes[$key] as $attrKey => $attrValue) {
                    $customHtmlAttributes[$attrKey] = $attrValue;
                }
            }
            $jsRequirements .= HTML::createTag(
                'script',
                $customHtmlAttributes,
                "//<![CDATA[\n{$script}\n//]]>"
            );
            $jsRequirements .= "\n";
        }

        $css = $this->getCSS();

        // What was required the usual way, which is not what the page decides on
        foreach ($css as $file => $params) {
            if (!isset($this->stylesheets[$file])) {
                $requirements .= $this->preloadTag($file, $params);
            }
        }

        // What the page decided on, in the order it was asked for, so the cascade is the order of the templates
        foreach ($this->stylesheets as $file => $how) {
            if ($how === self::INLINE && !isset($this->blocked[$file])) {
                $requirements .= $this->styleTag($file, $this->inlineCSS[$file]) . "\n";
            } elseif ($how === self::DEFER && isset($css[$file])) {
                $requirements .= $this->preloadTag($file, $css[$file]);
            }
        }

        // Literal custom CSS content
        foreach ($this->getCustomCSS() as $customCSS) {
            $requirements .= HTML::createTag('style', ['type' => 'text/css'], "\n{$customCSS}\n");
            $requirements .= "\n";
        }

        foreach ($this->getCustomHeadTags() as $customHeadTag) {
            $requirements .= "{$customHeadTag}\n";
        }

        // Inject CSS  into body
        $content = $this->insertTagsIntoHead($requirements, $content);

        // Inject scripts
        if ($this->getForceJSToBottom()) {
            $content = $this->insertScriptsAtBottom($jsRequirements, $content);
        } elseif ($this->getWriteJavascriptToBody()) {
            $content = $this->insertScriptsIntoBody($jsRequirements, $content);
        } else {
            $content = $this->insertTagsIntoHead($jsRequirements, $content);
        }
        return $content;
    }

    /**
     * The path of a stylesheet as Requirements keeps it (themes/cffc/dist/styles/x.css). The public path of a file that
     * the site exposes (resources/themes/cffc/dist/styles/x.css) is the same file.
     */
    public function resolveStylesheet(string $path): string
    {
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'resources/')) {
            $path = substr($path, strlen('resources/'));
        }

        return ModuleResourceLoader::singleton()->resolvePath($path);
    }

    protected function isFrontEnd(): bool
    {
        return !(Controller::curr() instanceof LeftAndMain);
    }

    protected function fitsBudget(string $css): bool
    {
        $budget = (int) Config::inst()->get(static::class, 'inline_budget');

        return $budget > 0 && $this->inlineBytes + strlen($css) <= $budget;
    }

    protected function preloadTag(string $file, array $params): string
    {
        $href = $this->pathForFile($file);

        $link = HTML::createTag('link', [
            'rel' => 'preload',
            'href' => $href,
            'as' => 'style',
            'onload' => "this.onload=null;this.rel='stylesheet'",
            ...$params,
        ]);

        // With no script the link is never switched to a stylesheet
        $fallback = HTML::createTag('link', [
            'rel' => 'stylesheet',
            'href' => $href,
            ...$params,
        ]);

        return "{$link}\n<noscript>{$fallback}</noscript>\n";
    }

    protected function styleTag(string $file, string $css): string
    {
        return HTML::createTag('style', ['data-src' => $file], $css);
    }

    /**
     * The css of a file, or null when there is none. It is kept under the file's modified time and size, so a file
     * that has changed (a new build, new css) is read again and nothing kept of its old self is used. In dev it is
     * always read.
     */
    protected function readStylesheet(string $file): ?string
    {
        $fullPath = Path::join(Director::baseFolder(), $file);

        if (!array_key_exists($file, $this->versions)) {
            $this->versions[$file] = is_file($fullPath) ? filemtime($fullPath) . '_' . filesize($fullPath) : null;
        }

        if ($this->versions[$file] === null) {
            return null;
        }

        $key = 'css_' . md5($file) . '_' . $this->versions[$file];
        $css = Director::isDev() ? null : $this->getCache()->get($key);

        if ($css === null) {
            $css = file_get_contents($fullPath);

            if ($css === false || trim($css) === '') {
                return null;
            }

            // A source map comment points at a file the page doesn't have
            $css = trim(preg_replace('~/\*[#@] sourceMappingURL=[^*]*\*/~', '', $css));

            if (!Director::isDev()) {
                $this->getCache()->set($key, $css, (int) Config::inst()->get(static::class, 'cache_lifetime'));
            }
        }

        return $css;
    }

    protected function getCache(): CacheInterface
    {
        if (!$this->cache) {
            $this->cache = Injector::inst()->get(CacheInterface::class . '.deferredCSS');
        }

        return $this->cache;
    }
}
