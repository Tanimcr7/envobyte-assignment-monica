<?php

namespace App\Domains\Contact\ManageImport\Jobs;

use App\Domains\Contact\Dav\Services\ImportVCard;
use App\Domains\Contact\ManageContact\Services\CreateContact;
use App\Models\ImportJob;
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
            if (ImportJob::whereKey($this->importJobId)->value('status') === 'cancelled') {
                break;
            }

            $rowNum = $item['rowNum'];
            $data = $item['data'];
            $processed++;

            if (($item['type'] ?? 'csv') === 'vcard') {
                try {
                    $this->processVcard($importJob, $data);
                } catch (\Throwable $e) {
                    $failed++;
                    $newErrors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
                }

                continue;
            }

            $firstName = trim((string) ($data['first_name'] ?? ''));
            if ($firstName === '') {
                $firstName = trim((string) ($data['name'] ?? ''));
            }
            $lastName = trim((string) ($data['last_name'] ?? '')) ?: null;
            $email = trim((string) ($data['email'] ?? ''));

            try {
                if ($firstName === '') {
                    throw new \Exception("Missing required field: name");
                }
                
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid email: '{$email}'");
                }

                DB::transaction(function () use ($importJob, $firstName, $lastName) {
                    (new CreateContact)->execute([
                        'account_id' => $importJob->account_id,
                        'author_id' => $importJob->user_id,
                        'vault_id' => $importJob->vault_id,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'listed' => true,
                    ]);
                });
            } catch (\Throwable $e) {
                $failed++;
                $newErrors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
            }
        }

        if ($processed === 0 && $failed === 0 && empty($newErrors)) {
            return;
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

    private function processVcard(ImportJob $importJob, string $entry): void
    {
        $result = (new ImportVCard)->execute([
            'account_id' => $importJob->account_id,
            'author_id' => $importJob->user_id,
            'vault_id' => $importJob->vault_id,
            'entry' => $entry,
            'behaviour' => ImportVCard::BEHAVIOUR_ADD,
            'external' => true,
        ]);

        if (isset($result['error'])) {
            throw new \Exception($result['reason'] ?? $result['error']);
        }
    }
}
