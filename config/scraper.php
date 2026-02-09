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
    'delay_max' => env('SCRAPER_DELAY_MAX', 7),

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
                
                //'https://www.amazon.in/s?k=printer&i=computers&rh=n%3A976392031%2Cp_123%3A233970%257C242668%257C308445%257C359121&dc&crid=FA8FJ3BLCAQH&qid=1762351572&rnid=91049095031&sprefix=printer%2Ccomputers%2C268&ref=sr_nr_p_123_5&ds=v1%3Au9fHF8NkLhS5YXyr6yWNrTnqZL%2FbyZSwRt8sh1RGVF0',
                'https://www.amazon.in/s?k=mobile&i=electronics&rh=n%3A1389432031%2Cp_123%3A13145%257C146762%257C338933%257C339703%257C46655%257C559198%257C568349%257C940997&dc&qid=1770011684&rnid=91049095031&ref=sr_nr_p_123_10&ds=v1%3AgT1gPNJ0PGM1BDlR3Hs%2F%2FAH0cC5tVSdwU00Pi176eGM',
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
                //'https://www.flipkart.com/computers/computer-peripherals/printers-inks/printers/pr?sid=6bo,tia,ffn,t64&q=printer&otracker=categorytree',
                'https://www.flipkart.com/mobiles-accessories/mobiles/pr?sid=tyy%2C4io&otracker=categorytree&p%5B%5D=facets.brand%255B%255D%3DMOTOROLA&p%5B%5D=facets.brand%255B%255D%3DSamsung&p%5B%5D=facets.brand%255B%255D%3Drealme&p%5B%5D=facets.brand%255B%255D%3DOPPO&p%5B%5D=facets.brand%255B%255D%3DLAVA&p%5B%5D=facets.brand%255B%255D%3Dvivo&p%5B%5D=facets.brand%255B%255D%3DMi',
            ]
        ],
        'vijaysales' => [
            'name' => 'VijaySales',
            'base_url' => 'https://www.vijaysales.com',
            'category_urls' => [
                //'https://www.vijaysales.com/c/printers',
                //Mobile,
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Motorola',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Samsung&sort_by=price_low_to_high&price=8999_20000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Samsung&sort_by=price_low_to_high&price=20000_36000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Samsung&sort_by=price_low_to_high&price=36000_78548',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Samsung&sort_by=price_low_to_high&price=78548_158548',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Samsung&sort_by=price_low_to_high&price=158548_1449000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=realme&price=8699_18000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=realme&price=18000_30000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=realme&price=30000_1449000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Oppo&price=9999_18000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Oppo&price=18000_25000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Oppo&price=25000_40000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Oppo&price=40000_60000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Oppo&price=60000_200000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Lava',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Vivo&price=8999_16000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Vivo&price=16000_25000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Vivo&price=25000_35000',
                // 'https://www.vijaysales.com/c/mobiles-tablets-and-accessories?categories=Mobiles&brand=Vivo&price=35000_235000',
                //'https://www.vijaysales.com/c/smartphones',
                //'https://www.vijaysales.com/c/smartphones?brand=Samsung',
                //'https://www.vijaysales.com/c/smartphones?brand=Vivo',
                'https://www.vijaysales.com/c/smartphones?brand=realme',
                'https://www.vijaysales.com/c/smartphones',
                'https://www.vijaysales.com/c/mobiles-tablets-and-accessories',
                'https://www.vijaysales.com/search-listing?q=mobile',

            ]
        ],
        'reliancedigital' => [
            'name' => 'Reliance Digital',
            'base_url' => 'https://www.reliancedigital.in',
            'category_urls' => [
                // 'https://www.reliancedigital.in/collection/hp-printers?internal_source=navigation&page_no=1&page_size=36&page_type=number',
                // 'https://www.reliancedigital.in/collection/canon-printers?internal_source=navigation&page_no=1&page_size=24&page_type=number',
                // 'https://www.reliancedigital.in/collection/brother-printers',
                // 'https://www.reliancedigital.in/collection/epson-printers',
                //mobile
                //'https://www.reliancedigital.in/collection/samsung-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=480&page_type=number',
                //'https://www.reliancedigital.in/collection/oppo-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=240&page_type=number',
                'https://www.reliancedigital.in/collection/realme-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=240&page_type=number',
                'https://www.reliancedigital.in/collection/redmi-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=240&page_type=number',
                'https://www.reliancedigital.in/collection/vivo-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=240&page_type=number',
                'https://www.reliancedigital.in/collection/motorola-mobiles?is_available=true&sort_on=latest&page_no=1&page_size=24&page_type=number',
            ]
        ],
        'croma' => [
            'name' => 'Croma',
            'base_url' => 'https://www.croma.com',
            'category_urls' => [
                //'https://www.croma.com/computers-tablets/printers/c/31?q=%3Arelevance%3ASG-ManufacturerDetails-Brand%3ACanon',
                //'https://www.croma.com/computers-tablets/printers/c/31?q=%3Arelevance%3ASG-ManufacturerDetails-Brand%3AEpson',
                //'https://www.croma.com/computers-tablets/printers/c/31?q=%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AHP%3Aprice_group%3A10%2C001+-+20%2C000%3Aprice_group%3A5%2C001+-+10%2C000%3Aprice_group%3A20%2C001+-+30%2C000%3Aprice_group%3A1%2C001+-+1%2C500%3Aprice_group%3A50%2C001+-+60%2C000',
                //'https://www.croma.com/computers-tablets/printers/c/31?q=%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AHP%3Aprice_group%3A10%2C001+-+20%2C000%3Aprice_group%3A5%2C001+-+10%2C000%3Aprice_group%3A20%2C001+-+30%2C000%3Aprice_group%3A1%2C001+-+1%2C500%3Aprice_group%3A50%2C001+-+60%2C000',
                //mobile
                // 'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3ALatestArrival%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3AtopRated%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Adiscount-desc%3ASG-ManufacturerDetails-Brand%3ASamsung&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Adiscount-desc%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3ALatestArrival%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3AtopRated%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                // 'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AVivo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ARealme%3Aprice_group%3A30%2C001+-+40%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ARealme%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ARealme%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ARealme%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AOppo%3Aprice_group%3A5%2C001+-+10%2C000%3Aprice_group%3A40%2C001+-+50%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AOppo%3Aprice_group%3A30%2C001+-+40%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AOppo%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AOppo%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AOppo%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Adiscount-desc%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3ALatestArrival%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3AtopRated%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ARealme&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Adiscount-desc%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3ALatestArrival%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3AtopRated%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AOppo&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3AXiaomi&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A30%2C001+-+40%2C000%3Aprice_group%3A40%2C001+-+50%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A30%2C001+-+40%2C000%3Aprice_group%3A40%2C001+-+50%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3AVivo%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A60%2C001+-+70%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A70%2C001+-+80%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A1%2C00%2C001+-+2%2C00%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A1%2C00%2C001+-+2%2C00%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A40%2C001+-+50%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A20%2C001+-+30%2C000%3Aprice_group%3A30%2C001+-+40%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A20%2C001+-+30%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-asc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',
                'https://www.croma.com/searchB?q=mobile%3Aprice-desc%3ASG-ManufacturerDetails-Brand%3ASamsung%3Aprice_group%3A10%2C001+-+20%2C000&text=mobile',


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
            'min' => 2000,  // Minimum product price in INR
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
