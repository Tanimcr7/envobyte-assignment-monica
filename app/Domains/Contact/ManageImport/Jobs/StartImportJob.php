<?php

namespace App\Domains\Contact\ManageImport\Jobs;

use App\Models\ImportJob;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class StartImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $importJobId;

    public function __construct(int $importJobId)
    {
        $this->importJobId = $importJobId;
    }

    public function handle()
    {
        $importJob = ImportJob::find($this->importJobId);
        if (!$importJob || $importJob->wasCancelled()) {
            return;
        }

        $importJob->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $filePath = storage_path('app/' . $importJob->file_path);
        if (!file_exists($filePath)) {
            $importJob->update(['status' => 'failed', 'errors' => [['row' => 0, 'message' => 'File missing']]]);
            return;
        }

        $jobs = [];
        $file = fopen($filePath, 'r');
        $header = fgetcsv($file); // Skip header

        $chunkSize = 50;
        $chunk = [];
        $rowNum = 1; // 1 represents the header

        while (($row = fgetcsv($file)) !== false) {
            $rowNum++;
            $combined = [];
            foreach ($header as $i => $colName) {
                $combined[$colName] = $row[$i] ?? null;
            }
            $chunk[] = ['rowNum' => $rowNum, 'data' => $combined];

            if (count($chunk) >= $chunkSize) {
                $jobs[] = new ProcessImportChunkJob($this->importJobId, $chunk);
                $chunk = [];
            }
        }

        if (count($chunk) > 0) {
            $jobs[] = new ProcessImportChunkJob($this->importJobId, $chunk);
        }
        fclose($file);

        if (empty($jobs)) {
            $importJob->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        Bus::batch($jobs)->then(function (Batch $batch) use ($importJobId) {
            $job = ImportJob::find($importJobId);
            if ($job && !$job->wasCancelled()) {
                $job->update(['status' => 'completed', 'completed_at' => now()]);
            }
        })->catch(function (Batch $batch, Throwable $e) use ($importJobId) {
            $job = ImportJob::find($importJobId);
            if ($job && !$job->wasCancelled()) {
                $job->update(['status' => 'failed', 'completed_at' => now()]);
            }
        })->dispatch();
    }
}
