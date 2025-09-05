<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scraper Configuration
    |--------------------------------------------------------------------------
    */

    'timeout' => env('SCRAPER_TIMEOUT', 30),
    'retries' => env('SCRAPER_RETRIES', 3),
    'delay_min' => env('SCRAPER_DELAY_MIN', 2),
    'delay_max' => env('SCRAPER_DELAY_MAX', 5),

    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'),

    /*
    |--------------------------------------------------------------------------
    | Platform URLs
    |--------------------------------------------------------------------------
    */

    'platforms' => [
        'amazon' => [
            'name' => 'Amazon India',
            'base_url' => 'https://www.amazon.in',
            'category_urls' => [
                'https://www.amazon.in/s?k=printers&rh=n%3A1375443031&ref=nb_sb_noss',
            ]
        ],
        'flipkart' => [
            'name' => 'Flipkart',
            'base_url' => 'https://www.flipkart.com',
            'category_urls' => [
                'https://www.flipkart.com/computers/laptops/pr?sid=6bo%2Cb5g',
            ]
        ],
        'vijaysales' => [
            'name' => 'VijaySales',
            'base_url' => 'https://www.vijaysales.com',
            'category_urls' => [
                'https://www.vijaysales.com/c/laptops',
            ]
        ],
        'reliancedigital' => [
            'name' => 'Reliance Digital',
            'base_url' => 'https://www.reliancedigital.in',
            'category_urls' => [
                'https://www.reliancedigital.in/collection/apple-laptops?page_no=1&page_size=12&page_type=number',
            ]
        ],
        'croma' => [
            'name' => 'Croma',
            'base_url' => 'https://www.croma.com',
            'category_urls' => [
                'https://www.croma.com/computers-tablets/laptops/c/20',
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping Schedule
    |--------------------------------------------------------------------------
    */

    'schedule' => [
        'enabled' => env('SCRAPER_SCHEDULE_ENABLED', true),
        'interval_hours' => env('SCRAPER_INTERVAL_HOURS', 48), // 2 days
        'max_execution_time' => env('SCRAPER_MAX_EXECUTION_TIME', 3600), // 1 hour (reduced from 2 hours)
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Validation Rules
    |--------------------------------------------------------------------------
    */

    'validation' => [
        'required_fields' => ['sku', 'title', 'platform'],
        'max_description_length' => 5000,
        'max_title_length' => 500,
        'max_brand_length' => 100,
        'max_model_length' => 200,
        'price_range' => [
            'min' => 100,  // Minimum product price in INR
            'max' => 600000  // Maximum product price in INR
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    */

    'images' => [
        'download_enabled' => env('SCRAPER_DOWNLOAD_IMAGES', false),
        'max_images_per_product' => 5,
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_file_size' => 5 * 1024 * 1024, // 5MB
        'storage_path' => 'images/products'
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => true,
        'level' => env('SCRAPER_LOG_LEVEL', 'info'),
        'retention_days' => 30,
        'detailed_errors' => env('SCRAPER_DETAILED_ERRORS', true)
    ]

];
