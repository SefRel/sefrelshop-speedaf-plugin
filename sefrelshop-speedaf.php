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

if (!function_exists('sefrelshop_process_order')) {
    function sefrelshop_process_order($order_id)
    {
        update_option('step_1', 'Hook Fired');
        return;
    }
}