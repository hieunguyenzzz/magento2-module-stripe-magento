/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'StripeIntegration_Payments/js/view/payment/method-renderer/method'
    ],
    function (
        $,
        Component
    ) {
        'use strict';

        var iban = window.checkoutConfig.payment.stripe_payments_sepa.iban;
        var company = window.checkoutConfig.payment.stripe_payments_sepa.company;

        return Component.extend({
            defaults: {
                self: this,
                template: 'StripeIntegration_Payments/payment/sepa'
            },
            redirectAfterPlaceOrder: false,
            /**
             * @override
             */
            getData: function () {
                return {
                    'method': this.getCode(),
                    'additional_data': {
                        'iban': $('#' + this.getCode() + '_iban').val()
                    }
                };
            },

            /**
             * Get Iban
             * @returns string
             */
            getIban: function () {
                return iban;
            },

            /**
             * Get Company
             * @returns string
             */
            getCompany: function () {
                return company;
            }
        });
    }
);
