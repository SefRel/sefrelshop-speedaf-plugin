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
         * Check for an existing Speedaf shipment.
         */
        $existingBillCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (!empty($existingBillCode)) {

            return [
                'success'   => true,
                'duplicate' => true,
                'provider'  => 'Speedaf',
                'billCode'  => $existingBillCode,
                'message'   => 'Speedaf shipment already exists.',
                'shipment'  => $shipment
            ];
        }

        /**
         * Step 3:
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
         * Step 4:
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
         * Step 11:
         * Save Speedaf shipment information.
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
         * Step 13:
         * Return successful result.
         */
        return [
            'success'         => true,
            'duplicate'       => false,
            'provider'        => $provider->getName(),
            'billCode'        => $billCode,
            'customerOrderNo' => $customerOrderNo,
            'shipment'        => $shipment
        ];
    }


    /**
     * Handle logistics failure safely.
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
                sanitize_text_field($errorCode)
            );

            $order->update_meta_data(
                '_speedaf_error_message',
                sanitize_textarea_field($errorMessage)
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
            'success'    => false,
            'status'     => 'action_required',
            'error_code' => $errorCode,
            'message'    => $errorMessage,
            'result'     => $result
        ];
    }


    /**
     * Notify store administrator about
     * a logistics failure.
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

        $customerName = $order->get_formatted_billing_full_name();

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