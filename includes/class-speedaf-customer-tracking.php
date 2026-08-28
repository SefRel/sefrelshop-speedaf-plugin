<?php

if (!defined('ABSPATH')) {
    exit;
}

class SpeedafCustomerTracking
{
    /**
     * Register WooCommerce customer-facing hooks.
     */
    public function registerHooks(): void
    {
        /**
         * Display tracking information
         * on the WooCommerce View Order page.
         */
        add_action(
            'woocommerce_order_details_after_order_table',
            [$this, 'renderTracking'],
            20,
            1
        );
    }

    /**
     * Render Speedaf tracking information.
     */
    public function renderTracking($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $orderId = $order->get_id();

        /**
         * Only display tracking if
         * a Speedaf shipment exists.
         */
        $billCode = get_post_meta(
            $orderId,
            '_speedaf_bill_code',
            true
        );

        if (empty($billCode)) {
            return;
        }

        /**
         * Retrieve latest tracking data.
         */
        $status = get_post_meta(
            $orderId,
            '_speedaf_status',
            true
        );

        $message = get_post_meta(
            $orderId,
            '_speedaf_tracking_message',
            true
        );

        $trackingTime = get_post_meta(
            $orderId,
            '_speedaf_tracking_time',
            true
        );

        $history = get_post_meta(
            $orderId,
            '_speedaf_tracking_history',
            true
        );

        if (!is_array($history)) {
            $history = [];
        }

        /**
         * Convert Speedaf status code
         * into customer-friendly wording.
         */
        $statusLabel = $this->getStatusLabel($status);

        ?>

        <section
            class="sefrelshop-speedaf-tracking"
            style="
                margin-top: 30px;
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
                style="margin-bottom: 24px;"
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
            /**
             * Delivery progress indicator.
             */
            $this->renderProgress($status);
            ?>

            <?php
            /**
             * Tracking history.
             */
            if (!empty($history)) :
            ?>

                <div
                    class="sefrelshop-tracking-history"
                    style="margin-top: 30px;"
                >

                    <h3>
                        Tracking History
                    </h3>

                    <?php
                    /**
                     * Show newest event first.
                     */
                    $history = array_reverse($history);

                    foreach ($history as $event) :

                        if (!is_array($event)) {
                            continue;
                        }

                        $eventStatus = isset($event['action'])
                            ? $event['action']
                            : '';

                        $eventMessage = !empty($event['msgEng'])
                            ? $event['msgEng']
                            : (
                                !empty($event['message'])
                                    ? $event['message']
                                    : ''
                            );

                        $eventTime = isset($event['time'])
                            ? $event['time']
                            : '';

                        $eventCountry = isset($event['country'])
                            ? $event['country']
                            : '';

                        ?>

                        <div
                            class="sefrelshop-tracking-event"
                            style="
                                padding: 15px 0;
                                border-bottom: 1px solid #eee;
                            "
                        >

                            <p style="margin: 0 0 5px;">
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

                                <p style="margin: 0 0 5px;">
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

            <?php endif; ?>

        </section>

        <?php
    }

    /**
     * Render delivery progress.
     */
    private function renderProgress(string $status): void
    {
        /**
         * Main delivery stages.
         */
        $stages = [
            '10' => 'Order Confirmed',
            '1'  => 'Picked Up',
            '2'  => 'In Transit',
            '3'  => 'Arrived',
            '4'  => 'Out for Delivery',
            '5'  => 'Delivered',
        ];

        /**
         * Determine current position.
         */
        $stageKeys = array_keys($stages);

        $currentIndex = array_search(
            (string) $status,
            $stageKeys,
            true
        );

        /**
         * Unknown / special status.
         */
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

                $completed = $stageIndex <= $currentIndex;
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
                        echo $completed ? '✓' : '';
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
     * Convert Speedaf status codes
     * into customer-friendly labels.
     */
    private function getStatusLabel(string $status): string
    {
        $statuses = [

            '10' => 'Order Confirmed',

            '1' => 'Picked Up',

            '2' => 'In Transit',

            '3' => 'Arrived',

            '4' => 'Out for Delivery',

            '5' => 'Delivered',

            '-10' => 'Cancelled',

            '-710' => 'Returning',

            '730' => 'Returned',

            '18' => 'Self Collection',

            '16' => 'Delivered by Franchisee',

            '150' => 'Inbound',

            '181' => 'Packaged',

            '190' => 'Outbound',

            '402' => 'Customs Declaration',

            '220' => 'Flight Departed',

            '230' => 'Flight Landed',

            '360' => 'In Clearance',

            '401' => 'Clearance Exception',

            '370' => 'Clearance Completed',

        ];

        return $statuses[$status] ?? 'Shipment In Progress';
    }
}