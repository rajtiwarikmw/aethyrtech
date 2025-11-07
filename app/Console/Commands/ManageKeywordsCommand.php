<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use Illuminate\Console\Command;

class ManageKeywordsCommand extends Command
{
    protected $signature = 'keywords:manage 
                            {action : Action to perform (list, add, activate, deactivate, delete)}
                            {platform? : Platform (amazon, flipkart, vijaysales)}
                            {--keyword= : Keyword text}
                            {--id= : Keyword ID}
                            {--file= : File containing keywords (one per line)}';

    protected $description = 'Manage keywords for ranking tracking';

    public function handle()
    {
        $action = $this->argument('action');

        return match($action) {
            'list' => $this->listKeywords(),
            'add' => $this->addKeywords(),
            'activate' => $this->activateKeyword(),
            'deactivate' => $this->deactivateKeyword(),
            'delete' => $this->deleteKeyword(),
            default => $this->error("Invalid action: {$action}"),
        };
    }

    protected function listKeywords()
    {
        $platform = $this->argument('platform');

        $query = Keyword::query();
        
        if ($platform) {
            $query->where('platform', $platform);
        }

        $keywords = $query->orderBy('platform')->orderBy('keyword')->get();

        if ($keywords->isEmpty()) {
            $this->warn('No keywords found');
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Platform', 'Keyword', 'Status', 'Created'],
            $keywords->map(function ($keyword) {
                return [
                    $keyword->id,
                    strtoupper($keyword->platform),
                    $keyword->keyword,
                    $keyword->status ? 'Active' : 'Inactive',
                    $keyword->created_at->format('Y-m-d'),
                ];
            })
        );

        $this->newLine();
        $this->info("Total: {$keywords->count()} keywords");

        return Command::SUCCESS;
    }

    protected function addKeywords()
    {
        $platform = $this->argument('platform');
        $keyword = $this->option('keyword');
        $file = $this->option('file');

        if (!$platform) {
            $this->error('Platform is required for adding keywords');
            return Command::FAILURE;
        }

        $keywords = [];

        if ($keyword) {
            $keywords[] = $keyword;
        } elseif ($file) {
            if (!file_exists($file)) {
                $this->error("File not found: {$file}");
                return Command::FAILURE;
            }

            $keywords = array_filter(array_map('trim', file($file)));
        } else {
            $this->error('Either --keyword or --file is required');
            return Command::FAILURE;
        }

        $added = 0;
        $skipped = 0;

        foreach ($keywords as $kw) {
            try {
                $existing = Keyword::where('platform', $platform)
                    ->where('keyword', $kw)
                    ->first();

                if ($existing) {
                    $this->warn("Skipped (already exists): {$kw}");
                    $skipped++;
                } else {
                    Keyword::create([
                        'platform' => $platform,
                        'keyword' => $kw,
                        'status' => true,
                    ]);
                    $this->info("Added: {$kw}");
                    $added++;
                }
            } catch (\Exception $e) {
                $this->error("Failed to add '{$kw}': {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Summary: {$added} added, {$skipped} skipped");

        return Command::SUCCESS;
    }

    protected function activateKeyword()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Keyword ID is required (use --id=)');
            return Command::FAILURE;
        }

        $keyword = Keyword::find($id);

        if (!$keyword) {
            $this->error("Keyword not found with ID: {$id}");
            return Command::FAILURE;
        }

        $keyword->update(['status' => true]);
        $this->info("Activated keyword: {$keyword->keyword}");

        return Command::SUCCESS;
    }

    protected function deactivateKeyword()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Keyword ID is required (use --id=)');
            return Command::FAILURE;
        }

        $keyword = Keyword::find($id);

        if (!$keyword) {
            $this->error("Keyword not found with ID: {$id}");
            return Command::FAILURE;
        }

        $keyword->update(['status' => false]);
        $this->info("Deactivated keyword: {$keyword->keyword}");

        return Command::SUCCESS;
    }

    protected function deleteKeyword()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Keyword ID is required (use --id=)');
            return Command::FAILURE;
        }

        $keyword = Keyword::find($id);

        if (!$keyword) {
            $this->error("Keyword not found with ID: {$id}");
            return Command::FAILURE;
        }

        if (!$this->confirm("Are you sure you want to delete keyword '{$keyword->keyword}'?")) {
            $this->info('Cancelled');
            return Command::SUCCESS;
        }

        $keyword->delete();
        $this->info("Deleted keyword: {$keyword->keyword}");

        return Command::SUCCESS;
    }
}
