<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafCustomerTracking
{
    /**
     * Prevent duplicate rendering when multiple hooks fire.
     */
    private static bool $rendered = false;

    public function registerHooks(): void
    {
        /*
         * Standard WooCommerce View Order page.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderTracking'],
            20,
            1
        );

        /*
         * Additional fallback for themes/templates
         * that do not execute the standard hook above.
         */
        add_action(
            'woocommerce_view_order',
            [$this, 'renderTrackingById'],
            20,
            1
        );
    }

    /**
     * Render from WC_Order.
     */
    public function renderTracking($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $this->render($order);
    }

    /**
     * Fallback render from order ID.
     */
    public function renderTrackingById($orderId): void
    {
        $orderId = absint($orderId);

        if (!$orderId) {
            return;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->render($order);
    }

    /**
     * Main tracking renderer.
     */
    private function render(WC_Order $order): void
    {
        if (self::$rendered) {
            return;
        }

        $orderId = $order->get_id();

        /*
         * Only show tracking for Speedaf shipments.
         */
        $billCode = $order->get_meta(
            '_speedaf_bill_code',
            true
        );

        if (empty($billCode)) {
            return;
        }

        self::$rendered = true;

        $status = $order->get_meta(
            '_speedaf_status',
            true
        );

        $message = $order->get_meta(
            '_speedaf_tracking_message',
            true
        );

        $trackingTime = $order->get_meta(
            '_speedaf_tracking_time',
            true
        );

        $history = $order->get_meta(
            '_speedaf_tracking_history',
            true
        );

        /*
         * Older versions of the plugin may have stored
         * tracking history as JSON.
         */
        if (is_string($history) && !empty($history)) {
            $decodedHistory = json_decode(
                $history,
                true
            );

            if (is_array($decodedHistory)) {
                $history = $decodedHistory;
            }
        }

        if (!is_array($history)) {
            $history = [];
        }

        /*
         * Sort newest first.
         */
        if (!empty($history)) {
            usort(
                $history,
                function ($a, $b) {
                    $timeA = isset($a['time'])
                        ? strtotime($a['time'])
                        : 0;

                    $timeB = isset($b['time'])
                        ? strtotime($b['time'])
                        : 0;

                    return $timeB <=> $timeA;
                }
            );
        }

        $statusLabel = $this->getStatusLabel(
            (string) $status
        );

        ?>
        <section
            class="sefrelshop-speedaf-tracking"
            style="
                margin-top: 30px;
                margin-bottom: 30px;
                padding: 24px;
                border: 1px solid #e5e5e5;
                border-radius: 8px;
                background: #fff;
            "
        >

            <h2 style="margin-top: 0;">
                Track Your Order
            </h2>

            <div
                class="sefrelshop-tracking-summary"
                style="margin-bottom: 25px;"
            >

                <p>
                    <strong>Speedaf Waybill:</strong>
                    <?php echo esc_html($billCode); ?>
                </p>

                <p>
                    <strong>Current Status:</strong>
                    <?php echo esc_html($statusLabel); ?>
                </p>

                <?php if (!empty($message)) : ?>

                    <p>
                        <strong>Latest Update:</strong>
                        <?php echo esc_html($message); ?>
                    </p>

                <?php endif; ?>

                <?php if (!empty($trackingTime)) : ?>

                    <p>
                        <strong>Last Updated:</strong>
                        <?php echo esc_html($trackingTime); ?>
                    </p>

                <?php endif; ?>

            </div>

            <?php
            $this->renderProgress(
                (string) $status
            );
            ?>

            <?php if (!empty($history)) : ?>

                <div
                    class="sefrelshop-tracking-history"
                    style="margin-top: 30px;"
                >

                    <h3>
                        Tracking History
                    </h3>

                    <?php foreach ($history as $event) : ?>

                        <?php
                        if (!is_array($event)) {
                            continue;
                        }

                        $eventStatus = isset($event['action'])
                            ? (string) $event['action']
                            : '';

                        $eventMessage = '';

                        if (!empty($event['msgEng'])) {
                            $eventMessage =
                                $event['msgEng'];
                        } elseif (!empty($event['message'])) {
                            $eventMessage =
                                $event['message'];
                        } elseif (!empty($event['msgLoc'])) {
                            $eventMessage =
                                $event['msgLoc'];
                        }

                        $eventTime = isset($event['time'])
                            ? (string) $event['time']
                            : '';

                        $eventCountry = isset($event['country'])
                            ? (string) $event['country']
                            : '';
                        ?>

                        <div
                            class="sefrelshop-tracking-event"
                            style="
                                padding: 15px 0;
                                border-bottom: 1px solid #eee;
                            "
                        >

                            <p style="margin: 0 0 6px;">
                                <strong>
                                    <?php
                                    echo esc_html(
                                        $this->getStatusLabel(
                                            $eventStatus
                                        )
                                    );
                                    ?>
                                </strong>
                            </p>

                            <?php if (!empty($eventMessage)) : ?>

                                <p style="margin: 0 0 6px;">
                                    <?php
                                    echo esc_html(
                                        $eventMessage
                                    );
                                    ?>
                                </p>

                            <?php endif; ?>

                            <?php if (!empty($eventTime)) : ?>

                                <small>
                                    <?php
                                    echo esc_html(
                                        $eventTime
                                    );
                                    ?>
                                </small>

                            <?php endif; ?>

                            <?php if (!empty($eventCountry)) : ?>

                                <small>
                                    —
                                    <?php
                                    echo esc_html(
                                        $eventCountry
                                    );
                                    ?>
                                </small>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else : ?>

                <p>
                    Tracking information is not available yet.
                </p>

            <?php endif; ?>

        </section>
        <?php
    }

    /**
     * Render shipment progress.
     */
    private function renderProgress(string $status): void
    {
        $stages = [
            '10' => 'Order Confirmed',
            '1'  => 'Picked Up',
            '2'  => 'In Transit',
            '3'  => 'Arrived',
            '4'  => 'Out for Delivery',
            '5'  => 'Delivered',
        ];

        /*
         * Convert internal statuses used by TrackingSync
         * into equivalent Speedaf progress codes.
         */
        $internalMap = [
            'processing' =>
                '10',

            'created' =>
                '10',

            'tracking_subscribed' =>
                '10',

            'picked_up' =>
                '1',

            'in_transit' =>
                '2',

            'out_for_delivery' =>
                '4',

            'delivered' =>
                '5',

            'returning' =>
                '-710',

            'returned' =>
                '730',
        ];

        if (
            isset($internalMap[$status])
        ) {
            $status = $internalMap[$status];
        }

        $stageKeys = array_keys($stages);

        $currentIndex = array_search(
            $status,
            $stageKeys,
            true
        );

        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        ?>

        <div
            class="sefrelshop-tracking-progress"
            style="margin: 30px 0;"
        >

            <?php foreach ($stages as $code => $label) : ?>

                <?php
                $stageIndex = array_search(
                    $code,
                    $stageKeys,
                    true
                );

                $completed =
                    $stageIndex <= $currentIndex;
                ?>

                <div
                    style="
                        display: flex;
                        align-items: center;
                        margin-bottom: 12px;
                    "
                >

                    <span
                        style="
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            width: 28px;
                            height: 28px;
                            border-radius: 50%;
                            border: 2px solid #ccc;
                            margin-right: 10px;
                            font-size: 13px;
                        "
                    >
                        <?php
                        echo $completed
                            ? '✓'
                            : '';
                        ?>
                    </span>

                    <span>
                        <?php
                        echo esc_html($label);
                        ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

        <?php
    }

    /**
     * Convert Speedaf status codes into
     * customer-friendly labels.
     */
    private function getStatusLabel(
        string $status
    ): string {

        $statuses = [

            '10' =>
                'Order Confirmed',

            '1' =>
                'Picked Up',

            '2' =>
                'In Transit',

            '3' =>
                'Arrived',

            '4' =>
                'Out for Delivery',

            '5' =>
                'Delivered',

            '-10' =>
                'Cancelled',

            '-710' =>
                'Returning',

            '730' =>
                'Returned',

            '18' =>
                'Self Collection',

            '16' =>
                'Delivered by Franchisee',

            '150' =>
                'Inbound',

            '181' =>
                'Packaged',

            '190' =>
                'Outbound',

            '402' =>
                'Customs Declaration',

            '220' =>
                'Flight Departed',

            '230' =>
                'Flight Landed',

            '360' =>
                'In Clearance',

            '401' =>
                'Clearance Exception',

            '370' =>
                'Clearance Completed',

            'processing' =>
                'Processing',

            'created' =>
                'Shipment Created',

            'tracking_subscribed' =>
                'Tracking Active',

            'tracking_subscription_failed' =>
                'Tracking Subscription Requires Attention',

            'picked_up' =>
                'Picked Up',

            'in_transit' =>
                'In Transit',

            'out_for_delivery' =>
                'Out for Delivery',

            'delivered' =>
                'Delivered',

            'returning' =>
                'Returning',

            'returned' =>
                'Returned',

            'action_required' =>
                'Action Required',
        ];

        return $statuses[$status]
            ?? 'Shipment In Progress';
    }
}