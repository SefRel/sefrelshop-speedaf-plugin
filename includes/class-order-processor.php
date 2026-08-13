<?php

class OrderProcessor
{
    private OrderBuilder $builder;

    private ShippingRouter $router;

    public function __construct(
        OrderBuilder $builder,
        ShippingRouter $router
    ) {
        $this->builder = $builder;
        $this->router = $router;
    }


    /**
     * Process a WooCommerce order.
     */
    public function process(array $data): array
    {
        /**
         * Step 1:
         * Build our standard shipment.
         */
        try {

            $shipment = $this->builder->build($data);

        } catch (Throwable $e) {

            return $this->handleFailure(
                $data,
                'shipment_build_failed',
                $e->getMessage()
            );
        }


        /**
         * Get WooCommerce order.
         */
        $orderId = !empty($shipment['order_id'])
            ? absint($shipment['order_id'])
            : 0;

        if (!$orderId) {

            return [
                'success' => false,
                'message' => 'WooCommerce order ID is missing.'
            ];
        }


        $order = wc_get_order($orderId);

        if (!$order) {

            return [
                'success' => false,
                'message' => 'WooCommerce order could not be loaded.'
            ];
        }


        /**
         * Step 2:
         * Select shipping provider.
         */
        try {

            $provider = $this->router->route($shipment);

        } catch (Throwable $e) {

            return $this->handleFailure(
                $data,
                'provider_routing_failed',
                $e->getMessage(),
                $order
            );
        }


        if (!$provider) {

            return $this->handleFailure(
                $data,
                'no_shipping_provider',
                'No shipping provider is available for this shipment.',
                $order
            );
        }


        /**
         * Step 3:
         * Verify provider capability.
         */
        if (!method_exists($provider, 'createOrder')) {

            return $this->handleFailure(
                $data,
                'provider_cannot_create_order',
                'Selected shipping provider cannot create orders.',
                $order
            );
        }


        /**
         * --------------------------------------------------------------
         * Step 4:
         * Check for an existing Speedaf shipment.
         *
         * If a shipment already exists, we do NOT create another one.
         *
         * However, if tracking subscription was never completed,
         * we will attempt the subscription again.
         * --------------------------------------------------------------
         */

        $existingBillCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );


        if (!empty($existingBillCode)) {

            /**
             * Check whether tracking has already been subscribed.
             */
            $trackingSubscribed = $order->get_meta(
                '_speedaf_tracking_subscribed',
                true
            );


            /**
             * Tracking already subscribed.
             *
             * Nothing else needs to be created.
             */
            if ($trackingSubscribed === 'yes') {

                return [
                    'success'   => true,
                    'duplicate' => true,
                    'provider'  => $provider->getName(),
                    'billCode'  => $existingBillCode,
                    'message'   => 'Speedaf shipment and tracking subscription already exist.',
                    'shipment'  => $shipment
                ];
            }


            /**
             * Shipment exists but tracking subscription
             * has not been completed.
             *
             * Retry subscription.
             */
            if (
                method_exists(
                    $provider,
                    'subscribeTracking'
                )
            ) {

                $subscriptionResult =
                    $this->subscribeTracking(
                        $order,
                        $provider,
                        $existingBillCode
                    );


                /**
                 * Return the existing shipment information.
                 *
                 * The shipment itself already exists,
                 * so we don't treat subscription failure
                 * as shipment creation failure.
                 */
                return [
                    'success'            => true,
                    'duplicate'          => true,
                    'provider'           => $provider->getName(),
                    'billCode'           => $existingBillCode,
                    'trackingSubscribed' =>
                        $subscriptionResult['success'],
                    'trackingSubscription' =>
                        $subscriptionResult,
                    'message' =>
                        $subscriptionResult['success']
                            ? 'Speedaf shipment already exists and tracking subscription is active.'
                            : 'Speedaf shipment already exists, but tracking subscription requires attention.',
                    'shipment' => $shipment
                ];
            }


            /**
             * Provider does not support tracking subscription.
             */
            return [
                'success'   => true,
                'duplicate' => true,
                'provider'  => $provider->getName(),
                'billCode'  => $existingBillCode,
                'message'   => 'Speedaf shipment already exists. Tracking subscription is not supported by this provider.',
                'shipment'  => $shipment
            ];
        }


