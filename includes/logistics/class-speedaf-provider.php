<?php

class SpeedafProvider implements ShippingProvider
{
    private SpeedafApi $api;

    public function __construct(SpeedafApi $api)
    {
        $this->api = $api;
    }

    /**
     * Get provider name
     */
    public function getName(): string
    {
        return 'Speedaf';
    }

    /**
     * Check if provider supports this order
     */
    public function supports(array $order): bool
    {
        return true;
    }

    /**
     * Calculate shipping rate
     */
    public function calculateRate(array $package): array
    {
        return $this->api->post(
            "/open-api/express/pricing/calculatePrice",
            $package
        );
    }

    /**
     * Create order
     */
    public function createOrder(array $order): array
    {
        return $this->api->post(
            "/open-api/express/order/createOrder",
            $order
        );
    }

    /**
     * Create shipment in Speedaf
     */
    public function createShipment(array $shipmentData): array
    {
        return $this->api->post(
            "/open-api/express/order/createOrder",
            $shipmentData
        );
    }

    /**
     * Track shipment
     */
    public function track(array $trackingData): array
    {
        return $this->api->post(
            "/open-api/express/track/customer/order/query",
            $trackingData
        );
    }

    /**
     * Track shipment (legacy method)
     */
    public function trackShipment(array $trackingData): array
    {
        return $this->api->post(
            "/open-api/express/track/customer/order/query",
            $trackingData
        );
    }

    /**
     * Cancel shipment
     */
    public function cancel(string $shipmentId): array
    {
        return $this->api->post(
            "/open-api/express/order/cancelOrder",
            ['shipmentId' => $shipmentId]
        );
    }
}