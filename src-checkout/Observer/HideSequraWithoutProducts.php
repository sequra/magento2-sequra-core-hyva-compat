<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Observer;

use Hyva\SequraCoreCheckout\Service\AvailablePaymentMethods;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Sequra\Core\Model\Ui\ConfigProvider;

/**
 * Hyvä Checkout lists sequra_payment even when SeQura offers no products for the cart (Luma's
 * Knockout template hides itself instead). Once SeQura has answered "nothing" for the cart as
 * it is now, stop listing the method. It only reads the answer cached by the method component;
 * it never calls SeQura, so rendering the method list costs nothing extra.
 *
 * The method stays listed while it is the quote's selected method: hiding it then makes Hyvä's
 * method list reset the selection and show a "could not save your payment preferences" error.
 * It disappears as soon as the shopper picks another method.
 */
class HideSequraWithoutProducts implements ObserverInterface
{
    private AvailablePaymentMethods $availablePaymentMethods;
    private CheckoutSession $checkoutSession;

    public function __construct(AvailablePaymentMethods $availablePaymentMethods, CheckoutSession $checkoutSession)
    {
        $this->availablePaymentMethods = $availablePaymentMethods;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $result = $event->getData('result');
        $quote = $event->getData('quote');

        if ($event->getData('method_instance')->getCode() !== ConfigProvider::CODE
            || !$result->getData('is_available')
            || !$quote instanceof Quote
            || !$quote->getId()
            || (int) $quote->getId() !== (int) $this->checkoutSession->getQuoteId()
            || $quote->getPayment()->getMethod() === ConfigProvider::CODE
        ) {
            return;
        }

        if ($this->availablePaymentMethods->peek($quote) === []) {
            $result->setData('is_available', false);
        }
    }
}
