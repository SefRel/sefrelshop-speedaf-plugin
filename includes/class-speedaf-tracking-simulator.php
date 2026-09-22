<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafTrackingSimulator
{
    public function registerHooks(): void
    {
        add_action(
            'admin_menu',
            [$this, 'registerMenu']
        );
    }

    /**
     * Register admin menu.
     */
    public function registerMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Speedaf Tracking Test',
            'Speedaf Tracking Test',
            'manage_woocommerce',
            'sefrelshop-speedaf-tracking-test',
            [$this, 'renderPage']
        );
    }

    /**
     * Render simulator page.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You do not have permission to access this page.', 'sefrelshop')
            );
        }

        $result = null;

        /*
         * Process simulation.
         */
        if (
            isset($_POST['sefrelshop_speedaf_simulate'])
            && check_admin_referer(
                'sefrelshop_speedaf_simulate_tracking',
                'sefrelshop_speedaf_nonce'
            )
        ) {
            $result = $this->simulate();
        }

        $orders = wc_get_orders(
            [
                'limit'   => 100,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            ]
        );

        $statuses = [
            '10'   => 'Order Confirmed',
            '1'    => 'Picked Up',
            '2'    => 'In Transit',
            '3'    => 'Arrived at Pickup Point',
            '4'    => 'Out for Delivery',
            '5'    => 'Delivered',
            '16'   => 'Delivered by Franchisee',
            '-710' => 'Returning',
            '730'  => 'Returned',
            '401'  => 'Clearance Exception',
        ];
        ?>

        <div class="wrap">

            <h1>Speedaf Tracking Test</h1>

            <p>
                Use this tool to simulate Speedaf tracking updates for testing.
                Simulated events are processed through the same tracking callback
                used by real Speedaf webhook events.
            </p>

            <?php if (is_array($result)): ?>

                <?php if (!empty($result['success'])): ?>

                    <div class="notice notice-success is-dismissible">
                        <p>
                            <strong>Tracking update simulated successfully.</strong>
                        </p>

                        <?php if (!empty($result['message'])): ?>
                            <p>
                                <?php echo esc_html($result['message']); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong>Simulation failed.</strong>
                        </p>

                        <?php if (!empty($result['message'])): ?>
                            <p>
                                <?php echo esc_html($result['message']); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            <?php endif; ?>

            <div
                style="
                    max-width:800px;
                    background:#fff;
                    padding:25px;
                    margin-top:20px;
                    border:1px solid #ddd;
                "
            >

                <form method="post">

                    <?php
                    wp_nonce_field(
                        'sefrelshop_speedaf_simulate_tracking',
                        'sefrelshop_speedaf_nonce'
                    );
                    ?>

                    <table class="form-table">

                        <tr>
                            <th scope="row">
                                <label for="speedaf_order_id">
                                    WooCommerce Order
                                </label>
                            </th>

                            <td>

                                <select
                                    name="order_id"
                                    id="speedaf_order_id"
                                    required
                                    style="min-width:400px;"
                                >

                                    <option value="">
                                        Select an order
                                    </option>

                                    <?php foreach ($orders as $order): ?>

                                        <?php
                                        $billCode = $order->get_meta(
                                            '_speedaf_bill_code',
                                            true
                                        );

                                        $customerOrderNo = $order->get_meta(
                                            '_speedaf_customer_order_no',
                                            true
                                        );
                                        ?>

                                        <?php if (empty($billCode)): ?>
                                            <?php continue; ?>
                                        <?php endif; ?>

                                        <option
                                            value="<?php echo esc_attr($order->get_id()); ?>"
                                        >
                                            <?php
                                            echo esc_html(
                                                '#' .
                                                $order->get_id() .
                                                ' — ' .
                                                $billCode
                                            );
                                            ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <p class="description">
                                    Only orders with a Speedaf waybill are shown.
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="speedaf_action">
                                    Tracking Status
                                </label>
                            </th>

                            <td>

                                <select
                                    name="action"
                                    id="speedaf_action"
                                    required
                                    style="min-width:400px;"
                                >

                                    <?php foreach ($statuses as $code => $label): ?>

                                        <option
                                            value="<?php echo esc_attr($code); ?>"
                                        >
                                            <?php
                                            echo esc_html(
                                                $code . ' — ' . $label
                                            );
                                            ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="speedaf_message">
                                    Tracking Message
                                </label>
                            </th>

                            <td>

                                <input
                                    type="text"
                                    name="message"
                                    id="speedaf_message"
                                    class="regular-text"
                                    value=""
                                    placeholder="e.g. Parcel is on the way to the buyer."
                                />

                                <p class="description">
                                    Optional. This will appear on the customer's
                                    tracking timeline.
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="speedaf_time">
                                    Tracking Time
                                </label>
                            </th>

                            <td>

                                <input
                                    type="datetime-local"
                                    name="tracking_time"
                                    id="speedaf_time"
                                    value="<?php echo esc_attr(
                                        current_time('Y-m-d\TH:i')
                                    ); ?>"
                                />

                                <p class="description">
                                    Leave as the current time or change it to
                                    simulate an earlier/later event.
                                </p>

                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="speedaf_country">
                                    Country
                                </label>
                            </th>

                            <td>

                                <input
                                    type="text"
                                    name="country"
                                    id="speedaf_country"
                                    class="regular-text"
                                    value="Nigeria"
                                />

                            </td>
                        </tr>

                    </table>

                    <p>

                        <button
                            type="submit"
                            name="sefrelshop_speedaf_simulate"
                            class="button button-primary"
                        >
                            Simulate Tracking Update
                        </button>

                    </p>

                </form>

            </div>

            <div
                style="
                    max-width:800px;
                    background:#fff;
                    padding:25px;
                    margin-top:20px;
                    border:1px solid #ddd;
                "
            >

                <h2>Testing Workflow</h2>

                <ol>

                    <li>
                        Select a WooCommerce order with a Speedaf waybill.
                    </li>

                    <li>
                        Select <strong>Order Confirmed</strong>.
                    </li>

                    <li>
                        Click <strong>Simulate Tracking Update</strong>.
                    </li>

                    <li>
                        Open the customer's order page.
                    </li>

                    <li>
                        Repeat with:
                        <strong>
                            Picked Up → In Transit → Arrived →
                            Out for Delivery → Delivered
                        </strong>.
                    </li>

                </ol>

                <p>
                    This does not send anything to Speedaf. It only simulates
                    the webhook response inside SefrelShop.
                </p>

            </div>

        </div>

        <?php
    }

    /**
     * Simulate a Speedaf callback.
     */
    private function simulate(): array
    {
        $orderId = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        $action = isset($_POST['action'])
            ? sanitize_text_field(
                wp_unslash($_POST['action'])
            )
            : '';

        $message = isset($_POST['message'])
            ? sanitize_text_field(
                wp_unslash($_POST['message'])
            )
            : '';

        $trackingTime = isset($_POST['tracking_time'])
            ? sanitize_text_field(
                wp_unslash($_POST['tracking_time'])
            )
            : '';

        $country = isset($_POST['country'])
            ? sanitize_text_field(
                wp_unslash($_POST['country'])
            )
            : 'Nigeria';

        if (!$orderId) {
            return [
                'success' => false,
                'message' => 'Please select a WooCommerce order.',
            ];
        }

        if ($action === '') {
            return [
                'success' => false,
                'message' => 'Please select a tracking status.',
            ];
        }

        $order = wc_get_order($orderId);

        if (!$order) {
            return [
                'success' => false,
                'message' => 'WooCommerce order not found.',
            ];
        }

        $mailNo = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (empty($mailNo)) {
            return [
                'success' => false,
                'message' => 'This order does not have a Speedaf waybill.',
            ];
        }

        /*
         * Convert datetime-local value to a format
         * compatible with the existing callback.
         */
        if (!empty($trackingTime)) {

            $timestamp = strtotime($trackingTime);

            if ($timestamp) {
                $trackingTime = wp_date(
                    'Y-m-d H:i:s',
                    $timestamp
                );
            }

        } else {
            $trackingTime = current_time('mysql');
        }

        if (empty($message)) {

            $defaultMessages = [
                '10'   => 'Order confirmed.',
                '1'    => 'Parcel picked up.',
                '2'    => 'Parcel departed from sorting centre.',
                '3'    => 'Parcel arrived at buyer pickup point.',
                '4'    => 'Parcel is on the way to the buyer’s location.',
                '5'    => 'Parcel delivered successfully.',
                '16'   => 'Parcel delivered by franchisee.',
                '-710' => 'Parcel is being returned.',
                '730'  => 'Parcel has been returned.',
                '401'  => 'Clearance exception.',
            ];

            $message = $defaultMessages[$action]
                ?? 'Speedaf tracking update.';
        }

        /*
         * Build the same basic structure that Speedaf
         * sends to the production callback.
         */
        $event = [
            'mailNo'      => $mailNo,
            'action'      => $action,
            'subAction'   => '',
            'message'     => $message,
            'msgEng'      => $message,
            'msgLoc'      => $message,
            'time'        => $trackingTime,
            'country'     => $country,
            'countryCode' => 'NG',
            'pictureUrl'  => '',
        ];

        /*
         * Send the simulated event through the existing
         * Speedaf callback handler.
         */
        $request = new WP_REST_Request(
            'POST',
            '/sefrelshop/v1/speedaf/tracking'
        );

        $request->set_body(
            wp_json_encode($event)
        );

        $callback = new SpeedafTrackingCallback();

        $response = $callback->handle($request);

        if ($response instanceof WP_REST_Response) {

            $responseData = $response->get_data();

            if (
                isset($responseData['processed'])
                && $responseData['processed'] > 0
            ) {

                return [
                    'success' => true,
                    'message' =>
                        'Order #' .
                        $orderId .
                        ' updated to Speedaf status ' .
                        $action .
                        '.',
                ];
            }

            if (
                isset($responseData['duplicates'])
                && $responseData['duplicates'] > 0
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'This exact tracking event already exists in the order history.',
                ];
            }

            if (
                isset($responseData['failed'])
                && $responseData['failed'] > 0
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'The tracking callback could not process this event.',
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Unexpected response from tracking callback.',
        ];
    }
}