<?php

class ShippingRouter
{
    /**
     * Logistics Manager
     */
    private LogisticsManager $manager;

    /**
     * Constructor
     */
    public function __construct(
        LogisticsManager $manager
    ) {
        $this->manager = $manager;
    }

    /**
     * Select the best provider
     * for a WooCommerce order.
     *
     * For now, simply return the
     * first eligible provider.
     */
    public function route(
        array $order
    ): ?ShippingProvider {

        $providers = $this->manager
            ->getSupportedProviders($order);

        if (empty($providers)) {
            return null;
        }

        /**
         * Temporary strategy:
         * return the first provider.
         *
         * Later this will become:
         * - Category rules
         * - Coverage rules
         * - Cheapest rate
         * - Fastest delivery
         * - Vendor preference
         * - Customer preference
         */
        return $providers[0];
    }
}