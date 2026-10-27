<?php

declare(strict_types=1);

namespace Hyva\SequraCoreCheckout\Magewire\Checkout\Payment\Method;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Hyva\SequraCoreCheckout\Service\AvailablePaymentMethods;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use Sequra\Core\Model\Api\Builders\CreateOrderRequestBuilderFactory;
use Sequra\Core\Observer\DataAssignObserver;

class Sequra extends Component implements EvaluationInterface
{
    public const METHOD_CODE = 'sequra_payment';

    public array $paymentMethods = [];
    public ?string $selectedProduct = null;
    public ?string $selectedCampaign = null;
    /** "product|campaign" radio binding — wire:model works under Hyvä's CSP theme, inline action arguments do not. */
    public ?string $selection = null;
    public bool $isLoading = true;
    public ?string $errorMessage = null;
    /** SeQura answered and offers nothing for this cart (as opposed to "could not ask"). */
    public bool $unavailable = false;

    protected CheckoutSession $checkoutSession;
    protected CartRepositoryInterface $quoteRepository;
    protected CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory;
    protected LoggerInterface $logger;
    protected AvailablePaymentMethods $availablePaymentMethods;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        CreateOrderRequestBuilderFactory $createOrderRequestBuilderFactory,
        LoggerInterface $logger,
        ?AvailablePaymentMethods $availablePaymentMethods = null
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->createOrderRequestBuilderFactory = $createOrderRequestBuilderFactory;
        $this->logger = $logger;
        $this->availablePaymentMethods = $availablePaymentMethods
            ?: ObjectManager::getInstance()->get(AvailablePaymentMethods::class);
    }

    public function mount(): void
    {
        $this->loadPaymentMethods();
    }

    public function loadPaymentMethods(): void
    {
        $this->isLoading = true;
        $this->errorMessage = null;
        $this->unavailable = false;

        $methods = $this->availablePaymentMethods->get($this->checkoutSession->getQuote());
        if ($methods === null) {
            $this->clearMethods();
            return;
        }

        $this->paymentMethods = $methods;
        $this->unavailable = empty($methods);

        if (!empty($this->paymentMethods) && $this->selectedProduct === null) {
            $this->selectProduct(
                $this->paymentMethods[0]['product'] ?? '',
                $this->paymentMethods[0]['campaign'] ?? ''
            );
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

    /**
     * Only blocks when SeQura answered that it offers nothing. When it could not be asked, the
     * hosted page solicits again and decides, as before.
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if ($this->unavailable) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent('payment:method:error')
                ->withMessage((string) __('SeQura is not available for this order. Please choose another payment method.'));
        }

        return $resultFactory->createSuccess();
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
