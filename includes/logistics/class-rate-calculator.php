
<?php

/**
 * Speedaf local tariff calculator.
 *
 * Uses the SefrelShop agreed tariff, effective 1 February 2025.
 * Tariff prices exclude VAT; 7.5% VAT is added during calculation.
 *
 * This class calculates prices only. It does not create shipments,
 * call carrier APIs, or select a logistics provider.
 */
class RateCalculator
{
    private const VAT_RATE = 0.075;
    private const MAX_WAYBILL_WEIGHT_KG = 10.0;

    /**
     * Default agreed tariff prices, excluding VAT.
     * Rows are weight bands; columns are Zones 1, 2, 3, 4.
     *
     * These defaults will later be editable through WordPress Admin.
     */
   private const DEFAULT_TARIFF = [
    1  => [3016, 4950, 6624, 8100],
    2  => [3616, 5301, 6984, 8460],
    3  => [3920, 6003, 7362, 8838],
    4  => [4224, 6354, 8100, 9198],
    5  => [4528, 6714, 8460, 9936],
    6  => [5128, 7065, 8838, 10296],
    7  => [5432, 7767, 9198, 10674],
    8  => [5744, 8118, 9936, 11034],
    9  => [6040, 8487, 10296, 11772],
    10 => [6648, 8838, 10674, 12141],
    11 => [7248, 9891, 12717, 14310],
    12 => [7552, 10242, 13113, 14706],
    13 => [7856, 10602, 13518, 15102],
    14 => [8456, 11304, 14310, 15507],
    15 => [8760, 11655, 14706, 16290],
    16 => [9056, 12006, 15102, 16686],
    17 => [9664, 12717, 15507, 17082],
    18 => [9960, 13068, 16290, 17874],
    19 => [10264, 13419, 16686, 18279],
    20 => [10872, 13770, 17082, 18675],
];

    /**
     * Preserve compatibility with existing callers.
     */
    public function compare(array $rates): array
    {
        return $rates;
    }

    /**
     * Return the active tariff.
     *
     * The WordPress option will be populated by the tariff manager.
     * If it is absent, use the agreed tariff defaults.
     */
    private function getTariff(): array
    {
        if (function_exists('get_option')) {
            $saved = get_option('sefrelshop_speedaf_tariff', null);

            if (is_array($saved) && $this->isValidTariff($saved)) {
                return $saved;
            }
        }

        return self::DEFAULT_TARIFF;
    }

/**
 * Validate tariff rows before using saved settings.
 */
private function isValidTariff(array $tariff): bool
{
    if (count($tariff) !== count(self::DEFAULT_TARIFF)) {
        return false;
    }

    foreach (self::DEFAULT_TARIFF as $weight => $defaults) {
        if (!isset($tariff[$weight]) || !is_array($tariff[$weight])) {
            return false;
        }

        if (count($tariff[$weight]) !== 4) {
            return false;
        }

        for ($zone = 0; $zone < 4; $zone++) {
            if (!array_key_exists($zone, $tariff[$weight])) {
                return false;
            }

            $price = $tariff[$weight][$zone];

            // Accept only positive whole-number prices.
            if (
                !(is_int($price) || (is_string($price) && ctype_digit($price))) ||
                (int) $price < 1 ||
                (int) $price > 100000000
            ) {
                return false;
            }
        }
    }

    return true;
}


    /**
     * Calculate a Speedaf quote.
     *
     * Required input:
     * [
     *     'pickup_zone' => 1,
     *     'delivery_zone' => 3,
     *     'chargeable_weight_kg' => 1.2,
     * ]
     *
     * Chargeable weight must already account for actual versus
     * dimensional weight under the applicable carrier rules.
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

        if ($weight > self::MAX_WAYBILL_WEIGHT_KG) {
            throw new InvalidArgumentException(
                'Speedaf waybill weight exceeds 10 kg. Split the shipment into eligible waybills.'
            );
        }

        // Use the higher-numbered pickup/delivery zone.
        $zone = max($pickupZone, $deliveryZone);

        // Round upward to the next 0.5 kg tariff band.
        $halfKgUnits = (int) ceil(($weight * 2) - 1e-9);
        $billableWeight = $halfKgUnits / 2;

        $tariff = $this->getTariff();

    if (!isset($tariff[$halfKgUnits][$zone - 1])) {
    throw new InvalidArgumentException(
        'No tariff exists for the calculated weight band and zone.'
    );
    }

        $baseAmount = (int) $tariff[$halfKgUnits][$zone - 1];
        $vatAmount = (int) round($baseAmount * self::VAT_RATE);
        $finalAmount = $baseAmount + $vatAmount;

        return [
            'success' => true,
            'provider' => 'speedaf',
            'currency' => 'NGN',
            'amount' => $finalAmount,
            'base_amount' => $baseAmount,
            'vat_rate' => self::VAT_RATE,
            'vat_amount' => $vatAmount,
            'vat_inclusive' => true,
            'zone' => $zone,
            'pickup_zone' => $pickupZone,
            'delivery_zone' => $deliveryZone,
            'chargeable_weight_kg' => $weight,
            'billable_weight_kg' => $billableWeight,
            'rate_type' => 'parcel',
        ];
    }
}
