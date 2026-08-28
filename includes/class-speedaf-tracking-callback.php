<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafTrackingCallback
{
    /**
     * Register REST API endpoint.
     */
    public function registerRoutes(): void
    {
        register_rest_route(
            'sefrelshop/v1',
            '/speedaf/tracking',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Map Speedaf status codes to
     * SefrelShop internal statuses.
     */
    private function mapStatus(string $action): array
    {
        $statuses = [
            '10' => [
                'key'   => 'ordered',
                'label' => 'Order Confirmed',
            ],

            '1' => [
                'key'   => 'picked_up',
                'label' => 'Shipment Picked Up',
            ],

            '2' => [
                'key'   => 'in_transit',
                'label' => 'In Transit',
            ],

            '3' => [
                'key'   => 'arrived',
                'label' => 'Arrived at Destination',
            ],

            '4' => [
                'key'   => 'out_for_delivery',
                'label' => 'Out for Delivery',
            ],

            '5' => [
                'key'   => 'delivered',
                'label' => 'Delivered',
            ],

            '16' => [
                'key'   => 'delivered',
                'label' => 'Delivered',
            ],

            '-10' => [
                'key'   => 'cancelled',
                'label' => 'Cancelled',
            ],

            '-710' => [
                'key'   => 'returning',
                'label' => 'Returning',
            ],

            '730' => [
                'key'   => 'returned',
                'label' => 'Returned',
            ],
        ];

        return $statuses[$action] ?? [
            'key'   => 'unknown',
            'label' => 'Shipment Update',
        ];
    }

    /**
     * Handle Speedaf tracking callback.
     */
    public function handle(WP_REST_Request $request)
    {
        $body = $request->get_body();

        /**
         * Development diagnostics.
         */
        if (defined('WP_DEBUG') && WP_DEBUG) {
            update_option(
                'sefrelshop_speedaf_last_callback',
                $body
            );
        }

        /**
         * Validate body.
         */
        if (empty($body)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Empty tracking callback received.',
                ],
                400
            );
        }

        /**
         * Decode JSON.
         */
        $data = json_decode(
            $body,
            true
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Invalid JSON received.',
                ],
                400
            );
        }

        /**
         * Speedaf normally sends an array.
         *
         * We also accept a single event object.
         */
        if (
            isset($data['mailNo']) ||
            isset($data['action'])
        ) {
            $data = [$data];
        }

        if (!is_array($data)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Invalid tracking data format.',
                ],
                400
            );
        }

        $processed = 0;
        $duplicates = 0;
        $failed = 0;

        /**
         * Process each tracking event.
         */
        foreach ($data as $event) {

            if (!is_array($event)) {
                $failed++;
                continue;
            }

            $result = $this->processEvent($event);

            if ($result === 'processed') {
                $processed++;
            } elseif ($result === 'duplicate') {
                $duplicates++;
            } else {
                $failed++;
            }
        }

        /**
         * Speedaf expects a successful response
         * when the callback has been received.
         */
        return new WP_REST_Response(
            [
                'success'    => true,
                'processed'  => $processed,
                'duplicates' => $duplicates,
                'failed'     => $failed,
            ],
            200
        );
    }

    /**
     * Process one Speedaf tracking event.
     *
     * Returns:
     * - processed
     * - duplicate
     * - failed
     */
    private function processEvent(array $event): string
    {
        /**
         * Extract waybill.
         */
        $mailNo = isset($event['mailNo'])
            ? sanitize_text_field($event['mailNo'])
            : '';

        if (empty($mailNo)) {
            return 'failed';
        }

        /**
         * Extract tracking fields.
         */
        $action = isset($event['action'])
            ? sanitize_text_field($event['action'])
            : '';

        $subAction = isset($event['subAction'])
            ? sanitize_text_field($event['subAction'])
            : '';

        $message = isset($event['message'])
            ? sanitize_text_field($event['message'])
            : '';

        $msgEng = isset($event['msgEng'])
            ? sanitize_text_field($event['msgEng'])
            : '';

        $msgLoc = isset($event['msgLoc'])
            ? sanitize_text_field($event['msgLoc'])
            : '';

        $time = isset($event['time'])
            ? sanitize_text_field($event['time'])
            : '';

        $country = isset($event['country'])
            ? sanitize_text_field($event['country'])
            : '';

        $countryCode = isset($event['countryCode'])
            ? sanitize_text_field($event['countryCode'])
            : '';

        $pictureUrl = isset($event['pictureUrl'])
            ? esc_url_raw($event['pictureUrl'])
            : '';

        /**
         * Find WooCommerce order using
         * the Speedaf bill code.
         */
        $orders = wc_get_orders(
            [
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_key'   => '_speedaf_bill_code',
                'meta_value' => $mailNo,
            ]
        );

        /**
         * No matching order.
         */
        if (empty($orders)) {

            if (defined('WP_DEBUG') && WP_DEBUG) {
                update_option(
                    'sefrelshop_speedaf_unmatched_' . md5($mailNo),
                    $event
                );
            }

            return 'failed';
        }

        $order = $orders[0];
        $orderId = $order->get_id();

        /**
         * Create a unique fingerprint for this
         * tracking event.
         *
         * This prevents Speedaf sending the same
         * event multiple times from creating
         * duplicate history records.
         */
        $eventFingerprint = md5(
            $mailNo
            . '|'
            . $action
            . '|'
            . $subAction
            . '|'
            . $time
            . '|'
            . ($msgEng ?: $message)
        );

        /**
         * Retrieve tracking history.
         */
        $history = get_post_meta(
            $orderId,
            '_speedaf_tracking_history',
            true
        );

        if (!is_array($history)) {
            $history = [];
        }

        /**
         * Check whether this event already exists.
         */
        foreach ($history as $existingEvent) {

            if (
                isset($existingEvent['fingerprint']) &&
                $existingEvent['fingerprint'] === $eventFingerprint
            ) {
                return 'duplicate';
            }
        }

        /**
         * Convert Speedaf status into
         * SefrelShop's internal status.
         */
        $status = $this->mapStatus($action);

        /**
         * Latest customer-facing message.
         */
        $displayMessage = $msgEng ?: ($msgLoc ?: $message);

        /**
         * Store latest tracking information.
         */
        update_post_meta(
            $orderId,
            '_speedaf_tracking_action',
            $action
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_sub_action',
            $subAction
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_message',
            $displayMessage
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_time',
            $time
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_country',
            $country
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_country_code',
            $countryCode
        );

        if (!empty($pictureUrl)) {
            update_post_meta(
                $orderId,
                '_speedaf_tracking_picture',
                $pictureUrl
            );
        }

        /**
         * Preserve original Speedaf status code.
         */
        update_post_meta(
            $orderId,
            '_speedaf_status',
            $action
        );

        /**
         * Store normalized SefrelShop status.
         */
        update_post_meta(
            $orderId,
            '_sefrelshop_shipping_status',
            $status['key']
        );

        update_post_meta(
            $orderId,
            '_sefrelshop_shipping_status_label',
            $status['label']
        );

        /**
         * Store the latest tracking event time.
         */
        if (!empty($time)) {
            update_post_meta(
                $orderId,
                '_sefrelshop_shipping_updated_at',
                $time
            );
        }

        /**
         * Add event to tracking history.
         */
        $history[] = [
            'fingerprint' => $eventFingerprint,
            'mailNo'      => $mailNo,
            'action'      => $action,
            'subAction'   => $subAction,
            'status_key'  => $status['key'],
            'status_label'=> $status['label'],
            'message'     => $message,
            'msgEng'      => $msgEng,
            'msgLoc'      => $msgLoc,
            'time'        => $time,
            'pictureUrl'  => $pictureUrl,
            'country'     => $country,
            'countryCode' => $countryCode,
            'received_at' => current_time('mysql'),
        ];

        update_post_meta(
            $orderId,
            '_speedaf_tracking_history',
            $history
        );

        /**
         * Add WooCommerce order note.
         */
        $note = 'Speedaf tracking update: ' . $status['label'];

        if (!empty($displayMessage)) {
            $note .= ' — ' . $displayMessage;
        }

        if (!empty($action)) {
            $note .= ' (Status: ' . $action . ')';
        }

        $order->add_order_note($note);

        /**
         * Handle important shipment states.
         *
         * IMPORTANT:
         * We do NOT automatically mark the
         * WooCommerce order completed when
         * Speedaf reports "In delivery".
         */
        if ($status['key'] === 'delivered') {

            update_post_meta(
                $orderId,
                '_sefrelshop_delivery_confirmed_by_carrier',
                'yes'
            );

            update_post_meta(
                $orderId,
                '_sefrelshop_delivery_confirmed_at',
                current_time('mysql')
            );

            $order->add_order_note(
                'Speedaf has reported this shipment as delivered. Awaiting customer confirmation.'
            );
        }

        /**
         * Returning shipment.
         */
        if ($status['key'] === 'returning') {

            $order->add_order_note(
                'Speedaf has reported that this shipment is being returned.'
            );
        }

        /**
         * Returned shipment.
         */
        if ($status['key'] === 'returned') {

            $order->add_order_note(
                'Speedaf has reported that this shipment has been returned.'
            );
        }

        /**
         * Cancelled shipment.
         */
        if ($status['key'] === 'cancelled') {

            $order->add_order_note(
                'Speedaf has reported that this shipment was cancelled.'
            );
        }

        /**
         * Clear WordPress caches where available.
         */
        clean_post_cache($orderId);

        return 'processed';
    }
}