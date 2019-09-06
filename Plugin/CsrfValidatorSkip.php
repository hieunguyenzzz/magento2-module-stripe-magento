<?php

namespace StripeIntegration\Payments\Plugin;

class CsrfValidatorSkip
{
    public function aroundValidate(
        $subject,
        \Closure $proceed,
        $request,
        $action
    ) {
        // echo $request->getModuleName(); die;
        if ($request->getModuleName() == 'stripe') {
            return; // Skip CSRF check
        }
        $proceed($request, $action); // Proceed Magento 2 core functionalities
    }
}
