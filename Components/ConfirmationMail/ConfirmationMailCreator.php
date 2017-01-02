<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagBackendOrder\Components\ConfirmationMail;

use DateTime;
use sArticles;
use Shopware\Models\Article\Detail;
use Shopware\Models\Article\Repository;
use Shopware\Models\Attribute\Payment;
use Shopware\Models\Country\State;
use Shopware\Models\Customer\Customer;
use Shopware\Models\Dispatch\Dispatch;
use Shopware\Models\Order\Order;
use Shopware\Models\Shop\Locale;
use Shopware\Models\Shop\Shop;
use SwagBackendOrder\Components\PriceCalculation\TaxCalculation;
use SwagBackendOrder\Components\Translation\ShippingTranslator;
use SwagBackendOrder\Components\Translation\PaymentTranslator;

class ConfirmationMailCreator
{
    /**
     * @var TaxCalculation
     */
    private $taxCalculation;

    /**
     * @var PaymentTranslator
     */
    private $paymentTranslator;

    /**
     * @var ShippingTranslator
     */
    private $shippingTranslator;

    /**
     * @var ConfirmationMailRepository
     */
    private $confirmationMailRepository;

    /**
     * @var Repository
     */
    private $articleDetailRepository;

    /**
     * @var sArticles
     */
    private $sArticles;

    /**
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @var NumberFormatterWrapper
     */
    private $numberFormatterWrapper;

    /**
     * @param TaxCalculation $taxCalculation
     * @param PaymentTranslator $paymentTranslator
     * @param ShippingTranslator $shippingTranslator
     * @param ConfirmationMailRepository $confirmationMailRepository
     * @param Repository $articleDetailRepository
     * @param \Shopware_Components_Config $config
     * @param NumberFormatterWrapper $numberFormatterWrapper
     */
    public function __construct(
        TaxCalculation $taxCalculation,
        PaymentTranslator $paymentTranslator,
        ShippingTranslator $shippingTranslator,
        ConfirmationMailRepository $confirmationMailRepository,
        Repository $articleDetailRepository,
        \Shopware_Components_Config $config,
        NumberFormatterWrapper $numberFormatterWrapper
    ) {
        $this->taxCalculation = $taxCalculation;
        $this->paymentTranslator = $paymentTranslator;
        $this->shippingTranslator = $shippingTranslator;
        $this->confirmationMailRepository = $confirmationMailRepository;
        $this->articleDetailRepository = $articleDetailRepository;
        $this->config = $config;
        $this->numberFormatterWrapper = $numberFormatterWrapper;

        $this->sArticles = Shopware()->Modules()->Articles();
    }

    /**
     * @inheritdoc
     */
    public function prepareOrderDetailsConfirmationMailData(Order $orderModel, Locale $localeModel)
    {

        /** @var Customer $customerModel */
        $customerModel = $orderModel->getCustomer();

        /** @var DateTime $orderDateTime */
        $orderDateTime = $orderModel->getOrderTime();

        $this->numberFormatterWrapper->setLocale($localeModel->getLocale());

        $details = $this->confirmationMailRepository->getOrderDetailsByOrderId($orderModel->getId());

        foreach ($details as &$result) {
            /** @var Detail $articleDetailModel */
            $articleDetailModel = $this->articleDetailRepository->findOneBy(['number' => $result['articleordernumber']]);
            $result['articlename'] = $result['name'];

            $articleDetail = $this->confirmationMailRepository->getArticleDetailsByOrderNumber($result['articleordernumber']);

            $result = array_merge($result, $articleDetail);

            $result['additional_details'] = $this->sArticles->sGetProductByOrdernumber($articleDetailModel->getNumber());

            $result = $this->setPositionPrices($result, $orderModel->getLanguageSubShop()->getLocale());

            $result['image'] = $this->sArticles->sGetArticlePictures(
                $result['articleID'],
                true,
                $this->config->get('sTHUMBBASKET'),
                $articleDetailModel->getNumber()
            );

            $result = $this->getOrderDetailAttributes($result);

            $result['partnerID'] = '';
            $result['shippinginfo'] = 1;
            $result['esdarticle'] = null;
            $result['userID'] = $customerModel->getId();
            $result['currencyFactor'] = $orderModel->getCurrencyFactor();
            $result['datum'] = $orderDateTime->format('Y-m-d H:i:s');

            unset($result['name'], $result['articleordernumber']);
        }

        return $details;
    }

