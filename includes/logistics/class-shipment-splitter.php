
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Groups products by pickup location and splits shipments into
 * waybills weighing no more than 10 kg.
 *
 * Input item format:
 * [
 *   'vendor_id'       => 12,
 *   'product_id'      => 100,
 *   'parent_order_id' => 500,
 *   'sub_order_id'    => 501,
 *   'pickup_state'    => 'Oyo',
 *   'pickup_city'     => 'Ibadan',
 *   'unit_weight_kg'  => 2.5,
 *   'quantity'        => 3,
 *   'length_cm'       => 20,
 *   'width_cm'        => 15,
 *   'height_cm'       => 10,
 * ]
 */
class SefrelShop_Shipment_Splitter
{
    private const MAX_WAYBILL_WEIGHT_KG = 10.0;
    private const MAX_PARCEL_DIMENSION_CM = 30.0;

    /**
     * Group items by verified pickup state and city, then split
     * each group into waybills without exceeding 10 kg.
     *
     * @throws InvalidArgumentException On invalid shipment data.
     */
    public function groupAndSplit(array $items): array
    {
        if (empty($items)) {
            throw new InvalidArgumentException(
                'At least one shipment item is required.'
            );
        }

        $groups = [];

        foreach ($items as $item) {
            $item = $this->validateItem($item);

            $state = $this->normalizeLocation($item['pickup_state']);
            $city  = $this->normalizeLocation($item['pickup_city']);

            $groupKey = $state . '|' . $city;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'pickup_state' => $state,
                    'pickup_city'  => $city,
                    'items'        => [],
                ];
            }

