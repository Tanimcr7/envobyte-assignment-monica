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
use Illuminate\Support\Facades\Storage;
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

        if (!Storage::disk('local')->exists($importJob->file_path)) {
            $importJob->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'message' => 'File missing']],
                'completed_at' => now(),
            ]);
            return;
        }

        $filePath = Storage::disk('local')->path($importJob->file_path);
        $jobs = $importJob->format === 'vcard'
            ? $this->buildVcardJobs($filePath)
            : $this->buildCsvJobs($filePath);

        if ($jobs === null) {
            $importJob->update([
                'status' => 'failed',
                'errors' => [['row' => 0, 'message' => 'CSV header missing']],
                'completed_at' => now(),
            ]);
            return;
        }

        if (empty($jobs)) {
            if ($importJob->format === 'vcard' && $importJob->total_rows === 0) {
                $importJob->update([
                    'status' => 'failed',
                    'errors' => [['row' => 0, 'message' => 'No vCards found']],
                    'completed_at' => now(),
                ]);
                return;
            }

            $importJob->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $importJobId = $this->importJobId;
        $batch = Bus::batch($jobs)->then(function (Batch $batch) use ($importJobId) {
            $job = ImportJob::find($importJobId);
            if ($job && !$job->wasCancelled()) {
                $status = $job->total_rows > 0 && $job->failed_rows >= $job->total_rows
                    ? 'failed'
                    : 'completed';

                $job->update(['status' => $status, 'completed_at' => now()]);
            }
        })->catch(function (Batch $batch, Throwable $e) use ($importJobId) {
            $job = ImportJob::find($importJobId);
            if ($job && !$job->wasCancelled()) {
                $job->update(['status' => 'failed', 'completed_at' => now()]);
            }
        })->dispatch();

        $importJob->update(['batch_id' => $batch->id]);
    }

    private function buildCsvJobs(string $filePath): ?array
    {
        $jobs = [];
        $file = fopen($filePath, 'r');
        $header = fgetcsv($file); // Skip header
        if (!$header) {
            fclose($file);

            return null;
        }
        $header = array_map(fn ($column) => $this->normalizeHeader($column), $header);

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

        return $jobs;
    }

    private function buildVcardJobs(string $filePath): array
    {
        $jobs = [];
        $file = fopen($filePath, 'r');
        $chunkSize = 50;
        $chunk = [];
        $card = '';
        $cardNum = 0;
        $insideCard = false;

        while (($line = fgets($file)) !== false) {
            if (strtoupper(trim($line)) === 'BEGIN:VCARD') {
                $insideCard = true;
                $card = $line;
                continue;
            }

            if (!$insideCard) {
                continue;
            }

            $card .= $line;

            if (strtoupper(trim($line)) === 'END:VCARD') {
                $cardNum++;
                $chunk[] = ['rowNum' => $cardNum, 'type' => 'vcard', 'data' => $card];
                $card = '';
                $insideCard = false;

                if (count($chunk) >= $chunkSize) {
                    $jobs[] = new ProcessImportChunkJob($this->importJobId, $chunk);
                    $chunk = [];
                }
            }
        }

        if (count($chunk) > 0) {
            $jobs[] = new ProcessImportChunkJob($this->importJobId, $chunk);
        }

        fclose($file);

        return $jobs;
    }

    private function normalizeHeader(?string $column): string
    {
        $column = preg_replace('/^\xEF\xBB\xBF/', '', (string) $column);
        $column = strtolower(trim($column));

        return preg_replace('/[\s-]+/', '_', $column) ?? $column;
    }
}
