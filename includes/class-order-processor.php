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
    public function process(
        array $data
    ): array {

        /**
         * Step 1:
         * Build our standard shipment.
         */
        $shipment = $this->builder->build($data);

        /**
         * Step 2:
         * Select the best provider.
         */
        $provider = $this->router->route(
            $shipment
        );

        if (!$provider) {

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

        $result = $provider->createOrder($shipment);

        /**
         * Step 4:
         * Return everything.
         */
        return [

            'success' => true,

            'provider' => $provider->getName(),

            'shipment' => $shipment,

            'result' => $result

        ];
    }
}