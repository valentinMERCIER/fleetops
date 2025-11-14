<?php

namespace Fleetbase\FleetOps\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Fleetbase\FleetOps\Services\OrderImportService;
use Fleetbase\FleetOps\Models\ImportSession;
use Fleetbase\FleetOps\Models\ImportTemplate;

/**
 * Background job for processing import operations
 * 
 * Handles long-running import operations in the background:
 * - Large file parsing
 * - Batch dry run processing  
 * - Batch order creation
 * - Progress tracking and notifications
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ImportSession $session;
    protected ?ImportTemplate $template;
    protected string $action;
    protected array $options;
    
    /**
     * Job configuration
     */
    public $timeout = 3600; // 1 hour timeout
    public $tries = 3; // Retry up to 3 times
    public $maxExceptions = 3;
    public $backoff = [60, 300, 900]; // Backoff intervals: 1min, 5min, 15min

    /**
     * Create a new job instance
     * 
     * @param ImportSession $session Import session to process
     * @param ImportTemplate|null $template Import template to use
     * @param string $action Action to perform (dry_run, import)
     * @param array $options Processing options
     */
    public function __construct(
        ImportSession $session, 
        ?ImportTemplate $template, 
        string $action = 'import',
        array $options = []
    ) {
        $this->session = $session;
        $this->template = $template;
        $this->action = $action;
        $this->options = $options;
        
        // Set queue based on file size
        if ($session->file_size > 1048576) { // 1MB
            $this->onQueue('large-imports');
        } else {
            $this->onQueue('imports');
        }
    }

    /**
     * Execute the job
     * 
     * @param OrderImportService $importService
     */
    public function handle(OrderImportService $importService): void
    {
        Log::info("Processing import job", [
            'session_id' => $this->session->public_id,
            'action' => $this->action,
            'file_size' => $this->session->file_size
        ]);
        
        try {
            if ($this->action === 'dry_run') {
                $this->processDryRun($importService);
            } else {
                $this->processImport($importService);
            }
            
        } catch (\Exception $e) {
            Log::error("Import job failed", [
                'session_id' => $this->session->public_id,
                'action' => $this->action,
                'error' => $e->getMessage()
            ]);
            
            throw $e; // Re-throw to trigger job failure
        }
    }

    /**
     * Process dry run in background
     * 
     * @param OrderImportService $importService
     */
    protected function processDryRun(OrderImportService $importService): void
    {
        // Update session status
        $this->session->update([
            'status' => 'processing_dry_run',
            'dry_run_started_at' => now()
        ]);
        
        // Get parsed data from storage
        $filePath = storage_path('app/' . $this->session->file_path);
        
        if (!file_exists($filePath)) {
            throw new \Exception('Import file not found: ' . $this->session->file_path);
        }
        
        $file = new \Illuminate\Http\UploadedFile($filePath, $this->session->file_name, null, null, true);
        $parsed = $importService->parseFile($file);
        
        // Process in batches with progress tracking
        $chunks = array_chunk($parsed['rows'], $this->options['chunk_size'] ?? 50);
        $processed = 0;
        
        foreach ($chunks as $chunkIndex => $chunk) {
            // Process chunk
            $chunkResults = $importService->processBatchDryRun(
                $chunk, 
                $this->session, 
                $this->template
            );
            
            $processed += count($chunk);
            
            // Update progress
            $this->session->update(['processed_rows' => $processed]);
            
            // Broadcast progress update (if websockets enabled)
            $this->broadcastProgress([
                'session_id' => $this->session->public_id,
                'action' => 'dry_run',
                'processed' => $processed,
                'total' => $parsed['total'],
                'percentage' => round(($processed / $parsed['total']) * 100, 2),
                'chunk' => $chunkIndex + 1,
                'total_chunks' => count($chunks)
            ]);
            
            // Prevent memory issues on large files
            if (memory_get_usage() > 256 * 1024 * 1024) { // 256MB
                gc_collect_cycles();
            }
        }
        
        // Mark as completed
        $this->session->update([
            'status' => 'dry_run_completed',
            'dry_run_completed_at' => now(),
            'processed_rows' => $processed
        ]);
        
        Log::info("Dry run completed", [
            'session_id' => $this->session->public_id,
            'processed_rows' => $processed,
            'importable_rows' => $this->session->importable_rows
        ]);
    }

    /**
     * Process actual import in background
     * 
     * @param OrderImportService $importService
     */
    protected function processImport(OrderImportService $importService): void
    {
        // Update session status
        $this->session->update([
            'status' => 'importing',
            'import_started_at' => now()
        ]);
        
        // Execute batch import
        $results = $importService->createOrdersBatch(
            $this->session,
            $this->template,
            array_merge([
                'chunk_size' => 25,
                'stop_on_error' => false
            ], $this->options)
        );
        
        // Broadcast completion
        $this->broadcastCompletion([
            'session_id' => $this->session->public_id,
            'status' => $results['success'] ? 'completed' : 'failed',
            'created' => $results['created'],
            'failed' => $results['failed'],
            'errors' => $results['errors']
        ]);
        
        Log::info("Import completed", [
            'session_id' => $this->session->public_id,
            'created' => $results['created'],
            'failed' => $results['failed']
        ]);
    }

    /**
     * Handle job failure
     * 
     * @param \Throwable $exception
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Import job failed permanently", [
            'session_id' => $this->session->public_id,
            'action' => $this->action,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
        
        // Update session with failure
        $this->session->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'failed_at' => now()
        ]);
        
        // Broadcast failure
        $this->broadcastCompletion([
            'session_id' => $this->session->public_id,
            'status' => 'failed',
            'error' => $exception->getMessage()
        ]);
    }

    /**
     * Broadcast progress update
     * 
     * @param array $data Progress data
     */
    protected function broadcastProgress(array $data): void
    {
        try {
            // Use Laravel Broadcasting if available
            if (class_exists('\Illuminate\Broadcasting\BroadcastManager')) {
                broadcast(new \Fleetbase\FleetOps\Events\ImportProgress($data));
            }
            
            // Fallback: Store progress in cache for polling
            cache()->put(
                "import_progress_{$this->session->public_id}",
                $data,
                now()->addMinutes(10)
            );
            
        } catch (\Exception $e) {
            Log::warning("Failed to broadcast progress", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Broadcast completion event
     * 
     * @param array $data Completion data
     */
    protected function broadcastCompletion(array $data): void
    {
        try {
            // Use Laravel Broadcasting if available
            if (class_exists('\Illuminate\Broadcasting\BroadcastManager')) {
                broadcast(new \Fleetbase\FleetOps\Events\ImportCompleted($data));
            }
            
            // Store final result
            cache()->put(
                "import_result_{$this->session->public_id}",
                $data,
                now()->addHours(24)
            );
            
        } catch (\Exception $e) {
            Log::warning("Failed to broadcast completion", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the tags that should be assigned to the job
     * 
     * @return array
     */
    public function tags(): array
    {
        return [
            'import',
            'session:' . $this->session->public_id,
            'action:' . $this->action,
            'company:' . $this->session->company_uuid
        ];
    }
}