<?php

class LogisticsManager
{
    /**
     * Registered shipping providers.
     *
     * @var ShippingProvider[]
     */
    private array $providers = [];

    /**
     * Register a provider.
     */
    public function registerProvider(
        ShippingProvider $provider
    ): void {
        $this->providers[] = $provider;
    }

    /**
     * Return every registered provider.
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Return every provider that supports the order.
     */
    public function getSupportedProviders(
        array $order
    ): array {

        $supported = [];

        foreach ($this->providers as $provider) {

            if ($provider->supports($order)) {
                $supported[] = $provider;
            }

        }

        return $supported;
    }
}