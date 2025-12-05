<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for order import file upload
 */
class OrderImportUploadRequest extends FormRequest
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
    /**
     * Prepare the data for validation
     * Convert string 'true'/'false' from form-data to actual booleans
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values to actual booleans for form-data requests
        if ($this->has('auto_detect_mappings')) {
            $value = $this->input('auto_detect_mappings');
            
            // Convert string 'true'/'false' or '1'/'0' to boolean
            if (is_string($value)) {
                $this->merge([
                    'auto_detect_mappings' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
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
            'file' => [
                'required',
                'file',
                'mimes:csv,xlsx,xls,json,txt',
                'max:10240' // 10MB max
            ],
            'name' => 'nullable|string|max:255',
            'template_id' => 'nullable|string',
            'auto_detect_mappings' => 'nullable|boolean'
        ];
    }

    /**
     * Get custom messages for validator errors
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please upload a file',
            'file.file' => 'Invalid file upload',
            'file.mimes' => 'File must be CSV, Excel (xlsx/xls), or JSON format',
            'file.max' => 'File size must not exceed 10MB',
            'template_id.string' => 'Template ID must be a valid string'
        ];
    }

    /**
     * Get custom attributes for validator errors
     */
    public function attributes(): array
    {
        return [
            'file' => 'import file',
            'template_id' => 'template',
            'auto_detect_mappings' => 'auto-detect option'
        ];
    }
}