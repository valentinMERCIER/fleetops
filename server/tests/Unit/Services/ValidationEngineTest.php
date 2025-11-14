<?php

namespace Fleetbase\FleetOps\Tests\Unit\Services;

use Fleetbase\FleetOps\Services\OrderImportService;
use Fleetbase\FleetOps\Support\ValidationResult;
use PHPUnit\Framework\TestCase;
use Carbon\Carbon;

class ValidationEngineTest extends TestCase
{
    protected OrderImportService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderImportService();
    }
    
    public function test_validates_required_fields(): void
    {
        $data = [
            'customer_name' => '',
            'pickup_address' => '',
            'dropoff_address' => ''
        ];
        
        $result = $this->service->validateRow($data);
        
        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('customer_name', $result->getErrors());
        $this->assertArrayHasKey('pickup_address', $result->getErrors());
        $this->assertArrayHasKey('dropoff_address', $result->getErrors());
    }
    
    public function test_requires_either_phone_or_email(): void
    {
        // Test with neither
        $data1 = [
            'customer_name' => 'John Doe',
            'pickup_address' => '123 Main St, City, State 12345',
            'dropoff_address' => '456 Oak Ave, City, State 67890'
        ];
        
        $result1 = $this->service->validateRow($data1);
        $this->assertFalse($result1->isValid());
        
        // Test with phone only
        $data2 = array_merge($data1, ['customer_phone' => '+1234567890']);
        $result2 = $this->service->validateRow($data2);
        $this->assertTrue($result2->isValid());
        
        // Test with email only
        $data3 = array_merge($data1, ['customer_email' => 'john@example.com']);
        $result3 = $this->service->validateRow($data3);
        $this->assertTrue($result3->isValid());
        
        // Test with both
        $data4 = array_merge($data1, [
            'customer_phone' => '+1234567890',
            'customer_email' => 'john@example.com'
        ]);
        $result4 = $this->service->validateRow($data4);
        $this->assertTrue($result4->isValid());
    }
    
    public function test_validates_phone_number_format(): void
    {
        $validPhones = [
            '+1234567890',
            '1234567890',
            '+441234567890',
            '9876543210'
        ];
        
        foreach ($validPhones as $phone) {
            $data = $this->getValidBaseData();
            $data['customer_phone'] = $phone;
            
            $result = $this->service->validateRow($data);
            $errors = $result->getErrors();
            
            $this->assertArrayNotHasKey(
                'customer_phone',
                $errors,
                "Phone '$phone' should be valid"
            );
        }
        
        $invalidPhones = [
            '123',           // Too short
            'abc123456789',  // Contains letters
            '12345678901234567', // Too long
        ];
        
        foreach ($invalidPhones as $phone) {
            $data = $this->getValidBaseData();
            $data['customer_phone'] = $phone;
            
            $result = $this->service->validateRow($data);
            
            $this->assertArrayHasKey(
                'customer_phone',
                $result->getErrors(),
                "Phone '$phone' should be invalid"
            );
        }
    }
    
    public function test_validates_email_format(): void
    {
        $validEmails = [
            'user@example.com',
            'john.doe@company.org',
            'test+tag@email.co.uk'
        ];
        
        foreach ($validEmails as $email) {
            $data = $this->getValidBaseData();
            $data['customer_email'] = $email;
            
            $result = $this->service->validateRow($data);
            
            $this->assertArrayNotHasKey(
                'customer_email',
                $result->getErrors(),
                "Email '$email' should be valid"
            );
        }
        
        $invalidEmails = [
            'notanemail',
            'missing@',
            '@example.com',
            'spaces in@email.com'
        ];
        
        foreach ($invalidEmails as $email) {
            $data = $this->getValidBaseData();
            $data['customer_email'] = $email;
            
            $result = $this->service->validateRow($data);
            
            $this->assertArrayHasKey(
                'customer_email',
                $result->getErrors(),
                "Email '$email' should be invalid"
            );
        }
    }
    
    public function test_validates_scheduled_date(): void
    {
        // Test future date (valid)
        $data = $this->getValidBaseData();
        $data['scheduled_at'] = now()->addDay()->format('Y-m-d H:i:s');
        
        $result = $this->service->validateRow($data);
        $this->assertArrayNotHasKey('scheduled_at', $result->getErrors());
        
        // Test past date (invalid)
        $data['scheduled_at'] = now()->subDay()->format('Y-m-d H:i:s');
        
        $result = $this->service->validateRow($data);
        $this->assertArrayHasKey('scheduled_at', $result->getErrors());
    }
    
    public function test_generates_warnings_for_business_logic(): void
    {
        // Test scheduling too soon (less than 2 hours)
        $data = $this->getValidBaseData();
        $data['scheduled_at'] = now()->addMinutes(30)->format('Y-m-d H:i:s');
        
        $result = $this->service->validateRow($data);
        $this->assertTrue($result->hasErrors()); // Should be error due to min lead time
        $this->assertArrayHasKey('scheduled_at', $result->getErrors());
        
        // Test short address
        $data = $this->getValidBaseData();
        $data['pickup_address'] = 'Short addr';
        
        $result = $this->service->validateRow($data);
        $this->assertTrue($result->hasWarnings());
        $this->assertArrayHasKey('pickup_address', $result->getWarnings());
    }
    
    public function test_provides_helpful_suggestions(): void
    {
        $data = [
            'customer_name' => '',
            'customer_email' => 'invalid-email',
            'customer_phone' => '123',
            'pickup_address' => '123 Main St, City, State 12345',
            'dropoff_address' => '456 Oak Ave, City, State 67890'
        ];
        
        $result = $this->service->validateRow($data);
        $suggestions = $result->getSuggestions();
        
        $this->assertArrayHasKey('customer_name', $suggestions);
        $this->assertArrayHasKey('customer_email', $suggestions);
        $this->assertArrayHasKey('customer_phone', $suggestions);
        
        // Check suggestion content
        $this->assertStringContainsString('required', $suggestions['customer_name'][0]);
        $this->assertStringContainsString('valid email', $suggestions['customer_email'][0]);
        $this->assertStringContainsString('area code', $suggestions['customer_phone'][0]);
    }
    
    public function test_uses_template_validation_rules(): void
    {
        $template = [
            'validation_rules' => [
                'reference' => 'required|string',
                'quantity' => 'required|integer|min:5'
            ]
        ];
        
        $data = $this->getValidBaseData();
        // Don't include reference and quantity
        unset($data['reference']);
        unset($data['quantity']);
        
        $result = $this->service->validateRow($data, $template);
        
        $this->assertArrayHasKey('reference', $result->getErrors());
        $this->assertArrayHasKey('quantity', $result->getErrors());
    }
    
    public function test_validates_batch_efficiently(): void
    {
        $rows = [
            $this->getValidBaseData(),
            array_merge($this->getValidBaseData(), ['customer_name' => '']), // Invalid
            $this->getValidBaseData(),
            array_merge($this->getValidBaseData(), ['customer_email' => 'bad']), // Invalid
            $this->getValidBaseData()
        ];
        
        $results = $this->service->validateBatch($rows);
        
        $this->assertEquals(5, $results['stats']['total']);
        $this->assertEquals(3, $results['stats']['valid']);
        $this->assertEquals(2, $results['stats']['errors']);
        $this->assertTrue($results['has_errors']);
        
        // Check specific row errors
        $this->assertTrue($results['results'][0]['is_valid']);
        $this->assertFalse($results['results'][1]['is_valid']);
        $this->assertTrue($results['results'][2]['is_valid']);
        $this->assertFalse($results['results'][3]['is_valid']);
        $this->assertTrue($results['results'][4]['is_valid']);
    }
    
    public function test_stops_batch_validation_on_error_when_requested(): void
    {
        $rows = [
            $this->getValidBaseData(),
            array_merge($this->getValidBaseData(), ['customer_name' => '']), // Invalid
            $this->getValidBaseData(), // Should not be processed
            $this->getValidBaseData()  // Should not be processed
        ];
        
        $results = $this->service->validateBatch($rows, null, true); // stopOnError = true
        
        // Should only process first 2 rows
        $this->assertCount(2, $results['results']);
    }
    
    public function test_formats_errors_for_display(): void
    {
        $rows = [
            array_merge($this->getValidBaseData(), [
                'customer_name' => '',
                'customer_email' => 'invalid'
            ])
        ];
        
        $batchResults = $this->service->validateBatch($rows);
        $formatted = $this->service->formatValidationErrors($batchResults);
        
        $this->assertCount(1, $formatted);
        $this->assertEquals(2, $formatted[0]['row']); // Row 2 (header is row 1)
        $this->assertFalse($formatted[0]['can_import']);
        
        // Check messages structure
        $messages = $formatted[0]['messages'];
        $this->assertGreaterThanOrEqual(2, count($messages));
        $this->assertEquals('error', $messages[0]['type']);
        $this->assertContains($messages[0]['field'], ['customer_name', 'customer_email']);
    }
    
    public function test_provides_validation_summary(): void
    {
        $rows = [
            $this->getValidBaseData(),
            array_merge($this->getValidBaseData(), ['customer_name' => '']),
            array_merge($this->getValidBaseData(), ['customer_email' => 'bad']),
            $this->getValidBaseData()
        ];
        
        $batchResults = $this->service->validateBatch($rows);
        $summary = $this->service->getValidationSummary($batchResults);
        
        $this->assertEquals(4, $summary['total_rows']);
        $this->assertEquals(2, $summary['valid_rows']);
        $this->assertEquals(2, $summary['rows_with_errors']);
        $this->assertFalse($summary['can_proceed']);
        $this->assertEquals(2, $summary['import_ready_count']);
        $this->assertEquals(50.0, $summary['success_rate']);
        
        // Check common error fields
        $this->assertArrayHasKey('customer_name', $summary['common_error_fields']);
        $this->assertArrayHasKey('customer_email', $summary['common_error_fields']);
    }

    public function test_validates_address_completeness(): void
    {
        // Test incomplete address
        $data = $this->getValidBaseData();
        $data['pickup_address'] = '123'; // Too short, no street
        
        $result = $this->service->validateRow($data);
        $this->assertTrue($result->hasWarnings());
        $this->assertArrayHasKey('pickup_address', $result->getWarnings());
        
        // Test PO Box warning
        $data['pickup_address'] = 'PO Box 123, City, State';
        $result = $this->service->validateRow($data, ['validate_addresses' => true]);
        $this->assertTrue($result->hasWarnings());
    }

    public function test_validates_scheduling_business_logic(): void
    {
        // Test weekend scheduling
        $data = $this->getValidBaseData();
        $nextSaturday = now()->next(Carbon::SATURDAY)->setTime(10, 0, 0);
        $data['scheduled_at'] = $nextSaturday->format('Y-m-d H:i:s');
        
        $result = $this->service->validateRow($data);
        $this->assertTrue($result->hasWarnings());
        $this->assertArrayHasKey('scheduled_at', $result->getWarnings());
        
        // Test outside business hours
        $data['scheduled_at'] = now()->addDay()->setTime(20, 0, 0)->format('Y-m-d H:i:s'); // 8 PM
        $result = $this->service->validateRow($data);
        $this->assertTrue($result->hasWarnings());
    }

    public function test_validation_result_class(): void
    {
        // Test ValidationResult creation
        $result = ValidationResult::create();
        $this->assertTrue($result->isValid());
        
        // Test adding errors
        $result->addError('test_field', 'Test error message');
        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertEquals('error', $result->getSeverity());
        
        // Test adding warnings
        $result->addWarning('test_field', 'Test warning message');
        $this->assertTrue($result->hasWarnings());
        
        // Test adding suggestions
        $result->addSuggestion('test_field', 'Test suggestion');
        $this->assertTrue($result->hasSuggestions());
        
        // Test metadata
        $result->addMetadata('test_key', 'test_value');
        $this->assertEquals('test_value', $result->getMetadata()['test_key']);
    }

    public function test_validates_numeric_fields(): void
    {
        $data = $this->getValidBaseData();
        
        // Test valid numeric values
        $data['quantity'] = '5';
        $data['weight'] = '10.5';
        $result = $this->service->validateRow($data);
        
        $this->assertArrayNotHasKey('quantity', $result->getErrors());
        $this->assertArrayNotHasKey('weight', $result->getErrors());
        
        // Test invalid numeric values
        $data['quantity'] = '-1'; // Below minimum
        $data['weight'] = 'not a number';
        $result = $this->service->validateRow($data);
        
        $this->assertArrayHasKey('quantity', $result->getErrors());
        $this->assertArrayHasKey('weight', $result->getErrors());
    }

    public function test_validates_enum_fields(): void
    {
        $data = $this->getValidBaseData();
        
        // Test valid enum values
        $data['type'] = 'delivery';
        $data['priority'] = 'high';
        $result = $this->service->validateRow($data);
        
        $this->assertArrayNotHasKey('type', $result->getErrors());
        $this->assertArrayNotHasKey('priority', $result->getErrors());
        
        // Test invalid enum values
        $data['type'] = 'invalid_type';
        $data['priority'] = 'invalid_priority';
        $result = $this->service->validateRow($data);
        
        $this->assertArrayHasKey('type', $result->getErrors());
        $this->assertArrayHasKey('priority', $result->getErrors());
    }

    public function test_handles_template_with_custom_rules(): void
    {
        $template = [
            'validation_rules' => [
                'reference' => 'required|string|min:5',
                'notes' => 'required|string|min:10'
            ],
            'require_unique_reference' => true
        ];
        
        $data = $this->getValidBaseData();
        $data['reference'] = 'ABC'; // Too short
        $data['notes'] = 'Short'; // Too short
        
        $result = $this->service->validateRow($data, $template);
        
        $this->assertArrayHasKey('reference', $result->getErrors());
        $this->assertArrayHasKey('notes', $result->getErrors());
        $this->assertTrue($result->getMetadata()['reference_checked']);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $data = [
            'customer_name' => 'John Doe',
            'customer_phone' => '+1234567890',
            'pickup_address' => '123 Main St, City, State 12345',
            'dropoff_address' => '456 Oak Ave, City, State 67890'
            // Missing optional fields: email, scheduled_at, reference, notes, etc.
        ];
        
        $result = $this->service->validateRow($data);
        
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings()); // Should have warnings for missing recommended fields
    }

    public function test_batch_validation_statistics(): void
    {
        $rows = [
            $this->getValidBaseData(), // Valid
            $this->getValidBaseData(), // Valid
            array_merge($this->getValidBaseData(), ['customer_name' => '']), // Error
            array_merge($this->getValidBaseData(), ['pickup_address' => 'Short']), // Warning only
            array_merge($this->getValidBaseData(), ['customer_email' => 'invalid']) // Error
        ];
        
        $results = $this->service->validateBatch($rows);
        
        $this->assertEquals(5, $results['stats']['total']);
        $this->assertEquals(3, $results['stats']['valid']); // 3 valid (including warning-only)
        $this->assertEquals(2, $results['stats']['errors']); // 2 with errors
        $this->assertGreaterThanOrEqual(1, $results['stats']['warnings']); // At least 1 warning
        
        $this->assertTrue($results['has_errors']);
        $this->assertTrue($results['has_warnings']);
    }

    /**
     * Helper to get valid base data for testing
     * 
     * @return array
     */
    protected function getValidBaseData(): array
    {
        return [
            'customer_name' => 'John Doe',
            'customer_phone' => '+1234567890',
            'customer_email' => 'john@example.com',
            'pickup_address' => '123 Main St, City, State 12345',
            'dropoff_address' => '456 Oak Ave, City, State 67890',
            'reference' => 'REF-001',
            'notes' => 'Test order'
        ];
    }
}