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
     *
     * For now we only simulate the workflow.
     */
    public function process(array $order): array
{
    /**
     * Step 1
     * Build shipment data.
     */
    $shipment = $this->builder->build($order);

    /**
     * Step 2
     * Ask the router to select
     * the best provider.
     */
    $provider = $this->router->route($shipment);

    if (!$provider) {

        return [

            'success' => false,

            'message' => 'No shipping provider available.'

        ];

    }

    /**
     * Step 3
     * Create shipment.
     */
    return $provider->createShipment($shipment);
}
}