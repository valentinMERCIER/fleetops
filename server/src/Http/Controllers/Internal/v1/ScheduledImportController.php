<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\ScheduledImport;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class ScheduledImportController extends Controller
{
    /**
     * List scheduled imports
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledImport::where('company_uuid', session('company'));

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Sort and paginate
        $query->orderBy($request->input('sort_by', 'created_at'), $request->input('sort_order', 'desc'));
        
        $results = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total()
            ]
        ]);
    }

    /**
     * Create a new scheduled import
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'frequency' => 'required|string', // e.g., 'daily', 'weekly', 'custom'
            'period' => 'required|string', // e.g., 'day', 'week', 'month'
            'days' => 'nullable|array',
            'time' => 'required|string',
            'start_date' => 'required|date',
            'ends' => 'required|string|in:never,on,after',
            'end_date' => 'required_if:ends,on|nullable|date',
            'occurrences' => 'required_if:ends,after|nullable|integer|min:1',
            'template_uuid' => 'nullable|exists:import_templates,uuid',
            // File source config would typically be handled here too
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            $data['company_uuid'] = session('company');
            $data['status'] = 'active';
            
            // Construct cron expression based on inputs (simplified logic for now)
            // In a real implementation, we'd use a service to build the cron expression
            // $data['cron_expression'] = $this->buildCronExpression($data);
            
            // For now, we'll store the raw schedule options to reconstruct the UI
            $data['options'] = [
                'frequency' => $request->input('frequency'),
                'period' => $request->input('period'),
                'days' => $request->input('days'),
                'time' => $request->input('time'),
                'ends' => $request->input('ends'),
                'end_date' => $request->input('end_date'),
                'occurrences' => $request->input('occurrences'),
            ];

            $scheduledImport = ScheduledImport::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Scheduled import created successfully',
                'data' => $scheduledImport
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create scheduled import',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a scheduled import
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $scheduledImport = ScheduledImport::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $scheduledImport
        ]);
    }

    /**
     * Update a scheduled import
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $scheduledImport = ScheduledImport::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        // Allow updating specific fields, mainly the "Ends" configuration as per requirements
        // But generally we might allow more
        
        try {
            $data = $request->only(['name', 'status', 'options', 'ends', 'end_date', 'occurrences']);
            
            // If updating options, merge with existing
            if ($request->has('options')) {
                $data['options'] = array_merge($scheduledImport->options ?? [], $request->input('options'));
            }

            $scheduledImport->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Scheduled import updated successfully',
                'data' => $scheduledImport
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update scheduled import',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a scheduled import
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        $scheduledImport = ScheduledImport::where('public_id', $id)
            ->where('company_uuid', session('company'))
            ->firstOrFail();

        $scheduledImport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Scheduled import deleted successfully'
        ]);
    }
}
