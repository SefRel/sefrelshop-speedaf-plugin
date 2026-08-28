<?php

/**
 * Every shipping provider
 * must implement these methods.
 */
interface ShippingProvider
{
    /**
     * Provider name.
     */
    public function getName(): string;

    /**
     * Check whether this provider
     * supports the shipment.
     */
    public function supports(
        array $shipment
    ): bool;

    /**
     * Calculate shipping rate.
     */
    public function calculateRate(
        array $shipment
    ): array;

    /**
     * Create shipment.
     */
    public function createShipment(
        array $shipment
    ): array;

    /**
     * Track shipment.
     */
    public function track(
        array $trackingData
    ): array;

    /**
     * Cancel shipment.
     */
    public function cancel(
        string $shipmentId
    ): array;
}