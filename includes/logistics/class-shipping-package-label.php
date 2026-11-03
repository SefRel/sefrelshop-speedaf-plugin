<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds effective pickup states to Dokan vendor shipping package labels.
 */
class SefrelShop_Shipping_Package_Label
{
    private SefrelShop_Pickup_Location_Resolver $pickupResolver;

    public function __construct(
        ?SefrelShop_Pickup_Location_Resolver $pickupResolver = null
    ) {
        $this->pickupResolver = $pickupResolver
            ?? new SefrelShop_Pickup_Location_Resolver();
    }

    /**
     * Register the Dokan shipping package label filter.
     */
    public function register(): void
    {
        add_filter(
            'dokan_shipping_package_name',
            [$this, 'appendPickupStates'],
            20,
            3
        );
    }

    /**
     * Append unique effective pickup states to the vendor label.
     */
   public function appendPickupStates( 
    $shippingLabel,
    $packageIndex,
    $package
): string {

        error_log('SEFRELSHOP: Dokan shipping label callback executed.');

        error_log(
            'SEFRELSHOP: Package data: ' . print_r($package, true)
        );

        $vendorId = absint($package['seller_id'] ?? 0);

        if ($vendorId <= 0 || empty($package['contents'])) {
            return $shippingLabel;
        }

        $states = [];

        foreach ($package['contents'] as $cartItem) {
            $productId = absint($cartItem['product_id'] ?? 0);

            if (!$productId) {
                continue;
            }

            $product = $cartItem['data'] ?? null;

            if (is_object($product) && method_exists($product, 'is_virtual')) {
                if ($product->is_virtual()) {
                    continue;
                }
            }

            $location = $this->pickupResolver->resolve(
                $productId,
                $vendorId
            );

           if (is_wp_error($location)) {
    error_log(
        sprintf(
            'SefrelShop Speedaf: Unable to resolve pickup location for product %d, vendor %d: %s',
            $productId,
            $vendorId,
            $location->get_error_message()
        )
    );

    return $shippingLabel;
}

            $state = trim((string) ($location['state'] ?? ''));

            if ($state === '') {
                continue;
            }

            $stateKey = strtolower($state);

            if (!isset($states[$stateKey])) {
                $states[$stateKey] = $state;
            }
        }

        if (empty($states)) {
            return $shippingLabel;
        }

        return sprintf(
            '%s (%s)',
            $shippingLabel,
            implode(', ', array_values($states))
        );
    }
}
