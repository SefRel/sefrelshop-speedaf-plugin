
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce shipping method using the agreed Speedaf tariff.
 *
 * This calculates checkout rates only. It does not create shipments
 * or call the Speedaf pricing API.
 */
class SefrelShop_Speedaf_Shipping_Method extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'sefrelshop_speedaf';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'Speedaf Tariff';
        $this->method_description = 'Calculate Speedaf shipping using the agreed local tariff.';
        $this->supports           = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];

        $this->init();
    }

    public function init()
    {
        $this->init_instance_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title   = $this->get_option('title', 'Speedaf Shipping');

        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public function init_instance_form_fields()
    {
        $this->instance_form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Speedaf shipping',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'Method title',
                'type'        => 'text',
                'description' => 'Displayed to customers at checkout.',
                'default'     => 'Speedaf Shipping',
                'desc_tip'    => true,
            ],
        ];
    }





public function calculate_shipping($package = [])
{ error_log('[SefrelShop Speedaf] calculate_shipping() reached');
   $log = static function ($message) {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->debug(
            $message,
            ['source' => 'sefrelshop-speedaf']
        );
    }
};

    $log('Calculation started.');

    if ($this->enabled !== 'yes') {
        $log('STOP: Shipping method is disabled.');
        return;
    }

    if (
        !class_exists('SefrelShop_Shipment_Item_Builder') ||
        !class_exists('SefrelShop_Shipment_Splitter') ||
        !class_exists('SefrelShop_Shipment_Pricing')
    ) {
        $log('STOP: One or more required classes are not loaded.');
        return;
    }

    $contents = $package['contents'] ?? [];

    if (empty($contents) || !is_array($contents)) {
        $log('STOP: Package contents are empty or invalid.');
        return;
    }

    $destination = $package['destination'] ?? [];

    $deliveryCity = sanitize_text_field($destination['city'] ?? '');
    $deliveryState = sanitize_text_field($destination['state'] ?? '');

    if ($deliveryCity === '' || $deliveryState === '') {
        $log('STOP: Delivery city or state is missing. City=' .
            $deliveryCity . '; State=' . $deliveryState);
        return;
    }

    try {
        $builder = new SefrelShop_Shipment_Item_Builder();
        $items = $builder->build($contents);

        if (is_wp_error($items)) {
            $log('STOP: Item builder returned WP_Error: ' .
                $items->get_error_message());
            return;
        }

        if (empty($items)) {
            $log('STOP: Item builder returned no items.');
            return;
        }

        $log('Items built successfully. Count=' . count($items));

        $splitter = new SefrelShop_Shipment_Splitter();
        $groups = $splitter->groupAndSplit($items);

        if (empty($groups)) {
            $log('STOP: Shipment splitter returned no groups.');
            return;
        }

        $log('Shipment groups created. Count=' . count($groups));

        $pricing = new SefrelShop_Shipment_Pricing();
        $totalAmount = 0.0;

        foreach ($groups as $index => $group) {
            $log('Pricing group ' . ($index + 1) . '.');

            $result = $pricing->priceGroup(
                $group,
                $deliveryCity,
                $deliveryState
            );

            $groupTotal = $result['total_amount'] ?? null;

            if (!is_numeric($groupTotal) || (float) $groupTotal <= 0) {
                $log('STOP: Pricing returned an invalid total for group ' .
                    ($index + 1) . '. Result=' .
                    wp_json_encode($result));
                return;
            }

            $totalAmount += (float) $groupTotal;
        }

        if ($totalAmount <= 0) {
            $log('STOP: Final shipping total is zero or negative.');
            return;
        }

        $this->add_rate([
            'id'      => $this->get_rate_id(),
            'label'   => $this->title,
            'cost'    => $totalAmount,
            'package' => $package,
        ]);

        $log('SUCCESS: Shipping rate added. Total=' . $totalAmount);

    } catch (\Throwable $exception) {
        $log('EXCEPTION: ' . get_class($exception) . ': ' .
            $exception->getMessage() . ' in ' .
            $exception->getFile() . ':' . $exception->getLine());
        return;
    }
}



    /**
     * Temporary explicit configuration hook.
     *
     * Replace this with verified vendor pickup-location lookup
     * when the Dokan vendor address source has been confirmed.
     */
    private function getPickupZone(array $package): ?int
    {
        $zone = apply_filters(
            'sefrelshop_speedaf_pickup_zone',
            null,
            $package
        );

        if (
            filter_var($zone, FILTER_VALIDATE_INT) === false ||
            (int) $zone < 1 ||
            (int) $zone > 4
        ) {
            return null;
        }

        return (int) $zone;
    }

    /**
     * Sum WooCommerce product weights in kilograms.
     */
    private function getPackageWeight(array $package): float
    {
        $total = 0.0;

        foreach (($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            $quantity = max(0, (int) ($item['quantity'] ?? 0));

            if (!$product || !is_object($product)) {
                continue;
            }

            $product_weight = $product->get_weight();

            if ($product_weight === '' || !is_numeric($product_weight)) {
                return 0.0;
            }

            $weight_kg = (float) wc_get_weight(
                (float) $product_weight,
                'kg'
            );

            $total += $weight_kg * $quantity;
        }

        return $total;
    }
}