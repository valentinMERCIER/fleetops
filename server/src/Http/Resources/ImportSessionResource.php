<?php

namespace Fleetbase\FleetOps\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ImportSession model
 */
class ImportSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'file_size_human' => $this->formatFileSize($this->file_size),
            'status' => $this->status,
            'template' => $this->when($this->template, function () {
                return [
                    'id' => $this->template->public_id,
                    'name' => $this->template->name,
                    'description' => $this->template->description
                ];
            }),
            'stats' => [
                'total_rows' => $this->total_rows,
                'processed_rows' => $this->processed_rows,
                'imported_rows' => $this->imported_rows,
                'failed_rows' => $this->failed_rows,
                'valid_rows' => $this->valid_rows,
                'warning_rows' => $this->warning_rows,
                'duplicate_rows' => $this->duplicate_rows,
                'importable_rows' => $this->importable_rows
            ],
            'progress' => $this->when($this->total_rows > 0, function () {
                return [
                    'percentage' => round(($this->processed_rows / $this->total_rows) * 100, 2),
                    'imported_percentage' => round(($this->imported_rows / $this->total_rows) * 100, 2),
                    'success_rate' => $this->imported_rows > 0 
                        ? round(($this->imported_rows / ($this->imported_rows + $this->failed_rows)) * 100, 2)
                        : 0
                ];
            }),
            'created_by' => $this->when($this->createdBy, function () {
                return [
                    'id' => $this->createdBy->public_id,
                    'name' => $this->createdBy->name
                ];
            }),
            'timeline' => [
                'created_at' => $this->created_at,
                'parsed_at' => $this->parsed_at,
                'dry_run_started_at' => $this->dry_run_started_at,
                'dry_run_completed_at' => $this->dry_run_completed_at,
                'import_started_at' => $this->import_started_at,
                'completed_at' => $this->completed_at,
                'cancelled_at' => $this->cancelled_at,
                'rolled_back_at' => $this->rolled_back_at
            ],
            'can_execute' => $this->canExecute(),
            'can_rollback' => $this->canRollback(),
            'error_message' => $this->when($this->status === 'failed', $this->error_message)
        ];
    }
    
    /**
     * Format file size in human readable format
     */
    protected function formatFileSize($bytes): string
    {
        if ($bytes === 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor(log($bytes) / log(1024));
        
        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Check if session can execute import
     */
    protected function canExecute(): bool
    {
        return in_array($this->status, ['ready', 'dry_run_completed', 'processed']) 
               && $this->importable_rows > 0;
    }
    
    /**
     * Check if session can be rolled back
     */
    protected function canRollback(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_errors']) 
               && $this->imported_rows > 0;
    }
}