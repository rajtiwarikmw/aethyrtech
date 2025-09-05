# Product Data Scraper Tool

A comprehensive Laravel PHP application for scraping product data from multiple e-commerce platforms including Amazon India, Flipkart, VijaySales, Reliance Digital, and Croma.
The application features automated scheduling, database management, and a real-time monitoring dashboard.

## Features

### 🚀 Core Features

-   **Multi-Platform Scraping**: Amazon, Flipkart, VijaySales, Reliance Digital, Croma
-   **Automated Scheduling**: Laravel scheduler – every 7 days by default
-   **Data Management**: deduplication, smart updates
-   **Monitoring Dashboard**: Real-time monitoring with charts and statistics
-   **Error Handling**: Comprehensive error tracking and retry mechanisms
-   **Data Validation**: Robust data sanitization and validation

### 📊 Data Collected

For each product, the scraper collects:

-   Platform name and SKU/Product ID
-   Product name and description
-   Pricing information (regular and sale prices)
-   Offers and discounts
-   Inventory/stock status
-   Ratings and review counts
-   Product variants and specifications
-   Brand, model, and technical details
-   Images and videos
-   Last scraped timestamp

## Requirements

### System Requirements

-   **PHP**: 8.1 or higher
-   **Database**: MySQL 5.7+ or MariaDB 10.3+
-   **Web Server**: Apache or Nginx
-   **Memory**: Minimum 512MB RAM (2GB recommended)
-   **Storage**: At least 1GB free space

### PHP Extensions

-   PDO MySQL
-   cURL
-   OpenSSL
-   Mbstring
-   XML
-   JSON
-   GD (optional, for image processing)

## Installation

### 1. Download and Extract

```bash
unzip aethyrtech.zip
cd aethyrtech
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Database Setup

Update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=Product_scraper
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Run migrations:

```bash
php artisan migrate
```

### 5. Configure Scraper Settings

Update `.env`:

```env
SCRAPER_DELAY_MIN=2
SCRAPER_DELAY_MAX=5
SCRAPER_TIMEOUT=30
SCRAPER_RETRIES=3
SCRAPER_USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
```

### 6. Set Up Cron Job

```bash
chmod +x setup-cron.sh
./setup-cron.sh
```

Or manually add to crontab:

```bash
* * * * * cd /path/to/product-scraper && php artisan schedule:run >> /dev/null 2>&1
```

## Usage

### CLI Commands

#### Run Scraper

```bash
php artisan scraper:run all
php artisan scraper:run amazon
php artisan scraper:run all --force
php artisan scraper:run all --limit=100
```

#### Check Status

```bash
php artisan scraper:status
php artisan scraper:status --platform=amazon
php artisan scraper:status --detailed --days=30
```

#### Cleanup Data

```bash
php artisan scraper:cleanup
php artisan scraper:cleanup --logs=14 --inactive=60
php artisan scraper:cleanup --dry-run
```

### Web Dashboard

Access: `http://your-domain/dashboard`

- **Overview**: Health, statistics, performance
- **Platform Details**: Per-platform data
- **Products**: Browse/search scraped products
- **Logs**: Scraper logs and errors
- **Real-time Updates**: Auto-refresh charts

## Configuration

### Platforms

Update `config/scraper.php`:

```php
'platforms' => [
    'amazon' => [
        'name' => 'Amazon India',
        'base_url' => 'https://www.amazon.in',
        'category_urls' => [
            'https://www.amazon.in/s?k=laptops&rh=n%3A1375424031',
        ]
    ],
    // others...
]
```

### Behavior
Modify scraper settings in `config/scraper.php`:
```php
'timeout' => 30,
'retries' => 3,
'delay_min' => 2,
'delay_max' => 5,
'max_execution_time' => 7200,
```

## Monitoring & Maintenance

```bash
php artisan scraper:status --detailed
php artisan scraper:cleanup
php artisan db:optimize
```

## Troubleshooting

- **HTTP 403**: Blocked – change UA/delays  
- **HTTP 429**: Rate limited – increase delays  
- **HTTP 503**: Retry later  
- **Timeout**: Increase `timeout` & `max_execution_time`

## API

```http
GET /dashboard/api/stats?days=7
```

## Security

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Contributing

```bash
composer install
php artisan test
./vendor/bin/phpcs
./vendor/bin/phpcbf
```

### Add New Platform

1. Create scraper class extending `BaseScraper`
2. Implement methods
3. Update `config/scraper.php`
4. Add dashboard entry
5. Test

## License

MIT License