        /**
         * Step 5:
         * Mark logistics as processing.
         */
        $order->update_meta_data(
            '_speedaf_status',
            'processing'
        );


        $order->delete_meta_data(
            '_speedaf_error_code'
        );


        $order->delete_meta_data(
            '_speedaf_error_message'
        );


        $order->save();


        /**
         * Step 6:
         * Create shipment with Speedaf.
         */
        try {

            $result = $provider->createOrder($shipment);

        } catch (Throwable $e) {

            return $this->handleFailure(
                $data,
                'speedaf_api_exception',
                $e->getMessage(),
                $order
            );
        }


        /**
         * Step 7:
         * Validate Speedaf API response.
         */
        if (
            !is_array($result) ||
            empty($result['success'])
        ) {

            $message = 'Speedaf rejected the shipment request.';

            if (
                is_array($result) &&
                !empty($result['error'])
            ) {

                $message .= ' ' . $result['error'];
            }


            return $this->handleFailure(
                $data,
                'speedaf_api_rejected',
                $message,
                $order,
                $result
            );
        }


        /**
         * Step 8:
         * Decode Speedaf response.
         */
        $decrypted = null;


        if (!empty($result['decrypted'])) {

            if (is_string($result['decrypted'])) {

                $decrypted = json_decode(
                    $result['decrypted'],
                    true
                );

            } elseif (is_array($result['decrypted'])) {

                $decrypted = $result['decrypted'];
            }
        }


        /**
         * Step 9:
         * Extract Speedaf identifiers.
         */
        $billCode = null;

        $customerOrderNo = null;


        if (is_array($decrypted)) {

            if (!empty($decrypted['billCode'])) {

                $billCode = sanitize_text_field(
                    $decrypted['billCode']
                );
            }


            if (!empty($decrypted['customerOrderNo'])) {

                $customerOrderNo = sanitize_text_field(
                    $decrypted['customerOrderNo']
                );
            }
        }


        /**
         * Step 10:
         * Successful HTTP response without
         * bill code is treated as failure.
         */
        if (empty($billCode)) {

            return $this->handleFailure(
                $data,
                'missing_bill_code',
                'Speedaf returned a successful response but no bill code was received.',
                $order,
                $result
            );
        }


        /**
         * --------------------------------------------------------------
         * Step 11:
         * Save Speedaf shipment information.
         * --------------------------------------------------------------
         */

        $order->update_meta_data(
            '_speedaf_bill_code',
            $billCode
        );


        if (!empty($customerOrderNo)) {

            $order->update_meta_data(
                '_speedaf_customer_order_no',
                $customerOrderNo
            );
        }


        $order->update_meta_data(
            '_speedaf_status',
            'created'
        );


        $order->update_meta_data(
            '_speedaf_created_at',
            current_time('mysql')
        );


        /**
         * Reset any previous tracking subscription state.
         */
        $order->delete_meta_data(
            '_speedaf_tracking_subscribed'
        );


        $order->delete_meta_data(
            '_speedaf_tracking_subscribed_at'
        );


        $order->delete_meta_data(
            '_speedaf_tracking_error'
        );


        $order->delete_meta_data(
            '_speedaf_error_code'
        );


        $order->delete_meta_data(
            '_speedaf_error_message'
        );


        $order->save();


        /**
         * Step 12:
         * Add WooCommerce order note.
         */
        $order->add_order_note(
            sprintf(
                'Speedaf shipment created successfully. Bill Code: %s',
                $billCode
            )
        );


