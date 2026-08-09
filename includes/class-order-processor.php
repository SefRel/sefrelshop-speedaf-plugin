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
        update_option(
            'processor_step_1',
            'Started'
        );

        /*
         * --------------------------------------------------------------
         * Step 1: Build shipment
         * --------------------------------------------------------------
         */

        try {

            $shipment = $this->builder->build($data);

        } catch (Throwable $e) {

            update_option(
                'processor_step_2',
                'Shipment Build Failed: ' . $e->getMessage()
            );

            return [

                'success' => false,

                'message' => 'Unable to build shipment.',

                'error' => $e->getMessage()

            ];
        }

        update_option(
            'processor_step_2',
            'Shipment Built'
        );

        /*
         * --------------------------------------------------------------
         * Step 2: Select provider
         * --------------------------------------------------------------
         */

        try {

            $provider = $this->router->route(
                $shipment
            );

        } catch (Throwable $e) {

            update_option(
                'processor_step_3',
                'Router Failed: ' . $e->getMessage()
            );

            return [

                'success' => false,

                'message' => 'Unable to select shipping provider.',

                'error' => $e->getMessage()

            ];
        }

        update_option(
            'processor_step_3',
            'Router Finished'
        );

        if (!$provider) {

            update_option(
                'processor_step_4',
                'No Provider'
            );

            return [

                'success' => false,

                'message' => 'No shipping provider available.'

            ];
        }

        update_option(
            'processor_step_4',
            'Provider Selected: ' . $provider->getName()
        );

        /*
         * --------------------------------------------------------------
         * Step 3: Verify provider capability
         * --------------------------------------------------------------
         */

        if (!method_exists($provider, 'createOrder')) {

            update_option(
                'processor_step_5',
                'Provider cannot create orders'
            );

            return [

                'success' => false,

                'message' =>
                    'Selected shipping provider cannot create orders.'

            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 4: Prevent duplicate shipment creation
         * --------------------------------------------------------------
         */

        $orderId = isset($shipment['order_id'])
            ? (int) $shipment['order_id']
            : 0;

        if ($orderId > 0) {

            $existingShipment =
                get_post_meta(
                    $orderId,
                    '_sefrelshop_speedaf_response',
                    true
                );

            if (!empty($existingShipment)) {

                update_option(
                    'processor_step_5',
                    'Existing Speedaf response found. API call skipped.'
                );

                return [

                    'success' => false,

                    'message' =>
                        'Speedaf shipment may already exist. API call skipped.',

                    'existing_response' =>
                        $existingShipment

                ];
            }
        }

        /*
         * --------------------------------------------------------------
         * Step 5: Call Speedaf
         * --------------------------------------------------------------
         */

        update_option(
            'processor_step_5',
            'Calling Speedaf API'
        );

        try {

            $result = $provider->createOrder(
                $shipment
            );

        } catch (Throwable $e) {

            update_option(
                'processor_step_6',
                'Provider Exception: ' . $e->getMessage()
            );

            return [

                'success' => false,

                'message' =>
                    'Shipping provider request failed.',

                'error' =>
                    $e->getMessage()

            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 6: Store response
         * --------------------------------------------------------------
         */

        if ($orderId > 0) {

            update_post_meta(
                $orderId,
                '_sefrelshop_speedaf_response',
                $result
            );
        }

        update_option(
            'processor_step_6',
            wp_json_encode($result)
        );

        /*
         * --------------------------------------------------------------
         * Step 7: Determine final status
         * --------------------------------------------------------------
         */

        if (
            isset($result['success']) &&
            $result['success'] === true
        ) {

            return [

                'success' => true,

                'provider' =>
                    $provider->getName(),

                'shipment' =>
                    $shipment,

                'result' =>
                    $result

            ];
        }

        return [

            'success' => false,

            'provider' =>
                $provider->getName(),

            'shipment' =>
                $shipment,

            'result' =>
                $result

        ];
    }
}