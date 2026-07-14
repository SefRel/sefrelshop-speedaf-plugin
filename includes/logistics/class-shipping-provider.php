<?php

/**
 * Every shipping provider must implement these methods.
 */
interface ShippingProvider
{
    /**
     * Create shipment
     */
    public function createOrder(array $order);

    /**
     * Track shipment
     */
    public function track(array $trackingData);

    /**
     * Cancel shipment
     */
    public function cancel(string $shipmentId);

    /**
     * Calculate shipping rate
     */
    public function calculateRate(array $package);

    /**
     * Provider name
     */
    public function getName(): string;

    /**
     * Check whether provider supports this order.
     */
    public function supports(array $order): bool;
}