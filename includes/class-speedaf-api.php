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
    * Build full API URL
    */
    private function buildUrl(
    string $endpoint,
    string $timestamp
    ): string
    {
    return sprintf(
        "%s%s?appCode=%s&timestamp=%s",
        rtrim($this->config->getBaseUrl(), '/'),
        $endpoint,
        $this->config->get('appCode'),
        $timestamp
    );
    }


    /**
 * Send POST request to Speedaf
 */
    public function post(string $endpoint, array $data)
    {
        $timestamp = $this->encryption->generateTimestamp();

        $json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

        $payload = $this->encryption->buildPayload(
            $timestamp,
            $json
        );

        $encrypted = $this->encryption->encrypt(
            $payload
        );

        $url = $this->buildUrl($endpoint, $timestamp);

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

    $response = curl_exec($ch);

    $decryptedResponse = null;

if ($response !== false) {

    $decoded = json_decode($response, true);

    if (
        isset($decoded['success']) &&
        $decoded['success'] === true &&
        !empty($decoded['data'])
    ) {

        try {

            $decryptedResponse = $this->encryption->decrypt(
                $decoded['data']
            );

        } catch (Exception $e) {

            $decryptedResponse = 'Decryption failed: ' . $e->getMessage();

        }

    }

}

    if ($response === false) {
    $error = curl_error($ch);
    } else {
    $error = null;
    }

    $status = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    return [
        'status' => $status,
        'error' => $error,
        'response' => $response,
        'decrypted' => $decryptedResponse,
        'url' => $url,
        'json' => $json,
        'payload' => $payload,
        'encrypted' => $encrypted
    ];
    }
}