<?php

namespace Toast\Injectors;

use SilverStripe\View\HTML;
use SilverStripe\View\Requirements_Backend;

class ToastEnhancedBackend extends Requirements_Backend
{
    public function includeInHTML($content)
    {
        // Skip if content isn't injectable, or there is nothing to inject
        $tagsAvailable = preg_match('#</head\b#', $content ?? '');
        $hasFiles = $this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags;
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

        // CSS file links
        foreach ($this->getCSS() as $file => $params) {
            $htmlAttributes = [
                'rel' => 'preload',
                'href' => $this->pathForFile($file),
                'as' => 'style',
                'onload' => "this.onload=null;this.rel='stylesheet'",
                ...$params,
            ];
            $requirements .= HTML::createTag('link', $htmlAttributes);
            $requirements .= "\n";
        }

        // Literal custom CSS content
        foreach ($this->getCustomCSS() as $css) {
            $requirements .= HTML::createTag('style', ['type' => 'text/css'], "\n{$css}\n");
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
}