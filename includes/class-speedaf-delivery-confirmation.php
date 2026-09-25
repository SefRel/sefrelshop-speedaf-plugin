<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafDeliveryConfirmation
{
    /**
     * Post-delivery inspection window.
     *
     * 3 days = 72 hours.
     */
    private const INSPECTION_WINDOW_SECONDS = 3 * DAY_IN_SECONDS;

    /**
     * Action Scheduler hook used for automatic completion.
     */
    private const ACTION_HOOK = 'sefrelshop_auto_complete_delivered_order';

    /**
     * Action Scheduler group.
     */
    private const ACTION_GROUP = 'sefrelshop-speedaf';

    /**
     * Register all hooks.
     */
    public function registerHooks(): void
    {
        /*
         * Register the custom Delivered order status.
         */
        $this->registerDeliveredStatus();

        /*
         * IMPORTANT:
         * wc_order_statuses is the correct WooCommerce filter
         * for making the custom status a valid WooCommerce status.
         */
        add_filter(
            'wc_order_statuses',
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
         * Include Delivered in WooCommerce reports.
         */
        add_filter(
            'woocommerce_reports_order_statuses',
            [$this, 'addDeliveredToReports']
        );

        /*
         * Customer delivery/review actions.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderDeliveryActions'],
            20
        );

        /*
         * Handle customer POST actions.
         */
        add_action(
            'template_redirect',
            [$this, 'handleCustomerAction'],
            1
        );

        /*
         * Automatically complete the order after 72 hours.
         */
        add_action(
            self::ACTION_HOOK,
            [$this, 'autoCompleteDeliveredOrder']
        );
    }

    /**
     * Register the Delivered WooCommerce order status.
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
     * Add Delivered to the WooCommerce order status list.
     */
    public function addDeliveredStatusToList(array $statuses): array
    {
        $new_statuses = [];

        foreach ($statuses as $key => $label) {

            $new_statuses[$key] = $label;

            /*
             * Put Delivered immediately after Processing.
             */
            if ($key === 'wc-processing') {
                $new_statuses['wc-delivered'] = 'Delivered';
            }
        }

        /*
         * Fallback in case Processing is not present.
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
     * Handle all customer actions.
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
         * No SefrelShop action = nothing to do.
         */
        if (empty($_POST['sefrelshop_delivery_action'])) {
            return;
        }

        /*
         * Customer must be logged in.
         */
        if (!is_user_logged_in()) {
            return;
        }

        $action = sanitize_key(
            wp_unslash($_POST['sefrelshop_delivery_action'])
        );

        /*
         * Supported actions.
         */
        $allowed_actions = [
            'confirm_order_received',
            'report_delivery_problem',
            'submit_product_review',
        ];

        if (!in_array($action, $allowed_actions, true)) {
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
         * Verify order ownership.
         */
        $current_user_id = get_current_user_id();

        if (
            (int) $order->get_user_id() !== $current_user_id
            && !current_user_can('manage_woocommerce')
        ) {
            return;
        }

        /*
         * Product review has its own validation process.
         */
        if ($action === 'submit_product_review') {
            $this->submitProductReview($order);
            return;
        }

        /*
         * Verify delivery action nonce.
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
         * Speedaf must have reported delivery.
         */
        if (!$this->isOrderDelivered($order)) {
            wc_add_notice(
                'This order has not yet been marked as delivered.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Confirm receipt.
         */
        if ($action === 'confirm_order_received') {
            $this->confirmOrderReceived($order);
            return;
        }

        /*
         * Report a problem / request return.
         */
        if ($action === 'report_delivery_problem') {
            $this->reportDeliveryProblem($order);
            return;
        }
    }

    /**
     * Determine whether Speedaf has marked an order as delivered.
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

        /*
         * Legacy fallback.
         */
        if (empty($history)) {
            $history = get_post_meta(
                $order->get_id(),
                '_speedaf_tracking_history',
                true
            );
        }

        /*
         * Decode JSON history if necessary.
         */
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

            if (
                in_array(
                    $event_status,
                    ['5', '16', 'delivered'],
                    true
                )
            ) {
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
     * Render delivery, review and return actions.
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
         * Only the customer who owns the order.
         */
        if (
            (int) $order->get_user_id() !== get_current_user_id()
        ) {
            return;
        }

        /*
         * Must have a Speedaf shipment.
         */
        if (!$order->get_meta('_speedaf_bill_code', true)) {
            return;
        }

        /*
         * Only display after Speedaf delivery.
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

        $problem_status = strtolower(
            trim(
                (string) $order->get_meta(
                    '_sefrelshop_delivery_problem_status',
                    true
                )
            )
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
                background:#fff;
            "
        >

            <?php if (!$confirmed): ?>

                <h3 style="margin-top:0;">
                    Your order has been delivered
                </h3>

                <p>
                    Please confirm that you have received your order.
                    After confirmation, you will have
                    <strong>3 days</strong>
                    to inspect your items, review your products and report
                    any problem or request a return in accordance with the
                    SefrelShop return policy.
                </p>

                <!-- Confirm receipt -->
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
                            font-weight:600;
                        "
                    >
                        Confirm Order Received
                    </button>

                </form>

                <!-- Report problem -->
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
                            <strong>
                                Having a problem with your order?
                            </strong>
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
                            box-sizing:border-box;
                            padding:12px;
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
                            font-weight:600;
                        "
                    >
                        Report the issue
                    </button>

                </form>

            <?php else: ?>

                <!-- Order received -->
                <div
                    style="
                        padding:15px;
                        margin-bottom:20px;
                        border-radius:6px;
                        background:#f0fff4;
                    "
                >

                    <h3 style="margin-top:0;">
                        ✓ Order Received
                    </h3>

                    <p style="margin-bottom:0;">
                        Thank you for confirming receipt of your order.
                        Please inspect your items carefully.
                    </p>

                </div>

                <?php if ($window_ends && $inspection_open): ?>

                    <p>
                        You have until
                        <strong>
                            <?php
                            echo esc_html(
                                wp_date(
                                    get_option('date_format')
                                    . ' '
                                    . get_option('time_format'),
                                    $window_ends
                                )
                            );
                            ?>
                        </strong>
                        to report a problem or request a return.
                    </p>

                    <?php if ($problem_reported): ?>

                        <!-- Problem already reported -->
                        <div
                            style="
                                margin:15px 0;
                                padding:15px;
                                border-left:4px solid #d63638;
                                background:#fff5f5;
                            "
                        >

                            <strong>
                                Your problem report has been received.
                            </strong>

                            <p style="margin-bottom:0;">
                                Our team will review the issue and contact
                                you regarding the next steps.
                            </p>

                        </div>

                    <?php else: ?>

                        <!-- Product reviews -->
                        <?php
                        $this->renderProductReviewLinks($order);
                        ?>

                        <!-- Report problem -->
                        <div
                            style="
                                margin-top:30px;
                                padding-top:20px;
                                border-top:1px solid #eee;
                            "
                        >

                            <h4>
                                Need help with your order?
                            </h4>

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

                                <textarea
                                    name="delivery_problem"
                                    rows="4"
                                    style="
                                        width:100%;
                                        max-width:700px;
                                        box-sizing:border-box;
                                        padding:12px;
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
                                        padding:12px 20px;
                                        border:0;
                                        border-radius:5px;
                                        cursor:pointer;
                                        font-weight:600;
                                    "
                                >
                                    Report a Problem / Request Return
                                </button>

                            </form>

                        </div>

                    <?php endif; ?>

                <?php else: ?>

                    <!-- Inspection window ended -->
                    <div
                        style="
                            padding:15px;
                            background:#f7f7f7;
                            border-radius:6px;
                        "
                    >

                        <p>
                            Your 3-day inspection and return window has ended.
                        </p>

                        <?php if ($problem_reported): ?>

                            <p>
                                Your previously reported issue is still
                                being handled by our team.
                            </p>

                        <?php else: ?>

                            <?php
                            $this->renderProductReviewLinks($order);
                            ?>

                            <p>
                                Your order is now being finalised.
                            </p>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>
        <?php
    }

    /**
     * Render product review forms directly on the order page.
     */
    private function renderProductReviewLinks(WC_Order $order): void
    {
        $items = $order->get_items();

        if (empty($items)) {
            return;
        }

        $customer_id = get_current_user_id();

        echo '<div class="sefrelshop-product-reviews" style="margin-top:25px;">';

        echo '<h3 style="margin-bottom:8px;">';
        echo esc_html__(
            'Review Your Products',
            'sefrelshop-speedaf'
        );
        echo '</h3>';

        echo '<p style="margin-bottom:20px;color:#666;">';
        echo esc_html__(
            'Tell us what you think about the products you received.',
            'sefrelshop-speedaf'
        );
        echo '</p>';

        foreach ($items as $item) {

            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            /*
             * Reviews belong to the parent product, not the variation.
             */
            $product_id = $product->get_parent_id()
                ? $product->get_parent_id()
                : $product->get_id();

            $existing_review = $this->getCustomerReviewForProduct(
                $customer_id,
                $product_id
            );

            echo '<div
                class="sefrelshop-review-item"
                style="
                    margin-bottom:20px;
                    padding:18px;
                    border:1px solid #e5e5e5;
                    border-radius:8px;
                    background:#fff;
                "
            >';

            /*
             * Product header.
             */
            echo '<div
                style="
                    display:flex;
                    align-items:center;
                    gap:15px;
                "
            >';

            echo '<div style="flex:0 0 auto;">';

            echo $product->get_image(
                'thumbnail',
                [
                    'style' => '
                        width:70px;
                        height:70px;
                        object-fit:cover;
                        border-radius:6px;
                    ',
                ]
            );

            echo '</div>';

            echo '<div>';

            echo '<strong style="font-size:16px;">';
            echo esc_html($product->get_name());
            echo '</strong>';

            echo '<div
                style="
                    font-size:13px;
                    color:#777;
                    margin-top:5px;
                "
            >';

            echo 'Qty: ';
            echo esc_html($item->get_quantity());

            echo '</div>';

            echo '</div>';

            echo '</div>';

            /*
             * Existing review.
             */
            if ($existing_review) {

                $existing_rating = (int) get_comment_meta(
                    $existing_review->comment_ID,
                    'rating',
                    true
                );

                echo '<div
                    style="
                        margin-top:15px;
                        padding:15px;
                        background:#f7f7f7;
                        border-radius:6px;
                    "
                >';

                echo '<strong>';
                echo esc_html__(
                    'Your Review',
                    'sefrelshop-speedaf'
                );
                echo '</strong>';

                echo '<div
                    style="
                        margin-top:6px;
                        font-size:22px;
                        letter-spacing:2px;
                    "
                >';

                for ($star = 1; $star <= 5; $star++) {

                    echo $star <= $existing_rating
                        ? '★'
                        : '☆';
                }

                echo '</div>';

                echo '<p style="margin:8px 0 0;">';
                echo esc_html(
                    $existing_review->comment_content
                );
                echo '</p>';

                echo '</div>';

                continue;
            }

            /*
             * Review form.
             */
            $order_url = wc_get_endpoint_url(
                'view-order',
                $order->get_id(),
                wc_get_page_permalink('myaccount')
            );

            echo '<form
                method="post"
                action="' . esc_url($order_url) . '"
                class="sefrelshop-review-form"
                style="margin-top:18px;"
            >';

            echo '<input
                type="hidden"
                name="sefrelshop_delivery_action"
                value="submit_product_review"
            >';

            echo '<input
                type="hidden"
                name="order_id"
                value="' . esc_attr($order->get_id()) . '"
            >';

            echo '<input
                type="hidden"
                name="product_id"
                value="' . esc_attr($product_id) . '"
            >';

            wp_nonce_field(
                'sefrelshop_product_review_' . $order->get_id(),
                'sefrelshop_review_nonce'
            );

            /*
             * Rating label.
             */
            echo '<label
                style="
                    display:block;
                    font-weight:600;
                    margin-bottom:8px;
                "
            >';

            echo esc_html__(
                'Your Rating',
                'sefrelshop-speedaf'
            );

            echo '</label>';

            /*
             * Star rating.
             */
            echo '<div
                class="sefrelshop-star-rating"
                style="
                    display:flex;
                    gap:5px;
                    align-items:center;
                    margin-bottom:18px;
                "
            >';

            for ($star = 1; $star <= 5; $star++) {

                echo '<label
                    style="
                        cursor:pointer;
                        font-size:32px;
                        line-height:1;
                        color:#ccc;
                        margin:0;
                    "
                >';

                echo '<input
                    type="radio"
                    name="review_rating"
                    value="' . esc_attr($star) . '"
                    style="display:none;"
                    required
                >';

                echo '<span
                    class="sefrelshop-star"
                    data-rating="' . esc_attr($star) . '"
                    aria-label="' . esc_attr(
                        $star . ' star'
                    ) . '"
                >★</span>';

                echo '</label>';
            }

            echo '</div>';

            /*
             * Review text.
             */
            echo '<label
                for="sefrelshop_review_' . esc_attr($product_id) . '"
                style="
                    display:block;
                    font-weight:600;
                    margin-bottom:8px;
                "
            >';

            echo esc_html__(
                'Your Review',
                'sefrelshop-speedaf'
            );

            echo '</label>';

            echo '<textarea
                id="sefrelshop_review_' . esc_attr($product_id) . '"
                name="review_content"
                rows="4"
                required
                minlength="3"
                maxlength="2000"
                placeholder="Share your experience with this product..."
                style="
                    width:100%;
                    box-sizing:border-box;
                    border:1px solid #ddd;
                    border-radius:6px;
                    padding:12px;
                    resize:vertical;
                    margin-bottom:12px;
                "
            ></textarea>';

            /*
             * Submit.
             */
            echo '<button
                type="submit"
                style="
                    display:inline-block !important;
                    padding:11px 22px;
                    border:0;
                    border-radius:5px;
                    cursor:pointer;
                    font-weight:600;
                "
            >';

            echo esc_html__(
                'Submit Review',
                'sefrelshop-speedaf'
            );

            echo '</button>';

            echo '</form>';
        }

        echo '</div>';

        /*
         * Star interaction.
         */
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {

            document
                .querySelectorAll('.sefrelshop-star-rating')
                .forEach(function (ratingBox) {

                    const stars = ratingBox.querySelectorAll(
                        '.sefrelshop-star'
                    );

                    stars.forEach(function (star, index) {

                        star.addEventListener('click', function () {

                            const rating = index + 1;

                            stars.forEach(
                                function (item, starIndex) {

                                    item.style.color =
                                        starIndex < rating
                                            ? '#f5b301'
                                            : '#ccc';

                                }
                            );

                        });

                    });

                });

        });
        </script>
        <?php
    }

    /**
     * Confirm customer receipt.
     *
     * This does NOT immediately complete the order.
     * It changes the order to Delivered and starts the 72-hour window.
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

        /*
         * Save confirmation.
         */
        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed',
            'yes'
        );

        $order->update_meta_data(
            '_sefrelshop_order_received_confirmed_at',
            $confirmed_at
        );

        /*
         * Save 72-hour deadline.
         */
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

        /*
         * Add order note.
         */
        $order->add_order_note(
            sprintf(
                'Customer confirmed receipt of the order. A 3-day (72-hour) post-delivery inspection/return window has started and ends on %s.',
                wp_date(
                    get_option('date_format')
                    . ' '
                    . get_option('time_format'),
                    $window_ends
                )
            )
        );

        /*
         * IMPORTANT:
         * Move to Delivered, NOT Completed.
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
            'Order received, thank you. Your order has been confirmed as received. You have 3 days to inspect your items, review your products and report any issue or request a return.',
            'success'
        );

        $this->redirectBackToOrder($order);
    }

    /**
     * Schedule automatic completion.
     */
    private function scheduleAutomaticCompletion(
        int $order_id,
        int $window_ends
    ): void {
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
     * Cancel automatic completion.
     */
    private function cancelAutomaticCompletion(int $order_id): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(
            self::ACTION_HOOK,
            [$order_id],
            self::ACTION_GROUP
        );
    }

    /**
     * Automatically complete the order after 72 hours.
     */
    public function autoCompleteDeliveredOrder(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        /*
         * Customer must have confirmed receipt.
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
         * Only Delivered orders can be automatically completed.
         */
        if ($order->get_status() !== 'delivered') {
            return;
        }

        /*
         * Get inspection deadline.
         */
        $window_ends = (int) $order->get_meta(
            '_sefrelshop_inspection_window_ends',
            true
        );

        if (!$window_ends) {
            return;
        }

        /*
         * Don't run early.
         */
        if (time() < $window_ends) {
            return;
        }

        /*
         * Never automatically complete an order with
         * an unresolved problem/return.
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
     * Submit a product review directly from the order page.
     */
    private function submitProductReview(WC_Order $order): void
    {
        $order_id = $order->get_id();

        /*
         * Verify review nonce.
         */
        $nonce = isset($_POST['sefrelshop_review_nonce'])
            ? sanitize_text_field(
                wp_unslash($_POST['sefrelshop_review_nonce'])
            )
            : '';

        if (
            !$nonce
            || !wp_verify_nonce(
                $nonce,
                'sefrelshop_product_review_' . $order_id
            )
        ) {
            wc_add_notice(
                'Security verification failed. Please try again.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Verify ownership.
         */
        if (
            !is_user_logged_in()
            || (int) $order->get_user_id() !== get_current_user_id()
        ) {
            wc_add_notice(
                'You are not authorised to review this order.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Customer must have confirmed receipt.
         */
        if (
            !$order->get_meta(
                '_sefrelshop_order_received_confirmed',
                true
            )
        ) {
            wc_add_notice(
                'Please confirm receipt of your order before reviewing a product.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Review must be within the 3-day window.
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
                'The 3-day review and inspection window for this order has ended.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Get submitted product.
         */
        $submitted_product_id = isset($_POST['product_id'])
            ? absint($_POST['product_id'])
            : 0;

        /*
         * Get rating.
         */
        $rating = isset($_POST['review_rating'])
            ? absint($_POST['review_rating'])
            : 0;

        /*
         * Get review text.
         */
        $review_content = isset($_POST['review_content'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['review_content'])
            )
            : '';

        /*
         * Validate rating.
         */
        if ($rating < 1 || $rating > 5) {
            wc_add_notice(
                'Please select a rating from 1 to 5 stars.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Validate review text.
         */
        if (strlen($review_content) < 3) {
            wc_add_notice(
                'Please write a short review before submitting.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Find the actual purchased product.
         */
        $purchased_product_id = 0;

        foreach ($order->get_items() as $item) {

            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $parent_product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();

            if (
                $submitted_product_id === $parent_product_id
                || (
                    $variation_id
                    && $submitted_product_id === $variation_id
                )
            ) {

                $purchased_product_id = $parent_product_id;
                break;
            }
        }

        if (!$purchased_product_id) {
            wc_add_notice(
                'This product does not belong to this order.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Load product.
         */
        $product = wc_get_product(
            $purchased_product_id
        );

        if (!$product) {
            wc_add_notice(
                'The selected product could not be found.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Prevent duplicate review.
         */
        $existing_review = $this->getCustomerReviewForProduct(
            get_current_user_id(),
            $purchased_product_id
        );

        if ($existing_review) {
            wc_add_notice(
                'You have already reviewed this product.',
                'notice'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Verify customer purchased the product.
         */
        if (
            function_exists('wc_customer_bought_product')
            && !wc_customer_bought_product(
                '',
                get_current_user_id(),
                $purchased_product_id
            )
        ) {
            wc_add_notice(
                'Only customers who purchased this product can review it.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Get customer.
         */
        $user = wp_get_current_user();

        /*
         * Insert native WooCommerce product review.
         */
        $review_id = wp_insert_comment(
            [
                'comment_post_ID'      => $purchased_product_id,
                'comment_author'       => $user->display_name,
                'comment_author_email' => $user->user_email,
                'comment_author_url'   => '',
                'comment_content'      => $review_content,
                'comment_type'         => 'review',
                'comment_parent'       => 0,
                'user_id'              => get_current_user_id(),
                'comment_approved'     => 1,
            ]
        );

        if (!$review_id) {
            wc_add_notice(
                'We could not submit your review. Please try again.',
                'error'
            );

            $this->redirectBackToOrder($order);
        }

        /*
         * Save WooCommerce rating.
         */
        update_comment_meta(
            $review_id,
            'rating',
            $rating
        );

        /*
         * Mark as verified purchase.
         */
        update_comment_meta(
            $review_id,
            'verified',
            1
        );

        /*
         * Link review to SefrelShop order.
         */
        update_comment_meta(
            $review_id,
            '_sefrelshop_order_id',
            $order_id
        );

        update_comment_meta(
            $review_id,
            '_sefrelshop_verified_purchase',
            1
        );

        /*
         * Store reviewed product against order.
         */
        $reviewed_products = $order->get_meta(
            '_sefrelshop_reviewed_products',
            true
        );

        if (!is_array($reviewed_products)) {
            $reviewed_products = [];
        }

        $reviewed_products[$purchased_product_id] = [
            'review_id' => $review_id,
            'rating'    => $rating,
            'submitted' => time(),
        ];

        $order->update_meta_data(
            '_sefrelshop_reviewed_products',
            $reviewed_products
        );

        /*
         * Save last review information.
         */
        $order->update_meta_data(
            '_sefrelshop_last_review_rating',
            $rating
        );

        $order->update_meta_data(
            '_sefrelshop_last_review_product_id',
            $purchased_product_id
        );

        $order->update_meta_data(
            '_sefrelshop_last_review_at',
            time()
        );

        /*
         * Add order note.
         */
        $order->add_order_note(
            sprintf(
                'Customer submitted a %d-star review for "%s".',
                $rating,
                $product->get_name()
            )
        );

        /*
         * IMPORTANT BUSINESS RULE:
         *
         * 2–5 stars = positive review.
         *
         * Positive review can complete the order immediately,
         * provided there is no unresolved problem/return.
         */
        if ($rating >= 2) {

            $order->update_meta_data(
                '_sefrelshop_positive_review_received',
                'yes'
            );

            $order->update_meta_data(
                '_sefrelshop_positive_review_at',
                time()
            );

            /*
             * Check for unresolved problem/return.
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

            $unresolved_problem =
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
                );

            /*
             * No unresolved problem:
             * complete the order immediately.
             */
            if (!$unresolved_problem) {

                /*
                 * Cancel the 72-hour automatic completion.
                 */
                $this->cancelAutomaticCompletion(
                    $order_id
                );

                /*
                 * Complete immediately.
                 */
                $order->update_status(
                    'completed',
                    sprintf(
                        'Order completed early because the customer submitted a positive %d-star product review.',
                        $rating
                    )
                );

                $order->update_meta_data(
                    '_sefrelshop_completed_early_by_review',
                    'yes'
                );

                $order->update_meta_data(
                    '_sefrelshop_completed_early_at',
                    time()
                );

                $order->save();

                wc_add_notice(
                    'Thank you for your review! Your order has been completed. We appreciate your feedback.',
                    'success'
                );

                $this->redirectBackToOrder($order);
            }
        }

        /*
         * Save review/order metadata.
         */
        $order->save();

        /*
         * Clear WooCommerce review transients.
         */
        if (class_exists('WC_Comments')) {
            WC_Comments::clear_transients(
                $purchased_product_id
            );
        }

        /*
         * 1-star review.
         */
        if ($rating === 1) {

            wc_add_notice(
                'Thank you for your feedback. Your review has been submitted. We will use your feedback to improve your experience.',
                'success'
            );

        } else {

            wc_add_notice(
                'Thank you! Your review has been submitted successfully.',
                'success'
            );
        }

        $this->redirectBackToOrder($order);
    }

    /**
     * Find an existing review from this customer for this product.
     */
    private function getCustomerReviewForProduct(
        int $customer_id,
        int $product_id
    ): ?WP_Comment {

        if (!$customer_id || !$product_id) {
            return null;
        }

        $reviews = get_comments(
            [
                'user_id' => $customer_id,
                'post_id' => $product_id,
                'type'    => 'review',
                'status'  => 'all',
                'number'  => 1,
                'orderby' => 'comment_date_gmt',
                'order'   => 'DESC',
            ]
        );

        if (empty($reviews)) {
            return null;
        }

        return $reviews[0];
    }

    /**
     * Report a delivery/product problem or request a return.
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
         * Check 3-day inspection window.
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

        /*
         * Save problem.
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
            '_sefrelshop_delivery_problem_at',
            $reported_at
        );

        $order->update_meta_data(
            '_sefrelshop_delivery_problem_by',
            get_current_user_id()
        );

        /*
         * Important:
         * Open means automatic completion is blocked.
         */
        $order->update_meta_data(
            '_sefrelshop_delivery_problem_status',
            'open'
        );

        $order->add_order_note(
            'Customer reported a delivery/product problem or requested a return: '
            . $problem
        );

        $order->save();

        /*
         * Notify admin.
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
            . 'Customer: '
            . $order->get_formatted_billing_full_name()
            . "\n"
            . 'Email: '
            . $order->get_billing_email()
            . "\n\n"
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

            if (
                !$vendor
                || empty($vendor->user_email)
            ) {
                continue;
            }

            $subject = sprintf(
                'SefrelShop: Customer Problem / Return Request #%s',
                $order->get_order_number()
            );

            $message =
                "A customer has reported a problem with an item from your store.\n\n"
                . 'Order: #' . $order->get_order_number()
                . "\n"
                . 'Customer: '
                . $order->get_formatted_billing_full_name()
                . "\n\n"
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
     * Redirect customer back to the order page.
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