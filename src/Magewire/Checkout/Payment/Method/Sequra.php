<?php

declare(strict_types=1);

namespace Hyva\SequraCore\Magewire\Checkout\Payment\Method;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magewire\Magewire\Component;
use SeQura\Core\BusinessLogic\AdminAPI\AdminAPI;
use SeQura\Core\BusinessLogic\CheckoutAPI\CheckoutAPI;
use Sequra\Core\Model\Api\Builders\CreateOrderRequestBuilderFactory;

class Sequra extends Component
{
    public const METHOD_CODE = 'sequra_payment';

    public $loader = [
        'loadPaymentMethods' => 'Loading payment methods...',
        'placeOrder' => 'Placing order...',
    ];

    /**
     * @var array
     */
    public array $paymentMethods = [];

    /**
     * @var string|null
     */
    public ?string $selectedProduct = null;

    /**
     * @var string|null
     */
    public ?string $selectedCampaign = null;

    /**
     * @var bool
     */
    public bool $showSeQuraCheckoutAsHostedPage = false;

    /**
     * @var string
     */
    public string $hppUrl = '';

    /**
     * @var string|null
     */
    public ?string $identificationForm = null;

    /**
     * @var bool
     */
    public bool $isLoading = true;

    /**
     * @var string|null
     */
    public ?string $errorMessage = null;

    protected CheckoutSession $checkoutSession;
    protected StoreManagerInterface $storeManager;
    protected UrlInterface $urlBuilder;
    protected CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory;

    public function __construct(
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->createOrderRequestBuilderFactory = $createOrderRequestBuilderFactory;
    }

    public function mount(): void
    {
        $this->loadConfiguration();
        $this->loadPaymentMethods();
    }

    protected function loadConfiguration(): void
    {
        try {
            $storeId = (string) $this->storeManager->getStore()->getId();
            $generalSettingsResponse = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();

            if ($generalSettingsResponse->isSuccessful()) {
                $settings = $generalSettingsResponse->toArray();
                $this->showSeQuraCheckoutAsHostedPage = $settings['showSeQuraCheckoutAsHostedPage'] ?? false;
            }

            $this->hppUrl = $this->urlBuilder->getUrl('sequra/hpp');
        } catch (\Exception $e) {
            $this->errorMessage = __('Failed to load Sequra configuration.')->render();
        }
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

            // Create order request builder
            $builder = $this->createOrderRequestBuilderFactory->create([
                'cartId' => $quote->getId(),
                'storeId' => $storeId,
            ]);

            // Check general settings
            $generalSettings = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings();
            if (!$generalSettings->isSuccessful() || !$builder->isAllowedFor($generalSettings)) {
                $this->paymentMethods = [];
                $this->isLoading = false;
                return;
            }

            // Get payment methods from Sequra API
            $response = CheckoutAPI::get()
                ->solicitation($storeId)
                ->solicitFor($builder);

            if (!$response->isSuccessful()) {
                $this->paymentMethods = [];
                $this->isLoading = false;
                return;
            }

            $this->paymentMethods = $response->toArray()['availablePaymentMethods'] ?? [];

            // Auto-select first product if available
            if (!empty($this->paymentMethods) && $this->selectedProduct === null) {
                $this->selectedProduct = $this->paymentMethods[0]['product'] ?? null;
                $this->selectedCampaign = $this->paymentMethods[0]['campaign'] ?? null;
            }
        } catch (\Exception $e) {
            $this->errorMessage = __('Failed to load Sequra payment methods.')->render();
            $this->paymentMethods = [];
        }

        $this->isLoading = false;
    }

    public function selectProduct(string $product, string $campaign = ''): void
    {
        $this->selectedProduct = $product;
        $this->selectedCampaign = $campaign;
    }

    public function getMethodCode(): string
    {
        return self::METHOD_CODE;
    }

    public function getSelectedAdditionalData(): array
    {
        return [
            'sequra_product' => $this->selectedProduct,
            'sequra_campaign' => $this->selectedCampaign ?? '',
        ];
    }

    public function placeOrder(): void
    {
        if (empty($this->selectedProduct)) {
            $this->errorMessage = __('Please select a payment method.')->render();
            return;
        }

        try {
            if ($this->showSeQuraCheckoutAsHostedPage) {
                // Redirect to Hosted Payment Page
                $hppUrl = $this->hppUrl;
                $hppUrl .= (strpos($hppUrl, '?') === false ? '?' : '&');
                $hppUrl .= http_build_query([
                    'sequra_product' => $this->selectedProduct,
                    'sequra_campaign' => $this->selectedCampaign ?? '',
                ]);

                $this->dispatchBrowserEvent('sequra:redirect', ['url' => $hppUrl]);
                return;
            }

            // Fetch identification form for inline mode
            $quote = $this->checkoutSession->getQuote();
            $storeId = (string) $quote->getStore()->getId();

            $formResponse = CheckoutAPI::get()
                ->solicitation($storeId)
                ->getIdentificationForm(
                    $quote->getId(),
                    $this->selectedProduct,
                    $this->selectedCampaign
                );

            if (!$formResponse->isSuccessful()) {
                $this->errorMessage = __('Failed to initialize payment form.')->render();
                return;
            }

            $form = $formResponse->getIdentificationForm()->getForm();
            $this->dispatchBrowserEvent('sequra:showForm', ['form' => $form]);
        } catch (\Exception $e) {
            $this->errorMessage = __('An error occurred while placing your order.')->render();
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
}
