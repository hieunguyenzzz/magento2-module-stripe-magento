define(
    [
        'ko',
        'uiComponent',
        'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments'
    ],
    function (
        ko,
        Component,
        paymentMethod
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                // template: 'StripeIntegration_Payments/payment/apple_pay_top',
                stripePaymentsShowApplePaySection: false,
                stripePaymentsApplePayToken: null
            },

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'stripePaymentsStripeJsToken',
                        'stripePaymentsApplePayToken',
                        'stripePaymentsShowApplePaySection',
                        'isPaymentRequestAPISupported'
                    ]);

                this.securityMethod = this.config().securityMethod;

                var self = this;

                if (typeof onPaymentSupportedCallbacks == 'undefined')
                    window.onPaymentSupportedCallbacks = [];

                onPaymentSupportedCallbacks.push(function()
                {
                    self.isPaymentRequestAPISupported(true);
                    self.stripePaymentsShowApplePaySection(true);
                });

                if (typeof onTokenCreatedCallbacks == 'undefined')
                    window.onTokenCreatedCallbacks = [];

                onTokenCreatedCallbacks.push(function(token)
                {
                    self.stripePaymentsStripeJsToken(token.id + ':' + token.card.brand + ':' + token.card.last4);
                    self.setApplePayToken(token);
                });

                this.displayAtThisLocation = ko.computed(function()
                {
                    return paymentMethod.prototype.config().applePayLocation == 2 &&
                        paymentMethod.prototype.config().enabled;
                }, this);

                return this;
            },

            showApplePaySection: function()
            {
                return this.isPaymentRequestAPISupported;
            },

            setApplePayToken: function(token)
            {
                this.stripePaymentsApplePayToken(token);
            },

            resetApplePay: function()
            {
                this.stripePaymentsApplePayToken(null);
                this.stripePaymentsStripeJsToken(null);
            },

            showApplePayButton: function()
            {
                return !this.isPaymentRequestAPISupported;
            },

            config: function()
            {
                return paymentMethod.prototype.config();
            },

            beginApplePay: function()
            {
                paymentMethod.prototype.beginApplePay();
            }

        });
    }
);