    /**
     * @param array $mailOrderPositions
     * @param Locale $localeModel
     * @return array
     */
    private function setPositionPrices(array $mailOrderPositions, Locale $localeModel)
    {
        $netPrice = $this->taxCalculation->getNetPrice($mailOrderPositions['price'], $mailOrderPositions['tax_rate']);
        $mailOrderPositions['netprice'] = $this->numberFormatterWrapper->format($netPrice);

        $amount = $mailOrderPositions['price'] * $mailOrderPositions['quantity'];
        $mailOrderPositions['amount'] = $this->numberFormatterWrapper->format($amount);

        $amountNet = $mailOrderPositions['netprice'] * $mailOrderPositions['quantity'];
        $mailOrderPositions['amountnet'] = $this->numberFormatterWrapper->format($amountNet);

        $mailOrderPositions['priceNumeric'] = $this->numberFormatterWrapper->format($mailOrderPositions['price']);

        $mailOrderPositions['price'] = $this->numberFormatterWrapper->format($mailOrderPositions['price']);
        return $mailOrderPositions;
    }

    /**
     * @param array $mailOrderPositions
     * @return array
     */
    private function getOrderDetailAttributes(array $mailOrderPositions)
    {
        $mailOrderPositions['ob_attr1'] = '';
        $mailOrderPositions['ob_attr2'] = '';
        $mailOrderPositions['ob_attr3'] = '';
        $mailOrderPositions['ob_attr4'] = '';
        $mailOrderPositions['ob_attr5'] = '';
        $mailOrderPositions['ob_attr6'] = '';
        return $mailOrderPositions;
    }

    /**
     * @inheritdoc
     */
    public function prepareOrderConfirmationMailData(Order $orderModel)
    {
        $result = [];
        /** @var DateTime $orderTime */
        $orderTime = $orderModel->getOrderTime();

        /** @var Shop $languageShopModel */
        $languageShopModel = $orderModel->getLanguageSubShop();

        /** @var Locale $languageLocaleModel */
        $languageLocaleModel = $languageShopModel->getLocale();

        /** @var Shop $languageShop */
        $shopModel = $orderModel->getShop();

        $shippingModel = $orderModel->getShipping();
        $billingModel = $orderModel->getBilling();
        $shippingStateModel = $shippingModel->getState();
        $billingStateModel = $billingModel->getState();
        $customerModel = $orderModel->getCustomer();

        $this->numberFormatterWrapper->setLocale($languageLocaleModel->getLocale());

        $orderAttributes = $this->confirmationMailRepository->getOrderAttributesByOrderId($orderModel->getId());
        $customer = $this->confirmationMailRepository->getCustomerByUserId($customerModel->getId());

        $result['sOrderNumber'] = $orderModel->getNumber();

        $result['sOrderDay'] = $orderTime->format('d.m.Y');
        $result['sOrderTime'] = $orderTime->format('H:i');

        $result['sLanguage'] = $languageShopModel->getId();
        $result['sSubShop'] = $shopModel->getId();
        $result['attributes'] = $orderAttributes;
        $result['additional']['user'] = $customer;

        $result = $this->setOrderCosts($orderModel, $result);
        $result = $this->setBillingAddress($orderModel, $result, $billingStateModel);
        $result = $this->setShippingAddress($orderModel, $result, $shippingStateModel);

        $result['sDispatch'] = $this->getTranslatedShipping($orderModel, $languageShopModel);
        $result['additional']['payment'] = $this->getTranslatedPayment($orderModel, $languageShopModel);

        $result['sPaymentTable'] = [];
        $result['sComment'] = '';
        $result['additional']['show_net'] = $orderModel->getNet();
        $result['additional']['charge_var'] = 1;

        return $result;
    }

