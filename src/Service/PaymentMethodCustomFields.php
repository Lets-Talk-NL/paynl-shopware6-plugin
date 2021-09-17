<?php

namespace PaynlPayment\Shopware6\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Storefront\Page\PageLoadedEvent;

class PaymentMethodCustomFields
{
    const PAYNL_PAYMENT_FIELD = 'paynl_payment';
    const DISPLAY_BANKS_FIELD = 'displayBanks';
    const IS_PAY_LATER_FIELD = 'isPayLater';
    const HAS_ADDITIONAL_INFO_INPUT_FIELD = 'hasAdditionalInfoInput';

    private $customFields;

    public function getCustomField(string $name)
    {
        return $this->customFields[$name] ?? null;
    }

    /**
     * @return mixed[]
     */
    public function getCustomFields()
    {
        return (array)$this->customFields;
    }

    private function setCustomField(string $name, $data): void
    {
        $this->customFields[$name] = $data;
    }

    public function generateCustomFields(PageLoadedEvent $event, PaymentMethodEntity $paymentMethod): void
    {
        $this->customFields = $paymentMethod->getTranslation('customFields');

        $isPaynlPaymentMethod = $this->getCustomField(self::PAYNL_PAYMENT_FIELD);
        if (!$isPaynlPaymentMethod) {
            return;
        }

        $pageData = $event->getPage()->getVars();
        $isBirthdayExists = $pageData['isBirthdayExists'] ?? true;
        $isPhoneNumberExists = $pageData['isPhoneNumberExists'] ?? true;

        $isPaymentDisplayBanks = $this->getCustomField(self::DISPLAY_BANKS_FIELD);
        $isPaymentPayLater = $this->getCustomField(self::IS_PAY_LATER_FIELD);
        $hasPaymentLaterInputs = $isPaymentPayLater && (($isBirthdayExists && $isPhoneNumberExists) === false);
        $selectedBank = $this->getPaymentMethodSelectedBank($paymentMethod, $event->getSalesChannelContext()->getCustomer());
        $hasAdditionalInfoInput = ($isPaymentDisplayBanks && is_null($selectedBank)) || $hasPaymentLaterInputs;

        $this->setCustomField(self::HAS_ADDITIONAL_INFO_INPUT_FIELD, $hasAdditionalInfoInput);
    }

    private function getPaymentMethodSelectedBank(PaymentMethodEntity $paymentMethod, CustomerEntity $customer): ?array
    {
        $paymentMethodCustomFields = $paymentMethod->getTranslation('customFields');
        if (!isset($paymentMethodCustomFields['displayBanks']) || $paymentMethodCustomFields['displayBanks'] == false) {
            return null;
        }

        $customerCustomFields = $customer->getCustomFields();
        if (!isset($customerCustomFields['paymentMethodsSelectedData'])) {
            return null;
        }

        $paymentMethodsSelectedData = $customerCustomFields['paymentMethodsSelectedData'];

        if (!isset($paymentMethodsSelectedData[$paymentMethod->getId()])) {
            return null;
        }

        $issuer = $paymentMethodsSelectedData[$paymentMethod->getId()]['issuer'] ?? null;

        if (is_null($issuer)) {
            return null;
        }

        $banks = $paymentMethodCustomFields['banks'];
        $selectedBank = array_filter($banks, function ($bank) use ($issuer) {
            return $bank['id'] == $issuer;
        });

        return current($selectedBank);
    }
}
