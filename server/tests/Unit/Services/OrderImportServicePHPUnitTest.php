<?php

namespace Fleetbase\FleetOps\Tests\Unit\Services;

use Fleetbase\FleetOps\Services\OrderImportService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class OrderImportServicePHPUnitTest extends TestCase
{
    private OrderImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderImportService();
    }

    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(OrderImportService::class, $this->service);
    }

    public function test_rejects_unsupported_file_formats(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported file format: pdf');
        
        $this->service->parseFile($file);
    }

    public function test_processes_dry_run_correctly_for_valid_data(): void
    {
        $row = [
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com'
        ];
        
        $result = $this->service->processRowDryRunSimple($row, null);
        
        $this->assertArrayHasKey('original', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertEquals($row, $result['original']);
        $this->assertEquals('pending', $result['status']);
    }

    public function test_validates_required_fields_in_dry_run(): void
    {
        $row = [
            'customer_name' => '', // Missing required field
            'pickup' => '123 Main St',
        ];
        
        $result = $this->service->processRowDryRunSimple($row, null);
        
        $this->assertEquals('error', $result['status']);
        $this->assertNotEmpty($result['errors']);
        
        // Check that the error mentions customer name
        $errorMessages = array_column($result['errors'], 'message');
        $hasCustomerNameError = false;
        foreach ($errorMessages as $message) {
            if (strpos($message, 'Customer name') !== false) {
                $hasCustomerNameError = true;
                break;
            }
        }
        $this->assertTrue($hasCustomerNameError, 'Should have customer name validation error');
    }

    public function test_adds_warnings_for_missing_recommended_fields(): void
    {
        $row = [
            'customer_name' => 'John Doe',
            'pickup' => '123 Main St',
            // Missing customer_email (recommended)
        ];
        
        $result = $this->service->processRowDryRunSimple($row, null);
        
        $this->assertEquals('pending', $result['status']);
        $this->assertNotEmpty($result['warnings']);
        
        // Check that the warning mentions email
        $warningMessages = array_column($result['warnings'], 'message');
        $hasEmailWarning = false;
        foreach ($warningMessages as $message) {
            if (strpos($message, 'email') !== false) {
                $hasEmailWarning = true;
                break;
            }
        }
        $this->assertTrue($hasEmailWarning, 'Should have email recommendation warning');
    }

    public function test_handles_file_size_limits(): void
    {
        $file = UploadedFile::fake()->create('large.csv', 11000); // 11MB > 10MB limit
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File size exceeds limit');
        
        $this->service->parseFile($file);
    }

    /**
     * Test data provider for various file formats
     */
    public static function invalidFileFormatsProvider(): array
    {
        return [
            'pdf' => ['test.pdf'],
            'doc' => ['test.doc'],
            'exe' => ['test.exe'],
            'jpg' => ['test.jpg'],
        ];
    }

    /**
     * @dataProvider invalidFileFormatsProvider
     */
    public function test_rejects_various_invalid_file_types(string $filename): void
    {
        $file = UploadedFile::fake()->create($filename, 100);
        
        $this->expectException(\InvalidArgumentException::class);
        
        $this->service->parseFile($file);
    }
}