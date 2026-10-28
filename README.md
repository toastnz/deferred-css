# Deferred CSS for Silverstripe

Keeps stylesheets out of the way of the first paint. It replaces Silverstripe's requirements backend
(`SilverStripe\View\Requirements_Backend`) with `Toast\Injectors\ToastEnhancedBackend`, which does three things.

## 1. Stylesheets that are required the usual way are deferred

`Requirements::css()` and `<% require css("...") %>` are written as

```html
<link rel="preload" as="style" href="..." onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="..."></noscript>
```

so they don't block the render, and still apply when there is no script.

## 2. A stylesheet can be left for the page to decide: `getCSS`

```html
{$getCSS("themes/cffc/dist/styles/elements/columns-block.css")}
```

is what `<% require css("...") %>` was, and the backend decides how the file gets there:

- while the page's **inline budget** lasts, the css is written into the `<head>` as a `<style data-src="...">`
- once it is spent, the file is loaded as in 1

The templates are rendered top to bottom, so what is nearest the top gets the budget first. The order the files
were asked for in is the order they are written in, so the cascade is the order of the templates. Asking for the same
file twice, or for a file that is already in the page, does nothing.

`getCSS` is a template global (`Toast\Injectors\DeferredCSSProvider`), so it works in any template and any scope: in a
loop, a `with`, or a template that a method renders (a breadcrumb trail, which has the page for its `$Top`, not the controller).
`Toast\Injectors\DeferredCSSExtension` has the same method for what renders a template, if you want it on a controller or
an element:

```yml
SilverStripe\Control\Controller:
  extensions:
    - Toast\Injectors\DeferredCSSExtension
DNADesign\Elemental\Models\BaseElement:
  extensions:
    - Toast\Injectors\DeferredCSSExtension
```

## 3. A stylesheet that has to be in the page: `inlineStylesheet`

`ToastEnhancedBackend::inlineStylesheet($path)` returns the `<style>` of a file, for where the template writes it
(the first paint needs it, whatever it costs). It is not part of the budget. If the file was asked for before as a file to
load, the page has it inline instead.

## Config

```yml
Toast\Injectors\ToastEnhancedBackend:
  inline_budget: 20000   # bytes of css getCSS may write into a page, 0 loads all of it
  cache_lifetime: 604800 # seconds an entry of the css cache is kept
```

## The css cache

The content of an inlined file is kept in the `deferredCSS` cache, under the file's path and its **modified time and
size**. A new build is a new modified time (and nearly always a new size), so it is a new entry: what was kept of the
old file is never used, and nothing has to be flushed for new css to reach the site. The entries of old builds are
dropped when `cache_lifetime` is up. In dev mode the cache is not used at all.

A page that is cached as a whole (a `<% cached %>` block, a static cache, a CDN) keeps the css it was rendered with.
`getCSS` should not be inside a `<% cached %>` block: when the block is read from the cache the template isn't rendered,
so the file is not asked for.

## What it leaves alone

The CMS (`LeftAndMain` controllers) keeps Silverstripe's own behaviour, its stylesheets are linked as usual.

## Installation

```sh
composer require toastnz/deferred-css
```

Then flush: `vendor/bin/sake dev/build flush=all`

## License

BSD-3-Clause. See `LICENSE`.
