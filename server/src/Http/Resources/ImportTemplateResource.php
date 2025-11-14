<?php

namespace Fleetbase\FleetOps\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ImportTemplate model
 */
class ImportTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'field_mappings' => $this->field_mappings,
            'validation_rules' => $this->validation_rules,
            'default_values' => $this->default_values,
            'transformations' => $this->transformations,
            'duplicate_handling' => $this->duplicate_handling,
            'duplicate_check_fields' => $this->duplicate_check_fields,
            'options' => [
                'auto_geocode' => $this->auto_geocode,
                'validate_addresses' => $this->validate_addresses,
                'calculate_quotes' => $this->calculate_quotes ?? false,
                'send_notifications' => $this->send_notifications ?? false
            ],
            'defaults' => [
                'status' => $this->default_status,
                'type' => $this->default_type,
                'priority' => $this->default_priority,
                'country' => $this->default_country
            ],
            'business_rules' => [
                'business_hours_start' => $this->business_hours_start,
                'business_hours_end' => $this->business_hours_end,
                'min_lead_time_hours' => $this->min_lead_time_hours,
                'max_order_value' => $this->max_order_value
            ],
            'tracking' => [
                'tracking_number_format' => $this->tracking_number_format,
                'tracking_prefix' => $this->tracking_prefix,
                'initial_status' => $this->initial_status,
                'initial_status_code' => $this->initial_status_code
            ],
            'usage_stats' => $this->when($this->sessions, function () {
                return [
                    'total_sessions' => $this->sessions->count(),
                    'successful_sessions' => $this->sessions->where('status', 'completed')->count(),
                    'last_used' => $this->sessions->latest()->first()?->created_at,
                    'total_orders_imported' => $this->sessions->sum('imported_rows')
                ];
            }),
            'created_by' => $this->when($this->createdBy, function () {
                return [
                    'id' => $this->createdBy->public_id,
                    'name' => $this->createdBy->name
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}