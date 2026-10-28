<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use SeQura\Core\BusinessLogic\AdminAPI\AdminAPI;
use SeQura\Core\BusinessLogic\CheckoutAPI\CheckoutAPI;
use Sequra\Core\Model\Api\Builders\CreateOrderRequestBuilderFactory;

/**
 * Asks SeQura which products it offers for a cart and remembers the answer in the checkout
 * session, so the method component, the availability gate and the place-order guard share one
 * solicitation per cart state. Every solicitation creates an order on SeQura's side, so it only
 * runs when the shopper picks SeQura or places the order with it, never while rendering the list.
 */
class AvailablePaymentMethods
{
    private const SESSION_KEY = 'hyva_sequra_available_methods';
    private const TTL_SECONDS = 600;

    private CheckoutSession $checkoutSession;
    private CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory;
    private LoggerInterface $logger;

    public function __construct(
        CheckoutSession $checkoutSession,
        CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory,
        LoggerInterface $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->createOrderRequestBuilderFactory = $createOrderRequestBuilderFactory;
        $this->logger = $logger;
    }

    /**
     * The products SeQura offers for the cart: a list, an empty array when SeQura offers nothing
     * (or the cart is excluded), or null when it cannot be decided (no address yet, API error).
     * Only decided answers are cached.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function get(Quote $quote): ?array
    {
        $cached = $this->peek($quote);
        if ($cached !== null) {
            return $cached;
        }

        $methods = $this->solicit($quote);
        if ($methods !== null) {
            $this->checkoutSession->setData(self::SESSION_KEY, [
                'fingerprint' => $this->fingerprint($quote),
                'expires_at' => time() + self::TTL_SECONDS,
                'methods' => $methods,
            ]);
        }

        return $methods;
    }

    /**
     * The cached answer for the cart as it is now, without calling SeQura.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function peek(Quote $quote): ?array
    {
        $cached = $this->checkoutSession->getData(self::SESSION_KEY);
        if (!is_array($cached)
            || ($cached['expires_at'] ?? 0) < time()
            || ($cached['fingerprint'] ?? null) !== $this->fingerprint($quote)
        ) {
            return null;
        }

        return $cached['methods'];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function solicit(Quote $quote): ?array
    {
        try {
            if (empty($quote->getShippingAddress()->getCountryId())) {
                return null;
            }

            $storeId = (string) $quote->getStore()->getId();

            $generalSettings = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
            if (!$generalSettings->isSuccessful()) {
                $this->logger->warning('SeQura checkout: general settings response unsuccessful', [
                    'storeId' => $storeId,
                    'response' => $generalSettings->toArray(),
                ]);
                return null;
            }

            $builder = $this->createOrderRequestBuilderFactory->create([
                'cartId' => $quote->getId(),
                'storeId' => $storeId,
            ]);

            if (!$builder->isAllowedFor($generalSettings)) {
                $this->logger->info('SeQura checkout: cart not allowed (merchant per country, IP or exclusions)', [
                    'storeId' => $storeId,
                ]);
                return [];
            }

            $response = CheckoutAPI::get()->solicitation($storeId)->solicitFor($builder);
            if (!$response->isSuccessful()) {
                $this->logger->warning('SeQura checkout: solicitation unsuccessful', [
                    'storeId' => $storeId,
                    'response' => $response->toArray(),
                ]);
                return null;
            }

            return array_values($response->toArray()['availablePaymentMethods'] ?? []);
        } catch (\Exception $e) {
            $this->logger->error('Failed to load SeQura payment methods: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    /**
     * What SeQura's answer depends on and the shopper can change during checkout. The email and
     * the billing address are left out on purpose: typing them would trigger a new solicitation.
     */
    private function fingerprint(Quote $quote): string
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $lines[] = $item->getSku() . ':' . (float) $item->getQty();
        }
        sort($lines);

        return sha1(implode('|', [
            (string) $quote->getStoreId(),
            (string) $quote->getId(),
            number_format((float) $quote->getGrandTotal(), 2, '.', ''),
            (string) $quote->getQuoteCurrencyCode(),
            (string) $quote->getShippingAddress()->getCountryId(),
            implode(',', $lines),
        ]));
    }
}
