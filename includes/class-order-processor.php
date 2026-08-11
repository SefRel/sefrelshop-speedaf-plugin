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

            return [
                'success' => false,
                'message' => 'Unable to build shipment.',
                'error'   => $e->getMessage()
            ];
        }

        /**
         * Make sure we have a WooCommerce order ID.
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

        /**
         * Load WooCommerce order.
         */
        $order = wc_get_order($orderId);

        if (!$order) {

            return [
                'success' => false,
                'message' => 'WooCommerce order could not be loaded.'
            ];
        }

        /**
         * Step 2:
         * Check whether this order has already
         * been submitted to Speedaf.
         *
         * This prevents duplicate shipments
         * when WooCommerce fires the processing
         * hook more than once.
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
         * Select the best shipping provider.
         */
        try {

            $provider = $this->router->route($shipment);

        } catch (Throwable $e) {

            return [
                'success' => false,
                'message' => 'Unable to select shipping provider.',
                'error'   => $e->getMessage()
            ];
        }

        if (!$provider) {

            $order->add_order_note(
                'Speedaf shipment was not created: no supported shipping provider was available.'
            );

            return [
                'success' => false,
                'message' => 'No shipping provider available.'
            ];
        }

        /**
         * Step 4:
         * Make sure the provider can create orders.
         */
        if (!method_exists($provider, 'createOrder')) {

            $order->add_order_note(
                'Speedaf shipment was not created: selected provider cannot create orders.'
            );

            return [
                'success' => false,
                'provider' => $provider->getName(),
                'message' => 'Selected shipping provider cannot create orders.'
            ];
        }

        /**
         * Step 5:
         * Create shipment with Speedaf.
         */
        try {

            $result = $provider->createOrder($shipment);

        } catch (Throwable $e) {

            /**
             * Important:
             * Never allow a Speedaf API exception
             * to crash WooCommerce.
             */
            $order->add_order_note(
                'Speedaf shipment creation failed: ' . $e->getMessage()
            );

            return [
                'success'  => false,
                'provider' => $provider->getName(),
                'message'  => 'Speedaf shipment creation failed.',
                'error'    => $e->getMessage()
            ];
        }

        /**
         * Step 6:
         * Validate API result.
         */
        if (
            !is_array($result) ||
            empty($result['success'])
        ) {

            $order->add_order_note(
                'Speedaf shipment creation failed. The API did not accept the request.'
            );

            return [
                'success'  => false,
                'provider' => $provider->getName(),
                'message'  => 'Speedaf rejected the shipment request.',
                'result'   => $result
            ];
        }

        /**
         * Step 7:
         * Extract Speedaf response.
         */
        $billCode = null;

        $customerOrderNo = null;

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

        if (is_array($decrypted)) {

            $billCode = !empty($decrypted['billCode'])
                ? sanitize_text_field(
                    $decrypted['billCode']
                )
                : null;

            $customerOrderNo = !empty(
                $decrypted['customerOrderNo']
            )
                ? sanitize_text_field(
                    $decrypted['customerOrderNo']
                )
                : null;
        }

        /**
         * Step 8:
         * A successful HTTP response without
         * a Speedaf bill code is not considered
         * a completed shipment.
         */
        if (empty($billCode)) {

            $order->add_order_note(
                'Speedaf returned a successful API response, but no bill code was received.'
            );

            return [
                'success'  => false,
                'provider' => $provider->getName(),
                'message'  => 'Speedaf did not return a shipment bill code.',
                'result'   => $result
            ];
        }

        /**
         * Step 9:
         * Save Speedaf shipment information
         * to WooCommerce order metadata.
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
         * Save all metadata.
         */
        $order->save();

        /**
         * Step 10:
         * Add WooCommerce order note.
         */
        $order->add_order_note(
            sprintf(
                'Speedaf shipment created successfully. Bill Code: %s',
                $billCode
            )
        );

        /**
         * Step 11:
         * Return final result.
         */
        return [
            'success'          => true,
            'duplicate'        => false,
            'provider'         => $provider->getName(),
            'billCode'         => $billCode,
            'customerOrderNo'  => $customerOrderNo,
            'shipment'         => $shipment
        ];
    }
}