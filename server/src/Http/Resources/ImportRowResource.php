<?php

namespace Fleetbase\FleetOps\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ImportRow model
 */
class ImportRowResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'line_number' => $this->line_number,
            'status' => $this->processing_status,
            'severity' => $this->severity,
            'message' => $this->processing_message,
            'can_import' => $this->canImport(),
            'is_duplicate' => $this->is_duplicate,
            'is_resolvable' => $this->is_resolvable,
            'needs_attention' => $this->needsAttention(),
            'resolution_status' => $this->resolution_status,
            'resolution_method' => $this->resolution_method,
            'data' => [
                'original' => $this->original_data,
                'mapped' => $this->mapped_data,
                'normalized' => $this->normalized_data
            ],
            'validation' => [
                'errors' => $this->validation_errors ?? [],
                'warnings' => $this->validation_warnings ?? [],
                'suggestions' => $this->suggestions ?? [],
                'error_count' => count($this->validation_errors ?? []),
                'warning_count' => count($this->validation_warnings ?? [])
            ],
            'order' => $this->when($this->created_order_id, function () {
                return [
                    'id' => $this->created_order_id,
                    'uuid' => $this->order_uuid,
                    'reference' => $this->normalized_data['reference'] ?? null
                ];
            }),
            'duplicate_info' => $this->when($this->is_duplicate, function () {
                return [
                    'duplicate_order_id' => $this->duplicate_order_id,
                    'message' => 'Matches existing order: ' . $this->duplicate_order_id
                ];
            }),
            'preview' => $this->when(
                $this->canImport() && isset($this->meta['preview']),
                $this->meta['preview']
            ),
            'estimates' => $this->when(
                $this->canImport() && isset($this->meta['estimated_cost']),
                function () {
                    return [
                        'cost' => $this->meta['estimated_cost'],
                        'duration' => $this->meta['estimated_duration']
                    ];
                }
            ),
            'processed_at' => $this->processed_at
        ];
    }
}