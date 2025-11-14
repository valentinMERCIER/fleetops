<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\ImportTemplate;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * API Controller for Import Template Management
 * 
 * Provides CRUD operations for import templates including:
 * - Template creation with field mappings
 * - Template listing and filtering
 * - Template updates and cloning
 * - Template validation and testing
 */
class ImportTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware('fleetbase.protected');
        $this->middleware('permission:fleet-ops.import-template.create')->only(['store', 'clone']);
        $this->middleware('permission:fleet-ops.import-template.view')->only(['index', 'show']);
        $this->middleware('permission:fleet-ops.import-template.update')->only(['update']);
        $this->middleware('permission:fleet-ops.import-template.delete')->only(['destroy']);
    }
    
    /**
     * List import templates
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ImportTemplate::where('company_uuid', session('company'));
        
        // Apply search filter
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('description', 'like', '%' . $search . '%');
            });
        }
        
        // Apply status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        // Sort and paginate
        $query->orderBy($request->input('sort_by', 'name'), $request->input('sort_order', 'asc'));
        $templates = $query->paginate($request->input('per_page', 25));
        
        return response()->json([
            'success' => true,
            'data' => $templates->items(),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total()
            ]
        ]);
    }
    
    /**
     * Create new import template
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'field_mappings' => 'required|array',
            'validation_rules' => 'nullable|array',
            'default_values' => 'nullable|array',
            'transformations' => 'nullable|array',
            'duplicate_handling' => 'nullable|in:allow,warn,reject',
            'duplicate_check_fields' => 'nullable|array',
            'auto_geocode' => 'nullable|boolean',
            'validate_addresses' => 'nullable|boolean',
            'default_status' => 'nullable|string',
            'default_type' => 'nullable|string',
            'default_priority' => 'nullable|string'
        ]);
        
        $template = ImportTemplate::create([
            'company_uuid' => session('company'),
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'field_mappings' => $request->input('field_mappings'),
            'validation_rules' => $request->input('validation_rules', []),
            'default_values' => $request->input('default_values', []),
            'transformations' => $request->input('transformations', []),
            'duplicate_handling' => $request->input('duplicate_handling', 'warn'),
            'duplicate_check_fields' => $request->input('duplicate_check_fields', ['reference', 'customer_phone']),
            'auto_geocode' => $request->boolean('auto_geocode', false),
            'validate_addresses' => $request->boolean('validate_addresses', false),
            'default_status' => $request->input('default_status', 'created'),
            'default_type' => $request->input('default_type', 'delivery'),
            'default_priority' => $request->input('default_priority', 'normal'),
            'status' => 'active',
            'created_by_uuid' => $request->user()->uuid ?? null
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Template created successfully',
            'data' => [
                'id' => $template->public_id,
                'name' => $template->name,
                'description' => $template->description,
                'field_mappings' => $template->field_mappings,
                'status' => $template->status,
                'created_at' => $template->created_at
            ]
        ], 201);
    }
    
    /**
     * Get template details
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $template = ImportTemplate::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        // Get usage statistics
        $usageStats = [
            'total_sessions' => $template->sessions()->count(),
            'successful_sessions' => $template->sessions()->where('status', 'completed')->count(),
            'last_used' => $template->sessions()->latest()->first()?->created_at
        ];
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->public_id,
                'name' => $template->name,
                'description' => $template->description,
                'field_mappings' => $template->field_mappings,
                'validation_rules' => $template->validation_rules,
                'default_values' => $template->default_values,
                'transformations' => $template->transformations,
                'duplicate_handling' => $template->duplicate_handling,
                'duplicate_check_fields' => $template->duplicate_check_fields,
                'auto_geocode' => $template->auto_geocode,
                'validate_addresses' => $template->validate_addresses,
                'default_status' => $template->default_status,
                'default_type' => $template->default_type,
                'default_priority' => $template->default_priority,
                'status' => $template->status,
                'usage_stats' => $usageStats,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at
            ]
        ]);
    }
    
    /**
     * Update template
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $template = ImportTemplate::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'field_mappings' => 'sometimes|array',
            'validation_rules' => 'nullable|array',
            'default_values' => 'nullable|array',
            'transformations' => 'nullable|array',
            'duplicate_handling' => 'nullable|in:allow,warn,reject',
            'duplicate_check_fields' => 'nullable|array',
            'auto_geocode' => 'nullable|boolean',
            'validate_addresses' => 'nullable|boolean',
            'default_status' => 'nullable|string',
            'default_type' => 'nullable|string',
            'default_priority' => 'nullable|string',
            'status' => 'nullable|in:active,inactive'
        ]);
        
        $template->update($request->only([
            'name', 'description', 'field_mappings', 'validation_rules',
            'default_values', 'transformations', 'duplicate_handling',
            'duplicate_check_fields', 'auto_geocode', 'validate_addresses',
            'default_status', 'default_type', 'default_priority', 'status'
        ]));
        
        return response()->json([
            'success' => true,
            'message' => 'Template updated successfully',
            'data' => [
                'id' => $template->public_id,
                'name' => $template->name,
                'updated_at' => $template->updated_at
            ]
        ]);
    }
    
    /**
     * Delete template
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $template = ImportTemplate::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        // Check if template is in use
        $sessionsCount = $template->sessions()->count();
        if ($sessionsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete template that has been used for {$sessionsCount} import sessions"
            ], 422);
        }
        
        $template->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully'
        ]);
    }
    
    /**
     * Clone existing template
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function clone(Request $request, $id): JsonResponse
    {
        $template = ImportTemplate::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000'
        ]);
        
        $clone = $template->replicate();
        $clone->name = $request->input('name');
        $clone->description = $request->input('description', $template->description . ' (Copy)');
        $clone->created_by_uuid = $request->user()->uuid ?? null;
        $clone->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Template cloned successfully',
            'data' => [
                'id' => $clone->public_id,
                'name' => $clone->name,
                'description' => $clone->description,
                'field_mappings' => $clone->field_mappings
            ]
        ], 201);
    }
    
    /**
     * Test template with sample data
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function testTemplate(Request $request, $id): JsonResponse
    {
        $template = ImportTemplate::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();
        
        $request->validate([
            'sample_data' => 'required|array'
        ]);
        
        $sampleData = $request->input('sample_data');
        
        try {
            // Map and validate sample data
            $mapped = app(OrderImportService::class)->mapFields($sampleData, $template);
            $validation = app(OrderImportService::class)->validateRow($mapped, $template);
            
            return response()->json([
                'success' => true,
                'message' => 'Template test completed',
                'data' => [
                    'original' => $sampleData,
                    'mapped' => $mapped,
                    'validation' => [
                        'is_valid' => $validation->isValid(),
                        'errors' => $validation->getErrors(),
                        'warnings' => $validation->getWarnings(),
                        'suggestions' => $validation->getSuggestions()
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Template test failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}