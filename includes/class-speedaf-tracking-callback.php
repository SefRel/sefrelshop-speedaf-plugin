<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafTrackingCallback
{
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
     * Receive Speedaf tracking callback.
     */
    public function handle(WP_REST_Request $request)
    {
        $body = $request->get_body();

        /*
         * Keep the raw callback for debugging.
         */
        if (defined('WP_DEBUG') && WP_DEBUG) {
            update_option(
                'sefrelshop_speedaf_last_callback',
                $body
            );
        }

        if (empty($body)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Empty tracking callback received.',
                ],
                400
            );
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Invalid JSON received.',
                ],
                400
            );
        }

        /*
         * Speedaf may send either:
         *
         * {
         *   "mailNo": "...",
         *   "action": "4"
         * }
         *
         * or an array of events.
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
     */
    private function processEvent(array $event): string
    {
        $mailNo = isset($event['mailNo'])
            ? sanitize_text_field($event['mailNo'])
            : '';

        if (empty($mailNo)) {
            return 'failed';
        }

        /*
         * Find WooCommerce order using the Speedaf waybill.
         */
        $orders = wc_get_orders(
            [
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_key'   => '_speedaf_bill_code',
                'meta_value' => $mailNo,
            ]
        );

        /*
         * Fallback: try the customer order number if
         * the waybill lookup doesn't find the order.
         */
        if (empty($orders)) {

            $orders = wc_get_orders(
                [
                    'limit'      => 1,
                    'type'       => 'shop_order',
                    'meta_key'   => '_speedaf_customer_order_no',
                    'meta_value' => $mailNo,
                ]
            );
        }

        if (empty($orders)) {

            if (defined('WP_DEBUG') && WP_DEBUG) {
                update_option(
                    'sefrelshop_speedaf_unmatched_' . md5($mailNo),
                    $event
                );
            }

            return 'failed';
        }

        /** @var WC_Order $order */
        $order = $orders[0];

        /*
         * Extract event values.
         */
        $action = isset($event['action'])
            ? sanitize_text_field((string) $event['action'])
            : '';

        $subAction = isset($event['subAction'])
            ? sanitize_text_field((string) $event['subAction'])
            : '';

        $message = isset($event['message'])
            ? sanitize_text_field((string) $event['message'])
            : '';

        $msgEng = isset($event['msgEng'])
            ? sanitize_text_field((string) $event['msgEng'])
            : '';

        $msgLoc = isset($event['msgLoc'])
            ? sanitize_text_field((string) $event['msgLoc'])
            : '';

        $time = isset($event['time'])
            ? sanitize_text_field((string) $event['time'])
            : '';

        $country = isset($event['country'])
            ? sanitize_text_field((string) $event['country'])
            : '';

        $countryCode = isset($event['countryCode'])
            ? sanitize_text_field((string) $event['countryCode'])
            : '';

        $pictureUrl = isset($event['pictureUrl'])
            ? esc_url_raw($event['pictureUrl'])
            : '';

        /*
         * Create a stable fingerprint for this event.
         *
         * This prevents Speedaf retries from creating
         * duplicate tracking history.
         */
        $eventFingerprint = md5(
            $mailNo .
            '|' .
            $action .
            '|' .
            $subAction .
            '|' .
            $time .
            '|' .
            $msgEng .
            '|' .
            $message
        );

        /*
         * Read tracking history using WooCommerce CRUD.
         */
        $history = $order->get_meta(
            '_speedaf_tracking_history',
            true
        );

        /*
         * Backward compatibility:
         *
         * Older versions stored the history using
         * update_post_meta(). Import that data if it
         * exists but WooCommerce CRUD does not see it.
         */
        if (empty($history)) {

            $legacyHistory = get_post_meta(
                $order->get_id(),
                '_speedaf_tracking_history',
                true
            );

            if (is_string($legacyHistory) && !empty($legacyHistory)) {

                $decoded = json_decode(
                    $legacyHistory,
                    true
                );

                if (is_array($decoded)) {
                    $history = $decoded;
                }

            } elseif (is_array($legacyHistory)) {

                $history = $legacyHistory;
            }
        }

        if (!is_array($history)) {
            $history = [];
        }

        /*
         * Check whether this exact event already exists.
         */
        foreach ($history as $existingEvent) {

            if (!is_array($existingEvent)) {
                continue;
            }

            $existingFingerprint = md5(
                ($existingEvent['mailNo'] ?? '') .
                '|' .
                ($existingEvent['action'] ?? '') .
                '|' .
                ($existingEvent['subAction'] ?? '') .
                '|' .
                ($existingEvent['time'] ?? '') .
                '|' .
                ($existingEvent['msgEng'] ?? '') .
                '|' .
                ($existingEvent['message'] ?? '')
            );

            if ($existingFingerprint === $eventFingerprint) {

                /*
                 * Even if this event already exists,
                 * make sure the history is stored through
                 * WooCommerce CRUD.
                 */
                $order->update_meta_data(
                    '_speedaf_tracking_history',
                    $history
                );

                $order->save();

                return 'duplicate';
            }
        }

        /*
         * Add the new event.
         */
        $history[] = [
            'mailNo'      => $mailNo,
            'action'      => $action,
            'subAction'   => $subAction,
            'message'     => $message,
            'msgEng'      => $msgEng,
            'msgLoc'      => $msgLoc,
            'time'        => $time,
            'pictureUrl'  => $pictureUrl,
            'country'     => $country,
            'countryCode' => $countryCode,
            'fingerprint' => $eventFingerprint,
            'received_at' => current_time('mysql'),
        ];

        /*
         * Sort history oldest → newest.
         */
        usort(
            $history,
            function ($a, $b) {

                $timeA = isset($a['time'])
                    ? strtotime($a['time'])
                    : 0;

                $timeB = isset($b['time'])
                    ? strtotime($b['time'])
                    : 0;

                return $timeA <=> $timeB;
            }
        );

        /*
         * Save the latest tracking information.
         */
        $order->update_meta_data(
            '_speedaf_tracking_action',
            $action
        );

        $order->update_meta_data(
            '_speedaf_tracking_sub_action',
            $subAction
        );

        $displayMessage = $msgEng ?: ($message ?: $msgLoc);

        $order->update_meta_data(
            '_speedaf_tracking_message',
            $displayMessage
        );

        $order->update_meta_data(
            '_speedaf_tracking_time',
            $time
        );

        $order->update_meta_data(
            '_speedaf_tracking_country',
            $country
        );

        $order->update_meta_data(
            '_speedaf_tracking_country_code',
            $countryCode
        );

        if (!empty($pictureUrl)) {

            $order->update_meta_data(
                '_speedaf_tracking_picture',
                $pictureUrl
            );
        }

        /*
         * This is the important part:
         *
         * Customer tracking now receives the complete
         * Speedaf history through WooCommerce CRUD.
         */
        $order->update_meta_data(
            '_speedaf_tracking_history',
            $history
        );

        /*
         * The latest Speedaf action becomes the current
         * shipment status.
         *
         * DO NOT set this to "tracking_subscribed".
         */
        if (!empty($action)) {

            $order->update_meta_data(
                '_speedaf_status',
                $action
            );
        }

        /*
         * Record when the callback was processed.
         */
        $order->update_meta_data(
            '_speedaf_last_tracking_update',
            current_time('mysql')
        );

        $order->save();

        /*
         * Add an internal WooCommerce order note.
         */
        $note = 'Speedaf tracking update';

        if (!empty($displayMessage)) {
            $note .= ': ' . $displayMessage;
        }

        if (!empty($action)) {
            $note .= ' (Status: ' . $action . ')';
        }

        $order->add_order_note($note);

        return 'processed';
    }
}