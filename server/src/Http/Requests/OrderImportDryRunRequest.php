<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for order import dry run execution
 */
class OrderImportDryRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Prepare the data for validation
     * Convert string 'true'/'false' from form-data to actual booleans
     */
    protected function prepareForValidation(): void
    {
        $booleanFields = ['stop_on_error'];
        
        foreach ($booleanFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return [
            'template_id' => 'nullable|string',
            'mappings' => 'required_without:template_id|array',
            'mappings.*' => 'string', // Each mapping should be a string
            'validation_rules' => 'nullable|array',
            'default_values' => 'nullable|array',
            'duplicate_handling' => 'nullable|in:allow,warn,reject',
            'duplicate_check_fields' => 'nullable|array',
            'stop_on_error' => 'nullable|boolean',
            
            // Backward compatibility: single date format for all date fields
            'date_format' => 'nullable|string',
            
            // Field-specific date formats (new API design)
            'scheduled_at_format' => 'nullable|string',
            'created_at_format' => 'nullable|string',
            'updated_at_format' => 'nullable|string',
            'delivery_date_format' => 'nullable|string',
            'pickup_date_format' => 'nullable|string',
            'expected_at_format' => 'nullable|string',
            'completed_at_format' => 'nullable|string'
        ];
    }

    /**
     * Get custom messages for validator errors
     */
    public function messages(): array
    {
        return [
            'session_id.required' => 'Session ID is required',
            'session_id.string' => 'Session ID must be a valid string',
            'mappings.required_without' => 'Field mappings are required when no template is specified',
            'mappings.array' => 'Field mappings must be an array',
            'duplicate_handling.in' => 'Duplicate handling must be one of: allow, warn, reject',
            'duplicate_check_fields.array' => 'Duplicate check fields must be an array'
        ];
    }

    /**
     * Get custom attributes for validator errors
     */
    public function attributes(): array
    {
        return [
            'session_id' => 'session',
            'template_id' => 'template',
            'mappings' => 'field mappings',
            'validation_rules' => 'validation rules',
            'default_values' => 'default values',
            'duplicate_handling' => 'duplicate handling strategy',
            'duplicate_check_fields' => 'duplicate check fields',
            'stop_on_error' => 'stop on error option',
            'date_format' => 'date format',
            'scheduled_at_format' => 'scheduled at date format',
            'created_at_format' => 'created at date format',
            'updated_at_format' => 'updated at date format',
            'delivery_date_format' => 'delivery date format',
            'pickup_date_format' => 'pickup date format',
            'expected_at_format' => 'expected at date format',
            'completed_at_format' => 'completed at date format'
        ];
    }
}