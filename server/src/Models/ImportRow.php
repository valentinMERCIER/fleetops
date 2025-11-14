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
        'company_uuid',
        'session_uuid',
        'row_number',
        'line_number',
        'original_data',
        'mapped_data',
        'normalized_data',
        'status',
        'processing_status',
        'processing_message',
        'resolution_status',
        'resolution_method',
        'error_type',
        'severity',
        'errors',
        'warnings',
        'error_severity',
        'validation_errors',
        'validation_warnings',
        'suggestions',
        'order_uuid',
        'created_order_id',
        'is_resolvable',
        'is_duplicate',
        'duplicate_order_id',
        'processed_at',
        'suggested_fixes',
        'meta'
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
        'validation_errors' => 'array',
        'validation_warnings' => 'array',
        'suggestions' => 'array',
        'suggested_fixes' => 'array',
        'meta' => 'array',
        'is_resolvable' => 'boolean',
        'is_duplicate' => 'boolean',
        'processed_at' => 'datetime'
    ];

    /**
     * Processing status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_VALID = 'valid';
    const STATUS_ERROR = 'error';
    const STATUS_WARNING = 'warning';
    const STATUS_DUPLICATE = 'duplicate';
    const STATUS_SKIPPED = 'skipped';
    const STATUS_IMPORTED = 'imported';
    const STATUS_FAILED = 'failed';

    /**
     * Severity levels
     */
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Resolution status
     */
    const RESOLUTION_PENDING = 'pending';
    const RESOLUTION_AUTO_FIXED = 'auto_fixed';
    const RESOLUTION_MANUAL_FIXED = 'manual_fixed';
    const RESOLUTION_IGNORED = 'ignored';
    const RESOLUTION_CANNOT_FIX = 'cannot_fix';

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
     * Scopes for filtering rows by status
     */
    public function scopeValid($query)
    {
        return $query->where('processing_status', self::STATUS_VALID);
    }

    public function scopeWithErrors($query)
    {
        return $query->where('processing_status', self::STATUS_ERROR);
    }

    public function scopeWithWarnings($query)
    {
        return $query->where('processing_status', self::STATUS_WARNING);
    }

    public function scopeDuplicates($query)
    {
        return $query->where('processing_status', self::STATUS_DUPLICATE);
    }

    public function scopeResolvable($query)
    {
        return $query->where('is_resolvable', true);
    }

    public function scopeImportable($query)
    {
        return $query->whereIn('processing_status', [
            self::STATUS_VALID,
            self::STATUS_WARNING
        ]);
    }

    public function scopeNeedsAttention($query)
    {
        return $query->where('processing_status', self::STATUS_ERROR)
                    ->where('resolution_status', self::RESOLUTION_PENDING);
    }

    /**
     * Scope to get only problematic rows (with errors or warnings).
     */
    public function scopeProblematic($query)
    {
        return $query->whereIn('processing_status', [
            self::STATUS_ERROR,
            self::STATUS_FAILED,
            self::STATUS_DUPLICATE
        ])->orWhereNotNull('validation_warnings');
    }

    /**
     * Scope to filter by session.
     */
    public function scopeBySession($query, $sessionUuid)
    {
        return $query->where('session_uuid', $sessionUuid);
    }

    /**
     * Helper methods for dry run processing
     */
    public function canImport(): bool
    {
        return in_array($this->processing_status, [
            self::STATUS_VALID,
            self::STATUS_WARNING
        ]);
    }

    public function hasErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->validation_warnings);
    }

    public function isResolved(): bool
    {
        return in_array($this->resolution_status, [
            self::RESOLUTION_AUTO_FIXED,
            self::RESOLUTION_MANUAL_FIXED,
            self::RESOLUTION_IGNORED
        ]);
    }

    public function needsAttention(): bool
    {
        return $this->processing_status === self::STATUS_ERROR && 
               $this->resolution_status === self::RESOLUTION_PENDING;
    }

    public function isProblematic(): bool
    {
        return in_array($this->processing_status, [
            self::STATUS_ERROR,
            self::STATUS_FAILED,
            self::STATUS_DUPLICATE
        ]) || $this->hasWarnings();
    }

    public function canRetry(): bool
    {
        return in_array($this->processing_status, [
            self::STATUS_ERROR,
            self::STATUS_FAILED
        ]) && $this->is_resolvable;
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