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

/**
 * Runs whenever an order
 * changes to Processing.
 */
if (defined('ABSPATH') && function_exists('add_action')) {
    add_action(
        'woocommerce_order_status_processing',
        'sefrelshop_process_order',
        10,
        1
    );
}

function sefrelshop_process_order($order_id)
{
    error_log("[SefrelGO] Hook Fired for Order #{$order_id}");

    // Load WooCommerce order
    $order = wc_get_order($order_id);

    if (!$order) {

        error_log("[SefrelGO] Order not found.");

        return;

    }

    error_log("[SefrelGO] WooCommerce Order Loaded");

    // Create plugin instance
    $plugin = new SefrelShopPlugin();

    error_log("[SefrelGO] Plugin Initialised");

    // Process order
    $result = $plugin->processOrder($order);

    // Save result for inspection
    update_option(
        'sefrelshop_last_result',
        $result
    );

    error_log("[SefrelGO] Processing Complete");
}