# Installation Guide -Product Data Scraper

This guide provides step-by-step instructions for installing and configuring the product Data Scraper application.

## Pre-Installation Checklist

### System Requirements

-   [ ] PHP 8.1 or higher installed
-   [ ] MySQL 5.7+ or MariaDB 10.3+ installed
-   [ ] Web server (Apache/Nginx) configured
-   [ ] Composer installed globally
-   [ ] Cron daemon running
-   [ ] At least 1GB free disk space
-   [ ] 512MB+ RAM available

### PHP Extensions Check

Run this command to verify required extensions:

```bash
php -m | grep -E "(pdo_mysql|curl|openssl|mbstring|xml|json)"
```

Required extensions:

-   [ ] pdo_mysql
-   [ ] curl
-   [ ] openssl
-   [ ] mbstring
-   [ ] xml
-   [ ] json

## Step-by-Step Installation

### Step 1: Download and Extract

```bash
# Navigate to your web directory
cd /var/www/html

# Extract the project (replace with actual path)
unzip /path/to/product-scraper.zip

# Set proper ownership (adjust user/group as needed)
sudo chown -R www-data:www-data product-scraper
```

### Step 2: Install Dependencies

```bash
cd product-scraper

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# If you don't have Composer installed:
# curl -sS https://getcomposer.org/installer | php
# php composer.phar install --no-dev --optimize-autoloader
```

### Step 3: Environment Configuration

```bash
# Copy environment template
cp .env.example .env

# Generate application key
php artisan key:generate

# Set proper permissions
chmod 644 .env
```

Edit the `.env` file with your configuration:

```bash
nano .env
```

### Step 4: Database Setup

#### Create Database

```sql
-- Login to MySQL
mysql -u root -p

-- Create database
CREATE DATABASE product_scraper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user (optional, for security)
CREATE USER 'scraper_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON product_scraper.* TO 'scraper_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

#### Configure Database Connection

Update `.env` with your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=product_scraper
DB_USERNAME=scraper_user
DB_PASSWORD=secure_password
```

#### Run Migrations

```bash
# Test database connection
php artisan migrate:status

# Run migrations
php artisan migrate

# Verify tables were created
php artisan migrate:status
```

### Step 5: Configure Scraper Settings

Edit scraper configuration in `.env`:

```env
# Scraper Configuration
SCRAPER_DELAY_MIN=2
SCRAPER_DELAY_MAX=5
SCRAPER_TIMEOUT=30
SCRAPER_RETRIES=3
SCRAPER_USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"

# Scheduling
SCRAPER_SCHEDULE_ENABLED=true
SCRAPER_INTERVAL_HOURS=48
SCRAPER_MAX_EXECUTION_TIME=7200

# Logging
SCRAPER_LOG_LEVEL=info
SCRAPER_DETAILED_ERRORS=true
```

### Step 6: Set Up Permissions

```bash
# Set storage permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Set ownership (adjust as needed)
sudo chown -R www-data:www-data storage
sudo chown -R www-data:www-data bootstrap/cache
```

### Step 7: Web Server Configuration

#### Apache Configuration

Create virtual host file:

```bash
sudo nano /etc/apache2/sites-available/product-scraper.conf
```

Add configuration:

```apache
<VirtualHost *:80>
    ServerName product-scraper.local
    DocumentRoot /var/www/html/product-scraper/public

    <Directory /var/www/html/product-scraper/public>
        AllowOverride All
        Require all granted

        # Enable rewrite module
        RewriteEngine On
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/product-scraper-error.log
    CustomLog ${APACHE_LOG_DIR}/product-scraper-access.log combined
</VirtualHost>
```

Enable site and restart Apache:

```bash
sudo a2ensite product-scraper.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx Configuration

Create server block:

```bash
sudo nano /etc/nginx/sites-available/product-scraper
```

Add configuration:

```nginx
server {
    listen 80;
    server_name product-scraper.local;
    root /var/www/html/product-scraper/public;
    index index.php index.html;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;

    # Handle Laravel routes
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Increase timeouts for scraping
        fastcgi_read_timeout 300;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /(storage|bootstrap|config|database|resources|routes|tests) {
        deny all;
    }

    # Logging
    access_log /var/log/nginx/product-scraper-access.log;
    error_log /var/log/nginx/product-scraper-error.log;
}
```

Enable site and restart Nginx:

```bash
sudo ln -s /etc/nginx/sites-available/product-scraper /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

### Step 8: Set Up Cron Job

#### Automated Setup

```bash
# Make setup script executable
chmod +x setup-cron.sh

# Run setup script
./setup-cron.sh
```

#### Manual Setup

