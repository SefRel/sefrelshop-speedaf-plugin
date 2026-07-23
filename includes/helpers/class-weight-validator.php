<?php

class WeightValidator
{
    /**
     * Validate WooCommerce product weight.
     */
    public function validate(
        float $weight
    ): bool {

        return $weight > 0;

    }
}