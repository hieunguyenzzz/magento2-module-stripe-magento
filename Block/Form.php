<?php

namespace StripeIntegration\Payments\Block;

use StripeIntegration\Payments\Helper\Logger;

class Form extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'form/stripe_payments.phtml';

    public $config;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\StripeCustomer $stripeCustomer,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig, $data);
        $this->config = $config;
        $this->stripeCustomer = $stripeCustomer;
        $this->productMetadata = $productMetadata;
        $this->helper = $helper;
        $this->formKey = $formKey;
    }

    public function getFormKey()
    {
         return $this->formKey->getFormKey();
    }

    public function getCustomerCards()
    {
        return $this->stripeCustomer->getCustomerCards();
    }

    public function isSinglePaymentMethod()
    {
        return count($this->getParentBlock()->getMethods()) == 1;
    }

    public function hideIfNotBuggy()
    {
        // Issue: https://github.com/magento/magento2/issues/11380
        $version = $this->productMetadata->getVersion();

        if (version_compare($version, "2.2.0") >= 0 && $this->isSinglePaymentMethod())
            return "";
        else
            return 'style="display:none;';
    }

    public function isNewCustomer()
    {
        if ($this->helper->isAdmin() && !$this->helper->getCustomerId())
            return true;

        return false;
    }

    public function cardType($code)
    {
        return $this->helper->cardType($code);
    }
}
