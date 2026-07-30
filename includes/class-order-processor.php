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
        update_option('processor_step_1', 'Started');

        /**
         * Step 1:
         * Build our standard shipment.
         */
        $shipment = $this->builder->build($data);

        update_option('processor_step_2', 'Shipment Built');

        /**
         * Step 2:
         * Select the best provider.
         */
        $provider = $this->router->route($shipment);

        update_option('processor_step_3', 'Router Finished');

        if (!$provider) {

            update_option('processor_step_4', 'No Provider');

            return [
                'success' => false,
                'message' => 'No shipping provider available.'
            ];
        }

        /**
         * Step 3:
         * Create order.
         */
        if (!method_exists($provider, 'createOrder')) {

            return [
                'success' => false,
                'message' => 'Selected shipping provider cannot create orders.'
            ];
        }

        update_option(
            'processor_step_5',
            'Provider: ' . $provider->getName()
        );

        return [
            'success' => true
        ];
    }
}