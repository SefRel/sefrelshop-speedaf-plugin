<?php

class SpeedafProvider implements ShippingProvider
{
    private SpeedafApi $api;

    private SpeedafConfig $config;

    public function __construct(
        SpeedafApi $api,
        SpeedafConfig $config
    ) {
        $this->api = $api;
        $this->config = $config;
    }

    /**
     * Provider name.
     */
    public function getName(): string
    {
        return 'Speedaf';
    }

    /**
     * Determine whether Speedaf
     * can handle this shipment.
     *
     * For now we'll return true.
     * Later this will check
     * category rules and coverage.
     */
    public function supports(array $shipment): bool
    {
        return true;
    }

    /**
     * Create a shipment in Speedaf.
     */
    public function createOrder(array $shipment): array
    {
        return $this->createShipment($shipment);
    }

    /**
     * Create a shipment in Speedaf.
     */
    public function createShipment(array $shipment): array
    {
        $payload = $this->buildPayload($shipment);

        return $this->api->post(
            "/open-api/express/order/createOrder",
            $payload
        );
    }

    /**
     * Track shipment.
     */
    public function track(array $trackingData): array
    {
        return $this->api->post(
            "/open-api/express/track/customer/order/query",
            $trackingData
        );
    }

    /**
     * Calculate shipping rate.
     */
    public function calculateRate(array $package): array
    {
        return $this->api->post(
            "/open-api/express/pricing/calculatePrice",
            $package
        );
    }

    /**
     * Cancel shipment.
     */
    public function cancel(string $shipmentId): array
    {
        return $this->api->post(
            "/open-api/express/order/cancelOrder",
            [
                'shipmentId' => $shipmentId
            ]
        );
    }

    /**
     * Convert our standard shipment
     * into Speedaf format.
     */
    private function buildPayload(array $shipment): array
    {
        return [

            'customerCode' => $this->config->get('customerCode'),

            'customOrderNo' => $shipment['customer_order_no'],

            'acceptName' => $shipment['customer_name'],

            'acceptPhone' => $shipment['customer_phone'],

            'acceptMobile' => $shipment['customer_phone'],

            'acceptEmail' => $shipment['customer_email'],

            'acceptCountryCode' => $shipment['shipping_country'],

            'acceptCountryName' => 'Nigeria',

            'acceptProvinceName' => $shipment['shipping_state'],

            'acceptCityName' => $shipment['shipping_city'],

            'acceptStreetName' => $shipment['shipping_address'],

            'acceptAddress' => $shipment['shipping_address'],

            /*
             * Temporary sender.
             * Later this will come
             * from the Dokan vendor.
             */

            'sendName' => 'SefrelShop Warehouse',

            'sendCompanyName' => 'SefrelShop',

            'sendPhone' => '08011111111',

            'sendMobile' => '08011111111',

            'sendMail' => 'support@sefrelshop.com',

            'sendCountryCode' => 'NG',

            'sendCountryName' => 'Nigeria',

            'sendProvinceName' => 'Lagos',

            'sendCityName' => 'Lagos',

            'sendAddress' => 'No. 1 Test Street',

            'deliveryType' => 'DE01',

            'shipType' => 'ST01',

            'transportType' => 'TT02',

            'payMethod' => 'PA01',

            'parcelType' => 'PT01',

            'currencyType' => get_woocommerce_currency(),

            'parcelCurrencyType' => get_woocommerce_currency(),

            'goodsWeight' => $shipment['total_weight'],

            'parcelWeight' => $shipment['total_weight'],

            'goodsQTY' => count($shipment['items']),

            'piece' => 1,

            'parcelValue' => $shipment['total_value'],

            'shippingFee' => 0,

            'codFee' => 0,

            'insurePrice' => 0,

            'pickupType' => 0,

            'pickUpAging' => 0,

            'warehouseCode' => 'GZ01',

            'platformSource' => $this->config->get('platformSource'),

            'remark' => 'WooCommerce Order',

            'itemList' => $shipment['items']

        ];
    }
}