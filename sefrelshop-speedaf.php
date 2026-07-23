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
    // Save the last processed order ID.
    update_option(
        'sefrelshop_last_processing_order',
        $order_id
    );

    // Temporary log for debugging.
    error_log(
        "SefrelShop: Processing Order #{$order_id}"
    );

    // Initialize processors and route order
    try {
        $processor = new Order_Processor();
        $processor->process($order_id);
    } catch (Exception $e) {
        error_log("SefrelShop: Error processing order #{$order_id}: " . $e->getMessage());
    }
}