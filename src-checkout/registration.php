<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes 2020-present. All rights reserved.
 * This product is licensed per Magento install
 * See https://hyva.io/license
 */

use Magento\Framework\Component\ComponentRegistrar;

// The module extends Hyvä Checkout / Magewire base classes, which are an
// optional paid dependency. Registering it only when they are installed keeps
// setup:di:compile working on Hyvä-Theme-only stores (the class scanner never
// visits unregistered directories). After installing Hyvä Checkout, run
// bin/magento setup:upgrade so Magento picks the module up.
if (class_exists(\Magewirephp\Magewire\Component::class)
    && class_exists(\Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService::class)
) {
    ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Hyva_SequraCoreCheckout', __DIR__);
}
