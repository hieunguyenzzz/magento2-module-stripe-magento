<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use StripeIntegration\Payments\Helper\Logger;

class OrderObserver extends AbstractDataAssignObserver
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Helper\Generic $helper
    )
    {
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->helper = $helper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $eventName = $observer->getEvent()->getName();
        $method = $order->getPayment()->getMethod();

        if ($method != 'stripe_payments')
            return;

        switch ($eventName)
        {
            case 'sales_order_place_after':
                $this->updateOrderState($observer);
                $this->invoiceSubscriptionOrders($observer);

                // Different to M1, this is unnecessary
                // $this->updateStripeCustomer()
                break;
        }
    }

    public function updateOrderState($observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();

        if ($payment->getAdditionalInformation('stripe_outcome_type') == "manual_review")
        {
            $order->setHoldBeforeState($order->getState());
            $order->setHoldBeforeStatus($order->getStatus());
            $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
                ->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_HOLDED));
            $comment = __("Order placed under manual review by Stripe Radar");
            $order->addStatusToHistory(false, $comment, false);
            $order->save();
        }

        if ($payment->getAdditionalInformation('authentication_pending'))
        {
            $comment = __("Customer 3D secure authentication is pending for this order.");
            $order->addStatusToHistory($status = \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment, $isCustomerNotified = false);
            $order->save();
        }
    }

    // This will not create the initial invoice, but it will ensure that the order cannot be manually invoiced from the admin area
    public function invoiceSubscriptionOrders($observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $invoiceId = $payment->getAdditionalInformation("initial_subscription_invoice");
        if (empty($invoiceId))
            return;

        $this->helper->invoiceOrder($order, $invoiceId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, null, false);
    }
}
