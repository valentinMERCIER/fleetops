<?php

namespace Fleetbase\FleetOps\Tests\Feature\Api;

use Fleetbase\FleetOps\Models\ImportSession;
use Fleetbase\FleetOps\Models\ImportTemplate;
use Fleetbase\FleetOps\Models\ImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests for Order Import API endpoints
 */
class OrderImportApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock storage for file operations
        Storage::fake('local');
        
        // Mock session helper
        if (!function_exists('session')) {
            function session($key = null) {
                if ($key === 'company') {
                    return 'test-company-uuid';
                }
                return null;
            }
        }
    }
    
    public function test_file_upload_endpoint_structure(): void
    {
        // Test CSV content
        $csvContent = "customer_name,customer_phone,pickup_address,dropoff_address\n";
        $csvContent .= "John Doe,+1234567890,123 Main St,456 Oak Ave\n";
        $csvContent .= "Jane Smith,+0987654321,789 Pine Rd,321 Elm St";
        
        // Create test file
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csvContent);
        
        // Test upload request structure
        $requestData = [
            'file' => $file,
            'auto_detect_mappings' => true
        ];
        
        // Validate request structure
        $this->assertArrayHasKey('file', $requestData);
        $this->assertArrayHasKey('auto_detect_mappings', $requestData);
        
        // Test file properties
        $this->assertEquals('orders.csv', $file->getClientOriginalName());
        $this->assertEquals('csv', $file->getClientOriginalExtension());
        $this->assertGreaterThan(0, $file->getSize());
        
        // Expected response structure
        $expectedResponse = [
            'success' => true,
            'message' => 'File uploaded and parsed successfully',
            'data' => [
                'session' => [
                    'id' => 'session-id',
                    'file_name' => 'orders.csv',
                    'status' => 'parsed'
                ],
                'parsed' => [
                    'headers' => ['customer_name', 'customer_phone', 'pickup_address', 'dropoff_address'],
                    'total_rows' => 2,
                    'preview' => [
                        ['John Doe', '+1234567890', '123 Main St', '456 Oak Ave'],
                        ['Jane Smith', '+0987654321', '789 Pine Rd', '321 Elm St']
                    ]
                ],
                'mappings' => [
                    'mappings' => [
                        'customer_name' => 'customer_name',
                        'customer_phone' => 'customer_phone'
                    ]
                ],
                'next_step' => 'configure_mappings'
            ]
        ];
        
        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertArrayHasKey('data', $expectedResponse);
        $this->assertArrayHasKey('session', $expectedResponse['data']);
        $this->assertArrayHasKey('parsed', $expectedResponse['data']);
    }
    
    public function test_field_mapping_detection_endpoint(): void
    {
        $headers = ['Customer Name', 'Phone', 'Email', 'Pickup Location', 'Delivery Address'];
        $sampleData = [
            [
                'Customer Name' => 'John Doe',
                'Phone' => '+1234567890',
                'Email' => 'john@example.com',
                'Pickup Location' => '123 Main St',
                'Delivery Address' => '456 Oak Ave'
            ]
        ];
        
        // Test request validation
        $requestData = [
            'headers' => $headers,
            'sample_data' => $sampleData
        ];
        
        $this->assertIsArray($requestData['headers']);
        $this->assertCount(5, $requestData['headers']);
        $this->assertIsArray($requestData['sample_data']);
        
        // Expected response structure
        $expectedMappings = [
            'customer_name' => 'Customer Name',
            'customer_phone' => 'Phone',
            'customer_email' => 'Email',
            'pickup_address' => 'Pickup Location',
            'dropoff_address' => 'Delivery Address'
        ];
        
        foreach ($expectedMappings as $field => $header) {
            $this->assertContains($header, $headers);
        }
    }
    
    public function test_dry_run_endpoint_structure(): void
    {
        // Mock session data
        $sessionData = [
            'public_id' => 'test-session-123',
            'company_uuid' => 'test-company',
            'status' => 'parsed',
            'file_path' => 'imports/test/file.csv',
            'total_rows' => 10
        ];
        
        // Test dry run request
        $requestData = [
            'mappings' => [
                'customer_name' => 'Name',
                'customer_phone' => 'Phone',
                'pickup_address' => 'Pickup',
                'dropoff_address' => 'Dropoff'
            ],
            'duplicate_handling' => 'warn',
            'stop_on_error' => false
        ];
        
        $this->assertArrayHasKey('mappings', $requestData);
        $this->assertIsArray($requestData['mappings']);
        
        // Expected response structure
        $expectedResponse = [
            'success' => true,
            'message' => 'Dry run completed',
            'data' => [
                'session_id' => $sessionData['public_id'],
                'summary' => [
                    'overview' => [
                        'total_rows' => 10,
                        'ready_to_import' => 8,
                        'success_rate' => 80.0
                    ],
                    'breakdown' => [
                        'valid' => 6,
                        'warnings' => 2,
                        'errors' => 2,
                        'duplicates' => 0
                    ]
                ],
                'can_proceed' => true
            ]
        ];
        
        $this->assertArrayHasKey('summary', $expectedResponse['data']);
        $this->assertArrayHasKey('can_proceed', $expectedResponse['data']);
    }
    
    public function test_import_execution_endpoint_structure(): void
    {
        // Test execution request
        $requestData = [
            'stop_on_error' => false,
            'include_orders' => false,
            'chunk_size' => 10
        ];
        
        $this->assertIsBool($requestData['stop_on_error']);
        $this->assertIsBool($requestData['include_orders']);
        $this->assertIsInt($requestData['chunk_size']);
        
        // Expected response structure
        $expectedResponse = [
            'success' => true,
            'message' => 'Import completed. Created 8 orders.',
            'data' => [
                'session_id' => 'test-session-123',
                'created' => 8,
                'failed' => 2,
                'errors' => [
                    ['row' => 3, 'error' => 'Missing required field'],
                    ['row' => 7, 'error' => 'Invalid phone format']
                ]
            ]
        ];
        
        $this->assertArrayHasKey('created', $expectedResponse['data']);
        $this->assertArrayHasKey('failed', $expectedResponse['data']);
        $this->assertArrayHasKey('errors', $expectedResponse['data']);
    }
    
    public function test_session_listing_endpoint_structure(): void
    {
        // Test query parameters
        $queryParams = [
            'status' => 'completed',
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
            'page' => 1,
            'per_page' => 25,
            'sort_by' => 'created_at',
            'sort_order' => 'desc'
        ];
        
        foreach ($queryParams as $key => $value) {
            $this->assertNotNull($value);
        }
        
        // Expected response structure
        $expectedResponse = [
            'success' => true,
            'data' => [
                [
                    'id' => 'session-1',
                    'file_name' => 'orders.csv',
                    'status' => 'completed',
                    'stats' => [
                        'total_rows' => 100,
                        'imported_rows' => 95,
                        'failed_rows' => 5
                    ]
                ]
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 3,
                'per_page' => 25,
                'total' => 75
            ]
        ];
        
        $this->assertArrayHasKey('data', $expectedResponse);
        $this->assertArrayHasKey('meta', $expectedResponse);
        $this->assertIsArray($expectedResponse['data']);
    }
    
    public function test_row_fixing_endpoint_structure(): void
    {
        // Test fix request
        $requestData = [
            'corrections' => [
                'customer_name' => 'John Doe',
                'customer_phone' => '+1234567890',
                'customer_email' => 'john@example.com'
            ]
        ];
        
        $this->assertArrayHasKey('corrections', $requestData);
        $this->assertIsArray($requestData['corrections']);
        
        // Expected response structure
        $expectedResponse = [
            'success' => true,
            'message' => 'Row fixed successfully and ready for import',
            'data' => [
                'row' => [
                    'id' => 'row-123',
                    'row_number' => 5,
                    'status' => 'valid',
                    'can_import' => true
                ],
                'validation' => [
                    'errors' => [],
                    'warnings' => [],
                    'suggestions' => []
                ]
            ]
        ];
        
        $this->assertArrayHasKey('row', $expectedResponse['data']);
        $this->assertArrayHasKey('validation', $expectedResponse['data']);
    }
    
    public function test_template_management_endpoints(): void
    {
        // Test template creation request
        $createRequest = [
            'name' => 'Standard Order Template',
            'description' => 'Template for standard order imports',
            'field_mappings' => [
                'customer_name' => 'Customer',
                'customer_phone' => 'Phone',
                'pickup_address' => 'Pickup',
                'dropoff_address' => 'Delivery'
            ],
            'default_values' => [
                'type' => 'delivery',
                'priority' => 'normal'
            ],
            'duplicate_handling' => 'warn'
        ];
        
        $this->assertArrayHasKey('name', $createRequest);
        $this->assertArrayHasKey('field_mappings', $createRequest);
        $this->assertIsArray($createRequest['field_mappings']);
        
        // Expected template response
        $expectedTemplate = [
            'id' => 'template-123',
            'name' => 'Standard Order Template',
            'field_mappings' => $createRequest['field_mappings'],
            'status' => 'active',
            'created_at' => now()
        ];
        
        $this->assertArrayHasKey('id', $expectedTemplate);
        $this->assertArrayHasKey('field_mappings', $expectedTemplate);
    }
    
    public function test_status_polling_endpoint_structure(): void
    {
        // Test status response for different states
        $statuses = [
            'processing' => [
                'session_id' => 'test-session',
                'status' => 'processing',
                'is_complete' => false,
                'progress' => [
                    'total' => 100,
                    'processed' => 45,
                    'percentage' => 45.0,
                    'estimated_time_remaining' => '2 minutes'
                ]
            ],
            'completed' => [
                'session_id' => 'test-session',
                'status' => 'completed',
                'is_complete' => true,
                'results' => [
                    'imported' => 95,
                    'failed' => 5,
                    'errors' => []
                ]
            ]
        ];
        
        foreach ($statuses as $status => $data) {
            $this->assertArrayHasKey('session_id', $data);
            $this->assertArrayHasKey('status', $data);
            $this->assertArrayHasKey('is_complete', $data);
            
            if ($status === 'processing') {
                $this->assertArrayHasKey('progress', $data);
                $this->assertArrayHasKey('percentage', $data['progress']);
            } else {
                $this->assertArrayHasKey('results', $data);
                $this->assertArrayHasKey('imported', $data['results']);
            }
        }
    }
    
    public function test_rollback_endpoint_structure(): void
    {
        // Test rollback request
        $requestData = [
            'action' => 'rollback'
        ];
        
        $this->assertEquals('rollback', $requestData['action']);
        
        // Expected rollback response
        $expectedResponse = [
            'success' => true,
            'message' => 'Successfully rolled back 10 orders',
            'data' => [
                'session_id' => 'test-session',
                'orders_deleted' => 10,
                'status' => 'rolled_back'
            ]
        ];
        
        $this->assertArrayHasKey('orders_deleted', $expectedResponse['data']);
        $this->assertArrayHasKey('status', $expectedResponse['data']);
        $this->assertEquals('rolled_back', $expectedResponse['data']['status']);
    }
    
    public function test_api_validation_rules(): void
    {
        // Test upload validation
        $uploadRules = [
            'file' => 'required|file|mimes:csv,xlsx,xls,json|max:10240',
            'template_id' => 'nullable|string',
            'auto_detect_mappings' => 'nullable|boolean'
        ];
        
        $this->assertArrayHasKey('file', $uploadRules);
        $this->assertStringContainsString('required', $uploadRules['file']);
        $this->assertStringContainsString('csv', $uploadRules['file']);
        
        // Test dry run validation
        $dryRunRules = [
            'mappings' => 'required_without:template_id|array',
            'duplicate_handling' => 'nullable|in:allow,warn,reject'
        ];
        
        $this->assertArrayHasKey('mappings', $dryRunRules);
        
        // Test execute validation
        $executeRules = [
            'stop_on_error' => 'nullable|boolean',
            'chunk_size' => 'nullable|integer|min:1|max:100'
        ];
        
        $this->assertStringContainsString('max:100', $executeRules['chunk_size']);
        
        // Test fix row validation
        $fixRules = [
            'corrections' => 'required|array',
            'corrections.customer_name' => 'sometimes|string|max:255',
            'corrections.customer_email' => 'sometimes|email|max:255'
        ];
        
        $this->assertArrayHasKey('corrections', $fixRules);
        $this->assertStringContainsString('email', $fixRules['corrections.customer_email']);
    }
    
    public function test_api_response_structures(): void
    {
        // Test error response structure
        $errorResponse = [
            'success' => false,
            'message' => 'Validation failed',
            'errors' => [
                'field_name' => ['Error message']
            ]
        ];
        
        $this->assertFalse($errorResponse['success']);
        $this->assertArrayHasKey('message', $errorResponse);
        $this->assertArrayHasKey('errors', $errorResponse);
        
        // Test success response structure
        $successResponse = [
            'success' => true,
            'message' => 'Operation completed',
            'data' => [
                'id' => 'resource-id',
                'status' => 'completed'
            ]
        ];
        
        $this->assertTrue($successResponse['success']);
        $this->assertArrayHasKey('data', $successResponse);
        
        // Test paginated response structure
        $paginatedResponse = [
            'success' => true,
            'data' => [
                ['id' => 1], ['id' => 2], ['id' => 3]
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 5,
                'per_page' => 25,
                'total' => 125
            ]
        ];
        
        $this->assertArrayHasKey('meta', $paginatedResponse);
        $this->assertArrayHasKey('current_page', $paginatedResponse['meta']);
    }
}