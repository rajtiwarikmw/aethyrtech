#!/bin/bash

# Product Scraper - Cron Setup Script
# This script sets up the cron job for Laravel scheduler

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Product Scraper - Cron Setup${NC}"
echo "=================================="

# Get the current directory (project root)
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "Project root: $PROJECT_ROOT"

# Check if we're in the right directory
if [ ! -f "$PROJECT_ROOT/artisan" ]; then
    echo -e "${RED}Error: artisan file not found. Please run this script from the Laravel project root.${NC}"
    exit 1
fi

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is not installed or not in PATH.${NC}"
    exit 1
fi

# Get PHP path
PHP_PATH=$(which php)
echo "PHP path: $PHP_PATH"

# Create the cron entry
CRON_ENTRY="* * * * * cd $PROJECT_ROOT && $PHP_PATH artisan schedule:run >> /dev/null 2>&1"

echo -e "\n${YELLOW}Setting up cron job...${NC}"
echo "Cron entry: $CRON_ENTRY"

# Check if cron entry already exists
if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    echo -e "${YELLOW}Cron job already exists. Updating...${NC}"
    
    # Remove existing entry and add new one
    (crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "$CRON_ENTRY") | crontab -
else
    echo -e "${GREEN}Adding new cron job...${NC}"
    
    # Add new entry to existing crontab
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
fi

# Verify cron job was added
if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    echo -e "${GREEN}✓ Cron job successfully added!${NC}"
else
    echo -e "${RED}✗ Failed to add cron job.${NC}"
    exit 1
fi

echo -e "\n${GREEN}Current crontab:${NC}"
crontab -l

echo -e "\n${YELLOW}Additional Setup Steps:${NC}"
echo "1. Ensure your .env file is properly configured"
echo "2. Run 'php artisan migrate' to set up the database"
echo "3. Test the scraper with 'php artisan scraper:run --help'"
echo "4. Check logs in storage/logs/ directory"

echo -e "\n${YELLOW}Useful Commands:${NC}"
echo "- Check scraper status: php artisan scraper:status"
echo "- Run manual scrape: php artisan scraper:run all"
echo "- View scheduled tasks: php artisan schedule:list"
echo "- Test scheduler: php artisan schedule:run"

echo -e "\n${GREEN}Setup completed successfully!${NC}"
echo "The scraper will now run automatically according to the schedule defined in app/Console/Kernel.php"

# Create log directories if they don't exist
mkdir -p "$PROJECT_ROOT/storage/logs"
touch "$PROJECT_ROOT/storage/logs/scraper-schedule.log"
touch "$PROJECT_ROOT/storage/logs/cleanup.log"
touch "$PROJECT_ROOT/storage/logs/daily-status.log"
touch "$PROJECT_ROOT/storage/logs/health-check.log"

echo -e "\n${GREEN}Log files created in storage/logs/${NC}"

# Set proper permissions
chmod +x "$PROJECT_ROOT/setup-cron.sh"
chmod 755 "$PROJECT_ROOT/storage/logs"

echo -e "\n${YELLOW}Note:${NC} Make sure the web server user has write permissions to the storage directory."
echo "You may need to run: sudo chown -R www-data:www-data storage/"

