<?php

// PEST Bootstrap for FleetOps Tests
require_once __DIR__ . '/../../server_vendor/autoload.php';

// Initialize PEST TestSuite to register our test functions
$testSuite = \Pest\TestSuite::getInstance(
    dirname(__DIR__, 2), // Project root
    'server/tests'       // Test directory
);

// Mock Laravel Log facade since we're not in full Laravel context
if (!class_exists('Log')) {
    class Log
    {
        public static function info($message, $context = []) {}
        public static function warning($message, $context = []) {}
        public static function error($message, $context = []) {}
        public static function debug($message, $context = []) {}
    }
}

// Helper functions for tests
function createTestCsvFile(string $content, string $filename = 'test.csv'): \Illuminate\Http\UploadedFile
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_csv_');
    file_put_contents($tmpFile, $content);
    
    return new \Illuminate\Http\UploadedFile(
        $tmpFile,
        $filename,
        'text/csv',
        null,
        true // test mode
    );
}

function generateCsvContent(int $rows = 10): string
{
    $csv = "customer_name,customer_email,pickup,dropoff\n";
    for ($i = 1; $i <= $rows; $i++) {
        $csv .= "Customer $i,customer$i@example.com,\"Address $i\",\"Destination $i\"\n";
    }
    return $csv;
}