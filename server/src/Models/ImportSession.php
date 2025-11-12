<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Models\File;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model for tracking import sessions.
 */
class ImportSession extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'import_sessions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'template_uuid',
        'file_uuid',
        'name',
        'status',
        'is_dry_run',
        'dry_run_summary',
        'estimated_success_count',
        'estimated_warning_count',
        'estimated_error_count',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'errors',
        'meta',
        'started_at',
        'completed_at'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_dry_run' => 'boolean',
        'dry_run_summary' => 'array',
        'estimated_success_count' => 'integer',
        'estimated_warning_count' => 'integer',
        'estimated_error_count' => 'integer',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'failed_rows' => 'integer',
        'errors' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the import template associated with this session.
     */
    public function template()
    {
        return $this->belongsTo(ImportTemplate::class, 'template_uuid');
    }

    /**
     * Get the file associated with this session.
     */
    public function file()
    {
        return $this->belongsTo(File::class, 'file_uuid');
    }

    /**
     * Get all rows in this import session.
     */
    public function rows()
    {
        return $this->hasMany(ImportRow::class, 'session_uuid');
    }

    /**
     * Get only problematic rows (with errors or warnings).
     */
    public function problematicRows()
    {
        return $this->hasMany(ImportRow::class, 'session_uuid')->problematic();
    }

    /**
     * Get only successful rows.
     */
    public function successfulRows()
    {
        return $this->hasMany(ImportRow::class, 'session_uuid')->where('status', 'processed');
    }

    /**
     * Get only failed rows.
     */
    public function failedRows()
    {
        return $this->hasMany(ImportRow::class, 'session_uuid')
                    ->whereIn('status', ['mapping_failed', 'validation_failed', 'failed']);
    }

    /**
     * Get only rows with warnings.
     */
    public function rowsWithWarnings()
    {
        return $this->hasMany(ImportRow::class, 'session_uuid')->whereNotNull('warnings');
    }

    /**
     * Check if this is a dry run session.
     *
     * @return bool
     */
    public function isDryRun()
    {
        return $this->is_dry_run;
    }

    /**
     * Get the success rate as a percentage.
     *
     * @return float
     */
    public function getSuccessRate()
    {
        if ($this->total_rows === 0) {
            return 0;
        }
        
        return (($this->total_rows - $this->failed_rows) / $this->total_rows) * 100;
    }

    /**
     * Get the estimated success rate for dry runs.
     *
     * @return float
     */
    public function getEstimatedSuccessRate()
    {
        $totalEstimated = $this->estimated_success_count + $this->estimated_warning_count + $this->estimated_error_count;
        
        if ($totalEstimated === 0) {
            return 0;
        }
        
        return ($this->estimated_success_count / $totalEstimated) * 100;
    }

    /**
     * Check if the session is completed.
     *
     * @return bool
     */
    public function isCompleted()
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    /**
     * Check if the session is in progress.
     *
     * @return bool
     */
    public function isInProgress()
    {
        return in_array($this->status, ['validating', 'mapping', 'processing']);
    }

    /**
     * Check if the session failed.
     *
     * @return bool
     */
    public function hasFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Mark the session as started.
     */
    public function markAsStarted()
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now()
        ]);
    }

    /**
     * Mark the session as completed.
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);
    }

    /**
     * Mark the session as failed.
     *
     * @param array $errors
     */
    public function markAsFailed($errors = [])
    {
        $this->update([
            'status' => 'failed',
            'errors' => $errors,
            'completed_at' => now()
        ]);
    }

    /**
     * Update the processed rows count.
     *
     * @param int $count
     */
    public function updateProcessedCount($count = null)
    {
        if ($count === null) {
            $count = $this->rows()->whereIn('status', ['processed', 'failed', 'skipped'])->count();
        }
        
        $this->update(['processed_rows' => $count]);
    }

    /**
     * Update the failed rows count.
     *
     * @param int $count
     */
    public function updateFailedCount($count = null)
    {
        if ($count === null) {
            $count = $this->rows()->whereIn('status', ['mapping_failed', 'validation_failed', 'failed'])->count();
        }
        
        $this->update(['failed_rows' => $count]);
    }

    /**
     * Get duration of the import session in seconds.
     *
     * @return int|null
     */
    public function getDurationInSeconds()
    {
        if (!$this->started_at) {
            return null;
        }
        
        $endTime = $this->completed_at ?? now();
        
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Get a summary of the import session.
     *
     * @return array
     */
    public function getSummary()
    {
        return [
            'session_id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            'is_dry_run' => $this->is_dry_run,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'failed_rows' => $this->failed_rows,
            'success_rate' => $this->getSuccessRate(),
            'duration_seconds' => $this->getDurationInSeconds(),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at
        ];
    }
}