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
}