        /**
         * --------------------------------------------------------------
         * Step 13:
         * Subscribe shipment to Speedaf tracking.
         *
         * IMPORTANT:
         * $billCode is the Speedaf waybill/mailNo.
         * We must NOT use the WooCommerce order number here.
         * --------------------------------------------------------------
         */

        $trackingSubscription = null;


        if (
            method_exists(
                $provider,
                'subscribeTracking'
            )
        ) {

            $trackingSubscription =
                $this->subscribeTracking(
                    $order,
                    $provider,
                    $billCode
                );

        } else {

            /**
             * Provider does not expose tracking subscription.
             */
            $trackingSubscription = [
                'success' => false,
                'status' => 'unsupported',
                'message' =>
                    'Shipping provider does not support tracking subscription.'
            ];
        }


        /**
         * --------------------------------------------------------------
         * Step 14:
         * Determine final result.
         *
         * Shipment creation succeeded regardless of whether
         * tracking subscription succeeded.
         * --------------------------------------------------------------
         */

        if (
            is_array($trackingSubscription) &&
            !empty($trackingSubscription['success'])
        ) {

            $order->update_meta_data(
                '_speedaf_status',
                'tracking_subscribed'
            );

            $order->save();


            $order->add_order_note(
                sprintf(
                    'Speedaf tracking subscription successful. Waybill: %s',
                    $billCode
                )
            );

        } else {

            /**
             * Shipment exists, but tracking subscription
             * needs attention.
             */
            $order->update_meta_data(
                '_speedaf_status',
                'tracking_subscription_failed'
            );


            $order->save();


            $order->add_order_note(
                sprintf(
                    'Speedaf shipment was created, but tracking subscription requires attention. Bill Code: %s',
                    $billCode
                )
            );
        }


