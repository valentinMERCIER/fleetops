<?php

namespace Fleetbase\FleetOps\Tests\Unit\Services;

use Fleetbase\FleetOps\Services\OrderImportService;
use Fleetbase\FleetOps\Models\ImportTemplate;
use PHPUnit\Framework\TestCase;

class FieldMappingTest extends TestCase
{
    protected OrderImportService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderImportService();
    }
    
    public function test_can_auto_detect_common_field_mappings(): void
    {
        $headers = [
            'Customer Name',
            'Phone Number',
            'Email Address',
            'Pickup Location',
            'Delivery Address',
            'Order Reference',
            'Special Instructions'
        ];
        
        $result = $this->service->detectFieldMappings($headers);
        
        $this->assertEquals('Customer Name', $result['mappings']['customer_name']);
        $this->assertEquals('Phone Number', $result['mappings']['customer_phone']);
        $this->assertEquals('Email Address', $result['mappings']['customer_email']);
        $this->assertEquals('Pickup Location', $result['mappings']['pickup_address']);
        $this->assertEquals('Delivery Address', $result['mappings']['dropoff_address']);
        $this->assertEquals('Order Reference', $result['mappings']['reference']);
        $this->assertEquals('Special Instructions', $result['mappings']['notes']);
        
        $this->assertGreaterThan(80, $result['confidence']['customer_name']);
        $this->assertEmpty($result['unmapped']);
    }
    
    public function test_handles_various_header_formats(): void
    {
        $testCases = [
            'customer_name' => ['customer', 'Customer', 'CUSTOMER', 'customer-name', 'customer_name', 'Customer Name'],
            'customer_phone' => ['phone', 'Phone', 'telephone', 'mobile', 'Phone Number', 'phone-number'],
            'pickup_address' => ['pickup', 'Pickup', 'pickup-address', 'pickup_location', 'Pickup Address'],
        ];
        
        foreach ($testCases as $expectedField => $variations) {
            foreach ($variations as $header) {
                $result = $this->service->detectFieldMappings([$header]);
                
                $this->assertArrayHasKey(
                    $expectedField,
                    $result['mappings'],
                    "Failed to detect '$header' as '$expectedField'"
                );
            }
        }
    }
    
    public function test_maps_row_data_with_auto_detection(): void
    {
        $row = [
            'Customer' => 'John Doe',
            'Phone' => '+1234567890',
            'Email' => 'john@example.com',
            'Pickup Address' => '123 Main St',
            'Delivery Address' => '456 Oak Ave',
            'Notes' => 'Handle with care'
        ];
        
        $result = $this->service->mapFields($row);
        
        $this->assertEquals('John Doe', $result['customer_name']);
        $this->assertEquals('+1234567890', $result['customer_phone']);
        $this->assertEquals('john@example.com', $result['customer_email']);
        $this->assertEquals('123 Main St', $result['pickup_address']);
        $this->assertEquals('456 Oak Ave', $result['dropoff_address']);
        $this->assertEquals('Handle with care', $result['notes']);
        $this->assertTrue($result['_import_metadata']['auto_detected']);
    }
    
    public function test_uses_template_mappings_when_provided(): void
    {
        // Create a mock template object with required properties
        $template = (object) [
            'field_mappings' => [
                'Name' => 'customer_name',
                'Contact' => 'customer_phone',
                'From' => 'pickup_address',
                'To' => 'dropoff_address'
            ],
            'default_values' => [
                'notes' => 'Standard delivery'
            ],
            'name' => 'Test Template'
        ];
        
        $row = [
            'Name' => 'Jane Smith',
            'Contact' => '9876543210',
            'From' => '789 Pine St',
            'To' => '321 Elm St',
            'Other Field' => 'Ignored'
        ];
        
        $result = $this->service->mapFields($row, $template);
        
        $this->assertEquals('Jane Smith', $result['customer_name']);
        $this->assertEquals('9876543210', $result['customer_phone']);
        $this->assertEquals('789 Pine St', $result['pickup_address']);
        $this->assertEquals('321 Elm St', $result['dropoff_address']);
        $this->assertEquals('Standard delivery', $result['notes']);
        $this->assertArrayNotHasKey('Other Field', $result);
        $this->assertFalse($result['_import_metadata']['auto_detected']);
    }
    
    public function test_transforms_date_fields(): void
    {
        $testDates = [
            '2024-12-25 14:30:00' => '2024-12-25 14:30:00',
            '2024-12-25' => '2024-12-25 00:00:00'
        ];
        
        foreach ($testDates as $input => $expected) {
            $row = ['scheduled_at' => $input];
            $result = $this->service->mapFields($row);
            
            $this->assertEquals(
                $expected,
                $result['scheduled_at'],
                "Failed to parse date: $input"
            );
        }
    }
    
    public function test_normalizes_phone_numbers(): void
    {
        $testPhones = [
            '+1 (234) 567-8900' => '+12345678900',
            '234-567-8900' => '2345678900',
            '+44 20 1234 5678' => '+442012345678',
            '(555) 123-4567' => '5551234567'
        ];
        
        foreach ($testPhones as $input => $expected) {
            $row = ['phone' => $input];
            $result = $this->service->mapFields($row);
            
            $this->assertEquals(
                $expected,
                $result['customer_phone'],
                "Failed to normalize phone: $input"
            );
        }
    }
    
    public function test_validates_email_addresses(): void
    {
        $validEmails = [
            'test@example.com' => 'test@example.com',
            'USER@EXAMPLE.COM' => 'user@example.com',
            ' john.doe@company.org ' => 'john.doe@company.org'
        ];
        
        foreach ($validEmails as $input => $expected) {
            $row = ['email' => $input];
            $result = $this->service->mapFields($row);
            
            $this->assertEquals(
                $expected,
                $result['customer_email'],
                "Failed to normalize email: $input"
            );
        }
        
        // Test invalid emails
        $invalidEmails = ['not-an-email', 'missing@', '@example.com'];
        
        foreach ($invalidEmails as $invalid) {
            $row = ['email' => $invalid];
            $result = $this->service->mapFields($row);
            
            $this->assertNull(
                $result['customer_email'] ?? null,
                "Should reject invalid email: $invalid"
            );
        }
    }
    
    public function test_processes_batch_efficiently(): void
    {
        $rows = [
            ['Customer' => 'John', 'Phone' => '1234567890'],
            ['Customer' => 'Jane', 'Phone' => '0987654321'],
            ['Customer' => 'Bob', 'Phone' => '5555555555']
        ];
        
        $results = $this->service->mapBatch($rows);
        
        $this->assertCount(3, $results);
        $this->assertEquals('John', $results[0]['mapped_data']['customer_name']);
        $this->assertEquals('Jane', $results[1]['mapped_data']['customer_name']);
        $this->assertEquals('Bob', $results[2]['mapped_data']['customer_name']);
        $this->assertTrue($results[0]['auto_detected']);
    }

    public function test_header_normalization(): void
    {
        $testCases = [
            'Customer Name' => 'customername',
            'phone-number' => 'phonenumber',
            'Email_Address' => 'emailaddress',
            'PICKUP LOCATION' => 'pickuplocation',
            'delivery.address' => 'deliveryaddress'
        ];
        
        foreach ($testCases as $input => $expected) {
            $reflection = new \ReflectionClass($this->service);
            $method = $reflection->getMethod('normalizeHeaderName');
            $method->setAccessible(true);
            
            $result = $method->invoke($this->service, $input);
            $this->assertEquals($expected, $result, "Failed to normalize header: $input");
        }
    }

    public function test_confidence_scoring(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateMatchScore');
        $method->setAccessible(true);
        
        // Test exact match (should be 100%)
        $score = $method->invoke($this->service, 'customer', 'customer');
        $this->assertEquals(100, $score);
        
        // Test partial match (should be 85%)
        $score = $method->invoke($this->service, 'customername', 'customer');
        $this->assertEquals(85, $score);
        
        // Test no match (should be 0)
        $score = $method->invoke($this->service, 'randomtext', 'customer');
        $this->assertEquals(0, $score);
    }

    public function test_transforms_weight_values(): void
    {
        $testWeights = [
            '10.5' => 10.5,
            '23 kg' => 23.0,
            '15.5 lbs' => 15.5,
            'not a number' => null
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseWeight');
        $method->setAccessible(true);
        
        foreach ($testWeights as $input => $expected) {
            $result = $method->invoke($this->service, $input);
            $this->assertEquals($expected, $result, "Failed to parse weight: $input");
        }
    }

    public function test_transforms_numeric_values(): void
    {
        $testNumbers = [
            '10' => 10,
            '25 items' => 25,
            'abc123def' => 123,
            'no numbers' => null
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseNumericValue');
        $method->setAccessible(true);
        
        foreach ($testNumbers as $input => $expected) {
            $result = $method->invoke($this->service, $input);
            $this->assertEquals($expected, $result, "Failed to parse number: $input");
        }
    }

    public function test_normalizes_names(): void
    {
        $testNames = [
            'john doe' => 'John Doe',
            'JANE SMITH' => 'Jane Smith',
            'bob o\'brien' => 'Bob O\'brien',
            '  alice  johnson  ' => 'Alice Johnson'
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeName');
        $method->setAccessible(true);
        
        foreach ($testNames as $input => $expected) {
            $result = $method->invoke($this->service, $input);
            $this->assertEquals($expected, $result, "Failed to normalize name: $input");
        }
    }

    public function test_normalizes_addresses(): void
    {
        $testAddresses = [
            "123  Main   St" => "123 Main St",
            "456\nOak\tAve" => "456 Oak Ave",
            "  789 Pine Rd  " => "789 Pine Rd"
        ];
        
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeAddress');
        $method->setAccessible(true);
        
        foreach ($testAddresses as $input => $expected) {
            $result = $method->invoke($this->service, $input);
            $this->assertEquals($expected, $result, "Failed to normalize address: $input");
        }
    }

    public function test_handles_generic_customer_field_mapping(): void
    {
        // Test the specific issue: {"customer":"Customer"} should map to customer_name
        $template = (object) [
            'field_mappings' => [
                'customer' => 'Customer'  // This is the user's mapping
            ]
        ];
        
        $row = [
            'Type' => 'Transport',
            'Customer' => 'test customer',
            'Pick Up' => 'Pickup Location',
            'Drop Off' => 'Dropoff Location'
        ];
        
        $result = $this->service->mapFields($row, $template);
        
        // The generic "customer" field should be automatically mapped to "customer_name"
        $this->assertArrayHasKey('customer_name', $result, 'Generic "customer" field should be mapped to "customer_name"');
        $this->assertEquals('test customer', $result['customer_name']);
        
        // Should not have the generic "customer" field in the result
        $this->assertArrayNotHasKey('customer', $result, 'Generic "customer" field should be converted, not kept');
    }
    
    public function test_customer_field_mapping_with_existing_customer_fields(): void
    {
        // Test that generic "customer" field is only converted when no specific customer fields exist
        $template = (object) [
            'field_mappings' => [
                'customer' => 'Customer',
                'customer_email' => 'Email'
            ]
        ];
        
        $row = [
            'Customer' => 'test customer',
            'Email' => 'test@example.com'
        ];
        
        $result = $this->service->mapFields($row, $template);
        
        // Since customer_email exists, the generic "customer" should still be converted to customer_name
        $this->assertArrayHasKey('customer_name', $result);
        $this->assertEquals('test customer', $result['customer_name']);
        $this->assertArrayHasKey('customer_email', $result);
        $this->assertEquals('test@example.com', $result['customer_email']);
    }
    
    public function test_auto_detection_maps_customer_to_customer_name(): void
    {
        // Test auto-detection for "Customer" header
        $row = [
            'Type' => 'Transport',
            'Customer' => 'val test',
            'Pickup Name' => 'Pick Up Location',
            'Dropoff Name' => 'Drop Off Location'
        ];
        
        $result = $this->service->mapFields($row);  // No template = auto-detection
        
        // Auto-detection should map "Customer" to "customer_name" automatically
        $this->assertArrayHasKey('customer_name', $result);
        $this->assertEquals('val test', $result['customer_name']);
        $this->assertTrue($result['_import_metadata']['auto_detected']);
    }
}