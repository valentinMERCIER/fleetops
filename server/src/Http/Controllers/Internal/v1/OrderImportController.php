<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Services\OrderImportService;
use Fleetbase\FleetOps\Models\ImportSession;
use Fleetbase\FleetOps\Models\ImportTemplate;
use Fleetbase\FleetOps\Models\ImportRow;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Fleetbase\FleetOps\Http\Requests\OrderImportUploadRequest;
use Fleetbase\FleetOps\Http\Requests\OrderImportDryRunRequest;
use Fleetbase\FleetOps\Http\Requests\OrderImportExecuteRequest;

/**
 * API Controller for Order Import System
 * 
 * Provides RESTful endpoints for managing order imports including:
 * - File upload and parsing
 * - Field mapping detection
 * - Dry run execution
 * - Import execution
 * - Session management
 * - Progress tracking
 */
class OrderImportController extends Controller
{
    protected OrderImportService $importService;
    
    public function __construct(OrderImportService $importService)
    {
        $this->importService = $importService;
        
        // Apply middleware for authentication and permissions
        $this->middleware('fleetbase.protected');
    }
    
    /**
     * Upload and parse import file
     * 
     * @param OrderImportUploadRequest $request
     * @return JsonResponse
     */
    public function upload(OrderImportUploadRequest $request): JsonResponse
    {
        // Validation handled by FormRequest
        
        try {
            $file = $request->file('file');
            $templateId = $request->input('template_id');
            $autoDetect = $request->boolean('auto_detect_mappings', true);
            
            // Auto-generate name if not provided
            $name = $request->input('name');
            if (empty($name)) {
                $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $name = 'Import: ' . $filename . ' - ' . now()->format('Y-m-d H:i');
            }
            
            // Create import session in two steps
            $session = new ImportSession();
            $session->company_uuid = session('company');
            $session->status = 'uploading';
            $session->save();
            
            // Update with additional fields after creation
            $session->update([
                'name' => $name,
                'template_uuid' => $templateId,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType()
            ]);
            
            // Store file securely and create File record
            $path = $file->store('imports/' . $session->public_id, 'local');
            
            // Create File record
            $fileRecord = \Fleetbase\Models\File::create([
                'company_uuid' => session('company'),
                'name' => $file->getClientOriginalName(),
                'original_filename' => $file->getClientOriginalName(),
                'path' => $path,
                'disk' => 'local',
                'type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
            
            $session->update([
                'file_uuid' => $fileRecord->uuid,
                'file_path' => $path,
                'status' => 'validating'
            ]);
            
            // Parse file
            $parsed = $this->importService->parseFile($file);
            
            // Store parsed data and update session
            $session->update([
                'total_rows' => $parsed['total'],
                'meta' => [
                    'headers' => $parsed['headers'],
                    'preview_data' => array_slice($parsed['rows'], 0, 10), // Store first 10 rows as preview
                    'delimiter' => $parsed['delimiter'] ?? null,
                    'encoding' => $parsed['encoding'] ?? null
                ],
                'parsed_at' => now(),
                'status' => 'ready'
            ]);
            
            // Auto-detect mappings if requested
            $mappings = null;
            if ($autoDetect && !$templateId) {
                $mappings = $this->importService->detectFieldMappings($parsed['headers']);
            } elseif ($templateId) {
                $template = ImportTemplate::where('public_id', $templateId)->first();
                if ($template) {
                    $mappings = ['mappings' => $template->field_mappings];
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'File uploaded and parsed successfully',
                'data' => [
                    'session' => [
                        'id' => $session->public_id,
                        'name' => $session->name,
                        'file_name' => $fileRecord->name,
                        'status' => $session->status
                    ],
                    'parsed' => [
                        'headers' => $parsed['headers'],
                        'total_rows' => $parsed['total'],
                        'preview' => array_slice($parsed['rows'], 0, 5),
                        'delimiter' => $parsed['delimiter'] ?? null,
                        'encoding' => $parsed['encoding'] ?? null
                    ],
                    'mappings' => $mappings,
                    'next_step' => 'configure_mappings'
                ]
            ]);
            
        } catch (\Exception $e) {
            // Clean up on failure
            if (isset($session)) {
                $session->update([
                    'status' => 'failed', 
                    'errors' => [
                        'upload_error' => $e->getMessage(),
                        'timestamp' => now()->toISOString()
                    ]
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process file',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Auto-detect field mappings from headers
     * 
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Auto-detect field mappings from headers
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function detectMappings(Request $request, string $id): JsonResponse
    {
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
            
        $headers = $session->meta['headers'] ?? [];
        
        if (empty($headers)) {
             return response()->json([
                'success' => false,
                'message' => 'No headers found in session'
            ], 422);
        }
        
        // Detect mappings
        $detected = $this->importService->detectFieldMappings($headers);
        
        // Get sample data from session preview if available
        $sampleData = $session->meta['preview_data'] ?? [];
        
        // Validate sample data if provided
        $validation = null;
        if (!empty($sampleData) && !empty($detected['mappings'])) {
            $mapped = $this->importService->mapFields($sampleData[0], null);
            $validation = $this->importService->validateRow($mapped, null);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'mappings' => $detected['mappings'],
                'confidence' => $detected['confidence'],
                'unmapped' => $detected['unmapped'],
                'required_fields' => $this->getRequiredFields(),
                'optional_fields' => $this->getOptionalFields(),
                'validation_preview' => $validation ? [
                    'is_valid' => $validation->isValid(),
                    'errors' => $validation->getErrors(),
                    'warnings' => $validation->getWarnings()
                ] : null
            ]
        ]);
    }
    
    /**
     * Execute dry run to preview import results
     * 
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Execute dry run to preview import results
     * 
     * @param OrderImportDryRunRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function dryRun(OrderImportDryRunRequest $request, string $id): JsonResponse
    {
        // Validation handled by FormRequest
        
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        // Get or create template
        $template = null;
        if ($request->has('template_id')) {
            $template = ImportTemplate::where('public_id', $request->input('template_id'))->first();
        } elseif ($request->has('mappings')) {
            // Create temporary template from provided mappings
            $template = [
                'field_mappings' => $request->input('mappings'),
                'validation_rules' => $request->input('validation_rules', []),
                'default_values' => $request->input('default_values', []),
                'duplicate_handling' => $request->input('duplicate_handling', 'warn')
            ];
        }
        
        // Update session with configuration
        $session->update([
            'field_mappings' => $template ? ($template['field_mappings'] ?? $template->field_mappings) : $request->input('mappings'),
            'status' => 'processing_dry_run'
        ]);
        
        try {
            // Get parsed data from storage
            $filePath = storage_path('app/' . $session->file_path);
            $file = new \Illuminate\Http\UploadedFile($filePath, $session->file_name, null, null, true);
            $parsed = $this->importService->parseFile($file);
            
            // Process dry run
            $results = $this->importService->processBatchDryRun(
                $parsed['rows'],
                $session,
                $template
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Dry run completed',
                'data' => [
                    'session_id' => $session->public_id,
                    'summary' => $results['summary'],
                    'stats' => $results['stats'],
                    'can_proceed' => $results['can_proceed'],
                    'sample_errors' => $this->getSampleErrors($results['rows'], 5),
                    'sample_warnings' => $this->getSampleWarnings($results['rows'], 5)
                ]
            ]);
            
        } catch (\Exception $e) {
            $session->update([
                'status' => 'failed', 
                'errors' => [
                    'processing_error' => $e->getMessage(),
                    'timestamp' => now()->toISOString()
                ]
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Dry run failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get detailed dry run results
     * 
     * @param string $sessionId
     * @return JsonResponse
     */
    public function getDryRunResults($sessionId): JsonResponse
    {
        $session = ImportSession::where('public_id', $sessionId)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $results = $this->importService->getDryRunResults($session);
        
        // Apply filters and pagination
        $page = request()->input('page', 1);
        $perPage = request()->input('per_page', 50);
        $filter = request()->input('filter', 'all'); // all, valid, errors, warnings, duplicates
        
        $filteredRows = $this->filterImportRows($session, $filter);
        $paginated = $this->paginateResults($filteredRows, $page, $perPage);
        
        return response()->json([
            'success' => true,
            'data' => [
                'session' => [
                    'id' => $session->public_id,
                    'status' => $session->status,
                    'file_name' => $session->file_name
                ],
                'stats' => $results['stats'],
                'can_proceed' => $results['can_proceed'],
                'rows' => $paginated,
                'filters' => [
                    'all' => ImportRow::where('session_uuid', $session->uuid)->count(),
                    'valid' => ImportRow::where('session_uuid', $session->uuid)->valid()->count(),
                    'errors' => ImportRow::where('session_uuid', $session->uuid)->withErrors()->count(),
                    'warnings' => ImportRow::where('session_uuid', $session->uuid)->withWarnings()->count(),
                ]
            ]
        ]);
    }
    
    /**
     * Execute the actual import
     * 
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Execute the actual import
     * 
     * @param OrderImportExecuteRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function execute(OrderImportExecuteRequest $request, string $id): JsonResponse
    {
        // Validation handled by FormRequest
        
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        // Verify session is ready for import
        if (!in_array($session->status, ['ready', 'dry_run_completed', 'processed', 'has_warnings'])) {
            return response()->json([
                'success' => false,
                'message' => 'Session is not ready for import. Please run dry-run first.'
            ], 422);
        }
        
        // Check if there are importable rows
        $importableCount = ImportRow::where('session_uuid', $session->uuid)
            ->importable()
            ->count();
        
        if ($importableCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No valid rows to import'
            ], 422);
        }
        
        // Get template
        $template = $session->template;
        
        // Update session status
        $session->update([
            'status' => 'importing',
            'import_started_at' => now()
        ]);
        
        try {
            // Execute import
            $results = $this->importService->createOrdersBatch(
                $session,
                $template,
                ['stop_on_error' => $request->boolean('stop_on_error', false)]
            );
            
            return response()->json([
                'success' => $results['success'],
                'message' => "Import completed. Created {$results['created']} orders.",
                'data' => [
                    'session_id' => $session->public_id,
                    'created' => $results['created'],
                    'failed' => $results['failed'],
                    'errors' => $results['errors'],
                    'orders' => $request->boolean('include_orders', false) 
                        ? $results['orders'] 
                        : null
                ]
            ]);
            
        } catch (\Exception $e) {
            $session->update([
                'status' => 'failed', 
                'errors' => [
                    'processing_error' => $e->getMessage(),
                    'timestamp' => now()->toISOString()
                ]
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Import failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * List import sessions
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Debug logging
        \Log::info('OrderImportController@index called', [
            'user' => auth()->user() ? auth()->user()->email : 'not authenticated',
            'company' => session('company'),
            'request_path' => $request->path(),
            'request_method' => $request->method()
        ]);
        
        $query = ImportSession::where('company_uuid', session('company'));
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        if ($request->has('template_id')) {
            $query->where('import_template_uuid', $request->input('template_id'));
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        // Sort and paginate
        $query->orderBy($request->input('sort_by', 'created_at'), $request->input('sort_order', 'desc'));
        $sessions = $query->paginate($request->input('per_page', 25));
        
        return response()->json([
            'success' => true,
            'data' => $sessions->items(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total()
            ]
        ]);
    }
    
    /**
     * Get session details
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        // Get statistics
        $stats = [
            'total_rows' => $session->total_rows,
            'processed_rows' => $session->processed_rows,
            'imported_rows' => $session->imported_rows,
            'failed_rows' => $session->failed_rows,
            'valid_rows' => $session->valid_rows,
            'warning_rows' => $session->warning_rows,
            'duplicate_rows' => $session->duplicate_rows,
            'success_rate' => $session->total_rows > 0 
                ? round(($session->imported_rows / $session->total_rows) * 100, 2) 
                : 0
        ];
        
        // Get recent errors for quick display
        $recentErrors = ImportRow::where('session_uuid', $session->uuid)
            ->withErrors()
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'row_number' => $row->row_number,
                    'errors' => $row->validation_errors,
                    'message' => $row->processing_message
                ];
            });
        
        return response()->json([
            'success' => true,
            'data' => [
                'session' => [
                    'id' => $session->public_id,
                    'file_name' => $session->file_name,
                    'file_type' => $session->file_type,
                    'status' => $session->status,
                    'created_at' => $session->created_at
                ],
                'stats' => $stats,
                'recent_errors' => $recentErrors,
                'timeline' => [
                    'created' => $session->created_at,
                    'parsed' => $session->parsed_at,
                    'dry_run_completed' => $session->dry_run_completed_at,
                    'import_started' => $session->import_started_at,
                    'completed' => $session->completed_at
                ]
            ]
        ]);
    }
    
    /**
     * Fix and revalidate an import row
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    /**
     * Fix and revalidate an import row
     * 
     * @param Request $request
     * @param string $id
     * @param string $rowId
     * @return JsonResponse
     */
    public function fixRow(Request $request, string $id, string $rowId): JsonResponse
    {
        $request->validate([
            'corrections' => 'required|array',
            'corrections.customer_name' => 'sometimes|string|max:255',
            'corrections.customer_phone' => 'sometimes|string|max:50',
            'corrections.customer_email' => 'sometimes|email|max:255',
            'corrections.pickup_address' => 'sometimes|string|max:500',
            'corrections.dropoff_address' => 'sometimes|string|max:500',
            'corrections.reference' => 'sometimes|string|max:100',
            'corrections.notes' => 'sometimes|string|max:1000'
        ]);
        
        $importRow = ImportRow::where('id', $rowId)
            ->whereHas('session', function($query) use ($id) {
                $query->where('public_id', $id)
                      ->where('company_uuid', session('company'));
            })
            ->firstOrFail();
        
        $corrections = $request->input('corrections');
        $template = $importRow->session->template ?? null;
        
        // Apply fixes and revalidate
        $fixed = $this->importService->fixAndRevalidateRow($importRow, $corrections, $template);
        
        return response()->json([
            'success' => true,
            'message' => $fixed->canImport() 
                ? 'Row fixed successfully and ready for import' 
                : 'Row updated but still has validation errors',
            'data' => [
                'row' => [
                    'id' => $fixed->id,
                    'row_number' => $fixed->row_number,
                    'status' => $fixed->processing_status,
                    'message' => $fixed->processing_message,
                    'can_import' => $fixed->canImport()
                ],
                'validation' => [
                    'errors' => $fixed->validation_errors,
                    'warnings' => $fixed->validation_warnings,
                    'suggestions' => $fixed->suggestions
                ]
            ]
        ]);
    }
    
    /**
     * Cancel or rollback import
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $action = $request->input('action', 'cancel');
        
        if ($action === 'rollback') {
            // Rollback completed import
            if (!in_array($session->status, ['completed', 'completed_with_errors'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only rollback completed imports'
                ], 422);
            }
            
            $deletedCount = $this->importService->rollbackImportedOrders($session);
            
            return response()->json([
                'success' => true,
                'message' => "Successfully rolled back {$deletedCount} orders",
                'data' => [
                    'session_id' => $session->public_id,
                    'orders_deleted' => $deletedCount,
                    'status' => 'rolled_back'
                ]
            ]);
        } else {
            // Cancel ongoing import
            if (!in_array($session->status, ['processing', 'importing', 'processing_dry_run'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel import in current status'
                ], 422);
            }
            
            $session->update([
                'status' => 'cancelled',
                'cancelled_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Import cancelled successfully',
                'data' => [
                    'session_id' => $session->public_id,
                    'status' => 'cancelled'
                ]
            ]);
        }
    }
    
    /**
     * Get import status for polling
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function status($id): JsonResponse
    {
        $session = ImportSession::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $progress = null;
        if (in_array($session->status, ['processing', 'importing', 'processing_dry_run'])) {
            $progress = [
                'total' => $session->total_rows,
                'processed' => $session->processed_rows,
                'percentage' => $session->total_rows > 0 
                    ? round(($session->processed_rows / $session->total_rows) * 100, 2)
                    : 0,
                'current_action' => $session->status,
                'estimated_time_remaining' => $this->estimateTimeRemaining($session)
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->public_id,
                'status' => $session->status,
                'is_complete' => in_array($session->status, ['completed', 'completed_with_errors', 'failed', 'cancelled']),
                'progress' => $progress,
                'results' => $session->status === 'completed' ? [
                    'imported' => $session->imported_rows,
                    'failed' => $session->failed_rows,
                    'errors' => $session->errors
                ] : null
            ]
        ]);
    }
    
    // Helper methods
    
    protected function getRequiredFields(): array
    {
        return [
            'customer_name' => 'Customer name',
            'customer_phone' => 'Customer phone (or email)',
            'customer_email' => 'Customer email (or phone)',
            'pickup_address' => 'Pickup address',
            'dropoff_address' => 'Dropoff address'
        ];
    }
    
    protected function getOptionalFields(): array
    {
        return [
            'reference' => 'Order reference',
            'scheduled_at' => 'Scheduled date/time',
            'notes' => 'Notes/instructions',
            'quantity' => 'Number of packages',
            'weight' => 'Weight',
            'type' => 'Order type',
            'priority' => 'Priority level',
            'pickup_name' => 'Pickup contact name',
            'dropoff_name' => 'Dropoff contact name'
        ];
    }
    
    protected function filterImportRows(ImportSession $session, string $filter)
    {
        $query = ImportRow::where('session_uuid', $session->uuid);
        
        switch ($filter) {
            case 'valid':
                return $query->valid()->get();
            case 'errors':
                return $query->withErrors()->get();
            case 'warnings':
                return $query->withWarnings()->get();
            case 'duplicates':
                return $query->where('is_duplicate', true)->get();
            default:
                return $query->get();
        }
    }
    
    protected function paginateResults($items, $page, $perPage)
    {
        $offset = ($page - 1) * $perPage;
        $paginatedItems = $items->slice($offset, $perPage);
        
        return [
            'data' => $paginatedItems->map(function($row) {
                return [
                    'id' => $row->id,
                    'row_number' => $row->row_number,
                    'status' => $row->processing_status,
                    'message' => $row->processing_message,
                    'original_data' => $row->original_data,
                    'validation_errors' => $row->validation_errors,
                    'validation_warnings' => $row->validation_warnings,
                    'can_import' => $row->canImport()
                ];
            })->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $items->count(),
                'last_page' => ceil($items->count() / $perPage)
            ]
        ];
    }
    
    protected function getSampleErrors($rows, $limit = 5): array
    {
        return collect($rows)
            ->filter(fn($row) => $row->hasErrors())
            ->take($limit)
            ->map(fn($row) => [
                'row_number' => $row->row_number,
                'errors' => $row->validation_errors,
                'original_data' => $row->original_data
            ])
            ->values()
            ->toArray();
    }
    
    protected function getSampleWarnings($rows, $limit = 5): array
    {
        return collect($rows)
            ->filter(fn($row) => $row->hasWarnings())
            ->take($limit)
            ->map(fn($row) => [
                'row_number' => $row->row_number,
                'warnings' => $row->validation_warnings,
                'suggestions' => $row->suggestions
            ])
            ->values()
            ->toArray();
    }
    
    protected function estimateTimeRemaining(ImportSession $session): ?string
    {
        if (!$session->import_started_at || $session->processed_rows === 0) {
            return null;
        }
        
        $elapsed = now()->diffInSeconds($session->import_started_at);
        $rate = $session->processed_rows / $elapsed; // rows per second
        $remaining = $session->total_rows - $session->processed_rows;
        
        if ($rate > 0) {
            $secondsRemaining = $remaining / $rate;
            
            if ($secondsRemaining < 60) {
                return round($secondsRemaining) . ' seconds';
            } elseif ($secondsRemaining < 3600) {
                return round($secondsRemaining / 60) . ' minutes';
            } else {
                return round($secondsRemaining / 3600, 1) . ' hours';
            }
        }
        
        return null;
    }
}