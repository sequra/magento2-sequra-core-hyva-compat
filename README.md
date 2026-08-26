
# magento2-sequra-core
Hyvä Themes Compatibility module for Sequra_Core

This module provides compatibility for:
- **Hyvä Theme**: Product and cart page widgets
- **Hyvä Checkout**: Payment method integration (requires Hyvä Checkout >= 1.1.13)

## Installation

### Via packagist.com

Hyvä Compatibility modules that are tagged as stable can be installed using composer via packagist.com:

1. Install via composer
    ```
    composer require sequra/magento2-sequra-core-hyva-compat
    ```
2. Enable module
    ```
    bin/magento setup:upgrade
    ```


### Via gitlab

For development of or to contribute to a compatibility module, it needs to be installed using composer via gitlab.  
This installation method is not suited for deployments, because gitlab requires SSH key authorization.

1. Install via composer
    If this is the first time a compatibility module is installed via gitlab, the compat-module-fallback repository has to be
    added as a composer repository. This step is only required once.
    ```
    composer config repositories.hyva-themes/magento2-compat-module-fallback git git@gitlab.hyva.io:hyva-themes/magento2-compat-module-fallback.git
    ```

    When the compat-module-fallback repo is configured, the compatibility module itself can be installed with composer:
    ```
    composer config repositories.hyva-themes/magento2-sequra-core git git@gitlab.hyva.io:hyva-themes/hyva-compat/magento2-sequra-core.git
    composer require hyva-themes/magento2-sequra-core:dev-main
    ```
2. Enable module
    ```
    bin/magento module:enable Hyva_CompatModuleFallback Hyva_SequraCore
    bin/magento setup:upgrade
    ```

## Hyvä Checkout Support

The package ships two Magento modules:

- **`Hyva_SequraCore`** — the Hyvä Theme compatibility (widgets, widget initializer, hosted payment page). Always active.
- **`Hyva_SequraCoreCheckout`** — the Hyvä Checkout payment integration. It **registers itself only when Hyvä Checkout and Magewire are installed** (its classes extend theirs, and registering them unconditionally would break `setup:di:compile` on stores that only use Hyvä Theme).

For Hyvä Checkout payment method integration, ensure you have Hyvä Checkout (>= 1.1.13) installed:

```
composer require hyva-themes/magento2-hyva-checkout
```

**Important:** because the checkout module registers conditionally, Magento only discovers it after Hyvä Checkout is present. After installing (or later adding) Hyvä Checkout, you **must** run:

```
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento setup:static-content:deploy <locales>   # production mode
```

The SeQura payment methods will then automatically appear in the checkout.

Order placement is handled through a Hyvä Checkout place-order service: the Magento order is **not** created when the shopper clicks *Place Order*. Instead the shopper is redirected to the SeQura hosted page to complete identification, and the order is created by the SeQura webhook — exactly as in the standard Magento checkout.

### Features

- Dynamic payment method loading from the SeQura API
- Redirect to the SeQura hosted page for both hosted and inline configurations
- Educational popup widgets for payment information
- Automatic refresh when shipping/billing address changes