        /**
         * Step 15:
         * Return complete result.
         */
        return [
            'success' =>
                true,

            'duplicate' =>
                false,

            'provider' =>
                $provider->getName(),

            'billCode' =>
                $billCode,

            'customerOrderNo' =>
                $customerOrderNo,

            'trackingSubscribed' =>
                (
                    is_array($trackingSubscription) &&
                    !empty($trackingSubscription['success'])
                ),

            'trackingSubscription' =>
                $trackingSubscription,

            'shipment' =>
                $shipment,

            'result' =>
                $result
        ];
    }


    /**
     * --------------------------------------------------------------
     * Subscribe a Speedaf shipment to tracking.
     * --------------------------------------------------------------
     */
    private function subscribeTracking(
        WC_Order $order,
        $provider,
        string $billCode
    ): array {

        try {

            $result = $provider->subscribeTracking(
                $billCode
            );

        } catch (Throwable $e) {

            $errorMessage =
                'Tracking subscription exception: '
                . $e->getMessage();


            $this->saveTrackingSubscriptionFailure(
                $order,
                'tracking_subscription_exception',
                $errorMessage
            );


            return [
                'success' => false,
                'status' => 'exception',
                'message' => $errorMessage,
                'result' => null
            ];
        }


        /**
         * Validate API response.
         */
        if (
            !is_array($result) ||
            empty($result['success'])
        ) {

            $errorMessage =
                'Speedaf tracking subscription failed.';


            if (
                is_array($result) &&
                !empty($result['error'])
            ) {

                $errorMessage .=
                    ' ' . $result['error'];
            }


            $this->saveTrackingSubscriptionFailure(
                $order,
                'tracking_subscription_failed',
                $errorMessage
            );


            return [
                'success' => false,
                'status' => 'failed',
                'message' => $errorMessage,
                'result' => $result
            ];
        }


        /**
         * Subscription succeeded.
         */
        $order->update_meta_data(
            '_speedaf_tracking_subscribed',
            'yes'
        );


        $order->update_meta_data(
            '_speedaf_tracking_subscribed_at',
            current_time('mysql')
        );


        $order->delete_meta_data(
            '_speedaf_tracking_error'
        );


        $order->save();


        return [
            'success' => true,
            'status' => 'subscribed',
            'message' =>
                'Speedaf tracking subscription successful.',
            'result' => $result
        ];
    }


    /**
     * Save tracking subscription failure.
     */
    private function saveTrackingSubscriptionFailure(
        WC_Order $order,
        string $errorCode,
        string $errorMessage
    ): void {

        $order->update_meta_data(
            '_speedaf_tracking_subscribed',
            'no'
        );


        $order->update_meta_data(
            '_speedaf_tracking_error',
            sanitize_textarea_field(
                $errorMessage
            )
        );


        $order->update_meta_data(
            '_speedaf_error_code',
            sanitize_text_field(
                $errorCode
            )
        );


        $order->update_meta_data(
            '_speedaf_error_message',
            sanitize_textarea_field(
                $errorMessage
            )
        );


        $order->update_meta_data(
            '_speedaf_last_attempt',
            current_time('mysql')
        );


        $order->save();


        /**
         * Add order note.
         */
        $order->add_order_note(
            sprintf(
                'Speedaf tracking subscription failed. [%s] %s',
                $errorCode,
                $errorMessage
            )
        );


        /**
         * Notify administrator.
         */
        $this->sendFailureNotification(
            $order,
            $errorCode,
            $errorMessage
        );
    }


    /**
     * --------------------------------------------------------------
     * Handle logistics failure safely.
     * --------------------------------------------------------------
     */
    private function handleFailure(
        array $data,
        string $errorCode,
        string $errorMessage,
        ?WC_Order $order = null,
        $result = null
    ): array {

        /**
         * If the order wasn't passed in,
         * try to recover it from the input.
         */
        if (!$order && !empty($data['wc_order'])) {

            if ($data['wc_order'] instanceof WC_Order) {

                $order = $data['wc_order'];
            }
        }


        /**
         * Save failure information.
         */
        if ($order) {

            $order->update_meta_data(
                '_speedaf_status',
                'action_required'
            );


            $order->update_meta_data(
                '_speedaf_error_code',
                sanitize_text_field(
                    $errorCode
                )
            );


            $order->update_meta_data(
                '_speedaf_error_message',
                sanitize_textarea_field(
                    $errorMessage
                )
            );


            $order->update_meta_data(
                '_speedaf_last_attempt',
                current_time('mysql')
            );


            $order->save();


            /**
             * Add order note.
             */
            $order->add_order_note(
                sprintf(
                    'Speedaf shipment requires attention. [%s] %s',
                    $errorCode,
                    $errorMessage
                )
            );


            /**
             * Notify store administrator.
             */
            $this->sendFailureNotification(
                $order,
                $errorCode,
                $errorMessage
            );
        }


        /**
         * Return controlled failure response.
         */
        return [
            'success' =>
                false,

            'status' =>
                'action_required',

            'error_code' =>
                $errorCode,

            'message' =>
                $errorMessage,

            'result' =>
                $result
        ];
    }


    /**
     * --------------------------------------------------------------
     * Notify store administrator about
     * a logistics failure.
     * --------------------------------------------------------------
     */
    private function sendFailureNotification(
        WC_Order $order,
        string $errorCode,
        string $errorMessage
    ): void {

        $adminEmail = get_option(
            'admin_email'
        );


        if (empty($adminEmail)) {

            return;
        }


        $orderId = $order->get_id();


        $customerName =
            $order->get_formatted_billing_full_name();


        $subject = sprintf(
            '[SefrelShop] Shipping Action Required — Order #%s',
            $orderId
        );


        $message = sprintf(
            "A SefrelShop order requires logistics attention.\n\n" .
            "Order: #%s\n" .
            "Customer: %s\n" .
            "Error Code: %s\n" .
            "Reason: %s\n\n" .
            "Please review the order and resolve the issue before shipment creation.",
            $orderId,
            $customerName ?: 'N/A',
            $errorCode,
            $errorMessage
        );


        wp_mail(
            $adminEmail,
            $subject,
            $message
        );
    }
}