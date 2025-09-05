<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ProxyRotator
{
    private array $proxies = [];
    private int $currentProxyIndex = 0;
    private array $failedProxies = [];

    public function __construct()
    {
        $this->loadProxies();
    }

    /**
     * Load proxies from configuration or environment
     */
    private function loadProxies(): void
    {
        // Load from environment variable (comma-separated list)
        $proxyList = env('SCRAPER_PROXIES', '');
        
        if ($proxyList) {
            $this->proxies = array_map('trim', explode(',', $proxyList));
            Log::info("Loaded {count} proxies from configuration", ['count' => count($this->proxies)]);
        }
        
        // You can also load from a file or database
        $proxyFile = storage_path('app/proxies.txt');
        if (file_exists($proxyFile)) {
            $fileProxies = array_filter(array_map('trim', file($proxyFile)));
            $this->proxies = array_merge($this->proxies, $fileProxies);
            Log::info("Loaded additional {count} proxies from file", ['count' => count($fileProxies)]);
        }
        
        // Remove duplicates
        $this->proxies = array_unique($this->proxies);
    }

    /**
     * Check if proxies are available
     */
    public function hasProxies(): bool
    {
        return !empty($this->proxies);
    }

    /**
     * Get the next proxy in rotation
     */
    public function getNextProxy(): ?string
    {
        if (empty($this->proxies)) {
            return null;
        }

        $availableProxies = array_diff($this->proxies, $this->failedProxies);
        
        if (empty($availableProxies)) {
            // Reset failed proxies if all have failed
            Log::warning("All proxies have failed, resetting failed proxy list");
            $this->failedProxies = [];
            $availableProxies = $this->proxies;
        }

        $availableProxies = array_values($availableProxies);
        $proxy = $availableProxies[$this->currentProxyIndex % count($availableProxies)];
        
        $this->currentProxyIndex++;
        
        Log::debug("Using proxy: {proxy}", ['proxy' => $this->maskProxy($proxy)]);
        
        return $proxy;
    }

    /**
     * Mark a proxy as failed
     */
    public function markProxyAsFailed(string $proxy): void
    {
        if (!in_array($proxy, $this->failedProxies)) {
            $this->failedProxies[] = $proxy;
            Log::warning("Marked proxy as failed: {proxy}", ['proxy' => $this->maskProxy($proxy)]);
        }
    }

    /**
     * Get proxy configuration for HTTP client
     */
    public function getProxyConfig(string $proxy): array
    {
        // Support different proxy formats:
        // http://proxy:port
        // http://user:pass@proxy:port
        // socks5://proxy:port
        
        return [
            'proxy' => $proxy,
            'timeout' => 30,
            'connect_timeout' => 15,
        ];
    }

    /**
     * Test if a proxy is working
     */
    public function testProxy(string $proxy): bool
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get('http://httpbin.org/ip', [
                'proxy' => $proxy,
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                Log::info("Proxy test successful", [
                    'proxy' => $this->maskProxy($proxy),
                    'ip' => $data['origin'] ?? 'unknown'
                ]);
                return true;
            }
        } catch (\Exception $e) {
            Log::warning("Proxy test failed", [
                'proxy' => $this->maskProxy($proxy),
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Test all proxies and remove non-working ones
     */
    public function validateProxies(): void
    {
        if (empty($this->proxies)) {
            return;
        }

        Log::info("Validating {count} proxies", ['count' => count($this->proxies)]);
        
        $workingProxies = [];
        
        foreach ($this->proxies as $proxy) {
            if ($this->testProxy($proxy)) {
                $workingProxies[] = $proxy;
            } else {
                $this->markProxyAsFailed($proxy);
            }
            
            // Small delay between tests
            sleep(1);
        }
        
        $this->proxies = $workingProxies;
        
        Log::info("Proxy validation complete", [
            'working_proxies' => count($this->proxies),
            'failed_proxies' => count($this->failedProxies)
        ]);
    }

    /**
     * Mask proxy credentials for logging
     */
    private function maskProxy(string $proxy): string
    {
        // Hide credentials in logs for security
        return preg_replace('/\/\/([^:]+):([^@]+)@/', '//***:***@', $proxy);
    }

    /**
     * Get proxy statistics
     */
    public function getStats(): array
    {
        return [
            'total_proxies' => count($this->proxies),
            'failed_proxies' => count($this->failedProxies),
            'working_proxies' => count($this->proxies) - count($this->failedProxies),
            'current_index' => $this->currentProxyIndex,
        ];
    }
}