```bash
# Edit crontab
crontab -e

# Add this line (adjust path as needed):
* * * * * cd /var/www/html/product-scraper && php artisan schedule:run >> /dev/null 2>&1
```

### Step 9: Test Installation

#### Test Web Interface

1. Add to `/etc/hosts` (if using local domain):

    ```
    127.0.0.1 product-scraper.local
    ```

2. Visit: `http://product-scraper.local/dashboard`

3. You should see the dashboard interface

#### Test Command Line

```bash
# Test scraper status
php artisan scraper:status

# Test database connection
php artisan migrate:status

# Test scheduler
php artisan schedule:list
```

#### Test Scraping (Optional)

```bash
# Run a quick test scrape (limited products)
php artisan scraper:run amazon --limit=5

# Check results
php artisan scraper:status --platform=amazon
```

## Post-Installation Configuration

### 1. Configure Platform URLs

Edit `config/scraper.php` to customize platform URLs:

```php
'platforms' => [
    'amazon' => [
        'category_urls' => [
            'https://www.amazon.in/s?k=laptops&rh=n%3A1375424031',
            // Add more specific URLs
        ]
    ],
    // Configure other platforms
]
```

### 2. Set Up Monitoring

```bash
# Create log rotation
sudo nano /etc/logrotate.d/product-scraper
```

Add configuration:

```
/var/www/html/product-scraper/storage/logs/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
}
```

### 3. Configure Email Notifications (Optional)

Update `.env` with email settings:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="product Scraper"
```

### 4. Set Up Backup (Recommended)

```bash
# Create backup script
nano backup-scraper.sh
```

Add backup script:

```bash
#!/bin/bash
BACKUP_DIR="/backups/product-scraper"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u scraper_user -p product_scraper > $BACKUP_DIR/database_$DATE.sql

# Backup application files
tar -czf $BACKUP_DIR/application_$DATE.tar.gz /var/www/html/product-scraper

# Keep only last 7 days of backups
find $BACKUP_DIR -name "*.sql" -mtime +7 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete
```

Make executable and add to cron:

```bash
chmod +x backup-scraper.sh
crontab -e
# Add: 0 2 * * * /path/to/backup-scraper.sh
```

## Verification Checklist

After installation, verify these items:

### Web Interface

-   [ ] Dashboard loads without errors
-   [ ] Navigation works correctly
-   [ ] Charts and statistics display
-   [ ] No JavaScript errors in browser console

### Command Line

-   [ ] `php artisan scraper:status` works
-   [ ] `php artisan scraper:run --help` shows options
-   [ ] `php artisan schedule:list` shows scheduled tasks

### Database

-   [ ] All migration tables exist
-   [ ] Database connection works
-   [ ] Can insert/update records

### Cron Job

-   [ ] Cron job is listed in `crontab -l`
-   [ ] Scheduler runs without errors
-   [ ] Log files are being created

### File Permissions

-   [ ] Web server can read application files
-   [ ] Storage directory is writable
-   [ ] Log files are being created

## Troubleshooting Common Issues

### Issue: "Class not found" errors

**Solution:**

```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### Issue: Database connection failed

**Solution:**

1. Verify database credentials in `.env`
2. Check if MySQL service is running
3. Test connection manually:
    ```bash
    mysql -h 127.0.0.1 -u scraper_user -p product_scraper
    ```

### Issue: Permission denied errors

**Solution:**

```bash
sudo chown -R www-data:www-data /var/www/html/product-scraper
chmod -R 775 storage bootstrap/cache
```

### Issue: Cron job not running

**Solution:**

1. Check if cron service is running:
    ```bash
    sudo systemctl status cron
    ```
2. Check cron logs:
    ```bash
    sudo tail -f /var/log/cron.log
    ```
3. Test scheduler manually:
    ```bash
    php artisan schedule:run
    ```

### Issue: Scraper timeouts

**Solution:**

1. Increase PHP timeouts in `php.ini`:
    ```ini
    max_execution_time = 300
    memory_limit = 512M
    ```
2. Increase scraper timeouts in `config/scraper.php`

## Next Steps

After successful installation:

1. **Configure Platform URLs**: Customize the URLs for each platform
2. **Test Scraping**: Run manual scrapes to verify functionality
3. **Monitor Performance**: Check logs and system resources
4. **Set Up Alerts**: Configure email notifications for failures
5. **Schedule Backups**: Implement regular backup procedures

## Support

If you encounter issues during installation:

1. Check the main README.md for troubleshooting
2. Review log files in `storage/logs/`
3. Verify all requirements are met
4. Test each component individually

The application should now be fully installed and ready for use!
