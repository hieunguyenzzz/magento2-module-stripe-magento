<?php
namespace StripeIntegration\Payments\Plugin\Order;

use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use StripeIntegration\Payments\Helper\Logger;

class AddInitialFeeToTotalsBlock
{
    public function __construct(
        \StripeIntegration\Payments\Helper\InitialFee $helper,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    )
    {
        $this->helper = $helper;
        $this->quoteFactory = $quoteFactory;
    }

    public function afterGetOrder(Totals $subject, Order $order)
    {
        if (empty($subject->getTotals()))
            return $order;

        if ($subject->getTotal('initial_fee') !== false)
            return $order;

        if ($this->isRecurringInvoice($subject, $order))
            return $order;

        $quote = $this->quoteFactory->create()->load($order->getQuoteId());

        $fee = $this->helper->getTotalInitialFeeForQuote($quote);
        if ($fee > 0)
        {
            $subject->addTotalBefore(new DataObject([
                'code' => 'initial_fee',
                'value' => $fee,
                'label' => __('Initial Fee')
            ]), TotalsInterface::KEY_GRAND_TOTAL);
        }

        return $order;
    }

    public function isRecurringInvoice($subject, $order)
    {
        if (stripos(get_class($subject), 'Order\Invoice\Totals\Interceptor') === false)
            return false;

        $currentInvoiceID = $subject->getInvoice()->getId();

        $invoices = $order->getInvoiceCollection();
        foreach ($invoices as $invoice)
        {
            if ($invoice->getId() == $currentInvoiceID)
                return false;
            else
                return true;
        }

        return false;
    }
}
