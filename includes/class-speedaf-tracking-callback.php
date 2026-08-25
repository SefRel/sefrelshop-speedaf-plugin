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
     * Handle Speedaf tracking callback.
     */
    public function handle(WP_REST_Request $request)
    {
        $body = $request->get_body();

        /**
         * Log raw callback during development.
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
         * Speedaf documentation shows
         * tracking feedback as an array.
         *
         * We also support a single object
         * to make the endpoint more tolerant.
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
        $failed    = 0;

        foreach ($data as $event) {

            if (!is_array($event)) {
                $failed++;
                continue;
            }

            $result = $this->processEvent($event);

            if ($result) {
                $processed++;
            } else {
                $failed++;
            }
        }

        /**
         * Speedaf expects a successful
         * response when the callback has
         * been received.
         */
        return new WP_REST_Response(
            [
                'success'   => true,
                'processed' => $processed,
                'failed'    => $failed,
            ],
            200
        );
    }

    /**
     * Process one Speedaf tracking event.
     */
    private function processEvent(array $event): bool
    {
        $mailNo = isset($event['mailNo'])
            ? sanitize_text_field($event['mailNo'])
            : '';

        if (empty($mailNo)) {
            return false;
        }

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

        if (empty($orders)) {

            /**
             * Keep the event for diagnostics
             * if no matching order exists.
             */
            if (defined('WP_DEBUG') && WP_DEBUG) {
                update_option(
                    'sefrelshop_speedaf_unmatched_' . md5($mailNo),
                    $event
                );
            }

            return false;
        }

        $order = $orders[0];

        /**
         * Extract tracking information.
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
         * Store latest tracking information.
         */
        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_action',
            $action
        );

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_sub_action',
            $subAction
        );

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_message',
            $msgEng ?: $message
        );

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_time',
            $time
        );

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_country',
            $country
        );

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_country_code',
            $countryCode
        );

        if (!empty($pictureUrl)) {
            update_post_meta(
                $order->get_id(),
                '_speedaf_tracking_picture',
                $pictureUrl
            );
        }

        /**
         * Store complete tracking event.
         */
        $history = get_post_meta(
            $order->get_id(),
            '_speedaf_tracking_history',
            true
        );

        if (!is_array($history)) {
            $history = [];
        }

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
            'received_at' => current_time('mysql'),
        ];

        update_post_meta(
            $order->get_id(),
            '_speedaf_tracking_history',
            $history
        );

        /**
         * Add a WooCommerce order note.
         */
        $displayMessage = $msgEng ?: $message;

        $note = 'Speedaf tracking update';

        if (!empty($displayMessage)) {
            $note .= ': ' . $displayMessage;
        }

        if (!empty($action)) {
            $note .= ' (Status: ' . $action . ')';
        }

        $order->add_order_note($note);

        /**
         * Update our internal Speedaf status.
         */
        update_post_meta(
            $order->get_id(),
            '_speedaf_status',
            $action
        );

        return true;
    }
}