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
        $totalWeight = 0.0;
        $totalValue = 0.0;

        /*
         * --------------------------------------------------------------
         * Customer information
         * --------------------------------------------------------------
         */

        $customerName = trim(
            $order->get_shipping_first_name() . ' ' .
            $order->get_shipping_last_name()
        );

        if (empty($customerName)) {
            $customerName = trim(
                $order->get_billing_first_name() . ' ' .
                $order->get_billing_last_name()
            );
        }

        $customerPhone = trim(
            $order->get_billing_phone()
        );

        $customerEmail = trim(
            $order->get_billing_email()
        );

        /*
         * --------------------------------------------------------------
         * Shipping address
         * --------------------------------------------------------------
         */

        $shippingAddress = trim(
            $order->get_shipping_address_1()
        );

        if (empty($shippingAddress)) {
            $shippingAddress = trim(
                $order->get_billing_address_1()
            );
        }

        $shippingAddress2 = trim(
            $order->get_shipping_address_2()
        );

        if (!empty($shippingAddress2)) {
            $shippingAddress .= ' ' . $shippingAddress2;
        }

        if (empty($shippingAddress)) {
            $shippingAddress = trim(
                $order->get_billing_address_1()
            );

            $billingAddress2 = trim(
                $order->get_billing_address_2()
            );

            if (!empty($billingAddress2)) {
                $shippingAddress .= ' ' . $billingAddress2;
            }
        }

        /*
         * --------------------------------------------------------------
         * Location
         * --------------------------------------------------------------
         */

        $shippingCity = trim(
            $order->get_shipping_city()
        );

        if (empty($shippingCity)) {
            $shippingCity = trim(
                $order->get_billing_city()
            );
        }

        $shippingState = trim(
            $order->get_shipping_state()
        );

        /*
         * Speedaf requires a state.
         *
         * If the shipping state is empty,
         * attempt to use the billing state.
         */
        if (empty($shippingState)) {
            $shippingState = trim(
                $order->get_billing_state()
            );
        }

        $shippingCountry = strtoupper(
            trim($order->get_shipping_country())
        );

        if (empty($shippingCountry)) {
            $shippingCountry = strtoupper(
                trim($order->get_billing_country())
            );
        }

        /*
         * --------------------------------------------------------------
         * Validate required shipment information
         * --------------------------------------------------------------
         */

        if (empty($customerName)) {
            throw new InvalidArgumentException(
                'Customer name is required before creating a shipment.'
            );
        }

        if (empty($customerPhone)) {
            throw new InvalidArgumentException(
                'Customer phone number is required before creating a shipment.'
            );
        }

        if (empty($shippingAddress)) {
            throw new InvalidArgumentException(
                'Shipping address is required before creating a shipment.'
            );
        }

        if (empty($shippingCity)) {
            throw new InvalidArgumentException(
                'Shipping city is required before creating a shipment.'
            );
        }

        if (empty($shippingState)) {
            throw new InvalidArgumentException(
                'Shipping state is required before creating a shipment.'
            );
        }

        if (empty($shippingCountry)) {
            throw new InvalidArgumentException(
                'Shipping country is required before creating a shipment.'
            );
        }

        /*
         * --------------------------------------------------------------
         * Build order items
         * --------------------------------------------------------------
         */

        foreach ($order->get_items() as $item) {

            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $quantity = (int) $item->get_quantity();

            if ($quantity <= 0) {
                continue;
            }

            /*
             * Product weight.
             *
             * WooCommerce stores product weight
             * according to the store's configured unit.
             */
            $weight = (float) $product->get_weight();

            /*
             * Temporary fallback.
             *
             * We will later replace this with
             * proper product/shipping validation.
             */
            if ($weight <= 0) {
                $weight = 0.5;
            }

            $length = (float) $product->get_length();
            $width  = (float) $product->get_width();
            $height = (float) $product->get_height();

            $price = (float) $item->get_total();

            /*
             * ----------------------------------------------------------
             * Product categories
             * ----------------------------------------------------------
             */

            $terms = get_the_terms(
                $product->get_id(),
                'product_cat'
            );

            if (
                !empty($terms) &&
                !is_wp_error($terms)
            ) {

                foreach ($terms as $term) {

                    $categories[] = strtolower(
                        $term->slug
                    );

                }
            }

            /*
             * ----------------------------------------------------------
             * Shipment item
             * ----------------------------------------------------------
             */

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

                'currencyType' => function_exists(
                    'get_woocommerce_currency'
                )
                    ? get_woocommerce_currency()
                    : 'NGN',

                'unit' => 'pcs',

                'battery' => 0,

                'blInsure' => 0

            ];

            $totalWeight += (
                $weight * $quantity
            );

            $totalValue += $price;
        }

        /*
         * --------------------------------------------------------------
         * Validate items
         * --------------------------------------------------------------
         */

        if (empty($items)) {
            throw new InvalidArgumentException(
                'The order contains no valid shippable products.'
            );
        }

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException(
                'Shipment weight must be greater than zero.'
            );
        }

        /*
         * --------------------------------------------------------------
         * Return standardized shipment
         * --------------------------------------------------------------
         */

        return [

            'order_id' => $order->get_id(),

            'customer_order_no' => $order->get_order_number(),

            'customer_name' => $customerName,

            'customer_phone' => $customerPhone,

            'customer_email' => $customerEmail,

            'shipping_address' => $shippingAddress,

            'shipping_city' => $shippingCity,

            'shipping_state' => $shippingState,

            'shipping_country' => $shippingCountry,

            'items' => $items,

            'total_weight' => $totalWeight,

            'total_value' => $totalValue,

            'categories' => array_values(
                array_unique($categories)
            )

        ];
    }
}