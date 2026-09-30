<?php
/**
 * SefrelShop Speedaf Customer Emails
 *
 * Handles:
 * - Shipment created email
 * - Speedaf tracking-status emails
 * - Delivered email
 * - Receipt confirmation email
 * - Review invitation
 * - Review reminder sequence
 *
 * @package SefrelShopSpeedaf
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafCustomerEmails
{
    /**
     * Register plugin hooks.
     */
    public function registerHooks(): void
    {
        /*
         * Shipment created.
         *
         * Fired by OrderProcessor after a Speedaf shipment is successfully
         * created and the bill code is available.
         */
        add_action(
            'sefrelshop_speedaf_shipment_created',
            [$this, 'handleShipmentCreated'],
            10,
            2
        );

        /*
         * Fired by SpeedafTrackingCallback after a tracking event has been
         * successfully stored.
         */
        add_action(
            'sefrelshop_speedaf_tracking_event_processed',
            [$this, 'handleTrackingEvent'],
            10,
            2
        );

        /*
         * Also watch the Speedaf status meta directly.
         *
         * This makes manual/simulator status changes use the same email
         * pipeline as real Speedaf callbacks. The existing per-status
         * sent flags prevent duplicate emails when both paths fire.
         */
        add_action(
            'updated_post_meta',
            [$this, 'handleSpeedafStatusMetaChange'],
            10,
            4
        );

        add_action(
            'added_post_meta',
            [$this, 'handleSpeedafStatusMetaChange'],
            10,
            4
        );

        /*
         * Fired after customer confirms receipt.
         */
        add_action(
            'sefrelshop_order_receipt_confirmed',
            [$this, 'handleReceiptConfirmed'],
            10,
            1
        );

        /*
         * Fired after a product review is successfully created.
         */
        add_action(
            'sefrelshop_product_review_submitted',
            [$this, 'handleReviewSubmitted'],
            10,
            4
        );

        /*
         * Action Scheduler jobs.
         */
        add_action(
            'sefrelshop_review_reminder',
            [$this, 'sendReviewReminder'],
            10,
            2
        );
    }

    /**
     * Send shipment-created email.
     *
     * @param WC_Order $order
     * @param string   $billCode
     */
    public function handleShipmentCreated($order, string $billCode): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        if (empty($billCode)) {
            return;
        }

        /*
         * Send the Speedaf shipment-created notification to the
         * relevant Dokan vendor(s).
         *
         * This is independent of the customer email. Therefore,
         * a missing customer email must not prevent the vendor
         * notification from being sent.
        */
        $this->sendVendorShipmentCreatedEmails(
          $order,
          $billCode
        );

        /*
         * Prevent duplicate email.
         */
        if ($this->wasSent($order, '_sefrelshop_email_shipment_created')) {
            return;
        }

        $customerEmail = $this->getCustomerEmail($order);

        if (!$customerEmail) {
            return;
        }

        $subject = sprintf(
            'Your SefrelShop order #%s has been shipped',
            $order->get_order_number()
        );

        $message = $this->buildShipmentCreatedEmail($order, $billCode);

        if ($this->sendEmail($customerEmail, $subject, $message)) {
            $this->markSent(
                $order,
                '_sefrelshop_email_shipment_created'
            );

            $order->add_order_note(
                'SefrelShop shipment notification email sent to customer.'
            );
        }
    }

    /**
     * Handle Speedaf tracking event.
     *
     * @param WC_Order $order
     * @param array    $event
     */
    public function handleTrackingEvent($order, array $event): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $action = isset($event['action'])
            ? (string) $event['action']
            : '';

        if ($action === '') {
            return;
        }

        /*
         * We only send customer emails for the major customer-facing
         * delivery milestones.
         */
        $status = $this->normaliseSpeedafStatus($action);

        if ($status === null) {
            return;
        }

        $metaKey = '_sefrelshop_email_speedaf_status_' . $status;

        /*
        * Send the Speedaf status notification to the relevant
        * Dokan vendor(s).
        *
        * Vendor notifications use their own per-vendor status
        * flags and are independent of the customer notification.
        */
        $this->sendVendorTrackingEmails(
            $order,
            $status,
            $event
        );

        /*
        * Never send the same customer status notification twice.
        */
        if ($this->wasSent($order, $metaKey)) {
            return;
        }

        $customerEmail = $this->getCustomerEmail($order);

        if (!$customerEmail) {
            return;
        }

        $billCode = (string) $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        /*
         * Status 5 and 16 represent delivery completion in the existing
         * SefrelShop Speedaf integration.
         */
        if (in_array($status, ['5', '16'], true)) {
            $subject = sprintf(
                'Your SefrelShop order #%s has been delivered',
                $order->get_order_number()
            );

            $message = $this->buildDeliveredEmail(
                $order,
                $billCode,
                $event
            );
        } else {
            $subject = $this->getStatusSubject(
                $order,
                $status
            );

            $message = $this->buildTrackingStatusEmail(
                $order,
                $status,
                $billCode,
                $event
            );
        }

        if ($this->sendEmail($customerEmail, $subject, $message)) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s customer email sent to %s.',
                    $status,
                    $customerEmail
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s customer email FAILED. Recipient: %s. Check WordPress mail/SMTP configuration.',
                    $status,
                    $customerEmail
                )
            );
        }
    }

    /**
     * Handle direct changes to the Speedaf status meta.
     *
     * This covers the admin/test simulator path as well as any integration
     * that updates _speedaf_status without firing the tracking-event hook.
     *
     * @param int    $metaId
     * @param int    $postId
     * @param string $metaKey
     * @param mixed  $metaValue
     */
    public function handleSpeedafStatusMetaChange(
        $metaId,
        $postId,
        $metaKey,
        $metaValue
    ): void {
        if ($metaKey !== '_speedaf_status') {
            return;
        }

        $order = wc_get_order(absint($postId));

        if (!$order instanceof WC_Order) {
            return;
        }

        $status = is_scalar($metaValue)
            ? trim((string) $metaValue)
            : '';

        if ($status === '') {
            return;
        }

        $status = $this->normaliseSpeedafStatus($status);

        if ($status === null) {
            return;
        }

        $event = [
            'action' => $status,
            'msgEng' => sprintf(
                'Speedaf tracking status updated to %s.',
                $status
            ),
            'source' => 'speedaf_status_meta',
        ];

        $this->handleTrackingEvent($order, $event);
    }

    /**
     * Handle customer confirmation of receipt.
     *
     * This sends the review invitation and schedules the reminder sequence.
     *
     * @param WC_Order $order
     */
    public function handleReceiptConfirmed($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $customerEmail = $this->getCustomerEmail($order);

        if (!$customerEmail) {
            return;
        }

        /*
         * Prevent duplicate confirmation/review invitation emails.
         */
        if (!$this->wasSent(
            $order,
            '_sefrelshop_email_receipt_confirmed'
        )) {
            $subject = sprintf(
                'Thank you for confirming your SefrelShop order #%s',
                $order->get_order_number()
            );

            $message = $this->buildReceiptConfirmedEmail($order);

            if ($this->sendEmail(
                $customerEmail,
                $subject,
                $message
            )) {
                $this->markSent(
                    $order,
                    '_sefrelshop_email_receipt_confirmed'
                );
            }
        }

        /*
         * Send the first review invitation immediately.
         */
        $this->sendInitialReviewInvitation($order);

        /*
         * Schedule the reminder sequence.
         */
        $this->scheduleReviewReminders($order);
    }

    /**
     * Handle a submitted product review.
     *
     * @param WC_Order $order
     * @param int      $productId
     * @param int      $rating
     * @param int      $reviewId
     */
    public function handleReviewSubmitted(
        $order,
        int $productId,
        int $rating,
        int $reviewId
    ): void {
        if (!$order instanceof WC_Order) {
            return;
        }

        /*
         * Do not immediately cancel every reminder just because one product
         * was reviewed. Multi-product orders may contain several products.
         *
         * The reminder method checks whether ALL purchased products have
         * already been reviewed.
         */
        if ($this->allProductsReviewed($order)) {
            $this->cancelReviewReminders($order);

            $order->update_meta_data(
                '_sefrelshop_all_products_reviewed',
                'yes'
            );

            $order->save();

            $order->add_order_note(
                'SefrelShop review reminders stopped: all purchased products have been reviewed.'
            );
        }
    }

    /**
     * Send initial review invitation.
     *
     * @param WC_Order $order
     */
    private function sendInitialReviewInvitation(WC_Order $order): void
    {
        if ($this->wasSent(
            $order,
            '_sefrelshop_email_review_invitation'
        )) {
            return;
        }

        $customerEmail = $this->getCustomerEmail($order);

        if (!$customerEmail) {
            return;
        }

        $subject = sprintf(
            'How was your SefrelShop order #%s?',
            $order->get_order_number()
        );

        $message = $this->buildReviewInvitationEmail($order);

        if ($this->sendEmail(
            $customerEmail,
            $subject,
            $message
        )) {
            $this->markSent(
                $order,
                '_sefrelshop_email_review_invitation'
            );
        }
    }

    /**
     * Schedule review reminders.
     *
     * 24h, 48h, 60h and 70h after receipt confirmation.
     *
     * @param WC_Order $order
     */
    private function scheduleReviewReminders(WC_Order $order): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        $orderId = $order->get_id();

        $reminders = [
            1 => 24 * HOUR_IN_SECONDS,
            2 => 48 * HOUR_IN_SECONDS,
            3 => 60 * HOUR_IN_SECONDS,
            4 => 70 * HOUR_IN_SECONDS,
        ];

        foreach ($reminders as $reminderNumber => $delay) {
            $timestamp = time() + $delay;

            /*
             * as_has_scheduled_action() prevents duplicate jobs.
             */
            if (
                function_exists('as_has_scheduled_action') &&
                as_has_scheduled_action(
                    'sefrelshop_review_reminder',
                    [
                        $orderId,
                        $reminderNumber,
                    ],
                    'sefrelshop-speedaf'
                )
            ) {
                continue;
            }

            as_schedule_single_action(
                $timestamp,
                'sefrelshop_review_reminder',
                [
                    $orderId,
                    $reminderNumber,
                ],
                'sefrelshop-speedaf'
            );
        }
    }

    /**
     * Send scheduled review reminder.
     *
     * @param int $orderId
     * @param int $reminderNumber
     */
    public function sendReviewReminder(
        int $orderId,
        int $reminderNumber
    ): void {
        $order = wc_get_order($orderId);

        if (!$order) {
            return;
        }

        /*
         * Stop if everything has already been reviewed.
         */
        if ($this->allProductsReviewed($order)) {
            $this->cancelReviewReminders($order);
            return;
        }

        /*
         * Stop if the customer has reported a delivery problem.
         */
        if ($this->hasOpenDeliveryProblem($order)) {
            $this->cancelReviewReminders($order);
            return;
        }

        /*
         * Stop after completion.
         */
        if ($order->has_status('completed')) {
            return;
        }

        $metaKey = '_sefrelshop_review_reminder_' . $reminderNumber;

        if ($this->wasSent($order, $metaKey)) {
            return;
        }

        $customerEmail = $this->getCustomerEmail($order);

        if (!$customerEmail) {
            return;
        }

        $subject = $this->getReminderSubject(
            $order,
            $reminderNumber
        );

        $message = $this->buildReviewReminderEmail(
            $order,
            $reminderNumber
        );

        if ($this->sendEmail(
            $customerEmail,
            $subject,
            $message
        )) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop review reminder #%d sent to customer.',
                    $reminderNumber
                )
            );
        }
    }

    /**
     * Cancel pending review reminders for an order.
     *
     * @param WC_Order $order
     */
    private function cancelReviewReminders(WC_Order $order): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(
            'sefrelshop_review_reminder',
            [$order->get_id()],
            'sefrelshop-speedaf'
        );

        /*
         * Action Scheduler arguments may contain the reminder number, so
         * explicitly cancel each known reminder.
         */
        for ($i = 1; $i <= 4; $i++) {
            if (function_exists('as_unschedule_action')) {
                as_unschedule_action(
                    'sefrelshop_review_reminder',
                    [
                        $order->get_id(),
                        $i,
                    ],
                    'sefrelshop-speedaf'
                );
            }
        }
    }

    /**
     * Determine whether all purchased products have reviews.
     *
     * @param WC_Order $order
     */
    private function allProductsReviewed(WC_Order $order): bool
    {
        $productIds = [];

        foreach ($order->get_items('line_item') as $item) {
            $productId = $item->get_product_id();

            if (!$productId) {
                continue;
            }

            $productIds[$productId] = $productId;
        }

        if (empty($productIds)) {
            return true;
        }

        $reviewedProducts = $order->get_meta(
            '_sefrelshop_reviewed_products',
            true
        );

        if (!is_array($reviewedProducts)) {
            $reviewedProducts = [];
        }

        foreach ($productIds as $productId) {
            if (!in_array(
                (int) $productId,
                array_map('intval', $reviewedProducts),
                true
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether the order has an open delivery problem.
     *
     * @param WC_Order $order
     */
    private function hasOpenDeliveryProblem(WC_Order $order): bool
    {
        $status = strtolower(
            (string) $order->get_meta(
                '_sefrelshop_delivery_problem_status',
                true
            )
        );

        return in_array(
            $status,
            [
                'open',
                'pending',
                'under_review',
                'unresolved',
            ],
            true
        );
    }

    /**
 * Send Speedaf shipment-created emails to all relevant vendors.
 *
 * Supports multi-vendor orders. If the same vendor has multiple
 * products in the order, the vendor receives only one email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 */
private function sendVendorShipmentCreatedEmails(
    WC_Order $order,
    string $billCode
): void {
    $vendors = $this->getOrderVendors($order);

    if (empty($vendors)) {
        return;
    }

    foreach ($vendors as $vendor) {
        $sellerId = $vendor['id'];
        $email    = $vendor['email'];
        $shopName = $vendor['shop_name'];

        if (!$email) {
            continue;
        }

        /*
         * Per-vendor duplicate protection.
         */
        $metaKey = '_sefrelshop_vendor_email_shipment_created_' . $sellerId;

        if ($this->wasSent($order, $metaKey)) {
            continue;
        }

        $subject = sprintf(
            'Speedaf shipment created — Order #%s',
            $order->get_order_number()
        );

        $message = $this->buildVendorShipmentCreatedEmail(
            $order,
            $billCode,
            $shopName
        );

        if ($this->sendEmail($email, $subject, $message)) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf shipment notification email sent to vendor #%d (%s).',
                    $sellerId,
                    $email
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf shipment notification email FAILED for vendor #%d (%s). Check WordPress mail/SMTP configuration.',
                    $sellerId,
                    $email
                )
            );
        }
    }
}


/**
 * Send Speedaf tracking-status emails to all relevant vendors.
 *
 * @param WC_Order $order
 * @param string   $status
 * @param array    $event
 */
private function sendVendorTrackingEmails(
    WC_Order $order,
    string $status,
    array $event
): void {
    $vendors = $this->getOrderVendors($order);

    if (empty($vendors)) {
        return;
    }

    $billCode = (string) $order->get_meta(
        '_speedaf_bill_code',
        true
    );

    foreach ($vendors as $vendor) {
        $sellerId = $vendor['id'];
        $email    = $vendor['email'];
        $shopName = $vendor['shop_name'];

        if (!$email) {
            continue;
        }

        /*
         * Each vendor gets an independent status flag.
         *
         * Example:
         * _sefrelshop_vendor_email_speedaf_status_2_123
         */
        $metaKey = sprintf(
            '_sefrelshop_vendor_email_speedaf_status_%s_%d',
            $status,
            $sellerId
        );

        if ($this->wasSent($order, $metaKey)) {
            continue;
        }

        if (in_array($status, ['5', '16'], true)) {
            $subject = sprintf(
                'Speedaf marked order #%s as delivered',
                $order->get_order_number()
            );

            $message = $this->buildVendorDeliveredEmail(
                $order,
                $billCode,
                $event,
                $shopName
            );
        } else {
            $subject = $this->getVendorStatusSubject(
                $order,
                $status
            );

            $message = $this->buildVendorTrackingEmail(
                $order,
                $status,
                $billCode,
                $event,
                $shopName
            );
        }

        if ($this->sendEmail($email, $subject, $message)) {
            $this->markSent($order, $metaKey);

            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s vendor email sent to vendor #%d (%s).',
                    $status,
                    $sellerId,
                    $email
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    'SefrelShop Speedaf status %s vendor email FAILED for vendor #%d (%s). Check WordPress mail/SMTP configuration.',
                    $status,
                    $sellerId,
                    $email
                )
            );
        }
    }
}


