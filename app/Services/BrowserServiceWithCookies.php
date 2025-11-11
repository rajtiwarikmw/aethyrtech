<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class BrowserServiceWithCookies
{
    protected array $defaultOptions;
    protected array $cookies = [];

    public function __construct()
    {
        $this->defaultOptions = [
            'timeout' => 120,
            'waitUntilNetworkIdle' => true,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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
     * Set cookies for browser session
     */
    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    /**
     * Get page content with JavaScript rendering and cookies
     */
    public function getPageContent(string $url, int $waitTime = 3): ?string
    {
        $attempts = 0;
        $maxAttempts = 3;
        
        while ($attempts < $maxAttempts) {
            try {
                Log::info("Fetching page content with browser (attempt " . ($attempts + 1) . ")", [
                    'url' => $url,
                    'has_cookies' => !empty($this->cookies)
                ]);

                $timeout = $attempts === 0 ? $this->defaultOptions['timeout'] : ($this->defaultOptions['timeout'] * 2);
                
                $browsershot = Browsershot::url($url)
                    ->timeout($timeout)
                    ->userAgent($this->defaultOptions['userAgent'])
                    ->windowSize($this->defaultOptions['windowSize'][0], $this->defaultOptions['windowSize'][1])
                    ->delay($waitTime * 1000);

                // Add cookies if provided
                if (!empty($this->cookies)) {
                    $browsershot->setOption('cookie', $this->cookies);
                    Log::debug("Added cookies to browser", ['cookie_count' => count($this->cookies)]);
                }

                // Add Chrome arguments
                foreach ($this->defaultOptions['args'] as $arg) {
                    $browsershot->addChromiumArguments([$arg]);
                }

                // Try with network idle first, then fallback to load event
                if ($attempts === 0) {
                    $browsershot->waitUntilNetworkIdle();
                } else {
                    $browsershot->setDelay(3000);
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
}
