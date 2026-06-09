<?php

declare(strict_types=1);

namespace Hyva\SequraCore\Magewire\Checkout\Payment\Method;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use SeQura\Core\BusinessLogic\AdminAPI\AdminAPI;
use SeQura\Core\BusinessLogic\CheckoutAPI\CheckoutAPI;
use Sequra\Core\Model\Api\Builders\CreateOrderRequestBuilderFactory;

class Sequra extends Component
{
    public const METHOD_CODE = 'sequra_payment';

    public array $paymentMethods = [];
    public ?string $selectedProduct = null;
    public ?string $selectedCampaign = null;
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
                $this->paymentMethods = [];
                $this->isLoading = false;
                return;
            }

            $storeId = (string) $quote->getStore()->getId();

            $builder = $this->createOrderRequestBuilderFactory->create([
                'cartId' => $quote->getId(),
                'storeId' => $storeId,
            ]);

            $generalSettings = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
            if (!$generalSettings->isSuccessful() || !$builder->isAllowedFor($generalSettings)) {
                $this->paymentMethods = [];
                $this->isLoading = false;
                return;
            }

            $response = CheckoutAPI::get()->solicitation($storeId)->solicitFor($builder);
            if (!$response->isSuccessful()) {
                $this->paymentMethods = [];
                $this->isLoading = false;
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

    public function selectProduct(string $product, string $campaign = ''): void
    {
        $this->selectedProduct = $product;
        $this->selectedCampaign = $campaign;
        $this->persistSelection();
    }

    /**
     * The selected product/campaign are stored on the quote payment so the place-order
     * service can build the hosted-page URL and the webhook-created order carries them.
     */
    private function persistSelection(): void
    {
        if ($this->selectedProduct === null) {
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $payment = $quote->getPayment();
            $payment->setAdditionalInformation('sequra_product', $this->selectedProduct);
            $payment->setAdditionalInformation('sequra_campaign', $this->selectedCampaign ?? '');
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store SeQura selection: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    public function getMethodCode(): string
    {
        return self::METHOD_CODE;
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
}
