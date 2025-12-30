> **Note:** This is a comprehensive technical documentation of the project. For a quick start, please refer to the `README.md` file.

# 🚀 Complete Project Workflow & Technical Documentation

**Project:** aethyrtech (E-commerce Web Scraper)  
**Version:** 2.1  
**Date:** December 29, 2025  
**Status:** ✅ ALL SYSTEMS OPERATIONAL

---

## 📋 TABLE OF CONTENTS

1.  [**Installation & Setup**](#1-installation--setup-)
2.  [**Project Architecture & Services**](#2-project-architecture--services-)
3.  [**Antibot & Proxy Integration**](#3-antibot--proxy-integration-)
4.  [**Data Storage & Flow**](#4-data-storage--flow-)
5.  [**Debugging Guide**](#5-debugging-guide-)
6.  [**Adding New Functionality**](#6-adding-new-functionality-)
7.  [**Platform-wise Customization**](#7-platform-wise-customization-)

---

## 1. INSTALLATION & SETUP ⚙️

### 1.1. Server Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| **OS** | Ubuntu 20.04+ | Ubuntu 22.04+ |
| **PHP** | 8.1+ | 8.2+ |
| **Composer** | 2.0+ | Latest |
| **Node.js** | 16+ | 18+ |
| **NPM/Yarn** | 8+ | Latest |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ | MySQL 8.0+ |
| **RAM** | 4 GB | 8 GB+ |
| **CPU** | 2 Cores | 4 Cores+ |

### 1.2. Installation Steps

**Step 1: Clone the Repository**
```bash
git clone https://github.com/rajtiwarikmw/aethyrtech.git
cd aethyrtech
```

**Step 2: Install PHP & Composer**
```bash
sudo apt update
sudo apt install -y php8.2-cli php8.2-common php8.2-mysql php8.2-zip php8.2-gd php8.2-mbstring php8.2-curl php8.2-xml php8.2-bcmath

# Install Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
```

**Step 3: Install Node.js & Dependencies**
```bash
# Install Node.js (using nvm)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.1/install.sh | bash
source ~/.bashrc
nvm install 18
nvm use 18

# Install Browsershot dependencies
npm install puppeteer
```

**Step 4: Install Laravel Dependencies**
```bash
composer install --no-dev --optimize-autoloader
```

**Step 5: Environment Configuration**
```bash
# Create .env file
cp .env.example .env

# Generate application key
php artisan key:generate
```

**Step 6: Configure `.env` file**

Update the following variables in your `.env` file:

```env
APP_NAME=AethyrtechScraper
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Optional: Proxy Configuration
SCRAPER_PROXIES="http://user:pass@host:port,http://user:pass@host2:port2"

# Optional: Custom User-Agent
SCRAPER_USER_AGENT="Your Custom User Agent"
```

**Step 7: Database Migration**
```bash
php artisan migrate --force
```

**Step 8: Clear Caches**
```bash
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:clear
```

**Step 9: Test Installation**
```bash
# Check available commands
php artisan scraper:run --help

# Run a test scrape
php artisan scraper:run amazon --limit=1
```

### 1.3. Directory Permissions

Ensure the following directories are writable by the web server:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 2. PROJECT ARCHITECTURE & SERVICES 🏗️

### 2.1. Core Components

| Component | Location | Purpose |
|---|---|---|
| **Commands** | `app/Console/Commands` | Entry points for scraping tasks (e.g., `scraper:run`) |
| **Services** | `app/Services` | Core logic for browser automation, proxy, user-agents |
| **Scrapers** | `app/Services/Scrapers` | Platform-specific scraping logic |
| **Models** | `app/Models` | Database interaction and data structure |
| **Configuration** | `config/` | Platform URLs, scraper settings, etc. |
| **Migrations** | `database/migrations` | Database schema definition |

### 2.2. Service Details

#### 2.2.1. BrowserService (`app/Services/BrowserService.php`)

This is the heart of the browser automation. It uses `spatie/browsershot` which is a wrapper around Puppeteer.

**Key Responsibilities:**
- **Browser Instance:** Creates and manages headless Chrome instances.
- **Page Fetching:** Navigates to URLs and fetches HTML content.
- **JavaScript Rendering:** Executes JavaScript on pages to get dynamic content.
- **Infinite Scroll:** Handles infinite scroll pages (FIXED).
- **Antibot Measures:** Applies Chrome flags, user-agents, and proxies.
- **Error Handling:** Manages timeouts, retries, and failed requests.

**Workflow:**
1.  `BaseScraper` calls `BrowserService->fetchPage()` or `fetchInfiniteScrollContent()`.
2.  `BrowserService` creates a `Browsershot` instance.
3.  It applies all antibot measures (flags, user-agent, proxy).
4.  It navigates to the URL and waits for the page to load.
5.  For infinite scroll, it executes a custom JavaScript to scroll and load all content.
6.  It returns the final HTML content to the scraper.

#### 2.2.2. ProxyRotator (`app/Services/ProxyRotator.php`)

Manages a pool of proxies to avoid IP bans.

**Features:**
- **Loading:** Loads proxies from `.env` (`SCRAPER_PROXIES`) and `storage/app/proxies.txt`.
- **Rotation:** Provides a new proxy for each request in a round-robin fashion.
- **Failure Tracking:** Marks proxies as failed if they result in an error.
- **Auto-Reset:** Resets the failed proxy list if all proxies have failed.

**How it's used:**
- `BrowserService` calls `ProxyRotator->getNextProxy()` before creating a browser instance.
- The returned proxy is passed to `Browsershot` using the `--proxy-server` flag.

#### 2.2.3. UserAgentRotator (`app/Services/UserAgentRotator.php`)

Rotates user-agents to mimic different browsers and devices.

**Features:**
- **Pool:** Contains 18 different user-agents (desktop and mobile).
- **Randomization:** Provides a random user-agent for each request.
- **Headers:** Also provides random `Accept-Language` and `Accept-Encoding` headers.

**How it's used:**
- `BrowserService` calls `UserAgentRotator->getRandomUserAgent()`.
- The user-agent is passed to `Browsershot`.

### 2.3. Scraper Workflow

**Step 1: Command Execution**
- You run `php artisan scraper:run meesho`.
- `ScrapeCommand` is executed.

**Step 2: Scraper Instantiation**
- `ScrapeCommand` identifies the platform (`meesho`).
- It creates an instance of `MeeshoScraper`.

**Step 3: Fetching Category Page**
- `MeeshoScraper` gets category URLs from `config/scraper.php`.
- It calls `BrowserService->fetchPage()` or `fetchInfiniteScrollContent()` to get the category page HTML.

**Step 4: Extracting Product URLs**
- `MeeshoScraper->extractProductUrls()` parses the category page HTML.
- It extracts all individual product URLs.

**Step 5: Fetching Product Pages**
- The scraper loops through each product URL.
- It calls `BrowserService->fetchPage()` for each product URL to get the product page HTML.

**Step 6: Extracting Product Data**
- `MeeshoScraper->extractProductData()` parses the product page HTML.
- It extracts all 67 product fields (title, price, rating, etc.).

**Step 7: Storing Data**
- The extracted data is passed to `DatabaseService`.
- `DatabaseService` uses the `Product` model to create or update the product in the database.

---

## 3. ANTIBOT & PROXY INTEGRATION 🛡️

This project implements a multi-layered strategy to avoid getting blocked by e-commerce sites.

### 3.1. How Antibot Measures Work

All antibot measures are orchestrated by **`BrowserService.php`**. When a scraper requests a page, `BrowserService` assembles a unique browser fingerprint for that request.

**File:** `app/Services/BrowserService.php`

#### 3.1.1. Chrome Flags

These flags are passed to the headless Chrome instance to make it look less like a bot.

**Location:** `app/Services/BrowserService.php` (inside the `createBrowser()` method)

| Flag | Purpose | Status |
|---|---|---|
| `--no-sandbox` | Disables the Chrome sandbox | ✅ |
| `--disable-setuid-sandbox` | Additional sandbox disabling | ✅ |
| `--disable-dev-shm-usage` | Prevents memory-related crashes | ✅ |
| `--disable-gpu` | Disables GPU hardware acceleration | ✅ |
| `--disable-web-security` | Disables same-origin policy (for CORS) | ✅ |
| `--disable-features=VizDisplayCompositor` | Disables a rendering feature | ✅ |
| `--disable-background-timer-throttling` | Prevents throttling of background tabs | ✅ |
| `--disable-backgrounding-occluded-windows` | Prevents backgrounding of occluded windows | ✅ |
| `--disable-renderer-backgrounding` | Prevents renderer backgrounding | ✅ |
| `--disable-geolocation` | Prevents location permission popups (FIXED) | ✅ |

**How it's called:**
```php
// in BrowserService.php
$browsershot = new Browsershot($url);
$browsershot->addChromiumArguments([
    '--disable-setuid-sandbox',
    // ... all other flags
]);
```

#### 3.1.2. User-Agent Rotation

**Service:** `app/Services/UserAgentRotator.php`

This service provides a random User-Agent string for each request from a pool of 18 real-world user agents.

**How it's called:**
```php
// in BrowserService.php
$userAgentRotator = new UserAgentRotator();
$userAgent = $userAgentRotator->getRandomUserAgent();

$browsershot->userAgent($userAgent);
```

This makes each request appear to come from a different browser, OS, and device combination.

#### 3.1.3. Request Delays & Throttling

To mimic human behavior, the scraper introduces delays:

-   **Random Delays:** A random delay between 2-5 seconds is added between requests (`config/scraper.php`).
-   **Exponential Backoff:** If a request fails, the retry delay increases exponentially (e.g., 1s, 2s, 4s). This is handled in `BrowserService.php`.
-   **Throttling:** A hardcoded delay is added in some scrapers to avoid overwhelming a server.

### 3.2. Proxy Integration

**Service:** `app/Services/ProxyRotator.php`

This service manages and rotates proxies to distribute requests across multiple IP addresses.

#### 3.2.1. How Proxies are Loaded

1.  **Environment Variable:** Proxies are first loaded from the `.env` file's `SCRAPER_PROXIES` variable. Proxies should be a comma-separated list.
    ```env
    SCRAPER_PROXIES="http://user1:pass1@host1:port1,http://user2:pass2@host2:port2"
    ```

2.  **File-based:** Proxies are also loaded from `storage/app/proxies.txt`. Each proxy should be on a new line.
    ```
    http://user3:pass3@host3:port3
    http://user4:pass4@host4:port4
    ```

#### 3.2.2. How Proxies are Used

**Location:** `app/Services/BrowserService.php` (inside the `createBrowser()` method)

1.  `BrowserService` creates an instance of `ProxyRotator`.
2.  It calls `$proxyRotator->getNextProxy()` to get the next available proxy.
3.  This proxy is passed to the `Browsershot` instance.

**Code Snippet:**
```php
// in BrowserService.php
$proxyRotator = new ProxyRotator();
if ($proxyRotator->hasProxies()) {
    $proxy = $proxyRotator->getNextProxy();
    $browsershot->setProxy($proxy);
}
```

#### 3.2.3. Failure Handling

-   If a request fails with a specific proxy, `BrowserService` calls `$proxyRotator->markProxyAsFailed($proxy)`.
-   The failed proxy is not used again until all other proxies have also failed.
-   If all proxies fail, the list is automatically reset, and the rotation starts over.

---

## 4. DATA STORAGE & FLOW 💾

### 4.1. Data Models

All scraped data is stored in the MySQL database using Laravel's Eloquent ORM. Here are the primary models:

| Model | Table Name | Purpose |
|---|---|---|
| `Product` | `products` | Stores all 67 product attributes |
| `Review` | `reviews` | Stores individual customer reviews |
| `ProductRanking` | `product_rankings` | Stores product keyword ranking history |
| `Keyword` | `keywords` | Stores keywords to be tracked for ranking |

### 4.2. Data Flow: From Scraper to Database

**Step 1: Data Extraction**
- The platform-specific scraper (e.g., `MeeshoScraper.php`) extracts data from the HTML.
- The data is returned as a structured array.

**File:** `app/Services/Scrapers/MeeshoScraper.php`
```php
// in extractProductData()
return [
    'sku' => $sku,
    'title' => $title,
    'price' => $price,
    // ... all 67 fields
];
```

**Step 2: Saving Data**
- The `BaseScraper`'s `saveProduct()` method is called with the extracted data array.

**File:** `app/Services/Scrapers/BaseScraper.php`
```php
// in run() method
$this->saveProduct($productData);
```

**Step 3: Database Interaction**
- The `saveProduct()` method uses the `Product` model to interact with the database.
- It first checks if a product with the same `platform` and `sku` already exists.
- **If it exists:** It calls `updateIfChanged()` to update the product data only if something has changed.
- **If it doesn't exist:** It calls `Product::create()` to insert a new product record.

**File:** `app/Services/Scrapers/BaseScraper.php`
```php
// in saveProduct()
$product = Product::where('platform', $data['platform'])
                    ->where('sku', $data['sku'])
                    ->first();

if ($product) {
    $product->updateIfChanged($data);
} else {
    Product::create($data);
}
```

### 4.3. Key Model Logic

#### Product Model (`app/Models/Product.php`)

- **`$fillable` array:** Contains all 67 fields that are allowed to be mass-assigned.
- **`$casts` array:** Automatically casts data types (e.g., `price` to `decimal`, `image_urls` to `array`).
- **`hasDataChanged()` method:** Compares the new scraped data with the existing data in the database to check for changes. This prevents unnecessary database writes.
- **`updateIfChanged()` method:** Updates the product only if `hasDataChanged()` returns `true`.

#### Review Model (`app/Models/Review.php`)

- **`isValidReview()` method (FIXED):** Before saving a review, this method is called to ensure that all required fields (reviewer name, rating, text) are present. This prevents storing blank review entries.

**File:** `app/Services/Scrapers/FlipkartReviewScraper.php` (and other review scrapers)
```php
// in saveReview()
if ($this->isValidReview($reviewData)) {
    Review::create($reviewData);
}
```

---

## 5. DEBUGGING GUIDE 🐛

Here is a step-by-step guide to debug any issue with the scraper.

### 5.1. Step 1: Check the Logs

This is always the first step. The application logs everything to `storage/logs/laravel.log`.

```bash
# View the live log file
tail -f storage/logs/laravel.log

# Search for a specific platform
tail -f storage/logs/laravel.log | grep -i "meesho"

# Search for errors
tail -f storage/logs/laravel.log | grep -i "error"
```

Look for error messages, stack traces, or any unusual activity.

### 5.2. Step 2: Enable Debug Mode

For more detailed error messages, enable debug mode in your `.env` file. **Do not do this in production!**

**File:** `.env`
```env
APP_DEBUG=true
```

After changing, clear the config cache:
```bash
php artisan config:cache
```

Now, run the command again, and you will get a full stack trace for any error.

### 5.3. Step 3: Test a Single URL

Instead of scraping a whole category, test a single product URL to isolate the issue.

```bash
php artisan scraper:run meesho --url="https://www.meesho.com/your-product-url/p/1abcde"
```

This will run the scraper for only one product, making it easier to debug.

### 5.4. Step 4: Dump the HTML

If your scraper is failing to extract data, the website's HTML structure might have changed. You can dump the HTML that the scraper sees to a file.

**File:** `app/Services/BrowserService.php`

Temporarily modify the `fetchPage()` method to save the HTML:

```php
// in fetchPage()
$html = $browsershot->bodyHtml();

// Add this line to save the HTML
file_put_contents(storage_path("logs/debug.html"), $html);

return $html;
```

Now, run the scraper for a single URL. The full HTML will be saved to `storage/logs/debug.html`. You can open this file in a browser to inspect the elements and fix your CSS selectors in the scraper file.

### 5.5. Step 5: Check Database Issues

-   **Connection:** Ensure your database credentials in `.env` are correct.
-   **Migrations:** Make sure you have run all migrations (`php artisan migrate`).
-   **Schema Mismatch:** If you get a "column not found" error, it means your scraper is trying to save a field that doesn't exist in the `products` table. You either need to add a migration to create the column or remove the field from the scraper's `extractProductData()` method.

### 5.6. Step 6: Debugging Antibot & Proxy Issues

-   **Proxy Failures:** Check `storage/logs/laravel.log` for messages like "All proxies have failed". This means your proxies are not working. Test them individually.
-   **Getting Blocked:** If you are getting CAPTCHA pages or "Access Denied" errors, the site is blocking you. Try the following:
    -   Add more high-quality proxies.
    -   Update the User-Agent list in `app/Services/UserAgentRotator.php`.
    -   Increase the delay between requests in `config/scraper.php`.

### 5.7. Common Errors & Solutions

| Error | Solution |
|---|---|
| **"Unknown platform: meesho"** | Register the platform in `ScrapeCommand.php` and `config/scraper.php`. (FIXED) |
| **`await is only valid in async functions`** | Wrap the JavaScript code in an `async` IIFE in `BrowserService.php`. (FIXED) |
| **`column not found`** | Run `php artisan migrate` or add the missing column to the migration file. |
| **Timeout errors** | Increase the timeout in `config/scraper.php` or `BrowserService.php`. Check your internet connection and proxy speed. |
| **CSS selector not found** | The website structure has changed. Dump the HTML and update your selectors in the platform's scraper file. |

---

## 6. ADDING NEW FUNCTIONALITY 🚀

Here’s how to extend the project with new platforms, fields, or commands.

### 6.1. How to Add a New Platform

Let's say you want to add a scraper for a new platform called "MyStore".

**Step 1: Create the Scraper File**

Create a new file: `app/Services/Scrapers/MyStoreScraper.php`.

You can copy an existing scraper like `MeeshoScraper.php` as a template.

```php
// app/Services/Scrapers/MyStoreScraper.php
namespace App\Services\Scrapers;

class MyStoreScraper extends BaseScraper
{
    public function __construct()
    {
        parent::__construct(
            'mystore',
            'https://www.mystore.com'
        );
    }

    protected function extractProductUrls(string $html): array
    {
        // Add your logic to extract product URLs from category page
    }

    protected function extractProductData(string $html): array
    {
        // Add your logic to extract product data from product page
    }
}
```

**Step 2: Register the Platform in `config/scraper.php`**

Add a new entry for "MyStore" in the `platforms` array.

```php
// config/scraper.php
'platforms' => [
    // ... other platforms
    'mystore' => [
        'name' => 'MyStore',
        'base_url' => 'https://www.mystore.com',
        'category_urls' => [
            'https://www.mystore.com/category/printers',
        ]
    ]
]
```

**Step 3: Register the Platform in `ScrapeCommand.php`**

**File:** `app/Console/Commands/ScrapeCommand.php`

1.  **Import the new scraper class:**
    ```php
    use App\Services\Scrapers\MyStoreScraper;
    ```

2.  **Add it to the signature:**
    ```php
    protected $signature = 'scraper:run {platform? : Platform to scrape (amazon, flipkart, ..., meesho, mystore, all)}';
    ```

3.  **Add it to the `createScraper()` method:**
    ```php
    // in createScraper()
    case 'mystore':
        return new MyStoreScraper();
    ```

**Step 4: Clear Cache and Test**
```bash
php artisan config:cache
php artisan scraper:run mystore --limit=1
```

### 6.2. How to Add a New Field to Scrape

Let's say you want to add a `material` field to the `products` table.

**Step 1: Create a New Migration**

```bash
php artisan make:migration add_material_to_products_table --table=products
```

This will create a new migration file in `database/migrations`.

**Step 2: Edit the Migration File**

Add the new column in the `up()` method.

```php
// in the new migration file
public function up(): void
{
    Schema::table('products', function (Blueprint $table) {
        $table->string('material')->nullable()->after('color');
    });
}
```

Run the migration:
```bash
php artisan migrate
```

**Step 3: Update the `Product` Model**

Add the new `material` field to the `$fillable` array in `app/Models/Product.php`.

```php
// app/Models/Product.php
protected $fillable = [
    // ... other fields
    'color',
    'material', // Add the new field
    'image_urls',
    // ...
];
```

**Step 4: Update the Scraper(s)**

Now, go to the scraper file(s) (e.g., `app/Services/Scrapers/AmazonScraper.php`) and update the `extractProductData()` method to extract the new field.

```php
// in extractProductData()
$material = $crawler->filter('#product-details .material-spec')->text();

return [
    // ... other fields
    'material' => $material,
];
```

That's it! The new field will now be scraped and stored in the database.

### 6.3. How to Add a New Command

Let's say you want to create a new command to clean up old logs.

**Step 1: Create the Command File**

```bash
php artisan make:command CleanupLogsCommand
```

This creates a new file: `app/Console/Commands/CleanupLogsCommand.php`.

**Step 2: Define the Command**

Edit the new file:

```php
// app/Console/Commands/CleanupLogsCommand.php
protected $signature = 'logs:cleanup';
protected $description = 'Clean up old log files';

public function handle()
{
    // Your logic to delete old files from storage/logs
    $this->info('Old log files cleaned up successfully!');
}
```

**Step 3: Register the Command (Optional in modern Laravel)**

In modern Laravel versions, console commands are auto-discovered. If it doesn't show up when you run `php artisan`, you can register it in `app/Console/Kernel.php`.

```php
// app/Console/Kernel.php
protected $commands = [
    Commands\CleanupLogsCommand::class,
];
```

**Step 4: Test the Command**

```bash
php artisan logs:cleanup
```

---

## 7. PLATFORM-WISE CUSTOMIZATION 🔧

While the core services are shared, you can customize behavior for each platform.

### 7.1. Customizing Scraper Settings

You can override global scraper settings on a per-platform basis in `config/scraper.php`.

**Example: Custom timeout for Amazon**
```php
// config/scraper.php
'platforms' => [
    'amazon' => [
        'name' => 'Amazon India',
        'base_url' => 'https://www.amazon.in',
        'category_urls' => [...],
        'timeout' => 60, // Override global timeout (30s)
        'retries' => 5,   // Override global retries (3)
    ],
]
```

These settings will be used only when scraping Amazon.

### 7.2. Customizing Browser Behavior

If a specific platform requires special browser handling (e.g., clicking a button before scraping), you can add this logic to the platform's scraper file.

**Example: Clicking a cookie banner for a new platform**

**File:** `app/Services/Scrapers/NewPlatformScraper.php`
```php
// in run() method, before fetching product URLs
$this->browserService->clickElement('#cookie-accept-button');
```

### 7.3. Customizing User-Agents

If a platform is known to block certain user-agents, you can create a custom list for it.

**Step 1: Add a new user-agent list to `UserAgentRotator.php`**
```php
// app/Services/UserAgentRotator.php
private array $myPlatformUserAgents = [
    'Mozilla/5.0 (...', // Custom list
];

public function getMyPlatformUserAgent(): string
{
    return $this->myPlatformUserAgents[array_rand($this->myPlatformUserAgents)];
}
```

**Step 2: Call this method from `BrowserService.php`**
```php
// app/Services/BrowserService.php
if ($platform === 'myplatform') {
    $userAgent = $userAgentRotator->getMyPlatformUserAgent();
} else {
    $userAgent = $userAgentRotator->getRandomUserAgent();
}
```

### 7.4. Platform-specific Command Changes

If you need to add a command-line option that only applies to one platform, you can add it to `ScrapeCommand.php` and handle it within the platform's scraper.

**Example: Add a `--no-reviews` option for Flipkart**

**File:** `app/Console/Commands/ScrapeCommand.php`
```php
// Add the option to the signature
protected $signature = 'scraper:run {platform?} {--url=} {--limit=} {--no-reviews}';

// Pass the option to the scraper
if ($this->option('no-reviews')) {
    $scraper->setOption('no_reviews', true);
}
```

**File:** `app/Services/Scrapers/FlipkartScraper.php`
```php
// in run() method
if ($this->getOption('no_reviews')) {
    // Skip review scraping logic
}
```

---

This documentation provides a complete overview of the project. For any further questions, please refer to the code comments and the Laravel documentation.
