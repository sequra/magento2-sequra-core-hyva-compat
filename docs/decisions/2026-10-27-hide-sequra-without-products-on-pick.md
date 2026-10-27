# Hide sequra_payment once SeQura offers no products, without soliciting on render

---
* **Status:** 💡 proposed
* **Proposal date:** 2026-10-27
* **Technical Story:** follow-up of the caveat in [#5](https://github.com/sequra/magento2-sequra-core-hyva-compat/pull/5)
---
## Context and Problem Statement

Hyvä Checkout lists the Magento method `sequra_payment` as-is. When SeQura offers no products for the cart (for example, below the merchant's minimum amount), the shopper can still pick it, sees "No SeQura payment methods available for this order." (untranslated), and if they place the order they are redirected to `sequra/hpp?sequra_product=` with an empty product, where SeQura shows that there are no payment methods. In the Luma checkout the Knockout template draws one option per product and shows nothing.

Whether SeQura offers anything is only known by soliciting: the cached `PaymentMethod` entity carries `min_amount: 0` and `max_amount: null` on sandbox and live merchants, and every solicitation creates an order on SeQura's side (`POST /orders`, about 0.9 s on sandbox). How do we stop offering the method without adding that cost to every checkout?

## Decision Drivers

* No extra call to SeQura for shoppers who never pick SeQura.
* Never block a payment because SeQura could not be asked: errors keep today's behaviour.
* Never redirect to the hosted page with a product SeQura does not offer for the cart as it is now.

## Considered Options

1. **Solicit while rendering the method list** and hide the method when SeQura offers nothing.
2. **Decide when the shopper picks SeQura** (as the component already does), remember the answer per cart state, stop listing the method afterwards, and guard the redirect on the server.

## Decision Outcome

Option **2**.

* `Service\AvailablePaymentMethods` holds the solicitation the component used to do inline and caches decided answers (a list, or `[]`) in the checkout session for 10 minutes, keyed by store, quote, grand total, currency, shipping country and lines. `null` (no address yet, API error) is never cached. The email and the billing address are left out of the key so typing them does not trigger a new solicitation.
* `Observer\HideSequraWithoutProducts` (`payment_method_is_active`) only reads that cache and never calls SeQura. It skips quotes other than the checkout session's, and it keeps the method listed while it is the quote's selected method: hiding it then makes Hyvä's `MethodList` reset the selection and show "Something went wrong while saving your payment preferences". It disappears as soon as the shopper picks another method.
* `PlaceOrderService::getRedirectUrl()` refuses the redirect with a translated `LocalizedException` when the answer for the current cart state is `[]` or no longer contains the selected product (the cart or shipping changed after picking it). With `null` it redirects as before and the hosted page decides.
* The component shows a specific, translated message when SeQura offers nothing and blocks completion only in that case.

### Positive Consequences

* Same number of calls to SeQura as before: one when the shopper picks SeQura and the hosted page's own; the guard reuses the cached answer unless the cart changed.
* No dead end on the hosted page and no English text on non-English store views (`i18n/` for es_ES, ca_ES, pt_PT, fr_FR, it_IT).

### Negative Consequences

* The method is still listed until the shopper picks it once. Option 1 would avoid that.
* When the cart changed since the shopper picked SeQura, placing the order solicits once more before the hosted page does.

## Pros and Cons of the Options

### Option 1

* Good, because the method never shows when SeQura offers nothing (as in v2 and Luma).
* Bad, because it adds a synchronous `POST /orders` to the server render of every checkout, including shoppers who never pick SeQura. Luma fetches asynchronously from the browser.
* Bad, because the HTTP client's default timeout is 900 s and is global to integration-core: a slow SeQura API would hold the whole checkout.
* Bad, because it creates a solicited order on SeQura's side for every visitor that reaches the payment step.
