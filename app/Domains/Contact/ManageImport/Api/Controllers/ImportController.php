<?php

namespace App\Domains\Contact\ManageImport\Api\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $imports = ImportJob::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->input('per_page', 10));

        $data = $imports->map(function ($job) {
            $progressPct = $this->progressPct($job);
            return [
                'id' => $job->id,
                'filename' => $job->filename,
                'total_rows' => $job->total_rows,
                'processed_rows' => $job->processed_rows,
                'failed_rows' => $job->failed_rows,
                'status' => $job->status,
                'progress_pct' => $progressPct,
                'created_at' => $this->timestamp($job->created_at),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $imports->currentPage(),
                'per_page' => $imports->perPage(),
                'total' => $imports->total(),
                'last_page' => $imports->lastPage(),
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,vcf|max:10240',
            'vault_id' => 'sometimes|nullable|string',
        ]);

        $user = auth()->user();
        $vault = $this->resolveVault($request);
        if (!$vault) {
            return response()->json([
                'message' => 'No accessible vault found for this import.',
            ], 422);
        }

        $file = $request->file('file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        $existingJob = ImportJob::where('user_id', auth()->id())
            ->where('file_hash', $fileHash)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->first();

        if ($existingJob) {
            return response()->json([
                'message' => 'This file has already been imported or is currently processing.',
                'data' => $existingJob
            ], 409);
        }

        $path = $file->storeAs('imports', $fileHash . '_' . $file->getClientOriginalName(), 'local');

        $format = $this->detectFormat($file->getClientOriginalName());
        $totalRows = $format === 'vcard'
            ? $this->countVcards($file->getRealPath())
            : $this->countCsvRows($file->getRealPath());

        $importJob = ImportJob::create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'filename' => $file->getClientOriginalName(),
            'format' => $format,
            'file_path' => $path,
            'file_hash' => $fileHash,
            'total_rows' => $totalRows,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        \App\Domains\Contact\ManageImport\Jobs\StartImportJob::dispatch($importJob->id);

        return response()->json([
            'data' => [
                'id' => $importJob->id,
                'filename' => $importJob->filename,
                'total_rows' => $importJob->total_rows,
                'processed_rows' => $importJob->processed_rows,
                'failed_rows' => $importJob->failed_rows,
                'status' => $importJob->status,
                'created_at' => $this->timestamp($importJob->created_at),
            ]
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        $progressPct = $this->progressPct($job);
        
        $estimated_remaining_sec = null;
        if ($job->status === 'processing' && $job->started_at && $job->processed_rows > 0) {
            $elapsed = now()->diffInSeconds($job->started_at);
            $rate = $job->processed_rows / max($elapsed, 1);
            $remaining_rows = max(0, $job->total_rows - $job->processed_rows);
            $estimated_remaining_sec = round($remaining_rows / max($rate, 0.01));
        }

        return response()->json([
            'data' => [
                'id' => $job->id,
                'filename' => $job->filename,
                'total_rows' => $job->total_rows,
                'processed_rows' => $job->processed_rows,
                'failed_rows' => $job->failed_rows,
                'status' => $job->status,
                'progress_pct' => $progressPct,
                'errors' => $job->errors ?? [],
                'started_at' => $job->started_at ? $this->timestamp($job->started_at) : null,
                'estimated_remaining_sec' => $estimated_remaining_sec,
            ]
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        
        if (in_array($job->status, ['pending', 'processing'])) {
            $job->update(['status' => 'cancelled']);

            if ($job->batch_id) {
                Bus::findBatch($job->batch_id)?->cancel();
            }
        }

        return response()->json([
            'message' => 'Import cancelled successfully.'
        ]);
    }

    public function errors(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        
        $errors = $job->errors ?? [];
        $page = max(1, (int) request()->input('page', 1));
        $perPage = min(100, max(1, (int) request()->input('per_page', 10)));
        $offset = ($page - 1) * $perPage;
        
        $paginatedErrors = array_slice($errors, $offset, $perPage);

        return response()->json([
            'data' => $paginatedErrors,
            'meta' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => count($errors),
                'last_page' => count($errors) > 0 ? (int) ceil(count($errors) / $perPage) : 1,
            ]
        ]);
    }

    public function errorsCsv(int $id)
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        if (empty($job->errors)) {
            abort(404, 'No errors found');
        }
        if ($job->format !== 'csv') {
            abort(404, 'Error CSV is available only for CSV imports');
        }

        $errorMap = [];
        foreach ($job->errors as $err) {
            $errorMap[$err['row']] = $err['message'];
        }

        if (!Storage::disk('local')->exists($job->file_path)) {
            abort(404, 'Original file not found');
        }

        $filePath = Storage::disk('local')->path($job->file_path);

        $callback = function () use ($filePath, $errorMap) {
            $file = fopen($filePath, 'r');
            $output = fopen('php://output', 'w');
            
            $header = fgetcsv($file);
            if ($header) {
                $header[] = 'error';
                fputcsv($output, $header);
            }

            $rowNum = 1;
            while (($row = fgetcsv($file)) !== false) {
                $rowNum++;
                if (isset($errorMap[$rowNum])) {
                    $row[] = $errorMap[$rowNum];
                    fputcsv($output, $row);
                }
            }
            fclose($file);
            fclose($output);
        };

        return response()->stream($callback, 200, [
            "Content-Type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename={$job->filename}_errors.csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ]);
    }

    private function resolveVault(Request $request)
    {
        $user = auth()->user();
        $query = $user->vaults()->where('vaults.account_id', $user->account_id);

        if ($request->filled('vault_id')) {
            return $query->whereKey($request->input('vault_id'))->first();
        }

        return $query->orderBy('vaults.created_at')->first();
    }

    private function countCsvRows(string $path): int
    {
        $file = fopen($path, 'r');
        if (!$file) {
            return 0;
        }

        $header = fgetcsv($file);
        if (!$header) {
            fclose($file);
            return 0;
        }

        $rows = 0;
        while (fgetcsv($file) !== false) {
            $rows++;
        }

        fclose($file);

        return $rows;
    }

    private function countVcards(string $path): int
    {
        $file = fopen($path, 'r');
        if (!$file) {
            return 0;
        }

        $cards = 0;
        while (($line = fgets($file)) !== false) {
            if (strtoupper(trim($line)) === 'END:VCARD') {
                $cards++;
            }
        }

        fclose($file);

        return $cards;
    }

    private function detectFormat(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension === 'vcf' ? 'vcard' : 'csv';
    }

    private function progressPct(ImportJob $job): int
    {
        if ($job->total_rows <= 0) {
            return 0;
        }

        return min(100, (int) round(($job->processed_rows / $job->total_rows) * 100));
    }

    private function timestamp($date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
