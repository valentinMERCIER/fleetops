<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;

/**
 * Model for tracking individual rows in import sessions.
 */
class ImportRow extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'import_rows';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'session_uuid',
        'row_number',
        'line_number',
        'original_data',
        'mapped_data',
        'normalized_data',
        'status',
        'errors',
        'warnings',
        'error_severity',
        'order_uuid',
        'is_resolvable',
        'suggested_fixes'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'original_data' => 'array',
        'mapped_data' => 'array',
        'normalized_data' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'suggested_fixes' => 'array',
        'is_resolvable' => 'boolean'
    ];

    /**
     * Get the import session that owns this row.
     */
    public function session()
    {
        return $this->belongsTo(ImportSession::class, 'session_uuid');
    }

    /**
     * Get the order that was created from this row (if any).
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_uuid');
    }

    /**
     * Scope to get only problematic rows (with errors or warnings).
     */
    public function scopeProblematic($query)
    {
        return $query->whereIn('status', ['mapping_failed', 'validation_failed', 'duplicate'])
                    ->orWhereNotNull('warnings');
    }

    /**
     * Scope to get only resolvable rows.
     */
    public function scopeResolvable($query)
    {
        return $query->where('is_resolvable', true);
    }

    /**
     * Scope to filter by session.
     */
    public function scopeBySession($query, $sessionUuid)
    {
        return $query->where('session_uuid', $sessionUuid);
    }

    /**
     * Check if this row has errors.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * Check if this row has warnings.
     *
     * @return bool
     */
    public function hasWarnings()
    {
        return !empty($this->warnings);
    }

    /**
     * Check if this row is problematic (has errors or warnings).
     *
     * @return bool
     */
    public function isProblematic()
    {
        return in_array($this->status, ['mapping_failed', 'validation_failed', 'duplicate']) 
               || $this->hasWarnings();
    }

    /**
     * Check if this row can be retried.
     *
     * @return bool
     */
    public function canRetry()
    {
        return in_array($this->status, ['validation_failed', 'mapping_failed', 'failed']) 
               && $this->is_resolvable;
    }

    /**
     * Get the count of errors for this row.
     *
     * @return int
     */
    public function getErrorCount()
    {
        return count($this->errors ?? []);
    }

    /**
     * Get the count of warnings for this row.
     *
     * @return int
     */
    public function getWarningCount()
    {
        return count($this->warnings ?? []);
    }

    /**
     * Add an error to this row.
     *
     * @param string $field
     * @param string $message
     * @param string $code
     * @param string $severity
     */
    public function addError($field, $message, $code = 'validation_error', $severity = 'error')
    {
        $errors = $this->errors ?? [];
        $errors[] = [
            'field' => $field,
            'message' => $message,
            'code' => $code,
            'severity' => $severity
        ];
        
        $this->errors = $errors;
        
        // Update error severity if this is more critical
        if (!$this->error_severity || $this->isMoreCritical($severity, $this->error_severity)) {
            $this->error_severity = $severity;
        }
    }

    /**
     * Add a warning to this row.
     *
     * @param string $field
     * @param string $message
     * @param string $code
     */
    public function addWarning($field, $message, $code = 'validation_warning')
    {
        $warnings = $this->warnings ?? [];
        $warnings[] = [
            'field' => $field,
            'message' => $message,
            'code' => $code,
            'severity' => 'warning'
        ];
        
        $this->warnings = $warnings;
    }

    /**
     * Add a suggested fix for this row.
     *
     * @param string $field
     * @param string $suggestion
     * @param mixed $suggestedValue
     */
    public function addSuggestedFix($field, $suggestion, $suggestedValue = null)
    {
        $fixes = $this->suggested_fixes ?? [];
        $fixes[] = [
            'field' => $field,
            'suggestion' => $suggestion,
            'suggested_value' => $suggestedValue
        ];
        
        $this->suggested_fixes = $fixes;
    }

    /**
     * Check if one severity is more critical than another.
     *
     * @param string $severity1
     * @param string $severity2
     * @return bool
     */
    private function isMoreCritical($severity1, $severity2)
    {
        $levels = ['info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
        
        return ($levels[$severity1] ?? 0) > ($levels[$severity2] ?? 0);
    }
}