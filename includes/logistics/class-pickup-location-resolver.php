
<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves Speedaf pickup locations independently of Dokan geolocation.
 *
 * Product-specific pickup metadata takes priority over the vendor's
 * saved Dokan store address.
 */
class SefrelShop_Pickup_Location_Resolver
{
    private const PRODUCT_CITY_META  = '_sefrelshop_speedaf_pickup_city';
    private const PRODUCT_STATE_META = '_sefrelshop_speedaf_pickup_state';

    /**
     * Resolve a product's pickup location.
     *
     * @return array|WP_Error
     */
    public function resolve(int $product_id, int $vendor_id)
    {
        if ($product_id <= 0 || $vendor_id <= 0) {
            return new WP_Error(
                'invalid_pickup_reference',
                'A valid product and vendor are required.'
            );
        }

        // Product-level override takes priority.
        $product_city = trim((string) get_post_meta(
            $product_id,
            self::PRODUCT_CITY_META,
            true
        ));

        $product_state = trim((string) get_post_meta(
            $product_id,
            self::PRODUCT_STATE_META,
            true
        ));

        if ($product_city !== '' || $product_state !== '') {
            if ($product_city === '' || $product_state === '') {
                return new WP_Error(
                    'incomplete_product_pickup',
                    'The product pickup city and state must both be provided.'
                );
            }

            return $this->validateLocation($product_city, $product_state, 'product');
        }

        // Fallback to the vendor's saved store address.
        // This reads stored profile data; it does not require Dokan's
        // geolocation module to be active.
        $settings = get_user_meta(
            $vendor_id,
            'dokan_profile_settings',
            true
        );

        if (!is_array($settings) || !isset($settings['address'])
            || !is_array($settings['address'])) {
            return new WP_Error(
                'vendor_pickup_missing',
                'The vendor has no usable saved store address.'
            );
        }

        $address = $settings['address'];
        $city = trim((string) ($address['city'] ?? ''));
        $state = trim((string) ($address['state'] ?? ''));

        if ($city === '' || $state === '') {
            return new WP_Error(
                'vendor_pickup_incomplete',
                'The vendor store address must include both city and state.'
            );
        }

        return $this->validateLocation($city, $state, 'vendor');
    }

    /**
     * Normalize and validate the location against the existing zone resolver.
     *
     * @return array|WP_Error
     */

private function validateLocation(
    string $city,
    string $state,
    string $source
) {
    if (!class_exists('SpeedafZoneResolver')) {
        return new WP_Error(
            'zone_resolver_unavailable',
            'The Speedaf zone resolver is unavailable.'
        );
    }

    $zones = new SpeedafZoneResolver();
    $zone = $zones->resolveLocation($city, $state);

    if ($zone === null) {
        return new WP_Error(
            'unresolved_pickup_location',
            sprintf(
                'Pickup location "%s, %s" could not be mapped to a Speedaf tariff zone.',
                $city,
                $state
            )
        );
    }

    return [
        'city'       => $city,
        'state'      => $state,
        'zone'       => $zone,
        'source'     => $source,
        'product_id' => null,
    ];
}
}