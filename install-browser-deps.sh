#!/bin/bash

echo "Installing Browser Dependencies for Product Scraper"
echo "=================================================="

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "Node.js is not installed. Installing Node.js..."
    
    # Install Node.js based on OS
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        # Ubuntu/Debian
        curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
        sudo apt-get install -y nodejs
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        if command -v brew &> /dev/null; then
            brew install node
        else
            echo "Please install Homebrew first or install Node.js manually"
            exit 1
        fi
    else
        echo "Please install Node.js manually for your operating system"
        exit 1
    fi
else
    echo "Node.js is already installed: $(node --version)"
fi

# Check if npm is available
if ! command -v npm &> /dev/null; then
    echo "npm is not available"
    exit 1
else
    echo "npm is available: $(npm --version)"
fi

# Install Puppeteer globally
echo "Installing Puppeteer..."
npm install -g puppeteer

# Check if Chrome/Chromium is installed
if command -v google-chrome &> /dev/null; then
    echo "Google Chrome is installed"
elif command -v chromium-browser &> /dev/null; then
    echo "Chromium is installed"
elif command -v chromium &> /dev/null; then
    echo "Chromium is installed"
else
    echo "Chrome/Chromium not found. Installing..."
    
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        # Ubuntu/Debian
        wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
        sudo sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google-chrome.list'
        sudo apt-get update
        sudo apt-get install -y google-chrome-stable
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        if command -v brew &> /dev/null; then
            brew install --cask google-chrome
        else
            echo "Please install Google Chrome manually"
        fi
    else
        echo "Please install Chrome/Chromium manually for your operating system"
    fi
fi

# Test browser automation
echo "Testing browser automation..."
node -e "
const puppeteer = require('puppeteer');
(async () => {
  try {
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();
    await page.goto('https://example.com');
    const title = await page.title();
    console.log('Browser automation test successful:', title);
    await browser.close();
  } catch (error) {
    console.error('Browser automation test failed:', error.message);
    process.exit(1);
  }
})();
"

echo ""
echo "Browser dependencies installation completed!"
echo ""
echo "Next steps:"
echo "1. Run: composer install"
echo "2. Configure your .env file"
echo "3. Run: php artisan migrate"
echo "4. Test scraper: php artisan scraper:test"
echo ""

