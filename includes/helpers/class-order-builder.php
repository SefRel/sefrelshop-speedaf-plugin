<?php

class OrderBuilder
{
    /**
     * Convert a WooCommerce order
     * into our internal shipment format.
     */
    public function build(array $data): array
    {
        /**
         * Retrieve the WC_Order object.
         */
        $order = $data['wc_order'];

        $items = [];
        $totalWeight = 0;
        $totalValue = 0;
        $categories = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $weight = (float) $product->get_weight();
            $quantity = (int) $item->get_quantity();

            $totalWeight += ($weight * $quantity);
            $totalValue += $item->get_total();

            $terms = get_the_terms($product->get_id(), 'product_cat');

            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = strtolower($term->slug);
                }
            }

            $items[] = [
                'product_id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'quantity' => $quantity,
                'weight' => $weight,
                'price' => $item->get_total(),
            ];
        }

        return [
            'order_id' => $order->get_id(),
            'customer_order_no' => $order->get_order_number(),
            'customer_name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'customer_phone' => $order->get_billing_phone(),
            'customer_email' => $order->get_billing_email(),
            'shipping_address' => $order->get_shipping_address_1(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_country' => $order->get_shipping_country(),
            'items' => $items,
            'total_weight' => $totalWeight,
            'total_value' => $totalValue,
            'categories' => array_unique($categories),
        ];
    }
}