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
                'https://www.amazon.in/s?k=printer&i=computers&rh=n%3A976392031%2Cp_123%3A233970%257C242668%257C308445%257C359121&dc&crid=FA8FJ3BLCAQH&qid=1762351572&rnid=91049095031&sprefix=printer%2Ccomputers%2C268&ref=sr_nr_p_123_5&ds=v1%3Au9fHF8NkLhS5YXyr6yWNrTnqZL%2FbyZSwRt8sh1RGVF0',
                //'https://www.amazon.in/s?k=Cartridge&rh=n%3A14784020031&ref=nb_sb_noss',
                //'https://www.amazon.in/s?i=computers&srs=28122718031&rh=n%3A28122718031&s=popularity-rank&fs=true&qid=1762094386&xpid=vKzKzSYniQ-XU&ref=sr_pg_1',
                //'https://www.amazon.in/s?i=computers&srs=28122719031&rh=n%3A28122719031&s=popularity-rank&fs=true&ref=lp_28122719031_sar',
                //'https://www.amazon.in/s?i=computers&srs=28122720031&rh=n%3A28122720031&s=popularity-rank&fs=true&ref=lp_28122720031_sar',
                //'https://www.amazon.in/s?i=computers&srs=28122721031&rh=n%3A28122721031&s=popularity-rank&fs=true&ref=lp_28122721031_sar'
            ]
        ],
        'flipkart' => [
            'name' => 'Flipkart',
            'base_url' => 'https://www.flipkart.com',
            'category_urls' => [
                'https://www.flipkart.com/computers/computer-peripherals/printers-inks/printers/pr?sid=6bo,tia,ffn,t64&q=printer&otracker=categorytree',
            ]
        ],
        'vijaysales' => [
            'name' => 'VijaySales',
            'base_url' => 'https://www.vijaysales.com',
            'category_urls' => [
                'https://www.vijaysales.com/c/printers',
            ]
        ],
        'reliancedigital' => [
            'name' => 'Reliance Digital',
            'base_url' => 'https://www.reliancedigital.in',
            'category_urls' => [
                'https://www.reliancedigital.in/collection/hp-printers?internal_source=navigation&page_no=1&page_size=36&page_type=number',
                'https://www.reliancedigital.in/collection/canon-printers?internal_source=navigation&page_no=1&page_size=24&page_type=number',
                'https://www.reliancedigital.in/collection/brother-printers',
                'https://www.reliancedigital.in/collection/epson-printers',
            ]
        ],
        'croma' => [
            'name' => 'Croma',
            'base_url' => 'https://www.croma.com',
            'category_urls' => [
                'https://www.croma.com/computers-tablets/printers/c/31',
            ]
        ],
        'blinkit' => [
            'name' => 'Blinkit',
            'base_url' => 'https://blinkit.com',
            'category_urls' => [
                'https://blinkit.com/s/?q=printer',
            ]
        ],
        'bigbasket' => [
            'name' => 'Bigbasket',
            'base_url' => 'https://www.bigbasket.com',
            'category_urls' => [
                'https://www.bigbasket.com/pc/electronics/phone-laptop-accessory/printers-ink/?nc=nb',
            ]
        ],
        'meesho' => [
            'name' => 'Meesho',
            'base_url' => 'https://www.meesho.com',
            'category_urls' => [
                'https://www.meesho.com/search?q=printer&searchType=manual&searchIdentifier=text_search',
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
        'interval_hours' => env('SCRAPER_INTERVAL_HOURS', 168), // 7 days
        'max_execution_time' => env('SCRAPER_MAX_EXECUTION_TIME', 43200), // 12 hour
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
            'min' => 300,  // Minimum product price in INR
            'max' => 1600000  // Maximum product price in INR
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    */

    'images' => [
        'download_enabled' => env('SCRAPER_DOWNLOAD_IMAGES', false),
        'max_images_per_product' => 15,
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
