<?php

namespace App\Domains\Contact\ManageImport\Jobs;

use App\Models\ImportJob;
use App\Models\Contact;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessImportChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $importJobId;
    protected $chunk;

    public function __construct(int $importJobId, array $chunk)
    {
        $this->importJobId = $importJobId;
        $this->chunk = $chunk;
    }

    public function handle()
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $importJob = ImportJob::find($this->importJobId);
        if (!$importJob || $importJob->wasCancelled()) {
            return;
        }

        $processed = 0;
        $failed = 0;
        $newErrors = [];

        foreach ($this->chunk as $item) {
            $rowNum = $item['rowNum'];
            $data = $item['data'];

            try {
                if (empty($data['name']) && empty($data['first_name'])) {
                    throw new \Exception("Missing required field: name");
                }
                
                if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid email: '{$data['email']}'");
                }

                // Simulate processing logic that creates Contact
                Contact::create([
                    'account_id' => $importJob->account_id,
                    'first_name' => $data['name'] ?? $data['first_name'] ?? 'Unknown',
                    'last_name' => $data['last_name'] ?? null,
                ]);

                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $newErrors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }

        // Safely update counters and errors avoiding race conditions
        DB::transaction(function () use ($importJob, $processed, $failed, $newErrors) {
            $job = ImportJob::lockForUpdate()->find($importJob->id);
            if ($job) {
                $job->processed_rows += $processed;
                $job->failed_rows += $failed;
                
                $existingErrors = $job->errors ?? [];
                $job->errors = array_merge($existingErrors, $newErrors);
                
                $job->save();
            }
        });
    }
}
