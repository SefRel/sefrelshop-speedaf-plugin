<?php
/**
 * Plugin Name: SefrelShop Speedaf Shipping
 * Description: Speedaf Shipping Integration for WooCommerce & Dokan
 * Version: 0.6.0
 * Author: Sefrel Technologies Ltd.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Core Classes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/class-speedaf-config.php';
require_once __DIR__ . '/includes/class-speedaf-encryption.php';
require_once __DIR__ . '/includes/class-speedaf-api.php';
require_once __DIR__ . '/includes/class-order-processor.php';
require_once __DIR__ . '/includes/class-plugin.php';

require_once __DIR__ . '/includes/helpers/class-order-builder.php';

require_once __DIR__ . '/includes/logistics/class-shipping-provider.php';
require_once __DIR__ . '/includes/logistics/class-speedaf-provider.php';
require_once __DIR__ . '/includes/logistics/class-logistics-manager.php';
require_once __DIR__ . '/includes/logistics/class-shipping-router.php';

/*
|--------------------------------------------------------------------------
| WooCommerce Order Hook
|--------------------------------------------------------------------------
*/

add_action(
    'woocommerce_order_status_processing',
    'sefrelshop_process_order',
    10,
    1
);

/**
 * Runs whenever an order
 * changes to Processing.
 */
function sefrelshop_process_order($order_id)
{
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    $plugin = new SefrelShopPlugin();

    $result = $plugin->processOrder($order);

   update_option(
    'sefrelshop_last_processing_result',
    wp_json_encode(
        $result,
        JSON_PRETTY_PRINT
    )
);

    // Temporary log for debugging.
    error_log(
        "SefrelShop: Processing Order #{$order_id}"
    );

    // Initialize processors and route order
    try {
        // Support different possible class names for the order processor.
        if (class_exists('Order_Processor')) {
            $processor = new Order_Processor();
        } elseif (class_exists('OrderProcessor')) {
            $processor = new OrderProcessor($order_id, $order);
        } else {
            throw new RuntimeException('Order processor class not found');
        }

        if (method_exists($processor, 'process')) {
            $processor->process($order_id);
        } else {
            throw new RuntimeException('Order processor does not have a process() method');
        }
    } catch (Exception $e) {
        error_log("SefrelShop: Error processing order #{$order_id}: " . $e->getMessage());
    }
}