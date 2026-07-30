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

        /*
         * Build shipment.
         */
        $shipment = $this->builder->build($data);

        update_option('processor_step_2', 'Shipment Built');

        /*
         * Select provider.
         */
        $provider = $this->router->route($shipment);

        update_option('processor_step_3', 'Router Finished');

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
            'processor_step_5',
            'Provider: ' . $provider->getName()
        );

        /*
         * Temporary.
         * Don't call Speedaf yet.
         */
        return [
            'success' => true,
            'provider' => $provider->getName(),
            'shipment' => $shipment
        ];
    }
}