<?php

class SpeedafConfig
{
    private array $config;

    public function __construct()
    {
        $this->config = require dirname(__DIR__) . '/config.php';
    }

    public function get(string $key)
    {
        return $this->config[$key] ?? null;
    }

    public function getBaseUrl(): string
    {
        return $this->config['sandbox']
            ? $this->config['sandbox_url']
            : $this->config['production_url'];
    }
}