/**
 * Get all unique Dokan vendors represented in an order.
 *
 * @param WC_Order $order
 * @return array
 */
private function getOrderVendors(WC_Order $order): array
{
    $vendors = [];

    foreach ($order->get_items('line_item') as $item) {
        $productId = $item->get_product_id();

        if (!$productId) {
            continue;
        }

        /*
         * Dokan's native vendor lookup.
         */
        if (!function_exists('dokan_get_vendor_by_product')) {
            continue;
        }

        $vendor = dokan_get_vendor_by_product($productId);

        if (!$vendor || !is_object($vendor)) {
            continue;
        }

        if (!method_exists($vendor, 'get_id')) {
            continue;
        }

        $sellerId = absint($vendor->get_id());

        if (!$sellerId) {
            continue;
        }

        /*
         * Avoid sending multiple emails to the same vendor
         * when the order contains multiple products from them.
         */
        if (isset($vendors[$sellerId])) {
            continue;
        }

        $user = get_userdata($sellerId);

        if (!$user || !is_email($user->user_email)) {
            continue;
        }

        /*
         * Get shop name from Dokan when available.
         */
        $shopName = '';

        if (method_exists($vendor, 'get_shop_name')) {
            $shopName = (string) $vendor->get_shop_name();
        }

        if ($shopName === '') {
            $shopName = (string) $user->display_name;
        }

        if ($shopName === '') {
            $shopName = 'Vendor';
        }

        $vendors[$sellerId] = [
            'id'        => $sellerId,
            'email'     => sanitize_email($user->user_email),
            'shop_name' => sanitize_text_field($shopName),
        ];
    }

    return array_values($vendors);
}


