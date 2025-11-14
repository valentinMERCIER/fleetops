<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for fixing import row issues
 */
class FixImportRowRequest extends FormRequest
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
            'corrections' => 'required|array',
            'corrections.customer_name' => 'sometimes|string|max:255',
            'corrections.customer_phone' => 'sometimes|string|max:50',
            'corrections.customer_email' => 'sometimes|email|max:255',
            'corrections.pickup_address' => 'sometimes|string|max:500',
            'corrections.dropoff_address' => 'sometimes|string|max:500',
            'corrections.pickup_name' => 'sometimes|string|max:255',
            'corrections.dropoff_name' => 'sometimes|string|max:255',
            'corrections.reference' => 'sometimes|string|max:100',
            'corrections.notes' => 'sometimes|string|max:1000',
            'corrections.scheduled_at' => 'sometimes|date',
            'corrections.quantity' => 'sometimes|integer|min:1',
            'corrections.weight' => 'sometimes|numeric|min:0',
            'corrections.type' => 'sometimes|string|in:delivery,pickup,transport',
            'corrections.priority' => 'sometimes|string|in:low,normal,high,urgent'
        ];
    }

    /**
     * Get custom messages for validator errors
     */
    public function messages(): array
    {
        return [
            'corrections.required' => 'Corrections are required',
            'corrections.array' => 'Corrections must be an object',
            'corrections.customer_name.string' => 'Customer name must be text',
            'corrections.customer_name.max' => 'Customer name cannot exceed 255 characters',
            'corrections.customer_phone.max' => 'Phone number cannot exceed 50 characters',
            'corrections.customer_email.email' => 'Email must be a valid email address',
            'corrections.pickup_address.max' => 'Pickup address cannot exceed 500 characters',
            'corrections.dropoff_address.max' => 'Dropoff address cannot exceed 500 characters',
            'corrections.reference.max' => 'Reference cannot exceed 100 characters',
            'corrections.notes.max' => 'Notes cannot exceed 1000 characters',
            'corrections.scheduled_at.date' => 'Scheduled time must be a valid date',
            'corrections.quantity.integer' => 'Quantity must be a whole number',
            'corrections.quantity.min' => 'Quantity must be at least 1',
            'corrections.weight.numeric' => 'Weight must be a number',
            'corrections.weight.min' => 'Weight cannot be negative',
            'corrections.type.in' => 'Type must be one of: delivery, pickup, transport',
            'corrections.priority.in' => 'Priority must be one of: low, normal, high, urgent'
        ];
    }

    /**
     * Get custom attributes for validator errors
     */
    public function attributes(): array
    {
        return [
            'corrections' => 'corrections',
            'corrections.customer_name' => 'customer name',
            'corrections.customer_phone' => 'customer phone',
            'corrections.customer_email' => 'customer email',
            'corrections.pickup_address' => 'pickup address',
            'corrections.dropoff_address' => 'dropoff address',
            'corrections.reference' => 'reference number',
            'corrections.notes' => 'notes',
            'corrections.scheduled_at' => 'scheduled time',
            'corrections.quantity' => 'quantity',
            'corrections.weight' => 'weight',
            'corrections.type' => 'order type',
            'corrections.priority' => 'priority'
        ];
    }
}