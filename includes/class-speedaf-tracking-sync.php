<?php

/**
 * SefrelShop Speedaf Tracking Synchronisation
 *
 * Handles retrieval and storage of Speedaf tracking information
 * against WooCommerce orders.
 */

class SpeedafTrackingSync
{
    private SpeedafProvider $provider;

    private SpeedafConfig $config;

    public function __construct(
        SpeedafProvider $provider,
        SpeedafConfig $config
    ) {
        $this->provider = $provider;
        $this->config = $config;
    }

    /**
     * Synchronise tracking for a single WooCommerce order.
     */
    public function syncOrder(
        WC_Order $order
    ): array {

        $orderId = $order->get_id();

        $this->log(
            'info',
            'Tracking sync started for order #' . $orderId
        );

        /*
        |--------------------------------------------------------------------------
        | Step 1: Get Speedaf customer order number
        |--------------------------------------------------------------------------
        */

        $customerOrderNo = get_post_meta(
            $orderId,
            '_speedaf_customer_order_no',
            true
        );

        if (empty($customerOrderNo)) {

            $this->log(
                'warning',
                'No Speedaf customer order number found for order #' . $orderId
            );

            return [
                'success' => false,
                'status' => 'missing_customer_order_no',
                'message' => 'Speedaf customer order number not found.'
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Step 2: Build tracking request
        |--------------------------------------------------------------------------
        */

        $trackingData = [

            'customerCode' => $this->config->get(
                'customerCode'
            ),

            'customerOrderNos' => [

                (string) $customerOrderNo

            ]

        ];

        /*
        |--------------------------------------------------------------------------
        | Step 3: Call Speedaf tracking API
        |--------------------------------------------------------------------------
        */

        $result = $this->provider->track(
            $trackingData
        );

        if (
            !is_array($result) ||
            empty($result['success'])
        ) {

            $this->log(
                'error',
                'Speedaf tracking request failed for order #'
                . $orderId
                . ': '
                . wp_json_encode($result)
            );

            return [

                'success' => false,

                'status' => 'api_failed',

                'message' => 'Speedaf tracking request failed.',

                'result' => $result

            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Step 4: Decode decrypted response
        |--------------------------------------------------------------------------
        */

       $trackingResponse = null;

$decryptedResponse = $result['decrypted'] ?? null;


/*
|--------------------------------------------------------------------------
| Step 4: Handle empty/null tracking response
|--------------------------------------------------------------------------
*/

if (
    $decryptedResponse === null ||
    $decryptedResponse === '' ||
    $decryptedResponse === 'null'
) {

    $this->log(
        'info',
        'Speedaf returned no tracking events for order #'
        . $orderId
        . '. Customer order number: '
        . $customerOrderNo
    );

    return [

        'success' => true,

        'status' => 'no_tracking_events',

        'message' => 'Speedaf accepted the tracking request, but no tracking events are available yet.',

        'customer_order_no' => $customerOrderNo,

        'tracking' => [],

        'raw_decrypted' => $decryptedResponse

    ];
}


/*
|--------------------------------------------------------------------------
| Step 5: Decode tracking response
|--------------------------------------------------------------------------
*/

$trackingResponse = json_decode(
    $decryptedResponse,
    true
);

if (!is_array($trackingResponse)) {

    $this->log(
        'error',
        'Invalid Speedaf tracking response for order #'
        . $orderId
        . '. Decrypted response: '
        . $decryptedResponse
    );

    return [

        'success' => false,

        'status' => 'invalid_response',

        'message' => 'Speedaf returned an invalid tracking response.',

        'raw_decrypted' => $decryptedResponse,

        'result' => $result

    ];
}

        /*
        |--------------------------------------------------------------------------
        | Step 5: Validate Speedaf response
        |--------------------------------------------------------------------------
        */

        if (
            isset($trackingResponse['success']) &&
            $trackingResponse['success'] === false
        ) {

            $this->log(
                'warning',
                'Speedaf rejected tracking request for order #'
                . $orderId
                . ': '
                . wp_json_encode($trackingResponse)
            );

            return [

                'success' => false,

                'status' => 'speedaf_rejected',

                'message' => 'Speedaf rejected the tracking request.',

                'result' => $trackingResponse

            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Step 6: Extract tracking records
        |--------------------------------------------------------------------------
        */

        $records = $this->extractTrackingRecords(
            $trackingResponse
        );

        if (empty($records)) {

            $this->log(
                'info',
                'No tracking events returned for order #' . $orderId
            );

            return [

                'success' => true,

                'status' => 'no_tracking_events',

                'message' => 'No tracking events available yet.',

                'tracking' => []

            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Step 7: Sort events chronologically
        |--------------------------------------------------------------------------
        */

        usort(
            $records,
            function (
                array $a,
                array $b
            ): int {

                $timeA = strtotime(
                    $a['time'] ?? ''
                );

                $timeB = strtotime(
                    $b['time'] ?? ''
                );

                return $timeA <=> $timeB;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Step 8: Get latest tracking event
        |--------------------------------------------------------------------------
        */

        $latest = end($records);

        if (!is_array($latest)) {

            return [

                'success' => false,

                'status' => 'latest_event_failed',

                'message' => 'Unable to determine latest tracking event.'

            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Step 9: Save tracking information
        |--------------------------------------------------------------------------
        */

        update_post_meta(
            $orderId,
            '_speedaf_tracking_action',
            sanitize_text_field(
                $latest['action'] ?? ''
            )
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_status',
            sanitize_text_field(
                $latest['actionName'] ?? ''
            )
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_message',
            sanitize_text_field(
                $latest['msgEng'] ?? ''
            )
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_time',
            sanitize_text_field(
                $latest['time'] ?? ''
            )
        );

        update_post_meta(
            $orderId,
            '_speedaf_tracking_history',
            wp_json_encode($records)
        );

        update_post_meta(
            $orderId,
            '_speedaf_last_tracking_sync',
            current_time('mysql')
        );

        /*
        |--------------------------------------------------------------------------
        | Step 10: Determine internal status
        |--------------------------------------------------------------------------
        */

        $internalStatus = $this->mapStatus(
            $latest['action'] ?? null
        );

        update_post_meta(
            $orderId,
            '_speedaf_status',
            sanitize_text_field(
                $internalStatus
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Step 11: Add WooCommerce order note
        |--------------------------------------------------------------------------
        */

        $statusName = $latest['actionName'] ?? 'Unknown';

        $message = $latest['msgEng'] ?? '';

        $note = sprintf(
            'Speedaf tracking update: %s%s',
            $statusName,
            $message
                ? ' — ' . $message
                : ''
        );

        $order->add_order_note(
            $note
        );

        /*
        |--------------------------------------------------------------------------
        | Step 12: Log successful synchronisation
        |--------------------------------------------------------------------------
        */

        $this->log(
            'info',
            sprintf(
                'Tracking updated for order #%d. Status: %s. Events: %d.',
                $orderId,
                $internalStatus,
                count($records)
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Step 13: Return result
        |--------------------------------------------------------------------------
        */

        return [

            'success' => true,

            'status' => 'synced',

            'order_id' => $orderId,

            'customer_order_no' => $customerOrderNo,

            'internal_status' => $internalStatus,

            'latest' => $latest,

            'tracking' => $records

        ];
    }


    /**
     * Extract tracking records from Speedaf response.
     */
    private function extractTrackingRecords(
        array $response
    ): array {

        /*
         * Speedaf's response structure can contain
         * tracking records inside a data/result structure.
         *
         * We check the common structures without
         * assuming that every response has the same
         * nesting level.
         */

        $possible = [

            $response['data'] ?? null,

            $response['result'] ?? null,

            $response['records'] ?? null,

            $response['trackList'] ?? null,

            $response['tracking'] ?? null

        ];

        foreach ($possible as $candidate) {

            if (
                is_array($candidate) &&
                !empty($candidate)
            ) {

                /*
                 * Direct list of tracking records.
                 */

                if (
                    isset($candidate[0]) &&
                    is_array($candidate[0])
                ) {

                    return $candidate;
                }

                /*
                 * Nested tracking list.
                 */

                foreach (
                    [
                        'records',
                        'trackList',
                        'tracking',
                        'data'
                    ]
                    as $key
                ) {

                    if (
                        isset($candidate[$key]) &&
                        is_array($candidate[$key])
                    ) {

                        return $candidate[$key];
                    }
                }
            }
        }

        return [];
    }


    /**
     * Convert Speedaf status code into
     * SefrelShop internal shipment status.
     */
    private function mapStatus(
        $action
    ): string {

        $action = (string) $action;

        switch ($action) {

            /*
            |--------------------------------------------------------------------------
            | Shipment created / ordered
            |--------------------------------------------------------------------------
            */

            case '10':
                return 'shipment_created';


            /*
            |--------------------------------------------------------------------------
            | Warehouse / processing
            |--------------------------------------------------------------------------
            */

            case '150':
            case '181':
            case '190':
                return 'processing';


            /*
            |--------------------------------------------------------------------------
            | Picked up
            |--------------------------------------------------------------------------
            */

            case '1':
                return 'picked_up';


            /*
            |--------------------------------------------------------------------------
            | In transit
            |--------------------------------------------------------------------------
            */

            case '2':
            case '3':
                return 'in_transit';


            /*
            |--------------------------------------------------------------------------
            | Out for delivery
            |--------------------------------------------------------------------------
            */

            case '4':
                return 'out_for_delivery';


            /*
            |--------------------------------------------------------------------------
            | Delivered
            |--------------------------------------------------------------------------
            */

            case '5':
            case '16':
                return 'delivered';


            /*
            |--------------------------------------------------------------------------
            | Returning
            |--------------------------------------------------------------------------
            */

            case '-710':
                return 'returning';


            /*
            |--------------------------------------------------------------------------
            | Returned
            |--------------------------------------------------------------------------
            */

            case '730':
                return 'returned';


            /*
            |--------------------------------------------------------------------------
            | Exception
            |--------------------------------------------------------------------------
            */

            case '401':
                return 'exception';


            /*
            |--------------------------------------------------------------------------
            | Unknown status
            |--------------------------------------------------------------------------
            */

            default:
                return 'unknown';
        }
    }


    /**
     * Write to WooCommerce logger.
     */
    private function log(
        string $level,
        string $message
    ): void {

        if (
            function_exists('wc_get_logger')
        ) {

            $logger = wc_get_logger();

            $logger->log(
                $level,
                $message,
                [
                    'source' => 'sefrelshop-speedaf'
                ]
            );
        }
    }
}