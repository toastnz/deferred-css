<?php

namespace Toast\Injectors;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * {$getCSS("themes/cffc/dist/styles/elements/columns-block.css")} on what renders a template (a controller, an element),
 * which is what <% require css("...") %> was: the page decides how the file gets there (see ToastEnhancedBackend).
 * DeferredCSSProvider is the same in every other scope.
 */
class DeferredCSSExtension extends Extension
{
    /**
     * Asks for a stylesheet: written into the head of the page while there is room for it, loaded without blocking
     * the render when there is not. Prints nothing where it is.
     */
    public function getCSS(string $path = '', ?string $media = null)
    {
        return DeferredCSSProvider::getCSS($path, $media);
    }
}
