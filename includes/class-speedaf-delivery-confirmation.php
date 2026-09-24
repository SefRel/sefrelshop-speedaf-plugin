<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SefrelShop Speedaf Delivery Confirmation
 *
 * Handles:
 * - Customer confirms order received
 * - Customer reports delivery problem
 * - Vendor notification
 * - Admin notification
 * - WooCommerce order completion
 */
class SpeedafDeliveryConfirmation
{
    /**
     * Register hooks.
     */
    public function registerHooks(): void
    {
        /*
         * Customer-facing delivery actions.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderDeliveryActions'],
            30,
            1
        );

        /*
         * IMPORTANT:
         * We intentionally process the form through template_redirect
         * instead of admin-post.php.
         *
         * This avoids hosts/security plugins redirecting admin-post.php
         * requests back to the homepage.
         */
        add_action(
            'template_redirect',
            [$this, 'handleCustomerAction'],
            1
        );
    }

    /**
     * Process customer POST actions.
     */
    public function handleCustomerAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (empty($_POST['sefrelshop_delivery_action'])) {
            return;
        }

        $action = sanitize_key(
            wp_unslash($_POST['sefrelshop_delivery_action'])
        );

        /*
         * Only accept our two actions.
         */
        if (
            !in_array(
                $action,
                [
                    'confirm_order_received',
                    'report_delivery_problem'
                ],
                true
            )
        ) {
            return;
        }

