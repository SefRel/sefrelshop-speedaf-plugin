
<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafZoneResolver
{
    /**
     * Confirmed tariff reference cities and their zones.
     */
    private const CITY_ZONES = [
        1 => ['lagos'],
        2 => [
            'abeokuta', 'akure', 'ado ekiti', 'ado-ekiti',
            'ibadan', 'ogbomosho', 'oshogbo', 'ota', 'ilorin',
        ],
        3 => [
            'aba', 'asaba', 'enugu', 'onitsha', 'owerri',
            'umuahia', 'abuja', 'benin', 'calabar',
            'port harcourt', 'uyo', 'warri', 'yenegoa',
        ],
        4 => [
            'lafia', 'lokoja', 'makurdi', 'minna', 'bauchi',
            'jalingo', 'jos', 'gombe', 'maiduguri', 'damaturu',
            'yola', 'kaduna', 'katsina', 'dutse',
            'birnin kebbi', 'sokoto', 'kano',
        ],
    ];

    /**
     * State fallback zones.
     *
     * Ebonyi and Zamfara are provisional pending tariff confirmation.
     */
    private const STATE_ZONES = [
        'lagos' => 1,

        'ekiti' => 2,
        'kwara' => 2,
        'ogun' => 2,
        'ondo' => 2,
        'osun' => 2,
        'oyo' => 2,

        'abia' => 3,
        'akwa ibom' => 3,
        'anambra' => 3,
        'bayelsa' => 3,
        'cross river' => 3,
        'delta' => 3,
        'ebonyi' => 3,
        'edo' => 3,
        'enugu' => 3,
        'federal capital territory' => 3,
        'fct' => 3,
        'imo' => 3,
        'rivers' => 3,

        'adamawa' => 4,
        'bauchi' => 4,
        'benue' => 4,
        'borno' => 4,
        'gombe' => 4,
        'jigawa' => 4,
        'kaduna' => 4,
        'kano' => 4,
        'katsina' => 4,
        'kebbi' => 4,
        'kogi' => 4,
        'nasarawa' => 4,
        'niger' => 4,
        'plateau' => 4,
        'sokoto' => 4,
        'taraba' => 4,
        'yobe' => 4,
        'zamfara' => 4,
    ];

    /**
     * State for each explicitly listed tariff reference city.
     */
    private const CITY_STATES = [
        'lagos' => 'lagos',
        'abeokuta' => 'ogun',
        'akure' => 'ondo',
        'ado ekiti' => 'ekiti',
        'ado-ekiti' => 'ekiti',
        'ibadan' => 'oyo',
        'ogbomosho' => 'oyo',
        'oshogbo' => 'osun',
        'ota' => 'ogun',
        'ilorin' => 'kwara',

        'aba' => 'abia',
        'asaba' => 'delta',
        'enugu' => 'enugu',
        'onitsha' => 'anambra',
        'owerri' => 'imo',
        'umuahia' => 'abia',
        'abuja' => 'fct',
        'benin' => 'edo',
        'calabar' => 'cross river',
        'port harcourt' => 'rivers',
        'uyo' => 'akwa ibom',
        'warri' => 'delta',
        'yenegoa' => 'bayelsa',

        'lafia' => 'nasarawa',
        'lokoja' => 'kogi',
        'makurdi' => 'benue',
        'minna' => 'niger',
        'bauchi' => 'bauchi',
        'jalingo' => 'taraba',
        'jos' => 'plateau',
        'gombe' => 'gombe',
        'maiduguri' => 'borno',
        'damaturu' => 'yobe',
        'yola' => 'adamawa',
        'kaduna' => 'kaduna',
        'katsina' => 'katsina',
        'dutse' => 'jigawa',
        'birnin kebbi' => 'kebbi',
        'sokoto' => 'sokoto',
        'kano' => 'kano',
    ];

    /**
     * Resolve a known tariff reference city.
     *
     * Unknown cities return null.
     */
    public function resolveCity(string $city): ?int
    {
        $city = $this->normalize($city);

        foreach (self::CITY_ZONES as $zone => $cities) {
            foreach ($cities as $knownCity) {
                if ($city === $this->normalize($knownCity)) {
                    return (int) $zone;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a location using both city and state.
     *
     * Known tariff cities use their explicit zone.
     * Other cities use the configured state fallback.
     */
    public function resolveLocation(
        string $city,
        string $state
    ): ?int {
        $city = $this->normalize($city);
        $state = $this->normalizeState($state);

        if ($city === '' || $state === '') {
            return null;
        }

        $knownCityState = self::CITY_STATES[$city] ?? null;

        // Reject a known reference city paired with the wrong state.
        if ($knownCityState !== null) {
            if ($state !== $this->normalizeState($knownCityState)) {
                return null;
            }

            return $this->resolveCity($city);
        }

        // Unlisted town: use its state's configured fallback zone.
        return self::STATE_ZONES[$state] ?? null;
    }

    /**
     * Resolve the tariff zone for a shipment.
     */
    public function resolveShipmentZone(
        int $pickupZone,
        int $deliveryZone
    ): ?int {
        if (
            $pickupZone < 1 || $pickupZone > 4 ||
            $deliveryZone < 1 || $deliveryZone > 4
        ) {
            return null;
        }

        return max($pickupZone, $deliveryZone);
    }

    /**
     * Normalize city and state strings.
     */
    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['-', '_', ','], ' ', $value);

        return preg_replace('/\s+/', ' ', $value) ?? '';
    }

    /**
     * Normalize state names and supported aliases.
     */
   private function normalizeState(string $state): string
{
    $state = $this->normalize($state);

    $aliases = [
    // Federal Capital Territory
    'fc' => 'fct',
    'abuja' => 'fct',
    'federal capital territory' => 'fct',
    'abuja federal capital territory' => 'fct',
    'f c t' => 'fct',
    'f.c.t.' => 'fct',

    // All 36 states: WooCommerce code => normalized state name
    'ab' => 'abia',
    'ad' => 'adamawa',
    'ak' => 'akwa ibom',
    'an' => 'anambra',
    'ba' => 'bauchi',
    'by' => 'bayelsa',
    'be' => 'benue',
    'bo' => 'borno',
    'cr' => 'cross river',
    'de' => 'delta',
    'eb' => 'ebonyi',
    'ed' => 'edo',
    'ek' => 'ekiti',
    'en' => 'enugu',
    'go' => 'gombe',
    'im' => 'imo',
    'ji' => 'jigawa',
    'kd' => 'kaduna',
    'kn' => 'kano',
    'kt' => 'katsina',
    'ke' => 'kebbi',
    'ko' => 'kogi',
    'kw' => 'kwara',
    'la' => 'lagos',
    'na' => 'nasarawa',
    'ni' => 'niger',
    'og' => 'ogun',
    'on' => 'ondo',
    'os' => 'osun',
    'oy' => 'oyo',
    'pl' => 'plateau',
    'ri' => 'rivers',
    'so' => 'sokoto',
    'ta' => 'taraba',
    'yo' => 'yobe',
    'za' => 'zamfara',

    // Common full-name variations
    'nassarawa' => 'nasarawa',
    'river state' => 'rivers',
];

    return $aliases[$state] ?? $state;
}
}