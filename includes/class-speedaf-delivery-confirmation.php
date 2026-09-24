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
         * Display delivery confirmation actions
         * on the WooCommerce customer order page.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderDeliveryActions'],
            30,
            1
        );

        /*
         * Process customer delivery actions.
         *
         * We intentionally use template_redirect instead of
         * admin-post.php because the previous admin-post flow
         * was redirecting the customer to the homepage before
         * the action could be completed.
         */
        add_action(
            'template_redirect',
            [$this, 'handleCustomerAction'],
            1
        );
    }

    /**
     * Process customer delivery actions.
     */
    public function handleCustomerAction(): void
    {
        /*
         * Only process POST requests.
         */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        /*
         * Check whether this is one of our forms.
         */
        if (empty($_POST['sefrelshop_delivery_action'])) {
            return;
        }

        $action = sanitize_key(
            wp_unslash($_POST['sefrelshop_delivery_action'])
        );

        /*
         * Only allow our two supported actions.
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
         * Customer must be logged in.
         */
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        /*
         * Get order ID.
         */
        $orderId = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$orderId) {
            wp_die(
                esc_html__(
                    'Invalid order ID.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Delivery Confirmation Error',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 400
                ]
            );
        }

        /*
         * Load WooCommerce order.
         */
        $order = wc_get_order($orderId);

        if (!$order) {
            wp_die(
                esc_html__(
                    'WooCommerce order could not be found.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Delivery Confirmation Error',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 404
                ]
            );
        }

        /*
         * Make sure the logged-in customer owns this order.
         */
        $currentUserId = get_current_user_id();

        if (
            (int) $order->get_user_id() !==
            (int) $currentUserId
        ) {
            wp_die(
                esc_html__(
                    'You are not authorised to modify this order.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Unauthorised',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 403
                ]
            );
        }

        /*
         * Verify security nonce.
         */
        if (
            empty($_POST['sefrelshop_delivery_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash(
                        $_POST['sefrelshop_delivery_nonce']
                    )
                ),
                'sefrelshop_delivery_action_' . $orderId
            )
        ) {
            wp_die(
                esc_html__(
                    'Security verification failed. Please refresh the order page and try again.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Security Error',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 403
                ]
            );
        }

        /*
         * Make sure this is a Speedaf order.
         */
        $billCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (empty($billCode)) {
            wp_die(
                esc_html__(
                    'This order does not have a Speedaf shipment.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Delivery Confirmation Error',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 400
                ]
            );
        }

        /*
         * Verify Speedaf has marked the order as delivered.
         */
        if (!$this->isOrderDelivered($order)) {
            wp_die(
                esc_html__(
                    'This order has not yet been marked as delivered by Speedaf.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Delivery Confirmation Error',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 400
                ]
            );
        }

        /*
         * Process confirmation.
         */
        if ($action === 'confirm_order_received') {
            $this->confirmOrderReceived($order);
            return;
        }

        /*
         * Process problem report.
         */
        if ($action === 'report_delivery_problem') {
            $this->reportDeliveryProblem($order);
            return;
        }
    }

    /**
     * Determine whether Speedaf has marked an order as delivered.
     *
     * Checks both the current Speedaf status and tracking history.
     */
    private function isOrderDelivered(WC_Order $order): bool
    {
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
         * Known delivered statuses.
         */
        $deliveredStatuses = [
            '5',
            '16',
            'delivered',
            'delivered by franchisee',
            'delivery completed',
            'completed'
        ];

        if (
            in_array(
                $speedafStatus,
                $deliveredStatuses,
                true
            )
        ) {
            return true;
        }

        /*
         * Check tracking history as a fallback.
         */
        $history = $order->get_meta(
            '_speedaf_tracking_history',
            true
        );

        /*
         * Support JSON history.
         */
        if (is_string($history)) {
            $decoded = json_decode(
                $history,
                true
            );

            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        if (!is_array($history)) {
            return false;
        }

        /*
         * Inspect tracking events.
         */
        foreach ($history as $event) {

            if (!is_array($event)) {
                continue;
            }

            $action = strtolower(
                trim(
                    (string) (
                        $event['action']
                        ?? $event['status']
                        ?? ''
                    )
                )
            );

            $subAction = strtolower(
                trim(
                    (string) (
                        $event['subAction']
                        ?? ''
                    )
                )
            );

            $message = strtolower(
                trim(
                    (string) (
                        $event['msgEng']
                        ?? $event['message']
                        ?? ''
                    )
                )
            );

            /*
             * Speedaf delivered status.
             */
            if (
                $action === '5' ||
                $action === '16' ||
                $action === 'delivered' ||
                $subAction === 'delivered'
            ) {
                return true;
            }

            /*
             * Explicit delivery wording.
             */
            if (
                strpos(
                    $message,
                    'delivered'
                ) !== false ||
                strpos(
                    $message,
                    'delivery completed'
                ) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render delivery confirmation actions.
     */
    public function renderDeliveryActions($order): void
    {
        /*
         * Validate WooCommerce order.
         */
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
         * Customer must own order.
         */
        if (
            (int) $order->get_user_id() !==
            (int) get_current_user_id()
        ) {
            return;
        }

        /*
         * This must be a Speedaf order.
         */
        $billCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (empty($billCode)) {
            return;
        }

        /*
         * Only display actions after Speedaf delivery.
         */
        if (!$this->isOrderDelivered($order)) {
            return;
        }

        /*
         * Check whether customer has already confirmed receipt.
         */
        $confirmed = $order->get_meta(
            '_sefrelshop_order_received_confirmed',
            true
        );

        /*
         * Check whether customer has already reported a problem.
         */
        $problemReported = $order->get_meta(
            '_sefrelshop_delivery_problem_reported',
            true
        );

        /*
         * Main section.
         */
        echo '<section class="sefrelshop-delivery-confirmation" style="
            margin-top:30px;
            padding:25px;
            border:1px solid #e5e5e5;
            border-radius:8px;
        ">';

        echo '<h2 style="margin-top:0;">';

        echo esc_html__(
            'Delivery Confirmation',
            'sefrelshop-speedaf'
        );

        echo '</h2>';

        /*
         * Customer already confirmed.
         */
        if ($confirmed === 'yes') {

            echo '<div style="
                padding:15px;
                background:#ecfdf3;
                border:1px solid #a7f3d0;
                border-radius:6px;
            ">';

            echo '<strong>';

            echo esc_html__(
                'Order Received',
                'sefrelshop-speedaf'
            );

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
         * Customer already reported a problem.
         */
        if ($problemReported === 'yes') {

            echo '<div style="
                padding:15px;
                background:#fff7ed;
                border:1px solid #fed7aa;
                border-radius:6px;
            ">';

            echo '<strong>';

            echo esc_html__(
                'Problem Reported',
                'sefrelshop-speedaf'
            );

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
         * Customer has not yet responded.
         */
        echo '<p>';

        echo esc_html__(
            'Speedaf has marked this order as delivered. Have you received your order safely?',
            'sefrelshop-speedaf'
        );

        echo '</p>';

        /*
         * WooCommerce View Order URL.
         */
        $orderUrl = wc_get_account_view_order_url(
            $order->get_id()
        );

        /*
         * -----------------------------------------------------
         * CONFIRM ORDER RECEIVED FORM
         * -----------------------------------------------------
         */

        echo '<form method="post"
            action="' . esc_url($orderUrl) . '"
            style="
                display:inline-block;
                margin-right:10px;
            ">';

        echo '<input
            type="hidden"
            name="sefrelshop_delivery_action"
            value="confirm_order_received"
        >';

        echo '<input
            type="hidden"
            name="order_id"
            value="' . esc_attr(
                $order->get_id()
            ) . '"
        >';

        wp_nonce_field(
            'sefrelshop_delivery_action_' .
            $order->get_id(),
            'sefrelshop_delivery_nonce'
        );

        echo '<button
            type="submit"
            style="
                background:#009839;
                color:#ffffff;
                border:0;
                padding:12px 20px;
                border-radius:5px;
                cursor:pointer;
                font-weight:600;
            "
        >';

        echo esc_html__(
            'Confirm Order Received',
            'sefrelshop-speedaf'
        );

        echo '</button>';

        echo '</form>';

        /*
         * -----------------------------------------------------
         * REPORT A PROBLEM
         * -----------------------------------------------------
         */

        echo '<details style="
            display:inline-block;
            vertical-align:top;
        ">';

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

        echo esc_html__(
            'Report a Problem',
            'sefrelshop-speedaf'
        );

        echo '</summary>';

        echo '<form method="post"
            action="' . esc_url($orderUrl) . '"
            style="
                margin-top:15px;
                padding:15px;
                border:1px solid #eeeeee;
                border-radius:6px;
            ">';

        echo '<input
            type="hidden"
            name="sefrelshop_delivery_action"
            value="report_delivery_problem"
        >';

        echo '<input
            type="hidden"
            name="order_id"
            value="' . esc_attr(
                $order->get_id()
            ) . '"
        >';

        wp_nonce_field(
            'sefrelshop_delivery_action_' .
            $order->get_id(),
            'sefrelshop_delivery_nonce'
        );

        echo '<label for="sefrelshop_delivery_problem">';

        echo '<strong>';

        echo esc_html__(
            'Please describe the problem:',
            'sefrelshop-speedaf'
        );

        echo '</strong>';

        echo '</label>';

        echo '<textarea
            id="sefrelshop_delivery_problem"
            name="problem_message"
            rows="5"
            required
            style="
                width:100%;
                margin-top:8px;
                padding:10px;
            "
            placeholder="' .
            esc_attr__(
                'Tell us what happened with your delivery...',
                'sefrelshop-speedaf'
            ) .
            '"
        ></textarea>';

        echo '<button
            type="submit"
            style="
                margin-top:10px;
                background:#b42318;
                color:#ffffff;
                border:0;
                padding:11px 20px;
                border-radius:5px;
                cursor:pointer;
                font-weight:600;
            "
        >';

        echo esc_html__(
            'Submit Problem Report',
            'sefrelshop-speedaf'
        );

        echo '</button>';

        echo '</form>';

        echo '</details>';

        echo '</section>';
    }

    /**
     * Confirm that the customer received the order.
     */
    private function confirmOrderReceived(
        WC_Order $order
    ): void {

        /*
         * Prevent duplicate confirmation.
         */
        if (
            $order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            ) === 'yes'
        ) {
            $this->redirectBackToOrder(
                $order->get_id()
            );

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

        /*
         * Add order note.
         */
        $order->add_order_note(
            'Customer confirmed that the Speedaf order was received safely.'
        );

        /*
         * Complete WooCommerce order.
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
         * Return to customer order page.
         */
        $this->redirectBackToOrder(
            $order->get_id()
        );
    }

    /**
     * Process delivery problem report.
     */
    private function reportDeliveryProblem(
        WC_Order $order
    ): void {

        /*
         * Get submitted problem.
         */
        $problem = '';

        if (isset($_POST['problem_message'])) {

            $problem = sanitize_textarea_field(
                wp_unslash(
                    $_POST['problem_message']
                )
            );
        }

        /*
         * Problem is required.
         */
        if (empty($problem)) {

            wp_die(
                esc_html__(
                    'Please describe the delivery problem before submitting the report.',
                    'sefrelshop-speedaf'
                ),
                esc_html__(
                    'Delivery Problem',
                    'sefrelshop-speedaf'
                ),
                [
                    'response' => 400
                ]
            );
        }

        /*
         * Save problem report.
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
            'Customer reported a delivery problem: ' .
            $problem
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
         * Notify relevant vendor(s).
         */
        $this->notifyVendors(
            $order,
            $problem
        );

        /*
         * Return to order page.
         */
        $this->redirectBackToOrder(
            $order->get_id()
        );
    }

    /**
     * Notify SefrelShop administrator.
     */
    private function notifyAdmin(
        WC_Order $order,
        string $problem
    ): void {

        $adminEmail = get_option(
            'admin_email'
        );

        if (empty($adminEmail)) {
            return;
        }

        $subject = sprintf(
            'Delivery Problem Reported - Order #%s',
            $order->get_order_number()
        );

        $message = '';

        $message .=
            "A customer has reported a delivery problem.\n\n";

        $message .=
            'Order: #' .
            $order->get_order_number() .
            "\n";

        $message .=
            'Customer: ' .
            $order->get_formatted_billing_full_name() .
            "\n";

        $message .=
            'Email: ' .
            $order->get_billing_email() .
            "\n";

        $message .=
            'Phone: ' .
            $order->get_billing_phone() .
            "\n";

        $message .=
            'Speedaf Waybill: ' .
            $order->get_meta(
                '_speedaf_bill_code',
                true
            ) .
            "\n";

        $message .=
            "\nProblem reported:\n";

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
             * Dokan vendor metadata.
             */
            $vendorId = $item->get_meta(
                '_dokan_vendor_id',
                true
            );

            /*
             * Alternative Dokan metadata.
             */
            if (!$vendorId) {

                $vendorId = $item->get_meta(
                    'dokan_vendor_id',
                    true
                );
            }

            /*
             * Product author fallback.
             */
            if (!$vendorId) {

                $product = $item->get_product();

                if ($product) {

                    $vendorId = get_post_field(
                        'post_author',
                        $product->get_id()
                    );
                }
            }

            if ($vendorId) {

                $vendorIds[] = absint(
                    $vendorId
                );
            }
        }

        /*
         * Remove duplicates.
         */
        $vendorIds = array_unique(
            array_filter(
                $vendorIds
            )
        );

        /*
         * Notify each vendor.
         */
        foreach ($vendorIds as $vendorId) {

            $vendor = get_userdata(
                $vendorId
            );

            if (
                !$vendor ||
                empty($vendor->user_email)
            ) {
                continue;
            }

            $subject = sprintf(
                'Customer Delivery Problem - Order #%s',
                $order->get_order_number()
            );

            $message = '';

            $message .=
                "A customer has reported a problem with a delivered order.\n\n";

            $message .=
                'Order: #' .
                $order->get_order_number() .
                "\n";

            $message .=
                'Customer: ' .
                $order->get_formatted_billing_full_name() .
                "\n";

            $message .=
                'Customer Email: ' .
                $order->get_billing_email() .
                "\n";

            $message .=
                'Customer Phone: ' .
                $order->get_billing_phone() .
                "\n";

            $message .=
                'Speedaf Waybill: ' .
                $order->get_meta(
                    '_speedaf_bill_code',
                    true
                ) .
                "\n";

            $message .=
                "\nDelivery Problem:\n";

            $message .= $problem;

            $message .=
                "\n\nPlease review this issue and contact the customer where necessary.";

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
    private function redirectBackToOrder(
        int $orderId
    ): void {

        /*
         * Preferred WooCommerce URL.
         */
        $url = wc_get_account_view_order_url(
            $orderId
        );

        /*
         * Fallback.
         */
        if (empty($url)) {

            $url = wc_get_endpoint_url(
                'view-order',
                $orderId,
                wc_get_page_permalink(
                    'myaccount'
                )
            );
        }

        /*
         * Final fallback.
         */
        if (empty($url)) {

            $url = wc_get_page_permalink(
                'myaccount'
            );
        }

        wp_safe_redirect(
            $url
        );

        exit;
    }
}