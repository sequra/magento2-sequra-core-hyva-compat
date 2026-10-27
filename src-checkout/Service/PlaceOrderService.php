<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Service;

use Hyva\Checkout\Model\Magewire\Payment\AbstractOrderData;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Hyva\Checkout\Model\Magewire\Payment\PlaceOrderServiceInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Sequra\Core\Observer\DataAssignObserver;

class PlaceOrderService extends AbstractPlaceOrderService implements PlaceOrderServiceInterface
{
    private UrlInterface $url;
    private AvailablePaymentMethods $availablePaymentMethods;

    public function __construct(
        CartManagementInterface $cartManagement,
        UrlInterface $url,
        ?AbstractOrderData $orderData = null,
        ?AvailablePaymentMethods $availablePaymentMethods = null
    ) {
        parent::__construct($cartManagement, $orderData);
        $this->url = $url;
        $this->availablePaymentMethods = $availablePaymentMethods
            ?: ObjectManager::getInstance()->get(AvailablePaymentMethods::class);
    }

    /**
     * SeQura creates the Magento order from its webhook (sequra/webhook) once the
     * shopper completes identification, exactly as in the standard checkout. The
     * checkout step must therefore not place the order and must leave the quote
     * active for the webhook. canPlaceOrder() = false keeps the processor's order
     * id null, so Hyvä does not fire order:place:success before payment; the
     * no-op placeOrder() stays as a safety net should it ever be called.
     */
    public function canPlaceOrder(): bool
    {
        return false;
    }

    public function placeOrder(Quote $quote): int
    {
        return 0;
    }

    public function canRedirect(): bool
    {
        return true;
    }

    /**
     * Refuses to send the shopper to the hosted page when SeQura has answered that it offers
     * nothing for the cart as it is now, or no longer offers the product they picked (the cart
     * or the shipping changed after picking it). When SeQura cannot be asked, the hosted page
     * solicits again and decides, as before.
     *
     * @throws LocalizedException
     */
    public function getRedirectUrl(Quote $quote, ?int $orderId = null): string
    {
        $payment = $quote->getPayment();
        $product = (string) $payment->getAdditionalInformation(DataAssignObserver::SEQURA_PRODUCT_KEY);

        $methods = $this->availablePaymentMethods->get($quote);
        if ($methods === []) {
            throw new LocalizedException(
                __('SeQura is not available for this order. Please choose another payment method.')
            );
        }
        if ($methods !== null && $product !== '' && !in_array($product, array_column($methods, 'product'), true)) {
            throw new LocalizedException(
                __('The SeQura option you selected is no longer available for this order. Please select it again.')
            );
        }

        return $this->url->getUrl('sequra/hpp', [
            '_query' => [
                'sequra_product' => $product,
                'sequra_campaign' => (string) $payment->getAdditionalInformation(DataAssignObserver::SEQURA_CAMPAIGN_KEY),
            ],
        ]);
    }
}
