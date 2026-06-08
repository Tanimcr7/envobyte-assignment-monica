<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStuckImports extends Command
{
    protected $signature = 'imports:check-stuck';
    protected $description = 'Checks for stuck imports and high failure rates to trigger alerts';

    public function handle()
    {
        // 1. Recover/Fail Stuck Imports (Stuck for >30 minutes)
        $stuckImports = ImportJob::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();

        foreach ($stuckImports as $job) {
            $job->update([
                'status' => 'failed',
                'errors' => array_merge($job->errors ?? [], [['row' => 0, 'message' => 'Job timed out or crashed mid-way']]),
                'completed_at' => now(),
            ]);
            Log::warning("ImportJob {$job->id} was stuck and has been marked as failed.");
        }

        // 2. Alerting Rule: High Failure Rate (>20% in the last hour)
        $recentImports = ImportJob::where('created_at', '>=', now()->subHour())
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        $totalRows = $recentImports->sum('total_rows');
        $failedRows = $recentImports->sum('failed_rows');

        if ($totalRows > 0) {
            $failureRate = $failedRows / $totalRows;
            if ($failureRate > 0.20) {
                // Here we would typically trigger an email or Slack notification to the engineering team.
                Log::critical("High Import Failure Rate Alert: " . round($failureRate * 100) . "% of rows failed in the last hour.");
            }
        }

        $this->info('Stuck imports and failure rates checked successfully.');
    }
}
