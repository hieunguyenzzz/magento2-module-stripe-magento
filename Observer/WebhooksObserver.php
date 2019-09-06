<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class WebhooksObserver implements ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $dbTransaction,
        \StripeIntegration\Payments\Model\StripeCustomer $stripeCustomer,
        \Magento\Framework\Event\ManagerInterface $eventManager
    )
    {
        $this->webhooksHelper = $webhooksHelper;
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->orderCommentSender = $orderCommentSender;
        $this->_stripeCustomer = $stripeCustomer;
        $this->_eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
        $this->dbTransaction = $dbTransaction;
    }

    protected function orderAgeLessThan($minutes, $order)
    {
        $created = strtotime($order->getCreatedAt());
        $now = time();
        return (($now - $created) < ($minutes * 60));
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        $arrEvent = $observer->getData('arrEvent');
        $stdEvent = $observer->getData('stdEvent');
        $object = $observer->getData('object');

        $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

        switch ($eventName)
        {
            case 'stripe_payments_webhook_charge_captured':

                if (empty($arrEvent['data']['object']['payment_intent']))
                    return;

                $paymentIntentId = $arrEvent['data']['object']['payment_intent'];

                $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;
                $params = [
                    "amount" => $arrEvent['data']['object']['amount'],
                    "currency" => $arrEvent['data']['object']['currency']
                ];

                $this->paymentsHelper->invoiceOrder($order, $paymentIntentId, $captureCase, $params);

                // $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                //     ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING)
                //     ->save();

                break;

            case 'stripe_payments_webhook_charge_refunded_card':

                $this->webhooksHelper->refund($order, $object);
                break;

            case 'stripe_payments_webhook_payment_intent_succeeded_fpx':

                $paymentIntentId = $arrEvent['data']['object']['id'];
                $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;
                $params = [
                    "amount" => $arrEvent['data']['object']['amount_received'],
                    "currency" => $arrEvent['data']['object']['currency']
                ];

                $invoice = $this->paymentsHelper->invoiceOrder($order, $paymentIntentId, $captureCase, $params);

                $payment = $order->getPayment();
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                $payment->setLastTransId($paymentIntentId);
                $payment->setTransactionId($paymentIntentId);
                $transaction = $payment->addTransaction($transactionType, $invoice, true);
                $transaction->save();

                $comment = __("Payment succeeded.");
                $order->addStatusToHistory($status = \Magento\Sales\Model\Order::STATE_PROCESSING, $comment, $isCustomerNotified = false)
                    ->save();

                break;

            case 'stripe_payments_webhook_payment_intent_payment_failed_fpx':

                $this->paymentsHelper->cancelOrCloseOrder($order);
                $this->addOrderCommentWithEmail($order, "Your order has been canceled because the payment authorization failed.");
                break;

            case 'stripe_payments_webhook_source_chargeable_bancontact':
            case 'stripe_payments_webhook_source_chargeable_giropay':
            case 'stripe_payments_webhook_source_chargeable_ideal':
            case 'stripe_payments_webhook_source_chargeable_sepa_debit':
            case 'stripe_payments_webhook_source_chargeable_sofort':
            case 'stripe_payments_webhook_source_chargeable_multibanco':
            case 'stripe_payments_webhook_source_chargeable_eps':
            case 'stripe_payments_webhook_source_chargeable_przelewy':
            case 'stripe_payments_webhook_source_chargeable_alipay':
            case 'stripe_payments_webhook_source_chargeable_wechat':

                $this->webhooksHelper->charge($order, $arrEvent['data']['object']);
                break;

            case 'stripe_payments_webhook_source_canceled_bancontact':
            case 'stripe_payments_webhook_source_canceled_giropay':
            case 'stripe_payments_webhook_source_canceled_ideal':
            case 'stripe_payments_webhook_source_canceled_sepa_debit':
            case 'stripe_payments_webhook_source_canceled_sofort':
            case 'stripe_payments_webhook_source_canceled_multibanco':
            case 'stripe_payments_webhook_source_canceled_eps':
            case 'stripe_payments_webhook_source_canceled_przelewy':
            case 'stripe_payments_webhook_source_canceled_alipay':
            case 'stripe_payments_webhook_source_canceled_wechat':

                $cancelled = $this->paymentsHelper->cancelOrCloseOrder($order);
                if ($cancelled)
                    $this->addOrderCommentWithEmail($order, "Sorry, your order has been canceled because a payment request was sent to your bank, but we did not receive a response back. Please contact us or place your order again.");
                break;

            case 'stripe_payments_webhook_source_failed_bancontact':
            case 'stripe_payments_webhook_source_failed_giropay':
            case 'stripe_payments_webhook_source_failed_ideal':
            case 'stripe_payments_webhook_source_failed_sepa_debit':
            case 'stripe_payments_webhook_source_failed_sofort':
            case 'stripe_payments_webhook_source_failed_multibanco':
            case 'stripe_payments_webhook_source_failed_eps':
            case 'stripe_payments_webhook_source_failed_przelewy':
            case 'stripe_payments_webhook_source_failed_alipay':
            case 'stripe_payments_webhook_source_failed_wechat':

                $this->paymentsHelper->cancelOrCloseOrder($order);
                $this->addOrderCommentWithEmail($order, "Your order has been canceled because the payment authorization failed.");
                break;

            case 'stripe_payments_webhook_charge_succeeded_bancontact':
            case 'stripe_payments_webhook_charge_succeeded_giropay':
            case 'stripe_payments_webhook_charge_succeeded_ideal':
            case 'stripe_payments_webhook_charge_succeeded_sepa_debit':
            case 'stripe_payments_webhook_charge_succeeded_sofort':
            case 'stripe_payments_webhook_charge_succeeded_multibanco':
            case 'stripe_payments_webhook_charge_succeeded_eps':
            case 'stripe_payments_webhook_charge_succeeded_przelewy':
            case 'stripe_payments_webhook_charge_succeeded_alipay':
            case 'stripe_payments_webhook_charge_succeeded_wechat':

                $invoiceCollection = $order->getInvoiceCollection();

                foreach ($invoiceCollection as $invoice)
                {
                    if ($invoice->getState() != \Magento\Sales\Model\Order\Invoice::STATE_PAID)
                        $invoice->pay()->save();
                }

                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                    ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING)
                    ->save();
                break;

            case 'stripe_payments_webhook_charge_failed_bancontact':
            case 'stripe_payments_webhook_charge_failed_giropay':
            case 'stripe_payments_webhook_charge_failed_ideal':
            case 'stripe_payments_webhook_charge_failed_sepa_debit':
            case 'stripe_payments_webhook_charge_failed_sofort':
            case 'stripe_payments_webhook_charge_failed_multibanco':
            case 'stripe_payments_webhook_charge_failed_eps':
            case 'stripe_payments_webhook_charge_failed_przelewy':
            case 'stripe_payments_webhook_charge_failed_alipay':
            case 'stripe_payments_webhook_charge_failed_wechat':

                $this->paymentsHelper->cancelOrCloseOrder($order);
                $this->addOrderCommentWithEmail($order, "Your order has been canceled. The payment authorization succeeded, however the authorizing provider declined the payment when a charge was attempted.");
                break;

            // Recurring subscription payments
            case 'stripe_payments_webhook_invoice_payment_succeeded':

                $invoiceId = $stdEvent->data->object->id;
                $initialSubscriptionInvoiceId = $order->getPayment()->getAdditionalInformation("initial_subscription_invoice");
                if (!empty($initialSubscriptionInvoiceId) && $invoiceId == $initialSubscriptionInvoiceId)
                {
                    Logger::log("Received the initial subscription invoice webhook for order #" . $order->getIncrementId());

                    $this->paymentSucceeded($stdEvent);
                    // $this->paymentsHelper->invoiceOrder($order, $invoiceId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, $stdEvent->data->object->amount_paid);
                }
                else
                    $this->paymentSucceeded($stdEvent);

                break;
            case 'stripe_payments_webhook_invoice_payment_failed':
                //$this->paymentFailed($event);
                break;

            default:
                # code...
                break;
        }
    }

    public function addOrderCommentWithEmail($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
        $this->orderCommentSender->send($order, $notify = true, $comment);
        $order->save();
    }


    private function getSubscriptionID($event)
    {
        if (empty($event->type))
            throw new \Exception("Invalid event data");

        switch ($event->type)
        {
            case 'invoice.payment_succeeded':
            case 'invoice.payment_failed':
                if (!empty($event->data->object->subscription))
                    return $event->data->object->subscription;

                foreach ($event->data->object->lines->data as $data)
                {
                    if ($data->type == "subscription")
                        return $data->id;
                }

                return null;

            case 'customer.subscription.deleted':
                if (!empty($event->data->object->id))
                    return $event->data->object->id;
                break;

            default:
                return null;
        }
    }

    public function paymentSucceeded($event)
    {
        $subscriptionId = $this->getSubscriptionID($event);

        if (!isset($subscriptionId))
            throw new WebhookException(__("Received {$event->type} webhook but could not read the subscription object."));

        $subscription = \Stripe\Subscription::retrieve($subscriptionId);

        $metadata = $subscription->metadata;

        if (!empty($metadata->{'Order #'}))
            $orderId = $metadata->{'Order #'};
        else
            throw new WebhookException(__("The webhook request has no Order ID in its metadata - ignoring."));

        if (!empty($metadata->{'Product ID'}))
            $productId = $metadata->{'Product ID'};
        else
            throw new WebhookException(__("The webhook request has no product ID in its metadata - ignoring."));

        $transactionId = $event->data->object->id; // Will be an invoice ID in_xxxx
        $currency = strtoupper($event->data->object->currency);
        $orderItemId = false;
        $markAsPaid = true;

        if (isset($event->data->object->amount_paid))
            $amountPaid = $event->data->object->amount_paid;
        else if (isset($event->data->object->total))
            $amountPaid = $event->data->object->total;
        else
            $amountPaid = $subscription->amount;

        if ($amountPaid <= 0)
            return;

        $cents = 100;
        $decimals = 2;
        if ($this->paymentsHelper->isZeroDecimal($currency))
        {
            $cents = 1;
            $decimals = 0;
        }

        $amountPaid = round($amountPaid / $cents, $decimals);

        $order = $this->paymentsHelper->loadOrderByIncrementId($orderId);

        $items = $order->getAllItems();

        foreach ($items as $item)
        {
            if ($item->getProductId() == $productId)
            {
                // Is this a configurable product?
                if ($item->getRowTotalInclTax() == 0 && $item->getParentItem())
                    $item = $item->getParentItem();

                $item->setQtyInvoiced(0);
                $item->setQtyCanceled(0);

                $orderItemId = $item->getId();
                $orderItemQty = $item->getQtyOrdered();
                $taxAmount = $item->getTaxAmount();
                $baseTaxAmount = $item->getBaseTaxAmount();
                $grandTotal = $item->getRowTotalInclTax();
                $baseGrandTotal = $item->getBaseRowTotalInclTax();
                $subTotal = $item->getRowTotal();
                $baseSubtotal = $item->getBaseRowTotal();

                break;
            }
        }

        // Scenario where the merchant switched the customer to another subscription plan
        // In theory here's how to handle this scenario, but in practice, the invoice must be created first
        // We'd probably be better off if we created a new order with this product
        if (!$orderItemId)
        {
            // $item = $this->objectManager->create('Magento\Sales\Model\Order\Invoice\Item');
            // $orderItemQty = $subscription->quantity;
            // $taxAmount = 0;
            // $baseTaxAmount = 0;
            // $grandTotal = $amountPaid;
            // $baseGrandTotal = 0;
            // $subTotal = $amountPaid;
            // $baseSubtotal = 0;

            // $planAmount = round($subscription->plan->amount / $cents, $decimals);

            // $item->setName($subscription->plan->name);
            // $item->setQtyOrdered($orderItemQty);
            // $item->setPrice($planAmount);
            // $item->save();
            // $orderItemId = $item->getId();
            throw new WebhookException(__("Could not match the product ID $productId with an item on order #$orderId - ignoring."));
        }

        // $discount = $grandTotal - $amountPaid;
        // $baseDiscount = $discount * ($baseGrandTotal / $grandTotal);

        $itemsArray = array($orderItemId => $orderItemQty);

        $invoice = $this->invoiceService->prepareInvoice($order, $itemsArray);
        $invoice->setOrderCurrencyCode($currency);

        // There is only one order item per invoice
        foreach ($invoice->getAllItems() as $invoiceItem)
        {
            $invoiceItem->setRowTotal($subTotal);
            $invoiceItem->setBaseRowTotal($baseSubtotal);
            $invoiceItem->setSubtotal($grandTotal);
            $invoiceItem->setBaseSubtotal($baseGrandTotal);
            $invoiceItem->setTaxAmount($taxAmount);
            $invoiceItem->setBaseTaxAmount($baseTaxAmount);
            // $invoiceItem->setDiscountAmount($discount);
            // $invoiceItem->setBaseDiscountAmount($baseDiscount);
        }
        $invoice->setTaxAmount($taxAmount);
        $invoice->setBaseTaxAmount($baseTaxAmount);
        // $invoice->setDiscountTaxCompensationAmount()
        // $invoice->setBaseDiscountTaxCompensationAmount()
        // $invoice->setShippingTaxAmount();
        // $invoice->setBaseShippingTaxAmount();
        $invoice->setShippingAmount(0); // Shipping should be included in the subscription price
        $invoice->setBaseShippingAmount(0);
        // $invoice->setDiscountAmount($discount);
        // $invoice->setBaseDiscountAmount($baseDiscount);
        // $invoice->setBaseCost();
        $invoice->setSubtotal($subTotal);
        $invoice->setBaseSubtotal($baseSubtotal);

        $baseGrandTotal = round($amountPaid * $invoice->getBaseToOrderRate(), 2);
        $invoice->setBaseGrandTotal($baseGrandTotal);

        $invoice->setGrandTotal($amountPaid);

        $invoice->setTransactionId($transactionId);
        if ($markAsPaid)
        {
            $invoice->setState($invoice::STATE_PAID);
        }
        else
        {
            $invoice->setState($invoice::STATE_OPEN);
            $invoice->setRequestedCaptureCase($invoice::CAPTURE_OFFLINE);
        }
        // Invoice notification to the customer
        $notifyCustomerByEmail = true;
        $visibleOnFront = true;

        $invoice->register();

        $transactionSave = $this->dbTransaction;
        $transactionSave->addObject($invoice);
        $transactionSave->addObject($invoice->getOrder());
        $transactionSave->save();

        $comment = $this->paymentsHelper->createInvoiceComment("Created invoice for order #$orderId based on subscription", $notifyCustomerByEmail, $visibleOnFront);
        $comment->setInvoice($invoice);
        $comment->setParentId($invoice->getId());
        $comment->save();

        $invoice->addComment($comment, $notifyCustomerByEmail, $visibleOnFront);

        $order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getId()))
            ->setIsCustomerNotified(true)
            ->save();

        $this->_eventManager->dispatch('stripe_payments_webhook_invoice_payment_succeeded_complete', array(
            'productId' => $productId,
            'order' => $order
        ));
    }
}
