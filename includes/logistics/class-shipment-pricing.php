
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prices each generated waybill using the agreed Speedaf tariff.
 */
class SefrelShop_Shipment_Pricing
{
    private RateCalculator $calculator;
    private SpeedafZoneResolver $zoneResolver;

    public function __construct(
        ?RateCalculator $calculator = null,
        ?SpeedafZoneResolver $zoneResolver = null
    ) {
        $this->calculator = $calculator ?? new RateCalculator();
        $this->zoneResolver = $zoneResolver ?? new SpeedafZoneResolver();
    }

    /**
     * Price every waybill in one pickup-location group.
     *
     * Required destination format:
     * [
     *     'pickup_city' => 'Ibadan',
     *     'delivery_city' => 'Abuja',
     *     'waybills' => [...]
     * ]
     */

/**
 * Price every waybill in one pickup-location group.
 */
public function priceGroup(
    array $group,
    string $deliveryCity,
    string $deliveryState = ''
): array {
    if (
        empty($group['pickup_city']) ||
        empty($group['pickup_state']) ||
        empty($group['waybills']) ||
        trim($deliveryCity) === '' ||
        trim($deliveryState) === ''
    ) {
        throw new InvalidArgumentException(
            'Pickup city, pickup state, delivery city, delivery state, and waybills are required.'
        );
    }

    $pickupZone = $this->zoneResolver->resolveLocation(
        (string) $group['pickup_city'],
        (string) $group['pickup_state']
    );

    $deliveryZone = $this->zoneResolver->resolveLocation(
        $deliveryCity,
        $deliveryState
    );

    if ($pickupZone === null || $deliveryZone === null) {
        throw new InvalidArgumentException(
            'Pickup or delivery location could not be mapped to a Speedaf tariff zone.'
        );
    }

    $pricedWaybills = [];
    $totalBase = 0;
    $totalVat = 0;
    $total = 0;

    foreach ($group['waybills'] as $waybill) {
        $weight = $waybill['weight_kg'] ?? null;

        if (
            !is_numeric($weight) ||
            (float) $weight <= 0 ||
            (float) $weight > 10
        ) {
            throw new InvalidArgumentException(
                'Each waybill must weigh more than 0 kg and no more than 10 kg.'
            );
        }

        $quote = $this->calculator->calculate([
            'pickup_zone' => $pickupZone,
            'delivery_zone' => $deliveryZone,
            'chargeable_weight_kg' => (float) $weight,
        ]);

        $pricedWaybills[] = [
            'sequence' => $waybill['sequence'] ?? null,
            'weight_kg' => (float) $weight,
            'items' => $waybill['items'] ?? [],
            'quote' => $quote,
        ];

        $totalBase += $quote['base_amount'];
        $totalVat += $quote['vat_amount'];
        $total += $quote['amount'];
    }

    return [
        'pickup_city' => $group['pickup_city'],
        'pickup_state' => $group['pickup_state'],
        'delivery_city' => $deliveryCity,
        'delivery_state' => $deliveryState,
        'pickup_zone' => $pickupZone,
        'delivery_zone' => $deliveryZone,
        'waybills' => $pricedWaybills,
        'base_amount' => $totalBase,
        'vat_amount' => $totalVat,
        'total_amount' => $total,
        'currency' => 'NGN',
        'vat_inclusive' => true,
    ];
}
}