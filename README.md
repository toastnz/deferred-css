# Deferred CSS for Silverstripe

This module customises Silverstripe's requirements backend to defer CSS loading using the `preload` pattern.

It overrides `includeInHTML()` and rewrites stylesheet links to:

```html
<link rel="preload" as="style" href="..." onload="this.onload=null;this.rel='stylesheet'">
```

This helps reduce initial render-blocking caused by CSS files while still applying styles once each file has loaded.

## What this module changes

- Replaces the default `SilverStripe\View\Requirements_Backend` via Injector.
- Overrides `includeInHTML()` in `Toast\Injectors\ToastEnhancedBackend`.
- Keeps JavaScript and custom head tags injection behavior aligned with the parent backend flow.
- Converts CSS requirements to `rel="preload"` links with an `onload` switch to `rel="stylesheet"`.

Configured in `_config/config.yml`:

```yml
SilverStripe\Core\Injector\Injector:
  SilverStripe\View\Requirements_Backend:
    class: Toast\Injectors\ToastEnhancedBackend
```

## Installation

```sh
composer require toastnz/deferred-css
```

Then flush configuration:

```sh
vendor/bin/sake dev/build flush=all
```

## How it works

In `ToastEnhancedBackend::includeInHTML()`:

1. Checks if `</head>` exists and there are requirements to inject.
2. Runs `processCombinedFiles()` to preserve combined assets behavior.
3. Outputs JavaScript includes and inline custom scripts.
4. Rewrites CSS includes as preload links with `as="style"` and `onload` rel swap.
5. Injects CSS/head tags into `<head>` and scripts according to Silverstripe settings.

## Notes

- This module targets Silverstripe Framework 6.
- `onload`-based deferred CSS is widely used, but always test critical rendering paths in your project.
- Validate frontend behavior in older browsers used by your audience.

## License

BSD-3-Clause. See `LICENSE`.
