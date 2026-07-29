<?php

class SefrelShopPlugin
{
    /**
     * Order Processor
     */
    private OrderProcessor $processor;

    /**
     * Build the entire application.
     */
    public function __construct()
    {
        /*
        |--------------------------------------------------------------------------
        | Core Services
        |--------------------------------------------------------------------------
        */

        $config = new SpeedafConfig();

        $encryptor = new SpeedafEncryption(
            $config->get('secretKey')
        );

        $api = new SpeedafApi(
            $config,
            $encryptor
        );

        /*
        |--------------------------------------------------------------------------
        | Shipping Provider
        |--------------------------------------------------------------------------
        */

        $speedaf = new SpeedafProvider($api);

        /*
        |--------------------------------------------------------------------------
        | Logistics Manager
        |--------------------------------------------------------------------------
        */

        $manager = new LogisticsManager();

        $manager->registerProvider($speedaf);

        /*
        |--------------------------------------------------------------------------
        | Router
        |--------------------------------------------------------------------------
        */

        $router = new ShippingRouter($manager);

        /*
        |--------------------------------------------------------------------------
        | Builder
        |--------------------------------------------------------------------------
        */

        $builder = new OrderBuilder();

        /*
        |--------------------------------------------------------------------------
        | Processor
        |--------------------------------------------------------------------------
        */

        $this->processor = new OrderProcessor(
            $builder,
            $router
        );
    }

    /**
     * Process WooCommerce Order.
     */
    public function processOrder(
        WC_Order $order
    ): array {

        return $this->processor->process([

            'wc_order' => $order

        ]);

    }
}