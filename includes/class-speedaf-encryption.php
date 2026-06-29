<?php

class SpeedafEncryption
{
    private string $secretKey;

    /**
     * Fixed IV supplied by Speedaf.
     */
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
     * Generate the Speedaf signature.
     */
    public function generateSignature(string $timestamp, string $jsonData): string
    {
        return md5($timestamp . $this->secretKey . $jsonData);
    }
}