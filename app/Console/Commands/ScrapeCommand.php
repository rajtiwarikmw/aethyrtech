<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Scrapers\AmazonScraper;
use App\Services\Scrapers\FlipkartScraper;
use App\Services\Scrapers\VijaySalesScraper;
use App\Services\Scrapers\RelianceDigitalScraper;
use App\Services\Scrapers\CromaScraper;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\Log;

class ScrapeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scraper:run 
                            {platform? : Platform to scrape (amazon, flipkart, vijaysales, reliancedigital, croma, all)}
                            {--force : Force scraping even if recently scraped}
                            {--limit=50 : Limit number of products per platform}
                            {--timeout=7200 : Maximum execution time in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Run Product data scraper for specified platform(s)';

    protected DatabaseService $databaseService;

    public function __construct(DatabaseService $databaseService)
    {
        parent::__construct();
        $this->databaseService = $databaseService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $platform = $this->argument('platform') ?? 'all';
        $force = $this->option('force');
        $timeout = (int) $this->option('timeout');

        // Set execution time limit
        set_time_limit($timeout);

        $this->info("Starting product scraper for platform: {$platform}");
        Log::info("Scraper command started", [
            'platform' => $platform,
            'force' => $force,
            'timeout' => $timeout
        ]);

        try {
            if ($platform === 'all') {
                $this->scrapeAllPlatforms($force);
            } else {
                $this->scrapePlatform($platform, $force);
            }

            $this->info("Scraping completed successfully!");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Scraping failed: " . $e->getMessage());
            Log::error("Scraper command failed", [
                'platform' => $platform,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Scrape all platforms
     */
    protected function scrapeAllPlatforms(bool $force): void
    {
        $platforms = array_keys(config('scraper.platforms', []));

        $this->info("Scraping " . count($platforms) . " platforms...");

        $progressBar = $this->output->createProgressBar(count($platforms));
        $progressBar->start();

        foreach ($platforms as $platform) {
            try {
                $this->scrapePlatform($platform, $force, false);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->error("\nFailed to scrape {$platform}: " . $e->getMessage());
                Log::error("Platform scraping failed", [
                    'platform' => $platform,
                    'error' => $e->getMessage()
                ]);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();
    }

    /**
     * Scrape specific platform
     */
    protected function scrapePlatform(string $platform, bool $force, bool $showProgress = true): void
    {
        if (!$force && $this->wasRecentlyScraped($platform)) {
            $this->warn("Platform {$platform} was recently scraped. Use --force to override.");
            return;
        }

        $scraper = $this->createScraper($platform);
        if (!$scraper) {
            throw new \InvalidArgumentException("Unknown platform: {$platform}");
        }

        $categoryUrls = $this->getCategoryUrls($platform);
        if (empty($categoryUrls)) {
            throw new \InvalidArgumentException("No category URLs configured for platform: {$platform}");
        }

        if ($showProgress) {
            $this->info("Scraping {$platform}...");
        }

        $scraper->scrape($categoryUrls);

        if ($showProgress) {
            $this->info("Completed scraping {$platform}");
        }
    }

    /**
     * Create scraper instance for platform
     */
    protected function createScraper(string $platform): ?\App\Services\Scrapers\BaseScraper
    {
        return match ($platform) {
            'amazon' => new AmazonScraper(),
            'flipkart' => new FlipkartScraper(),
            'vijaysales' => new VijaySalesScraper(),
            'reliancedigital' => new RelianceDigitalScraper(),
            'croma' => new CromaScraper(),
            default => null
        };
    }

    /**
     * Get category URLs for platform
     */
    protected function getCategoryUrls(string $platform): array
    {
        $platformConfig = config("scraper.platforms.{$platform}");
        return $platformConfig['category_urls'] ?? [];
    }

    /**
     * Check if platform was recently scraped
     */
    protected function wasRecentlyScraped(string $platform): bool
    {
        $recentLogs = $this->databaseService->getRecentScrapingLogs(1, $platform);

        if ($recentLogs->isEmpty()) {
            return false;
        }

        $lastLog = $recentLogs->first();
        $hoursSinceLastScrape = now()->diffInHours($lastLog->created_at);

        // Consider recently scraped if less than 12 hours ago
        return $hoursSinceLastScrape < 12;
    }
}
