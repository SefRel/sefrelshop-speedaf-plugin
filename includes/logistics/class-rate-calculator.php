
<?php

/**
 * Speedaf local tariff calculator.
 *
 * Uses VAT-inclusive rates from the supplied Speedaf tariff,
 * effective 1 November 2024, pending confirmation of validity.
 *
 * This class calculates prices only. It does not create shipments,
 * call carrier APIs, or select a logistics provider.
 */
class RateCalculator
{
    /**
     * VAT-inclusive rates for shipments up to and including 20 kg.
     * Keys are chargeable weights in kg.
     * Values are ordered Zone 1, Zone 2, Zone 3, Zone 4.
     */
    private const PARCEL_RATES = [
        0.5  => [2419, 3522, 4718, 5766],
        1.0  => [2903, 3774, 4979, 6027],
        1.5  => [3145, 4277, 5241, 6289],
        2.0  => [3387, 4528, 5766, 6552],
        2.5  => [3629, 4780, 6027, 7075],
        3.0  => [4112, 5031, 6289, 7337],
        3.5  => [4354, 5535, 6552, 7600],
        4.0  => [4596, 5786, 7075, 7862],
        4.5  => [4838, 6038, 7337, 8385],
        5.0  => [5322, 6289, 7600, 8648],
        5.5  => [5805, 7044, 9056, 10188],
        6.0  => [6047, 7295, 9339, 10471],
        6.5  => [6289, 7547, 9623, 10755],
        7.0  => [6773, 8050, 10188, 11038],
        7.5  => [7015, 8302, 10471, 11603],
        8.0  => [7257, 8553, 10755, 11887],
        8.5  => [7740, 9056, 11038, 12169],
        9.0  => [7982, 9308, 11603, 12735],
        9.5  => [8224, 9559, 11887, 13019],
        10.0 => [8708, 9811, 12169, 13301],
        10.5 => [9192, 10566, 13019, 14151],
        11.0 => [9434, 10817, 13301, 14433],
        11.5 => [9917, 11069, 13584, 14716],
        12.0 => [10159, 11572, 14151, 15283],
        12.5 => [10401, 11823, 14433, 15565],
        13.0 => [10643, 12075, 14716, 16131],
        13.5 => [11127, 12578, 15283, 16131],
        14.0 => [11369, 12830, 15565, 16697],
        14.5 => [11610, 13081, 15848, 17263],
        15.0 => [12094, 13584, 16131, 17547],
        15.5 => [12336, 13836, 16697, 17829],
        16.0 => [12578, 14087, 17263, 18112],
        16.5 => [13062, 14339, 17547, 18679],
        17.0 => [13304, 14842, 17829, 18961],
        17.5 => [13545, 15345, 18112, 19244],
        18.0 => [13787, 15597, 18679, 19811],
        18.5 => [14271, 15848, 18961, 20093],
        19.0 => [14513, 16100, 19244, 20376],
        19.5 => [14755, 16603, 19527, 20943],
        20.0 => [15239, 16854, 20093, 21508],
    ];

    /**
     * VAT-inclusive bulk rates per kg.
     *
     * Keys are inclusive upper limits of each tariff band.
     * Values are ordered Zone 1, Zone 2, Zone 3, Zone 4.
     */
    private const BULK_RATES_PER_KG = [
        50   => [655, 736, 786, 917],
        100  => [525, 655, 736, 786],
        5000 => [505, 585, 655, 736],
    ];

    /**
     * Preserve the existing comparison method for compatibility.
     */
    public function compare(array $rates): array
    {
        return $rates;
    }

    /**
     * Calculate a Speedaf tariff quote.
     *
     * Required input:
     * [
     *     'pickup_zone' => 1,
     *     'delivery_zone' => 3,
     *     'chargeable_weight_kg' => 1.2,
     * ]
     *
     * Chargeable weight must already account for actual versus
     * dimensional weight according to the applicable carrier rules.
     *
     * @throws InvalidArgumentException For invalid or unsupported input.
     */
    public function calculate(array $shipment): array
    {
        foreach (
            ['pickup_zone', 'delivery_zone', 'chargeable_weight_kg']
            as $required
        ) {
            if (!array_key_exists($required, $shipment)) {
                throw new InvalidArgumentException(
                    "Missing required shipment field: {$required}"
                );
            }
        }

        $pickupZone = filter_var(
            $shipment['pickup_zone'],
            FILTER_VALIDATE_INT
        );

        $deliveryZone = filter_var(
            $shipment['delivery_zone'],
            FILTER_VALIDATE_INT
        );

        if (
            $pickupZone === false ||
            $deliveryZone === false ||
            $pickupZone < 1 ||
            $pickupZone > 4 ||
            $deliveryZone < 1 ||
            $deliveryZone > 4
        ) {
            throw new InvalidArgumentException(
                'Pickup and delivery zones must be integers from 1 to 4.'
            );
        }

        $weight = $shipment['chargeable_weight_kg'];

        if (
            !is_numeric($weight) ||
            !is_finite((float) $weight) ||
            (float) $weight <= 0
        ) {
            throw new InvalidArgumentException(
                'Chargeable weight must be a finite number greater than zero.'
            );
        }

        $weight = (float) $weight;

        // SefrelShop's agreed zone rule: use the higher zone number.
        $zone = max($pickupZone, $deliveryZone);

        if ($weight <= 20.0) {
            // Round upward to the next 0.5 kg band.
            // Integer arithmetic avoids common floating-point boundary errors.
            $halfKgUnits = (int) ceil(($weight * 2) - 1e-9);
            $billableWeight = $halfKgUnits / 2;

            if (!isset(self::PARCEL_RATES[$billableWeight])) {
                throw new InvalidArgumentException(
                    'No parcel tariff exists for the calculated weight band.'
                );
            }

            $amount = self::PARCEL_RATES[$billableWeight][$zone - 1];

            return [
                'success' => true,
                'provider' => 'speedaf',
                'currency' => 'NGN',
                'amount' => $amount,
                'vat_inclusive' => true,
                'zone' => $zone,
                'pickup_zone' => $pickupZone,
                'delivery_zone' => $deliveryZone,
                'chargeable_weight_kg' => $weight,
                'billable_weight_kg' => $billableWeight,
                'rate_type' => 'parcel',
            ];
        }

        // Bulk bands use their documented per-kg tariff rates.
        // This implementation does not round bulk weight to 0.5 kg.
        if ($weight > 5000) {
            throw new InvalidArgumentException(
                'Shipment weight exceeds the tariff maximum of 5000 kg.'
            );
        }

        $ratePerKg = null;
        $weightBand = null;

        foreach (self::BULK_RATES_PER_KG as $upperLimit => $rates) {
            if ($weight <= $upperLimit) {
                $ratePerKg = $rates[$zone - 1];
                $weightBand = $upperLimit;
                break;
            }
        }

        if ($ratePerKg === null) {
            throw new InvalidArgumentException(
                'No bulk tariff exists for this shipment weight.'
            );
        }

        return [
            'success' => true,
            'provider' => 'speedaf',
            'currency' => 'NGN',
            'amount' => (int) round($weight * $ratePerKg),
            'vat_inclusive' => true,
            'zone' => $zone,
            'pickup_zone' => $pickupZone,
            'delivery_zone' => $deliveryZone,
            'chargeable_weight_kg' => $weight,
            'billable_weight_kg' => $weight,
            'rate_type' => 'bulk',
            'bulk_band_max_kg' => $weightBand,
            'rate_per_kg' => $ratePerKg,
        ];
    }
}
