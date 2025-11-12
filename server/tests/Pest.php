<?php

// Basic Pest configuration without Laravel dependencies

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(PHPUnit\Framework\TestCase::class)->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

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