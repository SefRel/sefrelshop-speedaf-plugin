<?php

class OrderBuilder
{
    /**
     * Convert a WooCommerce order
     * into our internal shipment format.
     */
    public function build(array $data): array
    {
        if (
            empty($data['wc_order']) ||
            !($data['wc_order'] instanceof WC_Order)
        ) {
            throw new InvalidArgumentException(
                'A valid WooCommerce order is required.'
            );
        }

        /** @var WC_Order $order */
        $order = $data['wc_order'];

        $items = [];
        $categories = [];
        $totalWeight = 0;
        $totalValue = 0;

        foreach ($order->get_items() as $item) {

            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $quantity = (int) $item->get_quantity();

            $weight = (float) $product->get_weight();
            if ($weight <= 0) {
                $weight = 0.5; // Default weight if not set
            }
            $length = (float) $product->get_length();
            $width  = (float) $product->get_width();
            $height = (float) $product->get_height();

            $price = (float) $item->get_total();

            /**
             * Get product categories.
             */
            $terms = get_the_terms(
                $product->get_id(),
                'product_cat'
            );

            if (!empty($terms) && !is_wp_error($terms)) {

                foreach ($terms as $term) {

                    $categories[] = strtolower($term->slug);

                }

            }

            $items[] = [

                'product_id' => $product->get_id(),

                'sku' => $product->get_sku(),

                'goodsName' => $product->get_name(),

                'goodsType' => 'IT01',

                'goodsQTY' => $quantity,

                'goodsWeight' => $weight,

                'goodsLength' => $length,

                'goodsWidth' => $width,

                'goodsHigh' => $height,

                'goodsValue' => $price,

                'currencyType' => function_exists('get_woocommerce_currency')
                    ? get_woocommerce_currency()
                    : 'NGN',

                'unit' => 'pcs',

                'battery' => 0,

                'blInsure' => 0

            ];

            $totalWeight += ($weight * $quantity);
            $totalValue += $price;

        }

        // Ensure we have a customer name
        $customerName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        // Ensure we have a customer address
        $customerAddress = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());

        return [

            'order_id' => $order->get_id(),

            'customer_order_no' => $order->get_order_number(),

            'customer_name' => $customerName,

            'customer_phone' => $order->get_billing_phone(),

            'customer_email' => $order->get_billing_email(),

            'shipping_address' => $customerAddress,

            'shipping_city' => $order->get_shipping_city(),

            'shipping_state' => $order->get_shipping_state(),

            'shipping_country' => $order->get_shipping_country(),

            'items' => $items,

            'total_weight' => $totalWeight,

            'total_value' => $totalValue,

            'categories' => array_unique($categories)

        ];
    }
}