        /*
         * Must be logged in.
         */
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        /*
         * Order ID.
         */
        $orderId = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$orderId) {
            wp_die(
                esc_html__('Invalid order ID.', 'sefrelshop-speedaf'),
                esc_html__('Delivery Confirmation Error', 'sefrelshop-speedaf'),
                ['response' => 400]
            );
        }

        /*
         * Load order.
         */
        $order = wc_get_order($orderId);

        if (!$order) {
            wp_die(
                esc_html__('WooCommerce order could not be found.', 'sefrelshop-speedaf'),
                esc_html__('Delivery Confirmation Error', 'sefrelshop-speedaf'),
                ['response' => 404]
            );
        }

        /*
         * Verify customer owns the order.
         */
        $currentUserId = get_current_user_id();

        if ((int) $order->get_user_id() !== (int) $currentUserId) {
            wp_die(
                esc_html__('You are not authorised to modify this order.', 'sefrelshop-speedaf'),
                esc_html__('Unauthorised', 'sefrelshop-speedaf'),
                ['response' => 403]
            );
        }

        /*
         * Verify nonce.
         */
        if (
            empty($_POST['sefrelshop_delivery_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['sefrelshop_delivery_nonce'])
                ),
                'sefrelshop_delivery_action_' . $orderId
            )
        ) {
            wp_die(
                esc_html__('Security verification failed. Please refresh the order page and try again.', 'sefrelshop-speedaf'),
                esc_html__('Security Error', 'sefrelshop-speedaf'),
                ['response' => 403]
            );
        }

        /*
         * Make sure this is actually a Speedaf order.
         */
        $billCode = $order->get_meta('_speedaf_bill_code', true);

        if (empty($billCode)) {
            wp_die(
                esc_html__('This order does not have a Speedaf shipment.', 'sefrelshop-speedaf'),
                esc_html__('Delivery Confirmation Error', 'sefrelshop-speedaf'),
                ['response' => 400]
            );
        }

        /*
         * Make sure Speedaf has marked it as delivered.
         */
        $speedafStatus = $order->get_meta('_speedaf_status', true);

        $deliveredStatuses = [
            '5',
            '16',
            'delivered',
            'delivered by franchisee'
        ];

        if (!in_array(strtolower((string) $speedafStatus), $deliveredStatuses, true)) {
            wp_die(
                esc_html__('This order has not yet been marked as delivered by Speedaf.', 'sefrelshop-speedaf'),
                esc_html__('Delivery Confirmation Error', 'sefrelshop-speedaf'),
                ['response' => 400]
            );
        }

        /*
         * Process requested action.
         */
        if ($action === 'confirm_order_received') {
            $this->confirmOrderReceived($order);
            return;
        }

        if ($action === 'report_delivery_problem') {
            $this->reportDeliveryProblem($order);
            return;
        }
    }

    /**
     * Render customer delivery confirmation section.
     */
    public function renderDeliveryActions($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        /*
         * Customer must be logged in.
         */
        if (!is_user_logged_in()) {
            return;
        }

        /*
         * Make sure this is the customer's own order.
         */
        if ((int) $order->get_user_id() !== (int) get_current_user_id()) {
            return;
        }

        /*
         * Speedaf waybill.
         */
        $billCode = $order->get_meta('_speedaf_bill_code', true);

        if (empty($billCode)) {
            return;
        }

        /*
         * Current Speedaf status.
         */
        $speedafStatus = strtolower(
            trim(
                (string) $order->get_meta(
                    '_speedaf_status',
                    true
                )
            )
        );

        /*
         * Only show this section after delivery.
         */
        $deliveredStatuses = [
            '5',
            '16',
            'delivered',
            'delivered by franchisee'
        ];

        if (!in_array($speedafStatus, $deliveredStatuses, true)) {
            return;
        }

        /*
         * Already confirmed.
         */
        $confirmed = $order->get_meta(
            '_sefrelshop_order_received_confirmed',
            true
        );

        /*
         * Problem already reported.
         */
        $problemReported = $order->get_meta(
            '_sefrelshop_delivery_problem_reported',
            true
        );

        echo '<section class="sefrelshop-delivery-confirmation" style="margin-top:30px;padding:25px;border:1px solid #e5e5e5;border-radius:8px;">';

        echo '<h2 style="margin-top:0;">';
        echo esc_html__('Delivery Confirmation', 'sefrelshop-speedaf');
        echo '</h2>';

        echo '<p>';
        echo esc_html__(
            'Speedaf has marked this order as delivered.',
            'sefrelshop-speedaf'
        );
        echo '</p>';

        /*
         * Already confirmed.
         */
        if ($confirmed === 'yes') {

            echo '<div style="padding:15px;background:#ecfdf3;border:1px solid #a7f3d0;border-radius:6px;">';

            echo '<strong>';
            echo esc_html__('Order Received', 'sefrelshop-speedaf');
            echo '</strong>';

            echo '<p style="margin-bottom:0;">';
            echo esc_html__(
                'Thank you. Your order has been confirmed as received.',
                'sefrelshop-speedaf'
            );
            echo '</p>';

            echo '</div>';

            echo '</section>';

            return;
        }

        /*
         * Problem already reported.
         */
        if ($problemReported === 'yes') {

            echo '<div style="padding:15px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;">';

            echo '<strong>';
            echo esc_html__('Problem Reported', 'sefrelshop-speedaf');
            echo '</strong>';

            echo '<p style="margin-bottom:0;">';
            echo esc_html__(
                'Your delivery problem has been submitted to SefrelShop and the relevant vendor.',
                'sefrelshop-speedaf'
            );
            echo '</p>';

            echo '</div>';

            echo '</section>';

            return;
        }

        /*
         * Customer confirmation form.
         */
        $orderUrl = wc_get_account_view_order_url($order->get_id());

        echo '<div style="margin-top:20px;">';

        echo '<p>';
        echo esc_html__(
            'Have you received this order safely?',
            'sefrelshop-speedaf'
        );
        echo '</p>';

        echo '<form method="post" action="' . esc_url($orderUrl) . '" style="display:inline-block;margin-right:10px;">';

        echo '<input type="hidden" name="sefrelshop_delivery_action" value="confirm_order_received">';

        echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';

        wp_nonce_field(
            'sefrelshop_delivery_action_' . $order->get_id(),
            'sefrelshop_delivery_nonce'
        );

        echo '<button type="submit" style="
            background:#009839;
            color:#ffffff;
            border:0;
            padding:12px 20px;
            border-radius:5px;
            cursor:pointer;
            font-weight:600;
        ">';
        echo esc_html__('Confirm Order Received', 'sefrelshop-speedaf');
        echo '</button>';

        echo '</form>';

        /*
         * Problem report form.
         */
        echo '<details style="display:inline-block;vertical-align:top;">';

        echo '<summary style="
            display:inline-block;
            background:#ffffff;
            color:#b42318;
            border:1px solid #b42318;
            padding:11px 20px;
            border-radius:5px;
            cursor:pointer;
            font-weight:600;
        ">';
        echo esc_html__('Report a Problem', 'sefrelshop-speedaf');
        echo '</summary>';

        echo '<form method="post" action="' . esc_url($orderUrl) . '" style="margin-top:15px;padding:15px;border:1px solid #eee;border-radius:6px;">';

        echo '<input type="hidden" name="sefrelshop_delivery_action" value="report_delivery_problem">';

        echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';

        wp_nonce_field(
            'sefrelshop_delivery_action_' . $order->get_id(),
            'sefrelshop_delivery_nonce'
        );

        echo '<label for="sefrelshop_delivery_problem">';
        echo '<strong>';
        echo esc_html__('Please describe the problem:', 'sefrelshop-speedaf');
        echo '</strong>';
        echo '</label>';

        echo '<textarea
            id="sefrelshop_delivery_problem"
            name="problem_message"
            rows="5"
            required
            style="width:100%;margin-top:8px;padding:10px;"
            placeholder="' .
            esc_attr__(
                'Tell us what happened with your delivery...',
                'sefrelshop-speedaf'
            ) .
            '"></textarea>';

        echo '<button type="submit" style="
            margin-top:10px;
            background:#b42318;
            color:#ffffff;
            border:0;
            padding:11px 20px;
            border-radius:5px;
            cursor:pointer;
            font-weight:600;
        ">';
        echo esc_html__('Submit Problem Report', 'sefrelshop-speedaf');
        echo '</button>';

        echo '</form>';

        echo '</details>';

        echo '</div>';

        echo '</section>';
    }

    /**
     * Confirm order received.
     */
    private function confirmOrderReceived(WC_Order $order): void
    {
        /*
         * Prevent duplicate confirmation.
         */
        if (
            $order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            ) === 'yes'
        ) {
            $this->redirectBackToOrder($order->get_id());
            return;
        }

        /*
         * Save confirmation.
         */
        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed_at',
            current_time('mysql')
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed_by',
            get_current_user_id()
        );

        $order->add_order_note(
            'Customer confirmed that the Speedaf order was received safely.'
        );

        /*
         * Complete the WooCommerce order.
         */
        if (!$order->has_status('completed')) {
            $order->update_status(
                'completed',
                'Customer confirmed receipt of the order.'
            );
        }

        /*
         * Save metadata.
         */
        $order->save();

        /*
         * Redirect back to the order page.
         */
        $this->redirectBackToOrder($order->get_id());
    }

    /**
     * Report delivery problem.
     */
    private function reportDeliveryProblem(WC_Order $order): void
    {
        $problem = '';

        if (isset($_POST['problem_message'])) {
            $problem = sanitize_textarea_field(
                wp_unslash($_POST['problem_message'])
            );
        }

        if (empty($problem)) {
            wp_die(
                esc_html__(
                    'Please describe the delivery problem before submitting the report.',
                    'sefrelshop-speedaf'
                ),
                esc_html__('Delivery Problem', 'sefrelshop-speedaf'),
                ['response' => 400]
            );
        }

        /*
         * Save report.
         */
        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_message',
            $problem
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported_at',
            current_time('mysql')
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported_by',
            get_current_user_id()
        );

        /*
         * Add order note.
         */
        $order->add_order_note(
            'Customer reported a delivery problem: ' . $problem
        );

        /*
         * Save before sending notifications.
         */
        $order->save();

        /*
         * Notify SefrelShop admin.
         */
        $this->notifyAdmin(
            $order,
            $problem
        );

        /*
         * Notify relevant Dokan vendor(s).
         */
        $this->notifyVendors(
            $order,
            $problem
        );

        /*
         * Redirect back to order.
         */
        $this->redirectBackToOrder($order->get_id());
    }

    /**
     * Notify site administrator.
     */
    private function notifyAdmin(
        WC_Order $order,
        string $problem
    ): void {
        $adminEmail = get_option('admin_email');

        if (empty($adminEmail)) {
            return;
        }

        $subject = sprintf(
            'Delivery Problem Reported - Order #%s',
            $order->get_order_number()
        );

        $message = '';

        $message .= "A customer has reported a delivery problem.\n\n";

        $message .= 'Order: #' . $order->get_order_number() . "\n";

        $message .= 'Customer: ' . $order->get_formatted_billing_full_name() . "\n";

        $message .= 'Email: ' . $order->get_billing_email() . "\n";

        $message .= 'Phone: ' . $order->get_billing_phone() . "\n";

        $message .= 'Speedaf Waybill: ' .
            $order->get_meta('_speedaf_bill_code', true) .
            "\n";

        $message .= "\nProblem reported:\n";

        $message .= $problem;

        wp_mail(
            $adminEmail,
            $subject,
            $message
        );
    }

    /**
     * Notify relevant Dokan vendors.
     */
    private function notifyVendors(
        WC_Order $order,
        string $problem
    ): void {
        $vendorIds = [];

        /*
         * Identify vendors from order items.
         */
        foreach ($order->get_items() as $item) {

            /*
             * First attempt: Dokan vendor item metadata.
             */
            $vendorId = $item->get_meta(
                '_dokan_vendor_id',
                true
            );

            if (!$vendorId) {
                $vendorId = $item->get_meta(
                    'dokan_vendor_id',
                    true
                );
            }

            /*
             * Fallback: product author.
             */
            if (!$vendorId) {

                $product = $item->get_product();

                if ($product) {
                    $productId = $product->get_id();

                    $vendorId = get_post_field(
                        'post_author',
                        $productId
                    );
                }
            }

            if ($vendorId) {
                $vendorIds[] = absint($vendorId);
            }
        }

        /*
         * Remove duplicates.
         */
        $vendorIds = array_unique(
            array_filter($vendorIds)
        );

        /*
         * Send notification to every vendor.
         */
        foreach ($vendorIds as $vendorId) {

            $vendor = get_userdata($vendorId);

            if (!$vendor || empty($vendor->user_email)) {
                continue;
            }

            $subject = sprintf(
                'Customer Delivery Problem - Order #%s',
                $order->get_order_number()
            );

            $message = '';

            $message .= "A customer has reported a problem with a delivered order.\n\n";

            $message .= 'Order: #' .
                $order->get_order_number() .
                "\n";

            $message .= 'Customer: ' .
                $order->get_formatted_billing_full_name() .
                "\n";

            $message .= 'Customer Email: ' .
                $order->get_billing_email() .
                "\n";

            $message .= 'Customer Phone: ' .
                $order->get_billing_phone() .
                "\n";

            $message .= 'Speedaf Waybill: ' .
                $order->get_meta('_speedaf_bill_code', true) .
                "\n";

            $message .= "\nDelivery Problem:\n";

            $message .= $problem;

            $message .= "\n\n";

            $message .= 'Please review this issue and contact the customer where necessary.';

            wp_mail(
                $vendor->user_email,
                $subject,
                $message
            );
        }
    }

    /**
     * Redirect customer back to WooCommerce order page.
     */
    private function redirectBackToOrder(int $orderId): void
    {
        $url = wc_get_account_view_order_url($orderId);

        /*
         * Fallback.
         */
        if (empty($url)) {
            $url = wc_get_endpoint_url(
                'view-order',
                $orderId,
                wc_get_page_permalink('myaccount')
            );
        }

        /*
         * Final fallback.
         */
        if (empty($url)) {
            $url = wc_get_page_permalink('myaccount');
        }

        wp_safe_redirect($url);
        exit;
    }
}