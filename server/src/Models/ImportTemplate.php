<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasApiModelBehavior;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model for import templates that define how to import data.
 */
class ImportTemplate extends Model
{
    use HasUuid, HasPublicId, HasApiModelBehavior, SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'import_templates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'name',
        'description',
        'import_type',
        'field_mappings',
        'validation_rules',
        'default_values',
        'duplicate_strategy',
        'duplicate_check_fields',
        'options',
        'meta',
        'is_active'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'field_mappings' => 'array',
        'validation_rules' => 'array',
        'default_values' => 'array',
        'duplicate_check_fields' => 'array',
        'options' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Get all import sessions that use this template.
     */
    public function sessions()
    {
        return $this->hasMany(ImportSession::class, 'template_uuid');
    }

    /**
     * Get all scheduled imports that use this template.
     */
    public function scheduledImports()
    {
        return $this->hasMany(ScheduledImport::class, 'template_uuid');
    }

    /**
     * Scope to get only active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by import type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('import_type', $type);
    }

    /**
     * Get the field mapping for a specific CSV column.
     *
     * @param string $csvColumn
     * @return string|null
     */
    public function getFieldMapping($csvColumn)
    {
        return $this->field_mappings[$csvColumn] ?? null;
    }

    /**
     * Get all mapped model fields.
     *
     * @return array
     */
    public function getMappedFields()
    {
        return array_values($this->field_mappings);
    }

    /**
     * Get the validation rule for a specific field.
     *
     * @param string $field
     * @return string|array|null
     */
    public function getValidationRule($field)
    {
        return $this->validation_rules[$field] ?? null;
    }

    /**
     * Get the default value for a specific field.
     *
     * @param string $field
     * @return mixed
     */
    public function getDefaultValue($field)
    {
        return $this->default_values[$field] ?? null;
    }

    /**
     * Check if a field should be checked for duplicates.
     *
     * @param string $field
     * @return bool
     */
    public function isDuplicateCheckField($field)
    {
        return in_array($field, $this->duplicate_check_fields ?? []);
    }

    /**
     * Get all duplicate check fields.
     *
     * @return array
     */
    public function getDuplicateCheckFields()
    {
        return $this->duplicate_check_fields ?? [];
    }

    /**
     * Check if the template should skip duplicates.
     *
     * @return bool
     */
    public function shouldSkipDuplicates()
    {
        return $this->duplicate_strategy === 'skip';
    }

    /**
     * Check if the template should update duplicates.
     *
     * @return bool
     */
    public function shouldUpdateDuplicates()
    {
        return $this->duplicate_strategy === 'update';
    }

    /**
     * Check if the template should create new records for duplicates.
     *
     * @return bool
     */
    public function shouldCreateNewDuplicates()
    {
        return $this->duplicate_strategy === 'create_new';
    }

    /**
     * Check if the template should merge duplicates.
     *
     * @return bool
     */
    public function shouldMergeDuplicates()
    {
        return $this->duplicate_strategy === 'merge';
    }

    /**
     * Map CSV data to model fields based on the template.
     *
     * @param array $csvData
     * @return array
     */
    public function mapData($csvData)
    {
        $mappedData = [];
        
        foreach ($this->field_mappings as $csvColumn => $modelField) {
            if (isset($csvData[$csvColumn])) {
                $mappedData[$modelField] = $csvData[$csvColumn];
            }
        }
        
        // Add default values for missing fields
        foreach ($this->default_values ?? [] as $field => $value) {
            if (!isset($mappedData[$field])) {
                $mappedData[$field] = $value;
            }
        }
        
        return $mappedData;
    }

    /**
     * Get validation rules for the mapped data.
     *
     * @return array
     */
    public function getValidationRules()
    {
        return $this->validation_rules ?? [];
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
     * Get a summary of the template.
     *
     * @return array
     */
    public function getSummary()
    {
        return [
            'template_id' => $this->public_id,
            'name' => $this->name,
            'import_type' => $this->import_type,
            'field_count' => count($this->field_mappings),
            'duplicate_strategy' => $this->duplicate_strategy,
            'is_active' => $this->is_active,
            'sessions_count' => $this->sessions()->count(),
            'scheduled_imports_count' => $this->scheduledImports()->count()
        ];
    }
}