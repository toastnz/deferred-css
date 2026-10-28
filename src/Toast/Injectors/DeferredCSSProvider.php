<?php

namespace Toast\Injectors;

use SilverStripe\View\Requirements;
use SilverStripe\View\TemplateGlobalProvider;

/**
 * {$getCSS("themes/cffc/dist/styles/components/breadcrumbs.css")} in any template, whatever it is rendered with. A
 * template that is rendered by a method (a breadcrumb trail, a menu) has the page for its $Top, not the controller, and
 * that has no getCSS (DeferredCSSExtension is on what the template can be rendered by: the controller, an element),
 * so the global is what is found there.
 */
class DeferredCSSProvider implements TemplateGlobalProvider
{
    public static function get_template_global_variables(): array
    {
        return ['getCSS'];
    }

    /**
     * Asks for a stylesheet: written into the head of the page while there is room for it, loaded without blocking
     * the render when there is not. Prints nothing where it is.
     */
    public static function getCSS(string $path = '', ?string $media = null)
    {
        if ($path === '') {
            return null;
        }

        $backend = Requirements::backend();

        if ($backend instanceof ToastEnhancedBackend) {
            $backend->addStylesheet($path, $media);
        } else {
            Requirements::css($path, $media);
        }

        return null;
    }
}
