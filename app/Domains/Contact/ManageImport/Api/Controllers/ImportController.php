<?php

namespace App\Domains\Contact\ManageImport\Api\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $imports = ImportJob::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->input('per_page', 10));

        $data = $imports->map(function ($job) {
            $progressPct = $job->total_rows > 0 ? round(($job->processed_rows / $job->total_rows) * 100) : 0;
            return [
                'id' => $job->id,
                'filename' => $job->filename,
                'total_rows' => $job->total_rows,
                'processed_rows' => $job->processed_rows,
                'failed_rows' => $job->failed_rows,
                'status' => $job->status,
                'progress_pct' => $progressPct,
                'created_at' => $job->created_at->toIso8601String(),
            ];
        });

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
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $fileHash = md5_file($file->getRealPath());
        
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

        $lineCount = 0;
        $handle = fopen($file->getRealPath(), 'r');
        while (!feof($handle)) {
            $buffer = fread($handle, 8192);
            $lineCount += substr_count($buffer, "\n");
        }
        fclose($handle);

        $totalRows = max(0, $lineCount - 1);

        $importJob = ImportJob::create([
            'account_id' => auth()->user()->account_id,
            'user_id' => auth()->id(),
            'filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_hash' => $fileHash,
            'total_rows' => $totalRows,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        // Dispatch background job (we will create this job class next)
        \App\Domains\Contact\ManageImport\Jobs\StartImportJob::dispatch($importJob->id);

        return response()->json([
            'data' => [
                'id' => $importJob->id,
                'filename' => $importJob->filename,
                'total_rows' => $importJob->total_rows,
                'processed_rows' => $importJob->processed_rows,
                'failed_rows' => $importJob->failed_rows,
                'status' => $importJob->status,
                'created_at' => $importJob->created_at->toIso8601String(),
            ]
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        $progressPct = $job->total_rows > 0 ? round(($job->processed_rows / $job->total_rows) * 100) : 0;
        
        $estimated_remaining_sec = null;
        if ($job->status === 'processing' && $job->started_at && $job->processed_rows > 0) {
            $elapsed = now()->diffInSeconds($job->started_at);
            $rate = $job->processed_rows / max($elapsed, 1);
            $remaining_rows = $job->total_rows - $job->processed_rows;
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
                'started_at' => $job->started_at ? $job->started_at->toIso8601String() : null,
                'estimated_remaining_sec' => $estimated_remaining_sec,
            ]
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        
        if (in_array($job->status, ['pending', 'processing'])) {
            $job->update(['status' => 'cancelled']);
        }

        return response()->json([
            'message' => 'Import cancelled successfully.'
        ]);
    }

    public function errors(int $id): JsonResponse
    {
        $job = ImportJob::where('user_id', auth()->id())->findOrFail($id);
        
        $errors = $job->errors ?? [];
        $page = request()->input('page', 1);
        $perPage = request()->input('per_page', 10);
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

        $errorMap = [];
        foreach ($job->errors as $err) {
            $errorMap[$err['row']] = $err['message'];
        }

        $filePath = storage_path('app/' . $job->file_path);
        if (!file_exists($filePath)) {
            abort(404, 'Original file not found');
        }

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
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename={$job->filename}_errors.csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ]);
    }
}
