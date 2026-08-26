# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this module is

`Hyva_SequraCore` (composer name `hyva-themes/magento2-sequra-core`) is a Hyvä **compatibility module** for the `Sequra_Core` Magento 2 payment module. The default `Sequra_Core` frontend is built with Knockout/RequireJS/jQuery, which Hyvä themes do not load. This module re-implements that frontend with **Alpine.js** (widgets) and **Magewire** (checkout), while reusing `Sequra_Core`'s PHP block and API classes unchanged.

It contains no application logic of its own beyond presentation — all business logic comes from `sequra/magento2-core` (`^3.0`), which is the source of truth for block classes, the AdminAPI/CheckoutAPI facades, and widget data.

## How the compatibility mechanism works

`Hyva_SequraCore` is registered with `Hyva_CompatModuleFallback` in `src/etc/frontend/di.xml`, mapping `original_module: Sequra_Core` → `compat_module: Hyva_SequraCore`. The fallback registry causes Magento to resolve `Sequra_Core::` template paths to this module's templates when a Hyvä theme is active. That is why the `.phtml` files here are typed against `Sequra_Core` block classes (e.g. `\Sequra\Core\Block\Product`, `\Sequra\Core\Block\WidgetInitializer`) — they are drop-in replacements rendered by the original module's layout/blocks.

`Hyva_SequraCore` sequences after `Sequra_Core` and `Hyva_CompatModuleFallback` (`src/etc/module.xml`); `Hyva_SequraCoreCheckout` additionally sequences after `Magewirephp_Magewire` and `Hyva_Checkout` (`src-checkout/etc/module.xml`).

Layout XML strips the original Knockout-based assets so they don't conflict:
- `src/view/frontend/layout/hyva_default.xml` removes `Sequra_Core::css/styles.css`.
- `src/view/frontend/layout/hyva_checkout_cart_index.xml` removes the `sequra-script` block.

## The four frontend surfaces

1. **Promotional widgets** (`templates/widget/product.phtml`, `templates/widget/cart.phtml`) — identical Alpine components (`prepareSequraWidgets`) that push the block's `getAvailableWidgets()` config into a shared global `window.SequraWidgetFacade.widgets`.

2. **Widget initializer / SDK bootstrap** (`templates/widget_initializer.phtml`) — the largest file. It defines the `sequraInitConfig` Alpine component which loads SeQura's external SDK script, builds the `SequraWidgetFacade` object, and contains the full client-side widget-drawing engine (price parsing, mutation-observer-driven redraws, mini-widget vs. widget rendering, theme presets). The `window.__sequraInitDone` guard ensures it runs once per page. Mirrors the upstream `Sequra_Core` JS but as inline Alpine instead of RequireJS modules.

