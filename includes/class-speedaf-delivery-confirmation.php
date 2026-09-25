<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafDeliveryConfirmation
{
    /**
     * Post-delivery inspection/return window.
     * 3 days = 72 hours.
     */
    private const INSPECTION_WINDOW_SECONDS = 3 * DAY_IN_SECONDS;

    private const ACTION_HOOK = 'sefrelshop_auto_complete_delivered_order';

    private const ACTION_GROUP = 'sefrelshop-speedaf';

    /**
     * Register all hooks.
     */
    public function registerHooks(): void
    {
        /*
         * Register custom WooCommerce order status.
         */
        $this->registerDeliveredStatus();

        add_filter(
            'woocommerce_order_statuses',
            [$this, 'addDeliveredStatusToList']
        );

        /*
         * Treat Delivered as a paid status.
         */
        add_filter(
            'woocommerce_order_is_paid_statuses',
            [$this, 'addDeliveredAsPaidStatus']
        );

        /*
         * Include Delivered in WooCommerce reporting.
         */
        add_filter(
            'woocommerce_reports_order_statuses',
            [$this, 'addDeliveredToReports']
        );

        /*
         * Customer delivery actions.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderDeliveryActions'],
            20
        );

        /*
         * Handle customer POST actions before normal template output.
         */
        add_action(
            'template_redirect',
            [$this, 'handleCustomerAction'],
            1
        );

        /*
         * Automatic completion after the 72-hour window.
         */
        add_action(
            self::ACTION_HOOK,
            [$this, 'autoCompleteDeliveredOrder']
        );
    }

    /**
     * Register "Delivered" WooCommerce order status.
     */
    private function registerDeliveredStatus(): void
    {
        register_post_status(
            'wc-delivered',
            [
                'label'                     => 'Delivered',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Delivered <span class="count">(%s)</span>',
                    'Delivered <span class="count">(%s)</span>',
                    'woocommerce'
                ),
            ]
        );
    }

    /**
     * Add Delivered to WooCommerce order status list.
     */
    public function addDeliveredStatusToList(array $statuses): array
    {
        $new_statuses = [];

        foreach ($statuses as $key => $label) {
            $new_statuses[$key] = $label;

            /*
             * Place Delivered immediately after Processing.
             */
            if ($key === 'wc-processing') {
                $new_statuses['wc-delivered'] = 'Delivered';
            }
        }

        /*
         * Fallback in case Processing was not found.
         */
        if (!isset($new_statuses['wc-delivered'])) {
            $new_statuses['wc-delivered'] = 'Delivered';
        }

        return $new_statuses;
    }

    /**
     * Treat Delivered as a paid order status.
     */
    public function addDeliveredAsPaidStatus(array $statuses): array
    {
        if (!in_array('delivered', $statuses, true)) {
            $statuses[] = 'delivered';
        }

        return $statuses;
    }

    /**
     * Include Delivered in WooCommerce reports.
     */
    public function addDeliveredToReports(array $statuses): array
    {
        if (!in_array('wc-delivered', $statuses, true)) {
            $statuses[] = 'wc-delivered';
        }

        return $statuses;
    }

    /**
     * Handle customer delivery actions.
     */
    public function handleCustomerAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (empty($_POST['sefrelshop_delivery_action'])) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $action = sanitize_key(
            wp_unslash($_POST['sefrelshop_delivery_action'])
        );

        if (!in_array(
            $action,
            [
                'confirm_order_received',
                'report_delivery_problem'
            ],
            true
        )) {
            return;
        }

        $order_id = isset($_POST['order_id'])
            ? absint($_POST['order_id'])
            : 0;

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        /*
         * Verify ownership.
         */
        $current_user_id = get_current_user_id();

        if (
            (int) $order->get_user_id() !== $current_user_id
            && !current_user_can('manage_woocommerce')
        ) {
            return;
        }

        /*
         * Verify nonce.
         */
        $nonce = isset($_POST['sefrelshop_delivery_nonce'])
            ? sanitize_text_field(
                wp_unslash($_POST['sefrelshop_delivery_nonce'])
            )
            : '';

        if (
            !$nonce
            || !wp_verify_nonce(
                $nonce,
                'sefrelshop_delivery_action_' . $order_id
            )
        ) {
            wc_add_notice(
                'Security verification failed. Please try again.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * A Speedaf bill code must exist.
         */
        if (!$order->get_meta('_speedaf_bill_code', true)) {
            wc_add_notice(
                'This order does not have a valid Speedaf shipment.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Delivery must actually have occurred.
         */
        if (!$this->isOrderDelivered($order)) {
            wc_add_notice(
                'This order has not yet been marked as delivered.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

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
     * Determine whether Speedaf has marked the order as delivered.
     */
    private function isOrderDelivered(WC_Order $order): bool
    {
        $status = strtolower(
            trim(
                (string) $order->get_meta(
                    '_speedaf_status',
                    true
                )
            )
        );

        $delivered_statuses = [
            '5',
            '16',
            'delivered',
            'delivered by franchisee',
            'delivery completed',
            'completed',
        ];

        if (in_array($status, $delivered_statuses, true)) {
            return true;
        }

        /*
         * Check tracking history as a fallback.
         */
        $history = $order->get_meta(
            '_speedaf_tracking_history',
            true
        );

        if (empty($history)) {
            $history = get_post_meta(
                $order->get_id(),
                '_speedaf_tracking_history',
                true
            );
        }

        if (is_string($history) && $history !== '') {
            $decoded = json_decode($history, true);

            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        if (!is_array($history)) {
            return false;
        }

        foreach ($history as $event) {
            if (!is_array($event)) {
                continue;
            }

            $event_status = strtolower(
                trim(
                    (string) (
                        $event['action']
                        ?? $event['status']
                        ?? ''
                    )
                )
            );

            if (in_array($event_status, ['5', '16', 'delivered'], true)) {
                return true;
            }

            $sub_action = strtolower(
                trim(
                    (string) (
                        $event['subAction']
                        ?? ''
                    )
                )
            );

            if (
                strpos($sub_action, 'deliver') !== false
                || strpos($sub_action, 'completed') !== false
            ) {
                return true;
            }

            $message = strtolower(
                trim(
                    (string) (
                        $event['msgEng']
                        ?? $event['message']
                        ?? $event['msgLoc']
                        ?? ''
                    )
                )
            );

            if (
                strpos($message, 'delivered') !== false
                || strpos($message, 'delivery completed') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render customer delivery/inspection actions.
     */
    public function renderDeliveryActions($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        /*
         * Only the customer who owns the order can see these actions.
         */
        if (!is_user_logged_in()) {
            return;
        }

        if (
            (int) $order->get_user_id() !== get_current_user_id()
        ) {
            return;
        }

        /*
         * No Speedaf shipment = nothing to display.
         */
        if (!$order->get_meta('_speedaf_bill_code', true)) {
            return;
        }

        /*
         * Only show these actions once Speedaf reports delivery.
         */
        if (!$this->isOrderDelivered($order)) {
            return;
        }

        $order_id = $order->get_id();

        $confirmed = $order->get_meta(
            '_sefrelshop_order_received_confirmed',
            true
        );

        $problem_reported = $order->get_meta(
            '_sefrelshop_delivery_problem_reported',
            true
        );

        $problem_status = $order->get_meta(
            '_sefrelshop_delivery_problem_status',
            true
        );

        $window_ends = (int) $order->get_meta(
            '_sefrelshop_inspection_window_ends',
            true
        );

        $inspection_open =
            $confirmed
            && $window_ends
            && time() < $window_ends;

        $order_url = wc_get_endpoint_url(
            'view-order',
            $order_id,
            wc_get_page_permalink('myaccount')
        );

        ?>
        <section
            class="sefrelshop-delivery-actions"
            style="
                margin-top:30px;
                padding:20px;
                border:1px solid #e5e5e5;
                border-radius:8px;
            "
        >

            <?php if (!$confirmed): ?>

                <h3 style="margin-top:0;">
                    Your order has been delivered?
                </h3>

                <p>
                    Please confirm that you have received your order.
                    After confirmation, you will have
                    <strong>3 days</strong> to inspect your items and report
                    any problem or request a return in accordance with the
                    SefrelShop return policy.
                </p>

                <form
                    method="post"
                    action="<?php echo esc_url($order_url); ?>"
                    style="margin-bottom:20px;"
                >
                    <input
                        type="hidden"
                        name="sefrelshop_delivery_action"
                        value="confirm_order_received"
                    >

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo esc_attr($order_id); ?>"
                    >

                    <?php
                    wp_nonce_field(
                        'sefrelshop_delivery_action_' . $order_id,
                        'sefrelshop_delivery_nonce'
                    );
                    ?>

                    <button
                        type="submit"
                        style="
                            display:inline-block !important;
                            visibility:visible !important;
                            opacity:1 !important;
                            padding:12px 20px;
                            border:0;
                            border-radius:5px;
                            cursor:pointer;
                            color:green;
                        "
                    >
                        Confirm Order Received
                    </button>
                </form>

                <form
                    method="post"
                    action="<?php echo esc_url($order_url); ?>"
                >
                    <input
                        type="hidden"
                        name="sefrelshop_delivery_action"
                        value="report_delivery_problem"
                    >

                    <input
                        type="hidden"
                        name="order_id"
                        value="<?php echo esc_attr($order_id); ?>"
                    >

                    <?php
                    wp_nonce_field(
                        'sefrelshop_delivery_action_' . $order_id,
                        'sefrelshop_delivery_nonce'
                    );
                    ?>

                    <p>
                        <label for="sefrelshop_delivery_problem">
                            <strong>Having a problem with your order?</strong>
                        </label>
                    </p>

                    <textarea
                        id="sefrelshop_delivery_problem"
                        name="delivery_problem"
                        rows="4"
                        style="
                            width:100%;
                            max-width:700px;
                            margin-bottom:10px;
                        "
                        placeholder="Please describe the issue with your order."
                        required
                    ></textarea>

                    <br>

                    <button
                        type="submit"
                        style="
                            display:inline-block !important;
                            visibility:visible !important;
                            opacity:1 !important;
                            padding:12px 20px;
                            border:0;
                            border-radius:5px;
                            cursor:pointer;
                        "
                    >
                        Report a Problem / Request Return
                    </button>
                </form>

            <?php else: ?>

                <h3 style="margin-top:0;">
                    Order received
                </h3>

                <p>
                    Thank you. Your order has been confirmed as received.
                </p>

                <?php if ($window_ends && $inspection_open): ?>

                    <p>
                        Please inspect your items carefully. You have until
                        <strong>
                            <?php
                            echo esc_html(
                                wp_date(
                                    get_option(
                                        'date_format'
                                    ) . ' ' . get_option(
                                        'time_format'
                                    ),
                                    $window_ends
                                )
                            );
                            ?>
                        </strong>
                        to report a problem or request a return.
                    </p>

                    <?php if ($problem_reported): ?>

                        <div
                            style="
                                margin:15px 0;
                                padding:12px;
                                border-left:4px solid #d63638;
                                background:#fff5f5;
                            "
                        >
                            <strong>
                                Your problem report has been received.
                            </strong>

                            <p style="margin-bottom:0;">
                                Our team will review the issue and contact you
                                regarding the next steps.
                            </p>
                        </div>

                    <?php else: ?>

                        <div style="margin-top:20px;">

                            <h4>
                                What would you like to do?
                            </h4>

                            <?php
                            $this->renderProductReviewLinks($order);
                            ?>

                            <form
                                method="post"
                                action="<?php echo esc_url($order_url); ?>"
                                style="margin-top:20px;"
                            >
                                <input
                                    type="hidden"
                                    name="sefrelshop_delivery_action"
                                    value="report_delivery_problem"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?php echo esc_attr($order_id); ?>"
                                >

                                <?php
                                wp_nonce_field(
                                    'sefrelshop_delivery_action_' . $order_id,
                                    'sefrelshop_delivery_nonce'
                                );
                                ?>

                                <p>
                                    <label
                                        for="sefrelshop_delivery_problem_confirmed"
                                    >
                                        <strong>
                                            Report a Problem / Request Return
                                        </strong>
                                    </label>
                                </p>

                                <textarea
                                    id="sefrelshop_delivery_problem_confirmed"
                                    name="delivery_problem"
                                    rows="4"
                                    style="
                                        width:100%;
                                        max-width:700px;
                                        margin-bottom:10px;
                                    "
                                    placeholder="Describe the issue with your order."
                                    required
                                ></textarea>

                                <br>

                                <button
                                    type="submit"
                                    style="
                                        display:inline-block !important;
                                        visibility:visible !important;
                                        opacity:1 !important;
                                        padding:12px 20px;
                                        border:0;
                                        border-radius:5px;
                                        cursor:pointer;
                                    "
                                >
                                    Report a Problem / Request Return
                                </button>
                            </form>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <p>
                        Your 3-day inspection and return window has ended.
                    </p>

                    <?php if ($problem_reported): ?>

                        <p>
                            Your previously reported issue is still being
                            handled by our team.
                        </p>

                    <?php else: ?>

                        <?php
                        $this->renderProductReviewLinks($order);
                        ?>

                        <p>
                            Your order is now being finalised.
                        </p>

                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>

        </section>
        <?php
    }

    /**
     * Render links to review purchased products.
     */
    private function renderProductReviewLinks(WC_Order $order): void
    {
        $items = $order->get_items();

        if (empty($items)) {
            return;
        }

        echo '<div class="sefrelshop-product-reviews">';
        echo '<strong>Review your products</strong>';
        echo '<ul style="margin-top:10px;">';

        foreach ($items as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();

            $product_url = get_permalink($product_id);

            if (!$product_url) {
                continue;
            }

            echo '<li>';
            echo '<a href="' . esc_url($product_url . '#reviews') . '">';
            echo esc_html($product->get_name());
            echo ' — Review Product';
            echo '</a>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * Confirm that the customer received the order.
     *
     * IMPORTANT:
     * This no longer changes the order directly to Completed.
     */
    private function confirmOrderReceived(WC_Order $order): void
    {
        $order_id = $order->get_id();

        /*
         * Prevent duplicate confirmation.
         */
        if (
            $order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            )
        ) {
            wc_add_notice(
                'This order has already been confirmed as received.',
                'notice'
            );

            $this->redirectBackToOrder($order);
        }

        $confirmed_at = time();

        $window_ends =
            $confirmed_at + self::INSPECTION_WINDOW_SECONDS;

        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed_at',
            $confirmed_at
        );

        $order->update_meta_data(
            '_sefrelshop_inspection_window_ends',
            $window_ends
        );

        /*
         * Initial problem state.
         */
        $order->update_meta_data(
            '_sefrelshop_delivery_problem_status',
            'none'
        );

        $order->add_order_note(
            sprintf(
                'Customer confirmed receipt of the order. A 3-day (72-hour) post-delivery inspection/return window has started and ends on %s.',
                wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $window_ends
                )
            )
        );

        /*
         * Move to Delivered instead of Completed.
         */
        if ($order->get_status() !== 'delivered') {
            $order->update_status(
                'delivered',
                'Customer confirmed receipt. 3-day post-delivery inspection window started.'
            );
        }

        $order->save();

        /*
         * Schedule automatic completion.
         */
        $this->scheduleAutomaticCompletion(
            $order_id,
            $window_ends
        );

        wc_add_notice(
            'Order received, thank you. Your order has been confirmed as received. You have 3 days to inspect your items and report any issue or request a return.',
            'success'
        );

        $this->redirectBackToOrder($order);
    }

    /**
     * Schedule automatic completion after 72 hours.
     */
    private function scheduleAutomaticCompletion(
        int $order_id,
        int $window_ends
    ): void {
        /*
         * Action Scheduler is bundled with WooCommerce.
         */
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        /*
         * Prevent duplicate scheduled actions.
         */
        if (function_exists('as_has_scheduled_action')) {
            $already_scheduled = as_has_scheduled_action(
                self::ACTION_HOOK,
                [$order_id],
                self::ACTION_GROUP
            );
        } else {
            $already_scheduled = function_exists(
                'as_next_scheduled_action'
            )
                ? as_next_scheduled_action(
                    self::ACTION_HOOK,
                    [$order_id],
                    self::ACTION_GROUP
                )
                : false;
        }

        if ($already_scheduled) {
            return;
        }

        $action_id = as_schedule_single_action(
            $window_ends,
            self::ACTION_HOOK,
            [$order_id],
            self::ACTION_GROUP,
            true
        );

        if ($action_id) {
            $order = wc_get_order($order_id);

            if ($order) {
                $order->update_meta_data(
                    '_sefrelshop_auto_completion_action_id',
                    $action_id
                );

                $order->save();
            }
        }
    }

    /**
     * Automatically complete an order after the 72-hour window.
     */
    public function autoCompleteDeliveredOrder(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        /*
         * The order must have been confirmed by the customer.
         */
        if (
            !$order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            )
        ) {
            return;
        }

        /*
         * Only Delivered orders should be auto-completed.
         */
        if ($order->get_status() !== 'delivered') {
            return;
        }

        /*
         * Check the actual inspection deadline.
         */
        $window_ends = (int) $order->get_meta(
            '_sefrelshop_inspection_window_ends',
            true
        );

        if (!$window_ends) {
            return;
        }

        /*
         * If the scheduled action runs early, do nothing.
         */
        if (time() < $window_ends) {
            return;
        }

        /*
         * Do NOT automatically complete an order with an unresolved
         * delivery problem.
         */
        $problem_reported = $order->get_meta(
            '_sefrelshop_delivery_problem_reported',
            true
        );

        $problem_status = strtolower(
            trim(
                (string) $order->get_meta(
                    '_sefrelshop_delivery_problem_status',
                    true
                )
            )
        );

        if (
            $problem_reported
            || in_array(
                $problem_status,
                [
                    'open',
                    'pending',
                    'under_review',
                    'unresolved',
                ],
                true
            )
        ) {
            $order->add_order_note(
                'Automatic completion was skipped because the customer has an unresolved delivery problem/return request.'
            );

            return;
        }

        /*
         * Complete the order.
         */
        $order->update_status(
            'completed',
            'The 3-day post-delivery inspection/return window expired without an unresolved customer problem.'
        );

        $order->update_meta_data(
            '_sefrelshop_auto_completed_at',
            time()
        );

        $order->save();
    }

    /**
     * Report a delivery problem / return request.
     */
    private function reportDeliveryProblem(WC_Order $order): void
    {
        $problem = isset($_POST['delivery_problem'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['delivery_problem'])
            )
            : '';

        if (!$problem) {
            wc_add_notice(
                'Please describe the problem with your order.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Do not allow a new problem report after the inspection window.
         */
        $window_ends = (int) $order->get_meta(
            '_sefrelshop_inspection_window_ends',
            true
        );

        if (
            $window_ends
            && time() > $window_ends
        ) {
            wc_add_notice(
                'The 3-day inspection and return window for this order has ended.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        $reported_at = time();

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_reported',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_message',
            $problem
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_at',
            $reported_at
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_by',
            get_current_user_id()
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_status',
            'open'
        );

        $order->add_order_note(
            'Customer reported a delivery/product problem or requested a return: ' . $problem
        );

        $order->save();

        /*
         * Notify store administrator.
         */
        $this->notifyAdmin(
            $order,
            $problem
        );

        /*
         * Notify vendors.
         */
        $this->notifyVendors(
            $order,
            $problem
        );

        wc_add_notice(
            'Your problem report has been submitted successfully. Our team will review it and contact you regarding the next steps.',
            'success'
        );

        $this->redirectBackToOrder($order);
    }

    /**
     * Notify administrator.
     */
    private function notifyAdmin(
        WC_Order $order,
        string $problem
    ): void {
        $admin_email = get_option('admin_email');

        if (!$admin_email) {
            return;
        }

        $subject = sprintf(
            'SefrelShop: Delivery Problem / Return Request #%s',
            $order->get_order_number()
        );

        $message =
            "A customer has reported a delivery/product problem or requested a return.\n\n"
            . 'Order: #' . $order->get_order_number() . "\n"
            . 'Customer: ' . $order->get_formatted_billing_full_name() . "\n"
            . 'Email: ' . $order->get_billing_email() . "\n\n"
            . "Problem:\n"
            . $problem
            . "\n\n"
            . 'Please review the order in WooCommerce.';

        wp_mail(
            $admin_email,
            $subject,
            $message
        );
    }

    /**
     * Notify vendors attached to the order.
     */
    private function notifyVendors(
        WC_Order $order,
        string $problem
    ): void {
        $vendor_ids = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $vendor_id = $item->get_meta(
                '_dokan_vendor_id',
                true
            );

            if (!$vendor_id) {
                $vendor_id = $item->get_meta(
                    'dokan_vendor_id',
                    true
                );
            }

            if (!$vendor_id) {
                $product = $item->get_product();

                if ($product) {
                    $vendor_id = get_post_field(
                        'post_author',
                        $product->get_id()
                    );
                }
            }

            if ($vendor_id) {
                $vendor_ids[] = absint($vendor_id);
            }
        }

        $vendor_ids = array_unique(
            array_filter($vendor_ids)
        );

        foreach ($vendor_ids as $vendor_id) {
            $vendor = get_userdata($vendor_id);

            if (!$vendor || empty($vendor->user_email)) {
                continue;
            }

            $subject = sprintf(
                'SefrelShop: Customer Problem / Return Request #%s',
                $order->get_order_number()
            );

            $message =
                "A customer has reported a problem with an item from your store.\n\n"
                . 'Order: #' . $order->get_order_number() . "\n"
                . 'Customer: ' . $order->get_formatted_billing_full_name() . "\n\n"
                . "Problem:\n"
                . $problem
                . "\n\n"
                . 'Please review the order and coordinate with SefrelShop regarding resolution.';

            wp_mail(
                $vendor->user_email,
                $subject,
                $message
            );
        }
    }

    /**
     * Redirect customer back to the WooCommerce order page.
     */
    private function redirectBackToOrder(WC_Order $order): void
    {
        $order_url = wc_get_endpoint_url(
            'view-order',
            $order->get_id(),
            wc_get_page_permalink('myaccount')
        );

        if (!$order_url) {
            $order_url = wc_get_page_permalink('myaccount');
        }

        wp_safe_redirect($order_url);
        exit;
    }
}