            $groups[$groupKey]['items'][] = $item;
        }

        $result = [];

        foreach ($groups as $group) {
            $waybills = $this->splitGroupItems($group['items']);

            $result[] = [
                'pickup_state' => $group['pickup_state'],
                'pickup_city'  => $group['pickup_city'],
                'waybills'     => $waybills,
            ];
        }

        return $result;
    }

    /**
     * Validate and normalize one item.
     */
    private function validateItem(array $item): array
    {
        $required = [
            'vendor_id',
            'product_id',
            'pickup_state',
            'pickup_city',
            'unit_weight_kg',
            'quantity',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $item)) {
                throw new InvalidArgumentException(
                    "Missing shipment item field: {$field}"
                );
            }
        }

        if (
            !is_numeric($item['vendor_id']) ||
            (int) $item['vendor_id'] <= 0 ||
            !is_numeric($item['product_id']) ||
            (int) $item['product_id'] <= 0
        ) {
            throw new InvalidArgumentException(
                'Vendor ID and product ID must be positive integers.'
            );
        }

        $state = trim((string) $item['pickup_state']);
        $city  = trim((string) $item['pickup_city']);

        if ($state === '' || $city === '') {
            throw new InvalidArgumentException(
                'Pickup state and city are required.'
            );
        }

        if (
            !is_numeric($item['unit_weight_kg']) ||
            !is_finite((float) $item['unit_weight_kg']) ||
            (float) $item['unit_weight_kg'] <= 0
        ) {
            throw new InvalidArgumentException(
                'Each product must have a valid positive unit weight in kg.'
            );
        }

        if (
            filter_var(
                $item['quantity'],
                FILTER_VALIDATE_INT
            ) === false ||
            (int) $item['quantity'] < 1
        ) {
            throw new InvalidArgumentException(
                'Product quantity must be a positive integer.'
            );
        }

        $item['vendor_id']      = (int) $item['vendor_id'];
        $item['product_id']     = (int) $item['product_id'];
        $item['unit_weight_kg'] = (float) $item['unit_weight_kg'];
        $item['quantity']       = (int) $item['quantity'];

        foreach (
            ['parent_order_id', 'sub_order_id']
            as $orderField
        ) {
            if (
                isset($item[$orderField]) &&
                (
                    !is_numeric($item[$orderField]) ||
                    (int) $item[$orderField] < 0
                )
            ) {
                throw new InvalidArgumentException(
                    "Invalid {$orderField}."
                );
            }

            if (isset($item[$orderField])) {
                $item[$orderField] = (int) $item[$orderField];
            }
        }

        // A single product unit must fit within the waybill weight cap.
        if ($item['unit_weight_kg'] > self::MAX_WAYBILL_WEIGHT_KG) {
            throw new InvalidArgumentException(
                'A single product unit exceeds the 10 kg waybill limit.'
            );
        }

        // Reject known oversized individual product dimensions.
        // Final packed-parcel dimensions must still be checked separately.
        foreach (['length_cm', 'width_cm', 'height_cm'] as $dimension) {
            if (!array_key_exists($dimension, $item)) {
                throw new InvalidArgumentException(
                    "Missing parcel dimension: {$dimension}"
                );
            }

            if (
                !is_numeric($item[$dimension]) ||
                !is_finite((float) $item[$dimension]) ||
                (float) $item[$dimension] <= 0 ||
                (float) $item[$dimension] > self::MAX_PARCEL_DIMENSION_CM
            ) {
                throw new InvalidArgumentException(
                    "Invalid or oversized product dimension: {$dimension}."
                );
            }

            $item[$dimension] = (float) $item[$dimension];
        }

        $item['pickup_state'] = $state;
        $item['pickup_city']  = $city;

        return $item;
    }

    /**
     * Split a pickup-location group into sequential waybills.
     *
     * Product quantities may be divided between waybills, but every
     * allocated unit retains its vendor and order attribution.
     */
    private function splitGroupItems(array $items): array
    {
        $waybills = [];
        $current = $this->newWaybill(1);

        foreach ($items as $item) {
            $remainingQuantity = $item['quantity'];

            while ($remainingQuantity > 0) {
                $remainingCapacity = round(
                    self::MAX_WAYBILL_WEIGHT_KG -
                    $current['weight_kg'],
                    6
                );

                if ($remainingCapacity <= 0) {
                    $waybills[] = $current;
                    $current = $this->newWaybill(
                        count($waybills) + 1
                    );

                    continue;
                }

                $unitsThatFit = (int) floor(
                    (
                        $remainingCapacity + 1e-9
                    ) / $item['unit_weight_kg']
                );

                if ($unitsThatFit < 1) {
                    $waybills[] = $current;
                    $current = $this->newWaybill(
                        count($waybills) + 1
                    );

                    continue;
                }

                $allocatedQuantity = min(
                    $remainingQuantity,
                    $unitsThatFit
                );

                $allocatedWeight = round(
                    $allocatedQuantity * $item['unit_weight_kg'],
                    6
                );

                $current['items'][] = [
                    'vendor_id'       => $item['vendor_id'],
                    'product_id'      => $item['product_id'],
                    'parent_order_id' => $item['parent_order_id'] ?? null,
                    'sub_order_id'    => $item['sub_order_id'] ?? null,
                    'quantity'        => $allocatedQuantity,
                    'unit_weight_kg'  => $item['unit_weight_kg'],
                    'weight_kg'       => $allocatedWeight,
                    'dimensions_cm'   => [
                        'length' => $item['length_cm'],
                        'width'  => $item['width_cm'],
                        'height' => $item['height_cm'],
                    ],
                ];

                $current['weight_kg'] = round(
                    $current['weight_kg'] + $allocatedWeight,
                    6
                );

                $remainingQuantity -= $allocatedQuantity;
            }
        }

        if (!empty($current['items'])) {
            $waybills[] = $current;
        }

        return $waybills;
    }

    private function newWaybill(int $sequence): array
    {
        return [
            'sequence'  => $sequence,
            'weight_kg' => 0.0,
            'items'     => [],
        ];
    }

    private function normalizeLocation(string $location): string
    {
        $location = strtolower(trim($location));
        $location = preg_replace('/\s+/', ' ', $location);

        return $location;
    }
}
