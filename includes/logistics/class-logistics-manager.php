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
     * Return every registered providler.
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Return every provider that supportss the order.
     */
    public function getSupportedProviders(
        array $shipment
    ): array {

        $supported = [];

        foreach ($this->providers as $provider) {

            if ($provider->supports($shipment)) {
                $supported[] = $provider;
            }

        }

        return $supported;
    }
}