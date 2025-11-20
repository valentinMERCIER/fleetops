<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for order import execution
 */
class OrderImportExecuteRequest extends FormRequest
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
        $booleanFields = ['stop_on_error', 'include_orders', 'notify_on_completion'];
        
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
            'stop_on_error' => 'nullable|boolean',
            'include_orders' => 'nullable|boolean',
            'notify_on_completion' => 'nullable|boolean',
            'notification_emails' => 'nullable|array',
            'notification_emails.*' => 'email',
            'chunk_size' => 'nullable|integer|min:1|max:100'
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
            'notification_emails.array' => 'Notification emails must be an array',
            'notification_emails.*.email' => 'Each notification email must be a valid email address',
            'chunk_size.integer' => 'Chunk size must be a number',
            'chunk_size.min' => 'Chunk size must be at least 1',
            'chunk_size.max' => 'Chunk size cannot exceed 100'
        ];
    }

    /**
     * Get custom attributes for validator errors
     */
    public function attributes(): array
    {
        return [
            'session_id' => 'session',
            'stop_on_error' => 'stop on error option',
            'include_orders' => 'include orders option',
            'notify_on_completion' => 'notification option',
            'notification_emails' => 'notification emails',
            'chunk_size' => 'chunk size'
        ];
    }
}