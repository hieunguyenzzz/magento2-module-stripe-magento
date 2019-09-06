<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model;
use StripeIntegration\Payments\Model\PaymentMethod;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;

class Api
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        LoggerInterface $logger,
        Generic $helper,
        \StripeIntegration\Payments\Model\StripeCustomer $customer,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Model\Rollback $rollback,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        $this->logger = $logger;
        $this->helper = $helper;
        $this->config = $config;
        $this->_stripeCustomer = $customer;
        $this->_eventManager = $eventManager;
        $this->rollback = $rollback;
        $this->paymentIntent = $paymentIntent;
        $this->quoteFactory = $quoteFactory;
    }

    public function retrieveCharge($token)
    {
        if (empty($token))
            return null;

        if (strpos($token, 'pi_') === 0)
        {
            $pi = \Stripe\PaymentIntent::retrieve($token);

            if (empty($pi->charges->data[0]))
                return null;

            return $pi->charges->data[0];
        }
        else if (strpos($token, 'in_') === 0)
        {
            // Subscriptions save the invoice number instead
            $in = \Stripe\Invoice::retrieve(['id' => $token, 'expand' => ['charge']]);

            return $in->charge;
        }

        return \Stripe\Charge::retrieve($token);
    }

    public function validateParams($params)
    {
        if (is_array($params) && isset($params['card']) && is_array($params['card']) && empty($params['card']['number']))
            throw new \Exception("Unable to use Stripe.js, please see https://stripe.com/docs/magento/troubleshooting#stripejs");
    }

    public function getStripeParamsFrom($order)
    {
        return $this->config->getStripeParamsFrom($order);
    }

    public function getPaymentDetailsFrom($payment)
    {
        if (empty($payment))
            return null;

        $method = $payment->getMethod();
        if (strpos($method, "stripe_") !== 0)
            return null;

        $token = $payment->getAdditionalInformation('token');

        if (empty($token))
            $sourceId = $payment->getAdditionalInformation('stripejs_token');

        if (empty($token))
            $sourceId = $payment->getAdditionalInformation('source_id');

        if (empty($token))
            return null;

        try
        {
            // Used by Bancontact, iDEAL etc
            if (strpos($sourceId, "src_") === 0)
                $object = \Stripe\Source::retrieve($sourceId);
            // Used by card payments
            else if (strpos($sourceId, "pm_") === 0)
                $object = \Stripe\PaymentMethod::retrieve($sourceId);
            else
                return null;

            if (empty($object->customer))
                return null;

            $stripeId = $object->customer;
        }
        catch (\Exception $e)
        {
            return null;
        }

        return ['customer' => $stripeId, 'token' => $token];
    }

    public function createCharge($payment, $amount, $capture, $useSavedCard = false)
    {
        try
        {
            $order = $payment->getOrder();
            $data = $this->getPaymentDetailsFrom($payment);

            $switchSubscription = $payment->getAdditionalInformation('switch_subscription');

            if ($switchSubscription)
            {
                $this->_eventManager->dispatch('stripe_subscriptions_switch_subscription', array(
                    'payment' => $payment,
                    'order' => $order,
                    'switchSubscription' => $switchSubscription
                ));
                return;
            }
            else if ($useSavedCard) // We are coming here from the admin, capturing an expired authorization
            {
                $customer = $this->_stripeCustomer->loadFromPayment($payment);
                $token = $data['token'];
                $this->customerStripeId = $data['customer'];

                if (!$token || !$this->customerStripeId)
                {
                    // The exception will be caught and silenced, so we explicitly add an error too
                    $this->helper->addError("The authorization has expired and the customer has no saved cards to re-create the order");
                    throw new LocalizedException(__("The authorization has expired and the customer has no saved cards to re-create the order."));
                }
            }
            else
            {
                $token = $payment->getAdditionalInformation('token');

                if ($this->helper->hasSubscriptions())
                {
                    // Ensure that a customer exists in Stripe (may be the case with Guest checkouts)
                    if (!$this->_stripeCustomer->getStripeId())
                    {
                        try
                        {
                            $this->_stripeCustomer->createStripeCustomer($order);
                        }
                        catch (\StripeIntegration\Payments\Exception\SilentException $e)
                        {
                            return;
                        }
                    }
                }
            }

            $params = $this->getStripeParamsFrom($order);

            $params["source"] = $token;
            $params["capture"] = $capture;

            // If this is a 3D Secure charge, pass the customer id
            if ($payment->getAdditionalInformation('customer_stripe_id'))
            {
                $params["customer"] = $payment->getAdditionalInformation('customer_stripe_id');
            }
            else if ($this->_stripeCustomer->getStripeId())
            {
                $params["customer"] = $data['customer'];
                $payment->setAdditionalInformation('customer_stripe_id', $data['customer']);
            }

            $this->validateParams($params);

            $fraud = false;

            $statementDescriptor = $this->config->getStatementDescriptor();
            if (!empty($statementDescriptor))
                $params["statement_descriptor"] = $statementDescriptor;

            if ($params["amount"] > 0)
            {
                if (strpos($token, "pm_") === 0)
                {
                    $quoteId = $payment->getOrder()->getQuoteId();

                    if ($useSavedCard)
                    {
                        // We get here if an existing authorization has expired, in which case
                        // we want to discard old Payment Intents and create a new one
                        $this->paymentIntent->refreshCache($quoteId);
                        $this->paymentIntent->destroy($quoteId, true);
                    }

                    $quote = $this->quoteFactory->create()->load($quoteId);
                    $this->paymentIntent->quote = $quote;

                    // This in theory should always be true
                    if ($capture)
                        $this->paymentIntent->capture = \StripeIntegration\Payments\Model\PaymentIntent::CAPTURE_METHOD_AUTOMATIC;
                    else
                        $this->paymentIntent->capture = \StripeIntegration\Payments\Model\PaymentIntent::CAPTURE_METHOD_MANUAL;

                    if (!$this->paymentIntent->create())
                        throw new \Exception("The payment intent could not be created");

                    $this->paymentIntent->setPaymentMethod($token);
                    $pi = $this->paymentIntent->confirmAndAssociateWithOrder($payment->getOrder());
                    if (!$pi)
                        throw new \Exception("Could not create a Payment Intent for this order");

                    $charge = $this->retrieveCharge($pi->id);
                }
                else
                    $charge = \Stripe\Charge::create($params);

                $this->rollback->addCharge($charge);

                if ($this->config->isStripeRadarEnabled() &&
                    isset($charge->outcome->type) &&
                    $charge->outcome->type == 'manual_review')
                {
                    $payment->setAdditionalInformation("stripe_outcome_type", $charge->outcome->type);
                }

                if (!$charge->captured && $this->config->isAutomaticInvoicingEnabled())
                {
                    $payment->setIsTransactionPending(true);
                    $invoice = $order->prepareInvoice();
                    $invoice->register();
                    $order->addRelatedObject($invoice);
                }

                $payment->setTransactionId($charge->id);
                $payment->setLastTransId($charge->id);
            }

            $payment->setIsTransactionClosed(0);
            $payment->setIsFraudDetected($fraud);
        }
        catch (\Stripe\Error\Card $e)
        {
            $this->rollback->run($e->getMessage(), $e);
        }
        catch (\Stripe\Error $e)
        {
            $this->rollback->run($e->getMessage(), $e);
        }
        catch (\Exception $e)
        {
            if ($this->helper->isAdmin())
                $this->rollback->run($e->getMessage(), $e);
            else
                $this->rollback->run(null, $e);
        }
    }
}
