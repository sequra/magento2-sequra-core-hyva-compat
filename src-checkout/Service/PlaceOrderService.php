<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Service;

use Hyva\Checkout\Model\Magewire\Payment\AbstractOrderData;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Hyva\Checkout\Model\Magewire\Payment\PlaceOrderServiceInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Sequra\Core\Observer\DataAssignObserver;

class PlaceOrderService extends AbstractPlaceOrderService implements PlaceOrderServiceInterface
{
    private UrlInterface $url;

    public function __construct(
        CartManagementInterface $cartManagement,
        UrlInterface $url,
        ?AbstractOrderData $orderData = null
    ) {
        parent::__construct($cartManagement, $orderData);
        $this->url = $url;
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

    public function getRedirectUrl(Quote $quote, ?int $orderId = null): string
    {
        $payment = $quote->getPayment();

        return $this->url->getUrl('sequra/hpp', [
            '_query' => [
                'sequra_product' => (string) $payment->getAdditionalInformation(DataAssignObserver::SEQURA_PRODUCT_KEY),
                'sequra_campaign' => (string) $payment->getAdditionalInformation(DataAssignObserver::SEQURA_CAMPAIGN_KEY),
            ],
        ]);
    }
}
