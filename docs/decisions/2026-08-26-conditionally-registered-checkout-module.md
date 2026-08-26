# Ship the Hyvä Checkout integration as a conditionally registered module

---
* **Status:** ✅ accepted
* **Deciders:** Michel Escalante Álvarez
* **Proposal date:** 2026-08-26
* **Technical Story:** [TIJ-1972](https://sequra.atlassian.net/browse/TIJ-1972)
---
## Context and Problem Statement

The Hyvä Checkout integration (Magewire payment component and place-order service) extends classes from `magewirephp/magewire` and `hyva-themes/magento2-hyva-checkout`. Hyvä Checkout is a paid package on Hyvä's private Packagist, so it can only be a composer `suggest`. But `setup:di:compile` scans every class of every registered module: on a store with Hyvä Theme only (no Hyvä Checkout/Magewire), the missing parent classes make the compilation fail — this actually happened on a merchant install (November 2025, `Class Magewirephp\Magewire\Component not found`).

How do we ship the checkout integration without breaking Hyvä-Theme-only installs?

## Considered Options

1. **Hard `require` of `hyva-themes/magento2-hyva-checkout`.** Rejected: it is a paid package on a private repo — composer would not even resolve for theme-only merchants, and they would be forced to buy Hyvä Checkout.
2. **Separate composer package** (the ecosystem pattern: `hyva-themes/magento2-klarna-kp`, `multisafepay/magento2-hyva-checkout`, `mollie/magento2-hyva-compatibility` are all standalone). Clean, but adds a second repo, release cycle, and installation step.
3. **Same package, second module with guarded registration.** The checkout code lives in its own Magento module (`Hyva_SequraCoreCheckout`, `src-checkout/`) whose `registration.php` only registers the module when `\Magewirephp\Magewire\Component` and `\Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService` exist. The di:compile class scanner only visits registered component directories, so theme-only stores compile cleanly.

## Decision Outcome

Option **3**. One repo and one `composer require` for merchants, clean degradation for theme-only stores, and the code is already isolated in its own module — extracting it into a standalone package later (option 2, e.g. for distribution through Hyvä's Packagist) stays trivial.

### Consequences

- Stores that add Hyvä Checkout **after** this package must run `bin/magento setup:upgrade` (plus `setup:di:compile` and `setup:static-content:deploy` in production mode) so Magento discovers the newly registered module. Documented in the README and the composer `suggest` text.
- The base module `Hyva_SequraCore` keeps everything with no Hyvä Checkout dependency, including the `hpp.phtml` override (the hosted payment page renders under the Hyvä theme and needs the RequireJS-free bootstrap on theme-only stores too).
