<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class BrowserService
{
    protected array $defaultOptions;

    public function __construct()
    {
        $this->defaultOptions = [
            'timeout' => 120, // Increased from 60 to 120 seconds
            'waitUntilNetworkIdle' => true,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'windowSize' => [1920, 1080],
            'args' => [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding'
            ]
        ];
    }

    /**
     * Get page content with JavaScript rendering
     */
    public function getPageContent(string $url, int $waitTime = 3): ?string
    {
        $attempts = 0;
        $maxAttempts = 3;
        
        while ($attempts < $maxAttempts) {
            try {
                Log::info("Fetching page content with browser (attempt " . ($attempts + 1) . ")", ['url' => $url]);

                $timeout = $attempts === 0 ? $this->defaultOptions['timeout'] : ($this->defaultOptions['timeout'] * 2);
                
                $browsershot = Browsershot::url($url)
                    ->timeout($timeout)
                    ->userAgent($this->defaultOptions['userAgent'])
                    ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                    ->delay($waitTime * 1000); // Convert to milliseconds

                // Add Chrome arguments
                foreach ($this->defaultOptions['args'] as $arg) {
                    $browsershot->addChromiumArguments([$arg]);
                }

                // Try with network idle first, then fallback to load event
                if ($attempts === 0) {
                    $browsershot->waitUntilNetworkIdle();
                } else {
                    // Fallback: just wait for load event
                    $browsershot->waitForFunction('document.readyState === "complete"', 30000);
                }

                $html = $browsershot->bodyHtml();

                Log::info("Successfully fetched page content", [
                    'url' => $url,
                    'content_length' => strlen($html),
                    'attempt' => $attempts + 1
                ]);

                return $html;

            } catch (\Exception $e) {
                $attempts++;
                
                Log::warning("Browser attempt {$attempts} failed for URL: {$url}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts
                ]);
                
                if ($attempts < $maxAttempts) {
                    // Wait before retry
                    sleep(5 * $attempts);
                }
            }
        }
        
        Log::error('All browser attempts failed', [
            'url' => $url,
            'attempts' => $maxAttempts
        ]);
        
        return null;
    }

    /**
     * Get page content with pagination support
     */
    public function getAllPagesContent(string $baseUrl, array $paginationConfig = []): array
    {
        $allContent = [];
        $currentPage = 1;
        $maxPages = $paginationConfig['max_pages'] ?? 50;
        $pageParam = $paginationConfig['page_param'] ?? 'page';
        $noContentCount = 0;
        $maxNoContentPages = 3;

        while ($currentPage <= $maxPages && $noContentCount < $maxNoContentPages) {
            $url = $this->buildPaginationUrl($baseUrl, $pageParam, $currentPage);
            
            Log::info("Scraping page {$currentPage} with browser", ['url' => $url]);
            
            $content = $this->getPageContent($url, 5);
            
            if (!$content || strlen($content) < 1000) {
                $noContentCount++;
                Log::warning("No content or minimal content on page {$currentPage}");
                
                if ($noContentCount >= $maxNoContentPages) {
                    Log::info("Stopping pagination due to consecutive empty pages");
                    break;
                }
                
                $currentPage++;
                continue;
            }

            $noContentCount = 0; // Reset counter
            
            $allContent[] = [
                'page' => $currentPage,
                'url' => $url,
                'content' => $content
            ];

            // Check if there's a next page indicator
            if (isset($paginationConfig['has_next_selector'])) {
                if (!$this->hasNextPage($content, $paginationConfig['has_next_selector'])) {
                    Log::info("No more pages found after page {$currentPage}");
                    break;
                }
            }

            $currentPage++;
            
            // Add delay between pages
            sleep(rand(3, 6));
        }

        Log::info("Completed pagination scraping", [
            'base_url' => $baseUrl,
            'total_pages' => count($allContent),
            'last_page' => $currentPage - 1
        ]);

        return $allContent;
    }

    /**
     * Handle infinite scroll pages
     */
    public function getInfiniteScrollContent(string $url, int $scrolls = 10): ?string
    {
        try {
            Log::info("Fetching infinite scroll content", ['url' => $url, 'scrolls' => $scrolls]);

            $browsershot = Browsershot::url($url)
                ->timeout(120) // Longer timeout for infinite scroll
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntilNetworkIdle();

            // Add Chrome arguments
            foreach ($this->defaultOptions['args'] as $arg) {
                $browsershot->addChromiumArguments([$arg]);
            }

            // Custom script for infinite scrolling
            $scrollScript = "
                let scrollCount = 0;
                const maxScrolls = {$scrolls};
                
                async function scrollAndWait() {
                    while (scrollCount < maxScrolls) {
                        window.scrollTo(0, document.body.scrollHeight);
                        scrollCount++;
                        
                        // Wait for content to load
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        // Check if new content loaded
                        const currentHeight = document.body.scrollHeight;
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        if (currentHeight === document.body.scrollHeight) {
                            // No new content loaded, might be end of page
                            break;
                        }
                    }
                }
                
                await scrollAndWait();
            ";

            $html = $browsershot
                ->evaluate($scrollScript)
                ->bodyHtml();

            Log::info("Successfully fetched infinite scroll content", [
                'url' => $url,
                'content_length' => strlen($html)
            ]);

            return $html;

        } catch (\Exception $e) {
            Log::error('Infinite scroll error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Execute custom JavaScript and get result
     */
    public function executeScript(string $url, string $script, int $waitTime = 3): ?string
    {
        try {
            Log::info("Executing custom script", ['url' => $url]);

            $browsershot = Browsershot::url($url)
                ->timeout($this->defaultOptions['timeout'])
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntilNetworkIdle()
                ->delay($waitTime * 1000);

            // Add Chrome arguments
            foreach ($this->defaultOptions['args'] as $arg) {
                $browsershot->addChromiumArguments([$arg]);
            }

            $result = $browsershot->evaluate($script);

            return $result;

        } catch (\Exception $e) {
            Log::error('Script execution error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Take screenshot of page (useful for debugging)
     */
    public function takeScreenshot(string $url, string $savePath): bool
    {
        try {
            Log::info("Taking screenshot", ['url' => $url, 'path' => $savePath]);

            Browsershot::url($url)
                ->timeout($this->defaultOptions['timeout'])
                ->userAgent($this->defaultOptions['userAgent'])
                ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                ->waitUntilNetworkIdle()
                ->save($savePath);

            return file_exists($savePath);

        } catch (\Exception $e) {
            Log::error('Screenshot error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build pagination URL
     */
    protected function buildPaginationUrl(string $baseUrl, string $pageParam, int $page): string
    {
        if ($page === 1) {
            return $baseUrl;
        }

        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . $pageParam . '=' . $page;
    }

    /**
     * Check if there's a next page
     */
    protected function hasNextPage(string $content, string $selector): bool
    {
        // Simple check for next page indicator
        return strpos($content, $selector) !== false;
    }

    /**
     * Check if browser dependencies are installed
     */
    public static function checkDependencies(): array
    {
        $status = [
            'node' => false,
            'npm' => false,
            'chrome' => false,
            'puppeteer' => false,
            'ready' => false
        ];

        try {
            // Check Node.js
            $nodeResult = shell_exec('node --version 2>/dev/null');
            $status['node'] = !empty($nodeResult);

            // Check npm
            $npmResult = shell_exec('npm --version 2>/dev/null');
            $status['npm'] = !empty($npmResult);

            // Check Chrome/Chromium
            $chromeResult = shell_exec('which google-chrome 2>/dev/null || which chromium-browser 2>/dev/null || which chromium 2>/dev/null');
            $status['chrome'] = !empty($chromeResult);

            // Check Puppeteer
            $puppeteerResult = shell_exec('npm list -g puppeteer 2>/dev/null');
            $status['puppeteer'] = !empty($puppeteerResult) && strpos($puppeteerResult, 'puppeteer@') !== false;

            // Overall status
            $status['ready'] = $status['node'] && $status['npm'] && $status['chrome'] && $status['puppeteer'];

        } catch (\Exception $e) {
            Log::error('Failed to check browser dependencies', ['error' => $e->getMessage()]);
        }

        return $status;
    }

    /**
     * Install browser dependencies
     */
    public static function installDependencies(): bool
    {
        try {
            Log::info('Installing browser dependencies...');

            // Run the installation script
            $scriptPath = base_path('install-browser-deps.sh');
            
            if (!file_exists($scriptPath)) {
                Log::error('Browser dependencies installation script not found');
                return false;
            }

            $result = shell_exec("bash {$scriptPath} 2>&1");
            
            Log::info('Browser dependencies installation result', ['output' => $result]);

            // Check if installation was successful
            $status = self::checkDependencies();
            
            return $status['ready'];

        } catch (\Exception $e) {
            Log::error('Failed to install browser dependencies', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

