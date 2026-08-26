<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Magewire\Checkout\Payment\Method;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use SeQura\Core\BusinessLogic\AdminAPI\AdminAPI;
use SeQura\Core\BusinessLogic\CheckoutAPI\CheckoutAPI;
use Sequra\Core\Model\Api\Builders\CreateOrderRequestBuilderFactory;
use Sequra\Core\Observer\DataAssignObserver;

class Sequra extends Component
{
    public const METHOD_CODE = 'sequra_payment';

    public array $paymentMethods = [];
    public ?string $selectedProduct = null;
    public ?string $selectedCampaign = null;
    /** "product|campaign" radio binding — wire:model works under Hyvä's CSP theme, inline action arguments do not. */
    public ?string $selection = null;
    public bool $isLoading = true;
    public ?string $errorMessage = null;

    protected CheckoutSession $checkoutSession;
    protected CartRepositoryInterface $quoteRepository;
    protected CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory;
    protected LoggerInterface $logger;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory,
        LoggerInterface $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->createOrderRequestBuilderFactory = $createOrderRequestBuilderFactory;
        $this->logger = $logger;
    }

    public function mount(): void
    {
        $this->loadPaymentMethods();
    }

    public function loadPaymentMethods(): void
    {
        $this->isLoading = true;
        $this->errorMessage = null;

        try {
            $quote = $this->checkoutSession->getQuote();

            if (empty($quote->getShippingAddress()->getCountryId())) {
                $this->clearMethods();
                return;
            }

            $storeId = (string) $quote->getStore()->getId();

            $builder = $this->createOrderRequestBuilderFactory->create([
                'cartId' => $quote->getId(),
                'storeId' => $storeId,
            ]);

            $generalSettings = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
            if (!$generalSettings->isSuccessful()) {
                $this->logger->warning('SeQura checkout: general settings response unsuccessful', [
                    'storeId' => $storeId,
                    'response' => $generalSettings->toArray(),
                ]);
                $this->clearMethods();
                return;
            }

            if (!$builder->isAllowedFor($generalSettings)) {
                $this->logger->info('SeQura checkout: cart not allowed (merchant per country, IP or exclusions)', [
                    'storeId' => $storeId,
                ]);
                $this->clearMethods();
                return;
            }

            $response = CheckoutAPI::get()->solicitation($storeId)->solicitFor($builder);
            if (!$response->isSuccessful()) {
                $this->logger->warning('SeQura checkout: solicitation unsuccessful', [
                    'storeId' => $storeId,
                    'response' => $response->toArray(),
                ]);
                $this->clearMethods();
                return;
            }

            $this->paymentMethods = $response->toArray()['availablePaymentMethods'] ?? [];

            if (!empty($this->paymentMethods) && $this->selectedProduct === null) {
                $this->selectProduct(
                    $this->paymentMethods[0]['product'] ?? '',
                    $this->paymentMethods[0]['campaign'] ?? ''
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to load SeQura payment methods: ' . $e->getMessage(), ['exception' => $e]);
            $this->errorMessage = __('Failed to load SeQura payment methods.')->render();
            $this->paymentMethods = [];
        }

        $this->isLoading = false;
    }

    public function updatedSelection(?string $value): ?string
    {
        $parts = explode('|', (string) $value, 2);
        $this->selectProduct($parts[0], $parts[1] ?? '');

        return $this->selection;
    }

    public function selectProduct(string $product, string $campaign = ''): void
    {
        foreach ($this->paymentMethods as $method) {
            if (($method['product'] ?? '') === $product) {
                $this->selectedProduct = $product;
                $this->selectedCampaign = $campaign;
                $this->selection = $product . '|' . $campaign;
                $this->persistSelection();
                return;
            }
        }
    }

    public function getAmount(): int
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            return (int) round($quote->getGrandTotal() * 100);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function refreshPaymentMethods(): void
    {
        $this->loadPaymentMethods();
    }

    private function clearMethods(): void
    {
        $this->paymentMethods = [];
        $this->isLoading = false;
    }

    /**
     * The selected product/campaign are stored on the quote payment so the place-order
     * service can build the hosted-page URL and the webhook-created order carries them.
     */
    private function persistSelection(): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $payment = $quote->getPayment();

            if ($payment->getAdditionalInformation(DataAssignObserver::SEQURA_PRODUCT_KEY) === $this->selectedProduct
                && $payment->getAdditionalInformation(DataAssignObserver::SEQURA_CAMPAIGN_KEY) === $this->selectedCampaign) {
                return;
            }

            $payment->setAdditionalInformation(DataAssignObserver::SEQURA_PRODUCT_KEY, $this->selectedProduct);
            $payment->setAdditionalInformation(DataAssignObserver::SEQURA_CAMPAIGN_KEY, $this->selectedCampaign);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store SeQura selection: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