/**
 * Get Dokan vendor order dashboard URL.
 *
 * @param WC_Order $order
 * @return string
 */
private function getVendorOrderUrl(WC_Order $order): string
{
    if (function_exists('dokan_get_navigation_url')) {
        $baseUrl = dokan_get_navigation_url('orders');

        if ($baseUrl) {
            return add_query_arg(
                'order_id',
                $order->get_id(),
                $baseUrl
            );
        }
    }

    /*
     * Fallback.
     */
    return $order->get_view_order_url()
        ?: home_url('/');
}


/**
 * Get vendor tracking-status subject.
 *
 * @param WC_Order $order
 * @param string   $status
 * @return string
 */
private function getVendorStatusSubject(
    WC_Order $order,
    string $status
): string {
    $subjects = [
        '1'  => 'Speedaf picked up your SefrelShop order',
        '2'  => 'Your SefrelShop order is in transit',
        '3'  => 'Your SefrelShop order has arrived at pickup point',
        '4'  => 'Your SefrelShop order is out for delivery',
        '5'  => 'Speedaf delivered your SefrelShop order',
        '16' => 'Speedaf delivered your SefrelShop order',
    ];

    return sprintf(
        '%s — Order #%s',
        $subjects[$status]
            ?? 'Speedaf delivery update',
        $order->get_order_number()
    );
}


