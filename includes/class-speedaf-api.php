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
            $this->config->get('appCode'),
            $timestamp
        );

    }

    /**
     * Send POST request to Speedaf.
     */
    public function post(
        string $endpoint,
        array $data
    ): array {

        $timestamp = $this->encryption
            ->generateTimestamp();

        /**
         * Convert payload to JSON.
         */
        try {

            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

        } catch (JsonException $e) {

            return [

                'status' => 0,

                'error' => $e->getMessage(),

                'response' => null

            ];

        }

        /**
         * Encrypt request payload.
         */
        $payload = $this->encryption
            ->buildPayload(
                $timestamp,
                $json
            );

        $encrypted = $this->encryption
            ->encrypt($payload);

        $url = $this->buildUrl(
            $endpoint,
            $timestamp
        );

        /**
         * Initialise cURL.
         */
        $ch = curl_init();

        curl_setopt_array($ch, [

            CURLOPT_URL => $url,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $encrypted,

            CURLOPT_HTTPHEADER => [

                'Content-Type: application/json',

                'Content-Length: ' . strlen($encrypted)

            ],

            CURLOPT_SSL_VERIFYPEER => false,

            CURLOPT_SSL_VERIFYHOST => false,

            CURLOPT_TIMEOUT => 30

        ]);

        /**
         * Execute request.
         */
        $response = curl_exec($ch);

        $curlError = curl_error($ch);

        $curlErrNo = curl_errno($ch);

        $status = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        /**
         * Attempt to decrypt response.
         */
        $decryptedResponse = null;

        if ($response !== false) {

            $decoded = json_decode(
                $response,
                true
            );

            if (
                isset($decoded['success']) &&
                $decoded['success'] === true &&
                !empty($decoded['data'])
            ) {

                try {

                    $decryptedResponse =
                        $this->encryption
                            ->decrypt(
                                $decoded['data']
                            );

                } catch (Exception $e) {

                    $decryptedResponse =

                        'Decryption failed: '

                        . $e->getMessage();

                }

            }

        }

        /**
         * Standard response.
         */
        $result = [

            'status' => $status,

            'error' => $curlError,

            'curl_errno' => $curlErrNo,

            'response' => $response,

            'decrypted' => $decryptedResponse

        ];

        /**
         * Only expose debugging data
         * during development.
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