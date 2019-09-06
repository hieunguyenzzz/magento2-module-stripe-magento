<?php
declare(strict_types = 1);
namespace StripeIntegration\Payments\Plugin\Cart;

use StripeIntegration\Payments\Helper\Logger;

class Before
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper
    ) {
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->paymentsHelper = $paymentsHelper;
    }

    /**
     * beforeAddProduct
     *
     * @param      $subject
     * @param      $productInfo
     * @param null $requestInfo
     *
     * @return array
     * @throws LocalizedException
     */
    public function beforeAddProduct($subject, $productInfo, $requestInfo = null)
    {
        if ($productInfo->getTypeId() == \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE)
        {
            $productId = $requestInfo['selected_configurable_option'];
            $product = $this->paymentsHelper->loadProductById($productId);
            $this->subscriptionsHelper->validateCartItems($product);
        }
        else
            $this->subscriptionsHelper->validateCartItems($productInfo);

        return [$productInfo, $requestInfo];
    }
}
