<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory;

    protected $table = 'import_jobs';

    protected $fillable = [
        'account_id',
        'user_id',
        'filename',
        'file_path',
        'file_hash',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'status',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'failed_rows' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
