<?php

namespace App\Console\Commands;

use App\Services\Scrapers\AmazonRankingScraper;
use App\Services\Scrapers\FlipkartRankingScraper;
use App\Services\Scrapers\VijaySalesRankingScraper;
use Illuminate\Console\Command;

class ScrapeRankingsCommand extends Command
{
    protected $signature = 'scraper:rankings 
                            {platform : Platform to scrape (amazon, flipkart, vijaysales, all)}
                            {--keyword-ids=* : Specific keyword IDs to scrape}';

    protected $description = 'Scrape product rankings for keywords';

    public function handle()
    {
        $platform = $this->argument('platform');
        $keywordIds = $this->option('keyword-ids');

        $this->info("Starting ranking scraping for: {$platform}");
        $this->newLine();

        try {
            $stats = [];

            if ($platform === 'all' || $platform === 'amazon') {
                $this->info('Scraping Amazon rankings...');
                $scraper = new AmazonRankingScraper();
                $stats['amazon'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            if ($platform === 'all' || $platform === 'flipkart') {
                $this->info('Scraping Flipkart rankings...');
                $scraper = new FlipkartRankingScraper();
                $stats['flipkart'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            if ($platform === 'all' || $platform === 'vijaysales') {
                $this->info('Scraping VijaySales rankings...');
                $scraper = new VijaySalesRankingScraper();
                $stats['vijaysales'] = $scraper->scrapeRankings($keywordIds ?: null);
            }

            $this->newLine();
            $this->info('Ranking scraping completed!');
            $this->newLine();

            // Display statistics
            foreach ($stats as $plat => $stat) {
                $this->info(strtoupper($plat) . ' Statistics:');
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Keywords Processed', $stat['keywords_processed']],
                        ['Products Found', $stat['products_found']],
                        ['Rankings Recorded', $stat['rankings_recorded']],
                        ['Errors', $stat['errors_count']],
                    ]
                );
                $this->newLine();
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during ranking scraping: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
