<?php

class SefrelShopPlugin
{
    /**
     * Order Processor
     */
    private OrderProcessor $processor;

    /**
     * Speedaf Tracking Synchroniser
     */
    private SpeedafTrackingSync $trackingSync;

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

        $speedaf = new SpeedafProvider(
            $api,
            $config
        );

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

        /*
        |--------------------------------------------------------------------------
        | Speedaf Tracking Synchronisation
        |--------------------------------------------------------------------------
        */

        $this->trackingSync = new SpeedafTrackingSync(
            $speedaf,
            $config
        );
    }

    /**
     * Process WooCommerce Order.
     *
     * @param mixed $order
     */
    public function processOrder(
        $order
    ): array {

        return $this->processor->process([

            'wc_order' => $order

        ]);

    }

    /**
     * Synchronise tracking information.
     *
     * @param mixed $order
     */
    public function syncTracking(
        $order
    ): array {

        return $this->trackingSync->syncOrder(

             $order
    );

    }
}