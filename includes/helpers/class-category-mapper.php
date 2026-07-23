<?php

class CategoryMapper
{
    /**
     * Providers supported by each category.
     * Later this will come from WordPress settings.
     */
    private array $categoryProviders = [

        'fashion' => [
            'Speedaf',
            'GIGL'
        ],

        'electronics' => [
            'Speedaf',
            'GIGL'
        ],

        'beauty' => [
            'Speedaf',
            'GIGL'
        ],

        'food-beverages' => [
            'GIGL'
        ],

        'groceries' => [
            'GIGL'
        ],

        'documents' => [
            'Speedaf',
            'GIGL'
        ]

    ];

    /**
     * Get providers for a category.
     */
    public function getProviders(
        string $category
    ): array {

        return $this->categoryProviders[$category] ?? [];

    }

    /**
     * Does provider support category?
     */
    public function providerSupports(
        string $provider,
        string $category
    ): bool {

        return in_array(
            $provider,
            $this->getProviders($category),
            true
        );

    }

    /**
     * Is category known?
     */
    public function exists(
        string $category
    ): bool {

        return isset(
            $this->categoryProviders[$category]
        );

    }

}