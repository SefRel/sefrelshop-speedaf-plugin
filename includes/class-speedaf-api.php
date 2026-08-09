<?php

class SpeedafApi
{
    private SpeedafConfig $config;

    private SpeedafEncryption $encryption;

    public function __construct(
        SpeedafConfig $config,
        SpeedafEncryption $encryption
    ) {
        $this->config = $config;
        $this->encryption = $encryption;
    }

    /**
     * Build full API URL.
     */
    private function buildUrl(
        string $endpoint,
        string $timestamp
    ): string {

        return sprintf(
            "%s%s?appCode=%s&timestamp=%s",
            rtrim($this->config->getBaseUrl(), '/'),
            $endpoint,
            rawurlencode($this->config->get('appCode')),
            rawurlencode($timestamp)
        );
    }

    /**
     * Send POST request to Speedaf.
     */
    public function post(
        string $endpoint,
        array $data
    ): array {

        /*
         * --------------------------------------------------------------
         * Step 1: Generate timestamp
         * --------------------------------------------------------------
         */

        try {

            $timestamp = $this->encryption->generateTimestamp();

        } catch (Throwable $e) {

            return [
                'success' => false,
                'status' => 0,
                'error' => 'Timestamp generation failed: ' . $e->getMessage(),
                'response' => null
            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 2: Convert shipment data to JSON
         * --------------------------------------------------------------
         */

        try {

            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

        } catch (Throwable $e) {

            return [
                'success' => false,
                'status' => 0,
                'error' => 'JSON encoding failed: ' . $e->getMessage(),
                'response' => null
            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 3: Build Speedaf signed payload
         * --------------------------------------------------------------
         */

        try {

            $payload = $this->encryption->buildPayload(
                $timestamp,
                $json
            );

            $encrypted = $this->encryption->encrypt(
                $payload
            );

        } catch (Throwable $e) {

            return [
                'success' => false,
                'status' => 0,
                'error' => 'Encryption failed: ' . $e->getMessage(),
                'response' => null
            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 4: Build URL
         * --------------------------------------------------------------
         */

        $url = $this->buildUrl(
            $endpoint,
            $timestamp
        );

        /*
         * --------------------------------------------------------------
         * Step 5: Check cURL
         * --------------------------------------------------------------
         */

        if (!function_exists('curl_init')) {

            return [
                'success' => false,
                'status' => 0,
                'error' => 'cURL is not available on this server.',
                'response' => null
            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 6: Send request
         * --------------------------------------------------------------
         */

        $ch = curl_init();

        if ($ch === false) {

            return [
                'success' => false,
                'status' => 0,
                'error' => 'Unable to initialise cURL.',
                'response' => null
            ];
        }

        curl_setopt_array($ch, [

            CURLOPT_URL => $url,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $encrypted,

            CURLOPT_HTTPHEADER => [

                'Content-Type: application/json',

                'Content-Length: ' . strlen($encrypted)

            ],

            /*
             * Keep these aligned with the
             * Speedaf API documentation for now.
             *
             * We can harden TLS after the
             * sandbox connection is confirmed.
             */
            CURLOPT_SSL_VERIFYPEER => false,

            CURLOPT_SSL_VERIFYHOST => false,

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_TIMEOUT => 30

        ]);

        $response = curl_exec($ch);

        $curlError = curl_error($ch);

        $curlErrNo = curl_errno($ch);

        $status = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        /*
         * --------------------------------------------------------------
         * Step 7: Handle cURL failure
         * --------------------------------------------------------------
         */

        if ($response === false) {

            return [

                'success' => false,

                'status' => $status,

                'error' => $curlError ?: 'Unknown cURL error.',

                'curl_errno' => $curlErrNo,

                'response' => null

            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 8: Decode Speedaf response
         * --------------------------------------------------------------
         */

        $decoded = json_decode(
            $response,
            true
        );

        /*
         * If Speedaf returns something that
         * isn't JSON, preserve the raw response.
         */
        if (!is_array($decoded)) {

            return [

                'success' => false,

                'status' => $status,

                'error' => 'Speedaf returned a non-JSON response.',

                'curl_errno' => $curlErrNo,

                'response' => $response,

                'decrypted' => null

            ];
        }

        /*
         * --------------------------------------------------------------
         * Step 9: Attempt to decrypt successful response
         * --------------------------------------------------------------
         */

        $decryptedResponse = null;

        if (
            isset($decoded['success']) &&
            $decoded['success'] === true &&
            !empty($decoded['data'])
        ) {

            try {

                $decryptedResponse =
                    $this->encryption->decrypt(
                        $decoded['data']
                    );

            } catch (Throwable $e) {

                $decryptedResponse =
                    'Decryption failed: '
                    . $e->getMessage();
            }
        }

        /*
         * --------------------------------------------------------------
         * Step 10: Return standard response
         * --------------------------------------------------------------
         */

        $result = [

            'success' => (
                isset($decoded['success'])
                && $decoded['success'] === true
            ),

            'status' => $status,

            'error' => $curlError,

            'curl_errno' => $curlErrNo,

            'response' => $response,

            'decoded' => $decoded,

            'decrypted' => $decryptedResponse

        ];

        /*
         * --------------------------------------------------------------
         * Development diagnostics
         * --------------------------------------------------------------
         */

        if (
            defined('WP_DEBUG') &&
            WP_DEBUG
        ) {

            $result['url'] = $url;

            $result['json'] = $json;

            $result['payload'] = $payload;

            $result['encrypted'] = $encrypted;
        }

        return $result;
    }

    /**
     * Return plugin configuration.
     */
    public function getConfig(): SpeedafConfig
    {
        return $this->config;
    }
}