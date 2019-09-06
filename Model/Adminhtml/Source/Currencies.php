<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class Currencies
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('All Allowed Currencies')
            ],
            [
                'value' => 1,
                'label' => __('Specific Currencies')
            ],
        ];
    }
}
