<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Illuminate\Database\Eloquent\SoftDeletes;
use Cron\CronExpression;

/**
 * Model for scheduled imports that run on a recurring basis.
 */
class ScheduledImport extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'scheduled_imports';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'template_uuid',
        'name',
        'cron_expression',
        'file_source_type',
        'file_source_config',
        'status',
        'last_run_at',
        'next_run_at',
        'last_run_result',
        'run_count',
        'options',
        'meta'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'file_source_config' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_run_result' => 'array',
        'run_count' => 'integer',
        'options' => 'array',
        'meta' => 'array'
    ];

    /**
     * Get the import template associated with this scheduled import.
     */
    public function template()
    {
        return $this->belongsTo(ImportTemplate::class, 'template_uuid');
    }

    /**
     * Get all import sessions created by this scheduled import.
     */
    public function sessions()
    {
        return $this->hasMany(ImportSession::class, 'scheduled_import_uuid');
    }

    /**
     * Scope to get only active scheduled imports.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get scheduled imports that are due to run.
     */
    public function scopeDue($query)
    {
        return $query->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('next_run_at')
                          ->orWhere('next_run_at', '<=', now());
                    });
    }

    /**
     * Check if the scheduled import is active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Check if the scheduled import is due to run.
     *
     * @return bool
     */
    public function isDue()
    {
        return $this->isActive() && 
               (!$this->next_run_at || $this->next_run_at <= now());
    }

    /**
     * Calculate the next run time based on the cron expression.
     *
     * @return \DateTime|null
     */
    public function calculateNextRunTime()
    {
        if (!$this->cron_expression) {
            return null;
        }

        try {
            $cron = new CronExpression($this->cron_expression);
            return $cron->getNextRunDate();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update the next run time.
     */
    public function updateNextRunTime()
    {
        $nextRun = $this->calculateNextRunTime();
        
        if ($nextRun) {
            $this->update(['next_run_at' => $nextRun]);
        }
    }

    /**
     * Mark the scheduled import as completed for this run.
     *
     * @param array $result
     */
    public function markRunCompleted($result = [])
    {
        $this->update([
            'last_run_at' => now(),
            'last_run_result' => $result,
            'run_count' => $this->run_count + 1
        ]);
        
        $this->updateNextRunTime();
    }

    /**
     * Mark the scheduled import as failed.
     *
     * @param array $error
     */
    public function markRunFailed($error = [])
    {
        $this->update([
            'status' => 'failed',
            'last_run_at' => now(),
            'last_run_result' => array_merge(['success' => false], $error),
            'run_count' => $this->run_count + 1
        ]);
    }

    /**
     * Pause the scheduled import.
     */
    public function pause()
    {
        $this->update([
            'status' => 'paused',
            'next_run_at' => null
        ]);
    }

    /**
     * Resume the scheduled import.
     */
    public function resume()
    {
        $this->update(['status' => 'active']);
        $this->updateNextRunTime();
    }

    /**
     * Disable the scheduled import.
     */
    public function disable()
    {
        $this->update([
            'status' => 'disabled',
            'next_run_at' => null
        ]);
    }

    /**
     * Get the file source configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getFileSourceConfig($key, $default = null)
    {
        return $this->file_source_config[$key] ?? $default;
    }

    /**
     * Set a file source configuration value.
     *
     * @param string $key
     * @param mixed $value
     */
    public function setFileSourceConfig($key, $value)
    {
        $config = $this->file_source_config ?? [];
        $config[$key] = $value;
        $this->file_source_config = $config;
    }

    /**
     * Get an option value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOption($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option value.
     *
     * @param string $key
     * @param mixed $value
     */
    public function setOption($key, $value)
    {
        $options = $this->options ?? [];
        $options[$key] = $value;
        $this->options = $options;
    }

    /**
     * Check if the last run was successful.
     *
     * @return bool
     */
    public function lastRunWasSuccessful()
    {
        return isset($this->last_run_result['success']) && 
               $this->last_run_result['success'] === true;
    }

    /**
     * Get the cron expression in human readable format.
     *
     * @return string
     */
    public function getHumanReadableCron()
    {
        if (!$this->cron_expression) {
            return 'Not scheduled';
        }

        try {
            $cron = new CronExpression($this->cron_expression);
            return $cron->getExpression();
        } catch (\Exception $e) {
            return 'Invalid cron expression';
        }
    }

    /**
     * Get a summary of the scheduled import.
     *
     * @return array
     */
    public function getSummary()
    {
        return [
            'scheduled_import_id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            'cron_expression' => $this->cron_expression,
            'human_readable_cron' => $this->getHumanReadableCron(),
            'file_source_type' => $this->file_source_type,
            'run_count' => $this->run_count,
            'last_run_at' => $this->last_run_at,
            'next_run_at' => $this->next_run_at,
            'last_run_successful' => $this->lastRunWasSuccessful(),
            'template_name' => $this->template->name ?? null
        ];
    }
}