3. **Hyvä Checkout payment method** — lives in a SECOND Magento module, `Hyva_SequraCoreCheckout` (`src-checkout/`, PSR-4 `Hyva\SequraCoreCheckout\`), because its classes extend Hyvä Checkout/Magewire base classes and those packages are an optional paid dependency. Its `registration.php` registers the module **only when** `Magewirephp\Magewire\Component` and `Hyva\Checkout\...\AbstractPlaceOrderService` exist, so `setup:di:compile` keeps working on Hyvä-Theme-only stores (the class scanner never visits unregistered directories). Consequence: after installing Hyvä Checkout, `bin/magento setup:upgrade` is required for Magento to discover the module (see ADR 2026-08-26). Requires `hyva-themes/magento2-hyva-checkout` >= 1.1.13 (a `suggest`, not a hard `require`). Two collaborating pieces:
   - **Magewire component** (`src-checkout/Magewire/Checkout/Payment/Method/Sequra.php` extending `Magewirephp\Magewire\Component` + its template) — renders the method UI (method code `sequra_payment`), solicits available methods from the SeQura API on `mount()`, refreshes on cart/address change, and on selection stores `sequra_product`/`sequra_campaign` onto the **quote payment** `additionalInformation` (via `CartRepositoryInterface::save`). It does **not** place the order or handle redirects. The method view is registered as a **layout block** in `src-checkout/view/frontend/layout/hyva_checkout_components.xml`: a child of `checkout.payment.methods` whose `as=` alias MUST equal the payment method code (Hyvä's MethodList resolves it via `getChildBlock($method->getCode())`). There is no `etc/hyva_checkout_components.xml` mechanism in Hyvä Checkout 1.3.x — an etc/ file is silently ignored.
   - **Place Order Service** (`src-checkout/Service/PlaceOrderService.php` extending `Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService`, registered under `PlaceOrderServiceProvider` in `src-checkout/etc/frontend/di.xml` keyed by `sequra_payment`) — owns order placement for the method. Critical invariants: `canPlaceOrder()` returns **`false`** (the processor's order id stays `null`, so Hyvä never fires `order:place:success` before payment — a `placeOrder()` returning `0` would count as success because `0 !== null`) and `placeOrder()` is a no-op safety net. `canRedirect()` is `true` and `getRedirectUrl()` redirects the shopper to `sequra/hpp?sequra_product=…&sequra_campaign=…` (both hosted and inline configs route through the hosted page). The order is created later by the SeQura webhook (`sequra/webhook` → `CartManagement::placeOrder`), so the quote must stay active — placing it at checkout would duplicate the order and send a premature confirmation email.
   - **CSP-theme constraints** (validated against `magento2-default-theme-csp` + Hyvä Checkout 1.3.9): Alpine's CSP build rejects expression syntax (`x-data="fn()"`, `x-init="..."`), and Livewire evaluates both inline action arguments (`wire:click="fn('a')"`) and `x-data` attributes inside its components with `eval()`, which strict CSP blocks — an uncaught EvalError freezes the checkout step. Therefore the payment template uses **no Alpine at all** and binds the product radios with `wire:model` (value `product|campaign`, handled by `updatedSelection()`), the same mechanism Hyvä's own method list uses. Keep it that way.

4. **Hosted payment page** (`templates/hpp.phtml`, base module) — replaces `Sequra_Core::hpp.phtml`, which boots via `require([...])` (unavailable on Hyvä themes). Plain-JS port of the same flow: POST to the `fetch-sequra_payment-form` REST endpoint (guest/mine) **with the `X-Requested-With: XMLHttpRequest` header** (the core service validates `isAjax()`), append the returned form HTML re-creating its `<script>` tags (unlike jQuery's `append`, `innerHTML` does not execute them), wait bounded for `window.SequraFormInstance`, then `show()`. Stays in the base module: theme-only stores also render `sequra/hpp` under the Hyvä theme.

SeQura API access goes through `AdminAPI::get()` and `CheckoutAPI::get()` static facades from `SeQura\Core`.

## Conventions

- **CSP**: every inline `<script>` template registers itself via `$hyvaCsp->registerInlineScript()` (the `HyvaCsp` view model) so Hyvä's Content-Security-Policy allows it. Preserve this guard when adding inline scripts.
- **Escaping**: use `$escaper->escapeHtml*` in checkout templates and `$block->escapeQuote(...)` in widget templates; JSON is emitted with `JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
- **Namespace**: PHP is `Hyva\SequraCore\` (PSR-4 → `src/`) for the base module and `Hyva\SequraCoreCheckout\` (PSR-4 → `src-checkout/`) for the checkout module; the Magento module names are `Hyva_SequraCore` and `Hyva_SequraCoreCheckout`.
- **Tailwind**: each module ships its own `view/frontend/tailwind/tailwind.config.js` scanning its templates — a template added to one module is not purged-scanned by the other's config.

## Working in this repo

There is no build, lint, or test tooling in this repository — it is a Magento module consumed by a host Magento 2 + Hyvä install. Verification happens inside such an install (`bin/magento setup:upgrade`, then exercising product/cart pages and Hyvä Checkout). Tailwind purge scans `templates/**/*.phtml` (`view/frontend/tailwind/tailwind.config.js`), so utility classes added in templates are picked up by the host theme's Tailwind build.

Specs live in `.sdd/specs/` (Spec-Driven Development; see the `sq-spec` skill). Architectural decisions are recorded as ADRs in `docs/decisions/`.
