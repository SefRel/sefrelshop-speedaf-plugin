
<?php
if (!defined('ABSPATH')) {
    exit;
}

class SefrelShop_Shipment_Item_Builder
{
    private SefrelShop_Pickup_Location_Resolver $pickupResolver;

    public function __construct(
        ?SefrelShop_Pickup_Location_Resolver $pickupResolver = null
    ) {
        $this->pickupResolver = $pickupResolver
            ?? new SefrelShop_Pickup_Location_Resolver();
    }

    /**
     * Convert WooCommerce cart contents into shipment splitter inputs.
     *
     * @return array|WP_Error
     */
    public function build(array $cartContents)
    {
        $items = [];

        foreach ($cartContents as $cartItem) {
            $productId = absint($cartItem['product_id'] ?? 0);

            if (!$productId) {
                return new WP_Error(
                    'invalid_shipment_product',
                    'A cart item has no valid product ID.'
                );
            }

            if (function_exists('dokan_get_vendor_by_product')) {
                $vendor = dokan_get_vendor_by_product($productId);
                $vendorId = is_object($vendor) && method_exists($vendor, 'get_id')
                    ? (int) $vendor->get_id()
                    : 0;
            } else {
                $vendorId = (int) get_post_field('post_author', $productId);
            }

            if ($vendorId <= 0) {
                return new WP_Error(
                    'shipment_vendor_missing',
                    'Could not identify the vendor for product ' . $productId . '.'
                );
            }

            $location = $this->pickupResolver->resolve($productId, $vendorId);

            if (is_wp_error($location)) {
                return $location;
            }

            $product = $cartItem['data'] ?? null;

            if (!is_object($product) || !method_exists($product, 'get_weight')) {
                return new WP_Error(
                    'shipment_product_weight_missing',
                    'Could not read the weight for product ' . $productId . '.'
                );
            }

            $weight = (float) wc_get_weight(
                $product->get_weight(),
                'kg'
            );

            $quantity = absint($cartItem['quantity'] ?? 0);

            if ($weight <= 0 || $quantity <= 0) {
                return new WP_Error(
                    'shipment_item_invalid',
                    'Each shippable product needs a positive weight and quantity.'
                );
            }

            $length = (float) wc_get_dimension($product->get_length(), 'cm');
            $width  = (float) wc_get_dimension($product->get_width(), 'cm');
            $height = (float) wc_get_dimension($product->get_height(), 'cm');

            $items[] = [
                'vendor_id'      => $vendorId,
                'product_id'     => $productId,
                'pickup_state'   => $location['state'],
                'pickup_city'    => $location['city'],
                'unit_weight_kg' => $weight,
                'quantity'       => $quantity,
                'length_cm'      => $length,
                'width_cm'       => $width,
                'height_cm'      => $height,
                'parent_order_id' => 0,
                'sub_order_id'    => 0,
            ];
        }

        return $items;
    }
}