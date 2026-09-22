<?php

if (!defined('ABSPATH')) {
    exit;
}

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
         * Confirm order received.
         */
        add_action(
            'admin_post_sefrelshop_confirm_order_received',
            [$this, 'confirmOrderReceived']
        );

        /*
         * Report delivery problem.
         */
        add_action(
            'admin_post_sefrelshop_report_delivery_problem',
            [$this, 'reportDeliveryProblem']
        );
    }

    /**
     * Render post-delivery customer actions.
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
         * Make sure this order belongs to the
         * currently logged-in customer.
         */
        if (
            (int) $order->get_user_id() !==
            get_current_user_id()
        ) {
            return;
        }

        /*
         * Must have a Speedaf waybill.
         */
        $billCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (empty($billCode)) {
            return;
        }

        /*
         * Current Speedaf status.
         */
        $status = strtolower(
            trim(
                (string) $order->get_meta(
                    '_speedaf_status',
                    true
                )
            )
        );

        /*
         * Only show these actions after delivery.
         */
        $deliveredStatuses = [
            '5',
            '16',
            'delivered',
            'delivered by franchisee',
        ];

        if (
            !in_array(
                $status,
                $deliveredStatuses,
                true
            )
        ) {
            return;
        }

        /*
         * Has customer already confirmed receipt?
         */
        $confirmed = $order->get_meta(
            '_sefrelshop_order_received_confirmed',
            true
        );

        /*
         * Has customer already reported a problem?
         */
        $problemReported = $order->get_meta(
            '_sefrelshop_delivery_problem_reported',
            true
        );
        ?>

        <section
            class="sefrelshop-delivery-confirmation"
            style="
                margin-top:30px;
                padding:24px;
                border:1px solid #e5e5e5;
                border-radius:8px;
                background:#fff;
            "
        >

            <?php if ($confirmed === 'yes') : ?>

                <div
                    style="
                        padding:16px;
                        border:1px solid #d9ead3;
                        border-radius:6px;
                        background:#f3faf1;
                    "
                >

                    <h3 style="margin-top:0;">
                        Order Received
                    </h3>

                    <p style="margin-bottom:0;">
                        Thank you. You have confirmed that your order was received.
                    </p>

                </div>

            <?php elseif ($problemReported === 'yes') : ?>

                <div
                    style="
                        padding:16px;
                        border:1px solid #f3d2d2;
                        border-radius:6px;
                        background:#fff7f7;
                    "
                >

                    <h3 style="margin-top:0;">
                        Problem Reported
                    </h3>

                    <p style="margin-bottom:0;">
                        Your delivery issue has been submitted to SefrelShop
                        and the relevant vendor. Our team will review it and
                        contact you where necessary.
                    </p>

                </div>

            <?php else : ?>

                <h3 style="margin-top:0;">
                    Has Your Order Arrived?
                </h3>

                <p>
                    Speedaf has reported that this shipment has been delivered.
                    Please confirm whether you received your order safely.
                </p>

                <div
                    style="
                        display:flex;
                        gap:12px;
                        flex-wrap:wrap;
                        margin-top:18px;
                    "
                >

                    <!-- Confirm Order -->

                    <form
                        method="post"
                        action="<?php echo esc_url(
                            admin_url('admin-post.php')
                        ); ?>"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="sefrelshop_confirm_order_received"
                        >

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?php echo esc_attr(
                                $order->get_id()
                            ); ?>"
                        >

                        <?php
                        wp_nonce_field(
                            'sefrelshop_confirm_order_received_' .
                            $order->get_id(),
                            '_sefrelshop_nonce'
                        );
                        ?>

                        <button
                            type="submit"
                            style="
                                padding:12px 18px;
                                border:0;
                                border-radius:5px;
                                cursor:pointer;
                                background:#009839;
                                color:#fff;
                                font-weight:600;
                            "
                        >
                            Confirm Order Received
                        </button>

                    </form>

                    <!-- Report Problem -->

                    <button
                        type="button"
                        onclick="document.getElementById('sefrelshop-delivery-problem-form-<?php echo esc_attr(
                            $order->get_id()
                        ); ?>').style.display='block';"
                        style="
                            padding:12px 18px;
                            border:1px solid #ccc;
                            border-radius:5px;
                            cursor:pointer;
                            background:#fff;
                            font-weight:600;
                        "
                    >
                        Report a Problem
                    </button>

                </div>

                <!-- Problem Form -->

                <div
                    id="sefrelshop-delivery-problem-form-<?php echo esc_attr(
                        $order->get_id()
                    ); ?>"
                    style="
                        display:none;
                        margin-top:20px;
                        padding-top:20px;
                        border-top:1px solid #eee;
                    "
                >

                    <form
                        method="post"
                        action="<?php echo esc_url(
                            admin_url('admin-post.php')
                        ); ?>"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="sefrelshop_report_delivery_problem"
                        >

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?php echo esc_attr(
                                $order->get_id()
                            ); ?>"
                        >

                        <?php
                        wp_nonce_field(
                            'sefrelshop_report_delivery_problem_' .
                            $order->get_id(),
                            '_sefrelshop_nonce'
                        );
                        ?>

                        <p>
                            <label
                                for="sefrelshop_problem_<?php echo esc_attr(
                                    $order->get_id()
                                ); ?>"
                            >
                                <strong>
                                    Please tell us what went wrong
                                </strong>
                            </label>
                        </p>

                        <textarea
                            id="sefrelshop_problem_<?php echo esc_attr(
                                $order->get_id()
                            ); ?>"
                            name="problem_message"
                            rows="5"
                            required
                            style="
                                width:100%;
                                padding:12px;
                                border:1px solid #ccc;
                                border-radius:5px;
                                resize:vertical;
                            "
                            placeholder="For example: My package was damaged, incomplete, or I did not receive the correct item."
                        ></textarea>

                        <button
                            type="submit"
                            style="
                                margin-top:12px;
                                padding:12px 18px;
                                border:0;
                                border-radius:5px;
                                cursor:pointer;
                                background:#222;
                                color:#fff;
                                font-weight:600;
                            "
                        >
                            Submit Problem Report
                        </button>

                    </form>

                </div>

            <?php endif; ?>

        </section>

        <?php
    }

    /**
     * Confirm order received.
     */
    public function confirmOrderReceived(): void
    {
        if (!is_user_logged_in()) {
            wp_die(
                esc_html__(
                    'You must be logged in to perform this action.',
                    'sefrelshop-speedaf'
                )
            );
        }

        $orderId = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$orderId) {
            $this->redirectToOrders();
        }

        $nonce = isset($_POST['_sefrelshop_nonce'])
            ? sanitize_text_field(
                wp_unslash($_POST['_sefrelshop_nonce'])
            )
            : '';

        if (
            !wp_verify_nonce(
                $nonce,
                'sefrelshop_confirm_order_received_' . $orderId
            )
        ) {
            wp_die(
                esc_html__(
                    'Security check failed.',
                    'sefrelshop-speedaf'
                )
            );
        }

        $order = wc_get_order($orderId);

        if (!$order) {
            wp_die(
                esc_html__(
                    'Order could not be found.',
                    'sefrelshop-speedaf'
                )
            );
        }

        /*
         * Ownership check.
         */
        if (
            (int) $order->get_user_id() !==
            get_current_user_id()
        ) {
            wp_die(
                esc_html__(
                    'You are not authorised to update this order.',
                    'sefrelshop-speedaf'
                )
            );
        }

        /*
         * Prevent repeated confirmation.
         */
        if (
            $order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            ) === 'yes'
        ) {
            $this->redirectBackToOrder($orderId);
        }

        /*
         * Save confirmation.
         */
        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_at',
            current_time('mysql')
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_by',
            get_current_user_id()
        );

        $order->save();

        /*
         * Add internal order note.
         */
        $order->add_order_note(
            sprintf(
                'Customer confirmed order received. Order #%s.',
                $orderId
            )
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
         * Return to the actual WooCommerce View Order page.
         */
        $this->redirectBackToOrder($orderId);
    }

    /**
     * Report delivery problem.
     */
    public function reportDeliveryProblem(): void
    {
        if (!is_user_logged_in()) {
            wp_die(
                esc_html__(
                    'You must be logged in to perform this action.',
                    'sefrelshop-speedaf'
                )
            );
        }

        $orderId = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$orderId) {
            $this->redirectToOrders();
        }

        $nonce = isset($_POST['_sefrelshop_nonce'])
            ? sanitize_text_field(
                wp_unslash($_POST['_sefrelshop_nonce'])
            )
            : '';

        if (
            !wp_verify_nonce(
                $nonce,
                'sefrelshop_report_delivery_problem_' . $orderId
            )
        ) {
            wp_die(
                esc_html__(
                    'Security check failed.',
                    'sefrelshop-speedaf'
                )
            );
        }

        $order = wc_get_order($orderId);

        if (!$order) {
            wp_die(
                esc_html__(
                    'Order could not be found.',
                    'sefrelshop-speedaf'
                )
            );
        }

        /*
         * Ownership check.
         */
        if (
            (int) $order->get_user_id() !==
            get_current_user_id()
        ) {
            wp_die(
                esc_html__(
                    'You are not authorised to update this order.',
                    'sefrelshop-speedaf'
                )
            );
        }

        $problemMessage = isset($_POST['problem_message'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['problem_message'])
            )
            : '';

        if (empty($problemMessage)) {
            wp_die(
                esc_html__(
                    'Please describe the problem before submitting.',
                    'sefrelshop-speedaf'
                )
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
            $problemMessage
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported_at',
            current_time('mysql')
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported_by',
            get_current_user_id()
        );

        $order->save();

        /*
         * Add internal WooCommerce order note.
         */
        $order->add_order_note(
            sprintf(
                "Customer reported a delivery problem:\n\n%s",
                $problemMessage
            )
        );

        /*
         * Notify SefrelShop administrator.
         */
        $this->notifyAdmin(
            $order,
            $problemMessage
        );

        /*
         * Notify relevant Dokan vendor(s).
         */
        $this->notifyVendors(
            $order,
            $problemMessage
        );

        /*
         * Return to the View Order page.
         */
        $this->redirectBackToOrder($orderId);
    }

    /**
     * Notify site administrator.
     */
    private function notifyAdmin(
        WC_Order $order,
        string $problemMessage
    ): void {

        $adminEmail = get_option('admin_email');

        if (empty($adminEmail)) {
            return;
        }

        $subject = sprintf(
            '[SefrelShop] Delivery Problem Reported — Order #%s',
            $order->get_id()
        );

        $message = sprintf(
            "A customer has reported a problem with a delivered order.\n\n" .
            "Order: #%s\n" .
            "Customer: %s\n" .
            "Email: %s\n" .
            "Speedaf Waybill: %s\n\n" .
            "Customer Report:\n%s\n\n" .
            "Please review the order and contact the customer where necessary.",
            $order->get_id(),
            $order->get_formatted_billing_full_name(),
            $order->get_billing_email(),
            $order->get_meta('_speedaf_bill_code', true),
            $problemMessage
        );

        wp_mail(
            $adminEmail,
            $subject,
            $message
        );
    }

    /**
     * Notify Dokan vendor(s) associated with the order.
     */
    private function notifyVendors(
        WC_Order $order,
        string $problemMessage
    ): void {

        $vendorIds = [];

        /*
         * Collect vendor IDs from order items.
         */
        foreach ($order->get_items() as $item) {

            /*
             * Dokan commonly stores the vendor ID
             * against each order item.
             */
            $vendorId = $item->get_meta(
                '_dokan_vendor_id',
                true
            );

            if (!empty($vendorId)) {
                $vendorIds[] = absint($vendorId);
                continue;
            }

            /*
             * Fallback: identify the product author.
             */
            $product = $item->get_product();

            if ($product) {

                $productVendorId = (int) get_post_field(
                    'post_author',
                    $product->get_id()
                );

                if ($productVendorId > 0) {
                    $vendorIds[] = $productVendorId;
                }
            }
        }

        /*
         * Remove duplicates.
         */
        $vendorIds = array_unique(
            array_filter($vendorIds)
        );

        if (empty($vendorIds)) {
            return;
        }

        $subject = sprintf(
            '[SefrelShop] Customer Delivery Problem — Order #%s',
            $order->get_id()
        );

        foreach ($vendorIds as $vendorId) {

            $vendor = get_userdata($vendorId);

            if (!$vendor || empty($vendor->user_email)) {
                continue;
            }

            $message = sprintf(
                "A customer has reported a problem with an order containing your product(s).\n\n" .
                "Order: #%s\n" .
                "Customer: %s\n" .
                "Customer Email: %s\n" .
                "Speedaf Waybill: %s\n\n" .
                "Customer Report:\n%s\n\n" .
                "Please review the order and contact SefrelShop/customer where necessary.",
                $order->get_id(),
                $order->get_formatted_billing_full_name(),
                $order->get_billing_email(),
                $order->get_meta('_speedaf_bill_code', true),
                $problemMessage
            );

            wp_mail(
                $vendor->user_email,
                $subject,
                $message
            );
        }
    }

    /**
     * Redirect customer to the WooCommerce View Order page.
     */
    private function redirectBackToOrder(int $orderId): void
    {
        /*
         * WooCommerce's dedicated View Order URL.
         */
        $url = wc_get_account_view_order_url(
            $orderId
        );

        /*
         * Fallback in case the WooCommerce helper
         * is unavailable.
         */
        if (empty($url)) {

            $url = wc_get_endpoint_url(
                'view-order',
                $orderId,
                wc_get_page_permalink('myaccount')
            );
        }

        wp_safe_redirect(
            $url
        );

        exit;
    }

    /**
     * Redirect to My Account orders.
     */
    private function redirectToOrders(): void
    {
        $url = wc_get_account_endpoint_url(
            'orders'
        );

        wp_safe_redirect(
            $url
        );

        exit;
    }
}