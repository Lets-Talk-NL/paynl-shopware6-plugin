<?php declare(strict_types=1);

namespace PaynlPayment\Components;

use Paynl\Config as SDKConfig;
use Paynl\Paymentmethods;
use Paynl\Result\Transaction\Start;
use Paynl\Transaction;
use Paynl\Result\Transaction\Transaction as ResultTransaction;
use PaynlPayment\Exceptions\PaynlPaymentException;
use PaynlPayment\Helper\CustomerHelper;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class Api
{
    const PAYMENT_METHOD_ID = 'id';
    const PAYMENT_METHOD_NAME = 'name';
    const PAYMENT_METHOD_VISIBLE_NAME = 'visibleName';

    const ACTION_PENDING = 'pending';

    /** @var Config */
    private $config;
    /** @var CustomerHelper */
    private $customerHelper;

    public function __construct(Config $config, CustomerHelper $customerHelper)
    {
        $this->config = $config;
        $this->customerHelper = $customerHelper;
    }

    /**
     * @return mixed[]
     */
    public function getPaymentMethods(): array
    {
        // plugin doesn't configured, nothing to do
        if (empty($this->config->getTokenCode())
            || empty($this->config->getApiToken())
            || empty($this->config->getServiceId())) {
            return [];
        }

        $this->setCredentials();

        return Paymentmethods::getList();
    }

    private function setCredentials(): void
    {
        SDKConfig::setTokenCode($this->config->getTokenCode());
        SDKConfig::setApiToken($this->config->getApiToken());
        SDKConfig::setServiceId($this->config->getServiceId());
    }

    public function startTransaction(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        string $exchangeUrl
    ): Start {
        $transactionInitialData = $this->getTransactionInitialData($transaction, $salesChannelContext, $exchangeUrl);
        $this->setCredentials();

        return Transaction::start($transactionInitialData);
    }

    public function getTransaction(string $transactionId): ResultTransaction
    {
        $this->setCredentials();

        return Transaction::get($transactionId);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @param string $exchangeUrl
     * @return mixed[]
     * @throws PaynlPaymentException
     */
    private function getTransactionInitialData(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        string $exchangeUrl
    ): array {
        $shopwarePaymentMethodId = $salesChannelContext->getPaymentMethod()->getId();
        $paynlPaymentMethodId = $this->getPaynlPaymentMethodId($shopwarePaymentMethodId);
        $amount = $transaction->getOrder()->getAmountTotal();
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        // TODO: implement payment id increment
        $paymentId = time();
        $extra1 = $transaction->getOrder()->getId();
        $testMode = $this->config->getTestMode();
        $returnUrl = $transaction->getReturnUrl();
        $transactionInitialData = [
            // Basic data
            'paymentMethod' => $paynlPaymentMethodId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $paymentId,
            'orderNumber' => $paymentId,
            'extra1' => $extra1,
            'testmode' => $testMode,

            // Urls
            'returnUrl' => $returnUrl,
            'exchangeUrl' => $exchangeUrl,

            // Products
            // TODO: store 'products'
        ];

        $customer = $salesChannelContext->getCustomer();
        if ($customer instanceof CustomerEntity) {
            $addresses = $this->customerHelper->formatAddresses($customer);
            $transactionInitialData = array_merge($transactionInitialData, $addresses);
        }

        return $transactionInitialData;
    }

    public function getPaynlPaymentMethodId(string $shopwarePaymentMethodId): int
    {
        $paynlPaymentMethod = $this->findPaynlPaymentMethod($shopwarePaymentMethodId);
        if (is_null($paynlPaymentMethod)) {
            throw new PaynlPaymentException('Could not detect payment method.');
        }
        if (empty($paynlPaymentMethod) || !is_array($paynlPaymentMethod)) {
            throw new PaynlPaymentException('Wrong payment method data.');
        }

        return (int)$paynlPaymentMethod[self::PAYMENT_METHOD_ID];
    }

    /**
     * @param string $shopwarePaymentMethodId
     * @return mixed[]|null
     */
    private function findPaynlPaymentMethod(string $shopwarePaymentMethodId): ?array
    {
        $paymentMethods = $this->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            if ($shopwarePaymentMethodId === md5($paymentMethod[self::PAYMENT_METHOD_ID])) {
                return $paymentMethod;
            }
        }

        return null;
    }
}
