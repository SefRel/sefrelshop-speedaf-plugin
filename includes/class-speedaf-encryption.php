<?php

class SpeedafEncryption
{
    private string $secretKey;
    private string $iv;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;

        $this->iv = pack(
            'C*',
            0x12,
            0x34,
            0x56,
            0x78,
            0x90,
            0xAB,
            0xCD,
            0xEF
        );
    }

    /**
     * Generate MD5 Signature
     */
    public function generateSignature(string $timestamp, string $jsonData): string
    {
        return md5($timestamp . $this->secretKey . $jsonData);
    }

    /**
     * PKCS5 Padding
     */
    private function pkcs5Pad(string $text): string
    {
        $blockSize = 8;

        $pad = $blockSize - (strlen($text) % $blockSize);

        return $text . str_repeat(chr($pad), $pad);
    }

    /**
    * Encrypt data using DES-CBC
    */
    public function encrypt(string $plainText): string
    {
        $plainText = $this->pkcs5Pad($plainText);

        $encrypted = openssl_encrypt(
        $plainText,
        'DES-CBC',
        $this->secretKey,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        $this->iv
    );

    if ($encrypted === false) {
        throw new Exception('Encryption failed.');
    }

    return base64_encode($encrypted);
    }

    /**
    * Generate current timestamp in milliseconds
    */
    public function generateTimestamp(): string
    {
    return (string) round(microtime(true) * 1000);
    }

    /**
    * Build Speedaf payload
    */
    public function buildPayload(string $timestamp, string $jsonData): string
    {
    $payload = [
        'sign' => $this->generateSignature(
            $timestamp,
            $jsonData
        ),
        'data' => $jsonData
    ];

    return json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    );
    }
}
