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
     * Get the validation rules that apply to the request
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,xlsx,xls,json',
                'max:10240' // 10MB max
            ],
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