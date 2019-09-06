<?php

namespace StripeIntegration\Payments\Block;

use StripeIntegration\Payments\Helper\Logger;

class Success extends \Magento\Checkout\Block\Onepage\Success
{
    /**
     * @var string
     */
    protected $_template = 'StripeIntegration_Payments::order/success.phtml';

    public function getGrandTotal()
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->_checkoutSession->getLastRealOrder();
        return $order->getGrandTotal();
    }

    public function isWechatPaymentMethod()
    {
        $order = $this->_checkoutSession->getLastRealOrder();
        return $order->getPayment()->getMethod() == "stripe_payments_wechat";
    }
}
