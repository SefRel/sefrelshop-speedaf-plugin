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
require_once __DIR__ . '/includes/class-speedaf-customer-tracking.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-speedaf-tracking-callback.php';

require_once __DIR__ . '/includes/helpers/class-order-builder.php';

require_once __DIR__ . '/includes/logistics/class-shipping-provider.php';
require_once __DIR__ . '/includes/logistics/class-speedaf-provider.php';
require_once __DIR__ . '/includes/logistics/class-logistics-manager.php';
require_once __DIR__ . '/includes/logistics/class-shipping-router.php';

require_once __DIR__ . '/includes/class-speedaf-tracking-sync.php';
require_once __DIR__ . '/includes/class-speedaf-tracking-callback.php';

/*
|--------------------------------------------------------------------------
| Speedaf Tracking Callback
|--------------------------------------------------------------------------
*/

if (function_exists('add_action')) {
    add_action(
        'rest_api_init',
        function () {

            $callback = new SpeedafTrackingCallback();

            $callback->registerRoutes();
        }
    );
}

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

        $order = wc_get_order($order_id);

        if (!$order) {

            update_option(
                'step_2',
                'Order NOT loaded'
            );

            return;
        }

        update_option(
            'step_2',
            'Order Loaded: #' . $order->get_id()
        );

        $plugin = new SefrelShopPlugin();

        update_option(
            'step_3',
            'Plugin Created'
        );

        $result = $plugin->processOrder($order);

        update_option(
            'step_4',
            wp_json_encode($result)
        );
    }
}

/**
 * Make customer phone number required at checkout.
 */
if (function_exists('add_filter')) {
    add_filter(
        'woocommerce_billing_fields',
        'sefrelshop_make_billing_phone_required',
        20
    );
}

function sefrelshop_make_billing_phone_required(
    array $fields
): array {

    if (isset($fields['billing_phone'])) {

        $fields['billing_phone']['required'] = true;

    }

    return $fields;
}

/**
 * Register customer-facing Speedaf tracking.
 */
add_action(
    'init',
    'sefrelshop_register_customer_tracking'
);

function sefrelshop_register_customer_tracking(): void
{
    $tracking = new SpeedafCustomerTracking();

    $tracking->registerHooks();
}




/**
 * TEMPORARY SPEEDAF TRACKING TEST
 *
 * Usage:
 * /wp-admin/?sefrelshop_test_tracking=27533
 */

if (function_exists('add_action')) {
    add_action(
        'admin_init',
        'sefrelshop_test_tracking'
    );
}

function sefrelshop_test_tracking()
{
    if (
        !current_user_can('manage_woocommerce')
    ) {
        return;
    }

    if (
        empty($_GET['sefrelshop_test_tracking'])
    ) {
        return;
    }

    $order_id = absint(
        $_GET['sefrelshop_test_tracking']
    );

    if (!$order_id) {
        wp_die(
            'Invalid WooCommerce order ID.'
        );
    }

    $order = wc_get_order(
        $order_id
    );

    if (!$order) {
        wp_die(
            'WooCommerce order not found.'
        );
    }

    $plugin = new SefrelShopPlugin();

    $result = $plugin->syncTracking(
        $order
    );

    echo '<pre>';
    echo esc_html(
        wp_json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    );
    echo '</pre>';

    exit;
}