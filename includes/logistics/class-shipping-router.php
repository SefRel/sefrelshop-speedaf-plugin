<?php

class ShippingRouter
{
    /**
     * Logistics manager.
     */
    private LogisticsManager $manager;

    /**
     * Constructor.
     */
    public function __construct(
        LogisticsManager $manager
    ) {
        $this->manager = $manager;
    }

    /**
     * Determine the best provider
     * for this shipment.
     */
    public function route(
        array $shipment
    ): ?ShippingProvider {

        $providers = $this->manager
            ->getSupportedProviders($shipment);

        if (empty($providers)) {
            return null;
        }

        /**
         * Temporary strategy:
         * use the first supported provider.
         *
         * Future versions will rank providers
         * based on:
         *
         * - Supported categories
         * - Shipping price
         * - Delivery speed
         * - Vendor preference
         * - Customer preference
         * - Geographic coverage
         */
        return $providers[0];
    }
}