/**
 * Build vendor shipment-created email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 * @param string   $shopName
 * @return string
 */
private function buildVendorShipmentCreatedEmail(
    WC_Order $order,
    string $billCode,
    string $shopName
): string {
    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        'Speedaf shipment created',
        sprintf(
            '<p>Hello %s,</p>

            <p>A Speedaf shipment has been created for an order
            associated with your SefrelShop store
            <strong>%s</strong>.</p>

            <p>
                <strong>Order:</strong> #%s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>
                The shipment has been successfully created and
                is now being handled by Speedaf.
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($shopName),
            esc_html($order->get_order_number()),
            esc_html($customerName),
            esc_html($billCode),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}


/**
 * Build vendor tracking-status email.
 *
 * @param WC_Order $order
 * @param string   $status
 * @param string   $billCode
 * @param array    $event
 * @param string   $shopName
 * @return string
 */
private function buildVendorTrackingEmail(
    WC_Order $order,
    string $status,
    string $billCode,
    array $event,
    string $shopName
): string {
    $messages = [
        '1' => [
            'heading' => 'Speedaf has picked up the parcel',
            'message' => 'The parcel associated with this order has been picked up by Speedaf.',
        ],
        '2' => [
            'heading' => 'Your parcel is in transit',
            'message' => 'The parcel associated with this order is currently in transit.',
        ],
        '3' => [
            'heading' => 'Your parcel has arrived',
            'message' => 'The parcel has arrived at a Speedaf pickup point.',
        ],
        '4' => [
            'heading' => 'Your parcel is out for delivery',
            'message' => 'The parcel is currently out for delivery.',
        ],
    ];

    $heading = $messages[$status]['heading']
        ?? 'Speedaf delivery update';

    $message = $messages[$status]['message']
        ?? 'The Speedaf delivery status for this order has been updated.';

    $eventMessage = $this->getEventMessage($event);

    $latestUpdate = '';

    if ($eventMessage !== '') {
        $latestUpdate = sprintf(
            '<p>
                <strong>Latest Speedaf update:</strong><br>
                %s
            </p>',
            esc_html($eventMessage)
        );
    }

    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        $heading,
        sprintf(
            '<p>Hello %s,</p>

            <p>
                There is a Speedaf delivery update for an order
                associated with your store
                <strong>%s</strong>.
            </p>

            <p>%s</p>

            %s

            <p>
                <strong>Order:</strong> #%s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($shopName),
            esc_html($message),
            $latestUpdate,
            esc_html($order->get_order_number()),
            esc_html($customerName),
            esc_html($billCode ?: 'Not available'),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}


/**
 * Build vendor delivered email.
 *
 * @param WC_Order $order
 * @param string   $billCode
 * @param array    $event
 * @param string   $shopName
 * @return string
 */
private function buildVendorDeliveredEmail(
    WC_Order $order,
    string $billCode,
    array $event,
    string $shopName
): string {
    $eventMessage = $this->getEventMessage($event);

    $latestUpdate = '';

    if ($eventMessage !== '') {
        $latestUpdate = sprintf(
            '<p>
                <strong>Delivery update:</strong><br>
                %s
            </p>',
            esc_html($eventMessage)
        );
    }

    $customerName = trim(
        $order->get_billing_first_name()
        . ' '
        . $order->get_billing_last_name()
    );

    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return $this->emailLayout(
        'Speedaf has marked the order as delivered',
        sprintf(
            '<p>Hello %s,</p>

            <p>
                Speedaf has marked order
                <strong>#%s</strong>
                as delivered.
            </p>

            %s

            <p>
                <strong>Store:</strong> %s<br>
                <strong>Customer:</strong> %s<br>
                <strong>Speedaf Waybill:</strong> %s
            </p>

            <p>
                Please review the order from your vendor dashboard
                if any further action is required.
            </p>

            <p>%s</p>',
            esc_html($shopName),
            esc_html($order->get_order_number()),
            $latestUpdate,
            esc_html($shopName),
            esc_html($customerName),
            esc_html($billCode ?: 'Not available'),
            $this->button(
                'View Order in Vendor Dashboard',
                $this->getVendorOrderUrl($order)
            )
        )
    );
}

    /**
     * Normalise Speedaf status.
     *
     * @param string $action
     * @return string|null
     */
    private function normaliseSpeedafStatus(string $action): ?string
    {
        $action = trim($action);

        /*
         * Current Speedaf status mapping used by SefrelShop.
         */
        $allowed = [
            '1',
            '2',
            '3',
            '4',
            '5',
            '16',
        ];

        return in_array($action, $allowed, true)
            ? $action
            : null;
    }

    /**
     * Get customer email.
     *
     * @param WC_Order $order
     */
    private function getCustomerEmail(WC_Order $order): string
    {
        $email = $order->get_billing_email();

        if (!$email) {
            $email = $order->get_meta(
                '_billing_email',
                true
            );
        }

        return is_email($email)
            ? sanitize_email($email)
            : '';
    }

    /**
     * Check email sent flag.
     */
    private function wasSent(
        WC_Order $order,
        string $metaKey
    ): bool {
        return $order->get_meta($metaKey, true) === 'yes';
    }

    /**
     * Mark email as sent.
     */
    private function markSent(
        WC_Order $order,
        string $metaKey
    ): void {
        $order->update_meta_data(
            $metaKey,
            'yes'
        );

        $order->update_meta_data(
            $metaKey . '_at',
            current_time('mysql')
        );

        $order->save();
    }

    /**
     * Send email through WordPress/WooCommerce mail pipeline.
     */
    private function sendEmail(
        string $recipient,
        string $subject,
        string $html
    ): bool {
        if (!is_email($recipient)) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: SefrelShop <support@sefrelshop.com>',
        ];

        return (bool) wp_mail(
            $recipient,
            $subject,
            $html,
            $headers
        );
    }

    /**
     * Build shipment-created email.
     */
    private function buildShipmentCreatedEmail(
        WC_Order $order,
        string $billCode
    ): string {
        $orderUrl = $this->getOrderUrl($order);

        return $this->emailLayout(
            'Your order is being processed.',
            sprintf(
                '<p>Hello %s,</p>
                <p>Great news! Your SefrelShop order <strong>#%s</strong> is being processed and will be picked up soon for delivery.</p>
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>You can follow your delivery progress from your order page.</p>
                <p>%s</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($order->get_order_number()),
                esc_html($billCode),
                $this->button(
                    'Track Your Order',
                    $orderUrl
                )
            )
        );
    }

    /**
     * Build normal tracking status email.
     */
    private function buildTrackingStatusEmail(
        WC_Order $order,
        string $status,
        string $billCode,
        array $event
    ): string {
        $labels = [
            '1' => 'Your parcel has been picked up.',
            '2' => 'Your parcel is in transit.',
            '3' => 'Your parcel has arrived at the pickup point.',
            '4' => 'Your parcel is out for delivery.',
        ];

        $title = [
            '1' => 'Your parcel has been picked up',
            '2' => 'Your parcel is in transit',
            '3' => 'Your parcel has arrived',
            '4' => 'Your parcel is out for delivery',
        ];

        $message = isset($labels[$status])
            ? $labels[$status]
            : 'Your parcel delivery status has been updated.';

        $eventMessage = $this->getEventMessage($event);

        $extra = '';

        if ($eventMessage !== '') {
            $extra = sprintf(
                '<p><strong>Latest update:</strong><br>%s</p>',
                esc_html($eventMessage)
            );
        }

        return $this->emailLayout(
            $title[$status] ?? 'Your delivery has been updated',
            sprintf(
                '<p>Hello %s,</p>
                <p>%s</p>
                %s
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>%s</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($message),
                $extra,
                esc_html($billCode),
                $this->button(
                    'Track Your Order',
                    $this->getOrderUrl($order)
                )
            )
        );
    }

    /**
     * Build delivered email.
     */
    private function buildDeliveredEmail(
        WC_Order $order,
        string $billCode,
        array $event
    ): string {
        $orderUrl = $this->getOrderUrl($order);

        $eventMessage = $this->getEventMessage($event);

        $extra = '';

        if ($eventMessage !== '') {
            $extra = sprintf(
                '<p><strong>Delivery update:</strong><br>%s</p>',
                esc_html($eventMessage)
            );
        }

        return $this->emailLayout(
            'Your order has been delivered',
            sprintf(
                '<p>Hello %s,</p>
                <p>Your SefrelShop order <strong>#%s</strong> has been marked as delivered.</p>
                %s
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>Please confirm that you have received your order. After confirmation, you can review the products you purchased.</p>
                <p>%s</p>
                <p>%s</p>
                <p>If there is a problem with your delivery, you can report it from your order page.</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($order->get_order_number()),
                $extra,
                esc_html($billCode),
                $this->button(
                    'Confirm Receipt & Review Order',
                    $orderUrl
                ),
                $this->button(
                    'Track Your Order',
                    $orderUrl
                )
            )
        );
    }

    /**
     * Build receipt confirmation email.
     */
    private function buildReceiptConfirmedEmail(
        WC_Order $order
    ): string {
        return $this->emailLayout(
            'Thank you for confirming your order',
            sprintf(
                '<p>Hello %s,</p>
                <p>Thank you for confirming receipt of order <strong>#%s</strong>.</p>
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>We would love to hear about your experience. Please review the products you purchased and help other customers discover quality products from Nigerian businesses.</p>
                <p>%s</p>
                <p>%s</p>
                <p>Your order has entered its inspection and review period.</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($order->get_order_number()),
                esc_html((string) $order->get_meta('_speedaf_bill_code', true)),
                $this->button(
                    'Review Your Products',
                    $this->getReviewUrl($order)
                ),
                $this->button(
                    'Track Your Order',
                    $this->getOrderUrl($order)
                )
            )
        );
    }

    /**
     * Build review invitation.
     */
    private function buildReviewInvitationEmail(
        WC_Order $order
    ): string {
        return $this->emailLayout(
            'Tell us about your purchase',
            sprintf(
                '<p>Hello %s,</p>
                <p>Your order <strong>#%s</strong> has been received. How was your experience?</p>
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>Your feedback helps us support reliable Nigerian businesses and helps other shoppers make better purchasing decisions.</p>
                <p>%s</p>
                <p>%s</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($order->get_order_number()),
                esc_html((string) $order->get_meta('_speedaf_bill_code', true)),
                $this->button(
                    'Review Your Products',
                    $this->getReviewUrl($order)
                ),
                $this->button(
                    'Track Your Order',
                    $this->getOrderUrl($order)
                )
            )
        );
    }

    /**
     * Build reminder email.
     */
    private function buildReviewReminderEmail(
        WC_Order $order,
        int $reminderNumber
    ): string {
        $intro = [
            1 => 'We would love to hear how your order went.',
            2 => 'Just a quick reminder — your product review is still waiting for you.',
            3 => 'Your feedback can make a real difference to the businesses behind the products you purchased.',
            4 => 'This is our final reminder before your review window closes.',
        ];

        $message = $intro[$reminderNumber]
            ?? 'We would love to hear your feedback.';

        return $this->emailLayout(
            'Your SefrelShop review is waiting',
            sprintf(
                '<p>Hello %s,</p>
                <p>%s</p>
                <p>Order <strong>#%s</strong> is ready for your product review.</p>
                <p><strong>Speedaf Waybill:</strong> %s</p>
                <p>Your review helps genuine Nigerian businesses build trust and helps other shoppers buy with confidence.</p>
                <p>%s</p>
                <p>%s</p>',
                esc_html($order->get_billing_first_name()),
                esc_html($message),
                esc_html($order->get_order_number()),
                esc_html((string) $order->get_meta('_speedaf_bill_code', true)),
                $this->button(
                    'Review My Products',
                    $this->getReviewUrl($order)
                ),
                $this->button(
                    'Track Your Order',
                    $this->getOrderUrl($order)
                )
            )
        );
    }

    /**
     * Get status email subject.
     */
    private function getStatusSubject(
        WC_Order $order,
        string $status
    ): string {
        $subjects = [
            '1' => 'Your SefrelShop parcel has been picked up',
            '2' => 'Your SefrelShop parcel is in transit',
            '3' => 'Your SefrelShop parcel has arrived',
            '4' => 'Your SefrelShop parcel is out for delivery',
        ];

        return sprintf(
            '%s — Order #%s',
            $subjects[$status] ?? 'Your SefrelShop delivery has been updated',
            $order->get_order_number()
        );
    }

    /**
     * Get reminder subject.
     */
    private function getReminderSubject(
        WC_Order $order,
        int $reminderNumber
    ): string {
        $subjects = [
            1 => 'How was your SefrelShop order?',
            2 => 'A quick reminder to review your SefrelShop order',
            3 => 'Your SefrelShop review helps Nigerian businesses',
            4 => 'Final reminder: review your SefrelShop order',
        ];

        return sprintf(
            '%s — Order #%s',
            $subjects[$reminderNumber] ?? 'Review your SefrelShop order',
            $order->get_order_number()
        );
    }

    /**
     * Get event message.
     */
    private function getEventMessage(array $event): string
    {
        foreach (
            [
                'msgEng',
                'message',
                'msgLoc',
                'subAction',
            ] as $key
        ) {
            if (!empty($event[$key])) {
                return wp_strip_all_tags(
                    (string) $event[$key]
                );
            }
        }

        return '';
    }

    /**
     * Get order page URL.
     */
    private function getOrderUrl(WC_Order $order): string
    {
        $url = $order->get_view_order_url();

        return $url ?: home_url('/');
    }

    /**
     * Get review section URL.
     */
    private function getReviewUrl(WC_Order $order): string
    {
        return $this->getOrderUrl($order)
            . '#sefrelshop-product-reviews';
    }

    /**
     * Email button.
     */
    private function button(
        string $label,
        string $url
    ): string {
        return sprintf(
            '<a href="%s"
                style="
                    display:inline-block;
                    background:#009839;
                    color:#ffffff;
                    text-decoration:none;
                    padding:13px 22px;
                    border-radius:5px;
                    font-weight:600;
                ">%s</a>',
            esc_url($url),
            esc_html($label)
        );
    }

    /**
     * Common email layout.
     */
    private function emailLayout(
        string $heading,
        string $content
    ): string {
        return sprintf(
            '<!DOCTYPE html>
            <html>
            <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">
                <div style="max-width:620px;margin:30px auto;background:#ffffff;border-radius:8px;overflow:hidden;">
                    <div style="background:#009839;padding:22px;text-align:center;">
                        <h1 style="margin:0;color:#ffffff;font-size:24px;">SefrelShop</h1>
                    </div>

                    <div style="padding:32px;">
                        <h2 style="margin-top:0;">%s</h2>

                        %s

                        <hr style="border:0;border-top:1px solid #eeeeee;margin:30px 0;">

                        <p style="font-size:13px;color:#777;">
                            Thank you for supporting Made-in-Nigeria products.
                        </p>

                        <p style="font-size:13px;color:#777;">
                            SefrelShop<br>
                            The go-to platform for Nigeria-made products.
                        </p>
                    </div>
                </div>
            </body>
            </html>',
            esc_html($heading),
            $content
        );
    }
}