    /**
     * @param Order $orderModel
     * @param array $orderMail
     * @return array
     */
    private function setOrderCosts(Order $orderModel, array $orderMail)
    {
        $orderMail['sCurrency'] = $orderModel->getCurrency();

        $formattedAmount = $this->numberFormatterWrapper->format($orderModel->getInvoiceAmount());
        $orderMail['sAmount'] = $formattedAmount . ' ' . $orderModel->getCurrency();

        $formattedAmountNet = $this->numberFormatterWrapper->format($orderModel->getInvoiceAmountNet());
        $orderMail['sAmountNet'] = $formattedAmountNet . ' ' . $orderModel->getCurrency();

        $formattedShippingCosts = $this->numberFormatterWrapper->format($orderModel->getInvoiceShipping());
        $orderMail['sShippingCosts'] = $formattedShippingCosts . ' ' . $orderModel->getCurrency();
        if ($orderModel->getNet()) {
            $formattedShippingCostsNet = $this->numberFormatterWrapper->format($orderModel->getInvoiceShippingNet());
            $orderMail['sShippingCosts'] = $formattedShippingCostsNet . ' ' . $orderModel->getCurrency();
        }
        return $orderMail;
    }

    /**
     * @param Order $orderModel
     * @param array $orderMail
     * @param State $billingStateModel
     * @return array
     */
    private function setBillingAddress(Order $orderModel, array $orderMail, $billingStateModel)
    {
        $billingAddress = $this->confirmationMailRepository->getBillingAddressByOrderId($orderModel->getId());
        $billingCountry = $this->confirmationMailRepository->getCountryByCountryId($billingAddress['countryID']);

        $orderMail['billingaddress'] = $billingAddress;
        $orderMail['additional']['country'] = $billingCountry;
        $orderMail['additional']['state'] = [];
        if ($billingStateModel) {
            $billingState = $this->confirmationMailRepository->getStateByStateId($billingStateModel->getId());
            $orderMail['additional']['state'] = $billingState;
        }
        return $orderMail;
    }

    /**
     * @param Order $orderModel
     * @param array $orderMail
     * @param State $shippingStateModel
     * @return array
     */
    private function setShippingAddress(Order $orderModel, array $orderMail, $shippingStateModel)
    {
        $shippingAddress = $this->confirmationMailRepository->getShippingAddressByOrderId($orderModel->getId());
        $shippingCountry = $this->confirmationMailRepository->getCountryByCountryId($shippingAddress['countryID']);

        $orderMail['shippingaddress'] = $shippingAddress;
        $orderMail['additional']['countryShipping'] = $shippingCountry;
        $orderMail['additional']['stateShipping'] = [];
        if ($shippingStateModel) {
            $shippingStateModel = $this->confirmationMailRepository->getStateByStateId($shippingStateModel->getId());
            $orderMail['additional']['stateShipping'] = $shippingStateModel;
        }
        return $orderMail;
    }

    /**
     * @param Order $orderModel
     * @param Shop $languageShopModel
     * @return array
     */
    private function getTranslatedShipping(Order $orderModel, Shop $languageShopModel)
    {
        /** @var Dispatch $dispatchModel */
        $dispatchModel = $orderModel->getDispatch();

        $dispatch = $this->confirmationMailRepository->getDispatchByDispatchId($dispatchModel->getId());
        return $this->shippingTranslator->translate($dispatch, $languageShopModel->getId());
    }

    /**
     * @param Order $orderModel
     * @param Shop $languageShop
     * @return array
     */
    private function getTranslatedPayment(Order $orderModel, Shop $languageShop)
    {
        /** @var Payment $paymentModel */
        $paymentModel = $orderModel->getPayment();

        $payment = $this->confirmationMailRepository->getPaymentmeanByPaymentmeanId($paymentModel->getId());
        return $this->paymentTranslator->translate($payment, $languageShop->getId());
    }
}