<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SeQura\Core\BusinessLogic\AdminAPI\AdminAPI;
use Sequra\Core\Model\Ui\ConfigProvider;

/**
 * payment/sequra_payment/active defaults to 1 on every scope. The Luma checkout hides
 * itself when SeQura offers no products, but Hyvä Checkout lists the Magento method
 * as-is, so store views of a multi-store install that were never connected to SeQura
 * would show it. Hide it there.
 */
class HideSequraOnUnconnectedStore implements ObserverInterface
{
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    /** @var array<string, bool> */
    private array $connectedStores = [];

    public function __construct(StoreManagerInterface $storeManager, LoggerInterface $logger)
    {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $result = $event->getData('result');

        if ($event->getData('method_instance')->getCode() !== ConfigProvider::CODE
            || !$result->getData('is_available')) {
            return;
        }

        $storeId = $this->resolveStoreId($event->getData('quote'));
        if (!array_key_exists($storeId, $this->connectedStores)) {
            $this->connectedStores[$storeId] = $this->isStoreConnected($storeId);
        }

        if (!$this->connectedStores[$storeId]) {
            $result->setData('is_available', false);
        }
    }

    /**
     * Reads the stored connection data only; no request to SeQura is made. Errors fail
     * open so a transient failure never hides SeQura on a connected store.
     */
    protected function isStoreConnected(string $storeId): bool
    {
        try {
            $response = AdminAPI::get()->connection($storeId)->getOnboardingData();
            if (!$response->isSuccessful()) {
                $this->logger->warning('SeQura checkout: could not read connection data', ['storeId' => $storeId]);
                return true;
            }

            return !empty($response->toArray()['connectionData'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->error('SeQura checkout: could not read connection data: ' . $e->getMessage(), [
                'storeId' => $storeId,
            ]);
            return true;
        }
    }

    private function resolveStoreId(?CartInterface $quote): string
    {
        if ($quote !== null && $quote->getStoreId() !== null) {
            return (string) $quote->getStoreId();
        }

        return (string) $this->storeManager->getStore()->getId();
    }
}
