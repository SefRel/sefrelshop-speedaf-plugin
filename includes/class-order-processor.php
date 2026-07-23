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
     * Process WooCommerce Order
     */
    public function process(array $data): array
    {
        /*
         * Build shipment data.
         */
        $shipment = $this->builder->build($data);

        /*
         * Ask router to choose
         * the best provider.
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

        /*
         * Create shipment.
         */
        foreach (['createShipment', 'create', 'ship'] as $method) {
            if (method_exists($provider, $method)) {
                return $provider->$method($shipment);
            }
        }

        return [
            'success' => false,
            'message' => 'Shipping provider does not support shipment creation.'
        ];
    }
}