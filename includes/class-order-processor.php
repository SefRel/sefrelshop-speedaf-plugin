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
        $shipment = $this->builder->build($data);

        /**
         * Step 2:
         * Select the best shipping provider.
         */
        $provider = $this->router->route($shipment);

        if (!$provider) {
            return [
                'success' => false,
                'message' => 'No shipping provider available.'
            ];
        }

        /**
         * Step 3:
         * Make sure the provider can create orders.
         */
        if (!method_exists($provider, 'createOrder')) {
            return [
                'success' => false,
                'message' => 'Selected shipping provider cannot create orders.'
            ];
        }

        /**
         * Step 4:
         * Prevent duplicate Speedaf shipments.
         */
        if (!empty($shipment['order_id'])) {

            $existingBillCode = get_post_meta(
                $shipment['order_id'],
                '_speedaf_bill_code',
                true
            );

            if (!empty($existingBillCode)) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'provider' => $provider->getName(),
                    'message' => 'Speedaf shipment already exists.',
                    'billCode' => $existingBillCode,
                    'shipment' => $shipment
                ];
            }
        }

        /**
         * Step 5:
         * Create the shipment with Speedaf.
         */
        $result = $provider->createOrder($shipment);

        /**
         * Step 6:
         * Check whether Speedaf accepted the request.
         */
        if (
            !is_array($result) ||
            empty($result['success'])
        ) {
            return [
                'success' => false,
                'provider' => $provider->getName(),
                'message' => 'Speedaf rejected the shipment request.',
                'result' => $result
            ];
        }

        /**
         * Step 7:
         * Extract Speedaf response.
         */
        $billCode = null;
        $customerOrderNo = null;

        if (!empty($result['decrypted'])) {

            $decrypted = json_decode(
                $result['decrypted'],
                true
            );

            if (is_array($decrypted)) {

                $billCode = $decrypted['billCode'] ?? null;

                $customerOrderNo =
                    $decrypted['customerOrderNo'] ?? null;
            }
        }

        /**
         * Step 8:
         * Save Speedaf information
         * against the WooCommerce order.
         */
        if (
            !empty($shipment['order_id']) &&
            !empty($billCode)
        ) {

            update_post_meta(
                $shipment['order_id'],
                '_speedaf_bill_code',
                sanitize_text_field($billCode)
            );

            if (!empty($customerOrderNo)) {

                update_post_meta(
                    $shipment['order_id'],
                    '_speedaf_customer_order_no',
                    sanitize_text_field($customerOrderNo)
                );
            }

            update_post_meta(
                $shipment['order_id'],
                '_speedaf_status',
                'created'
            );

            update_post_meta(
                $shipment['order_id'],
                '_speedaf_created_at',
                current_time('mysql')
            );
        }

        /**
         * Step 9:
         * Return complete result.
         */
        return [
            'success' => true,
            'duplicate' => false,
            'provider' => $provider->getName(),
            'billCode' => $billCode,
            'customerOrderNo' => $customerOrderNo,
            'shipment' => $shipment,
            'result' => $result
        ];
    }
}