<?php

// PEST Bootstrap for FleetOps Tests
require_once __DIR__ . '/../../server_vendor/autoload.php';

// Initialize PEST TestSuite to register our test functions
$testSuite = \Pest\TestSuite::getInstance(
    dirname(__DIR__, 2), // Project root
    'server/tests'       // Test directory
);

// Mock Laravel facades since we're not in full Laravel context
if (!class_exists('Log')) {
    class Log
    {
        public static function info($message, $context = []) {}
        public static function warning($message, $context = []) {}
        public static function error($message, $context = []) {}
        public static function debug($message, $context = []) {}
    }
}

// Mock Laravel Validator facade
if (!class_exists('Validator')) {
    class Validator
    {
        public static function make($data, $rules, $messages = [])
        {
            return new MockValidator($data, $rules, $messages);
        }
    }
}

// Mock Validator class
class MockValidator
{
    protected $data;
    protected $rules;
    protected $messages;
    protected $errors = [];
    
    public function __construct($data, $rules, $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
        $this->validate();
    }
    
    protected function validate()
    {
        foreach ($this->rules as $field => $rule) {
            $this->validateField($field, $rule);
        }
    }
    
    protected function validateField($field, $rule)
    {
        $value = $this->data[$field] ?? null;
        $rules = explode('|', $rule);
        
        foreach ($rules as $singleRule) {
            if ($singleRule === 'required' && empty($value)) {
                $this->errors[$field][] = "The $field field is required.";
            } elseif (str_starts_with($singleRule, 'min:') && is_string($value)) {
                $min = (int) substr($singleRule, 4);
                if (strlen($value) < $min) {
                    $this->errors[$field][] = "The $field must be at least $min characters.";
                }
            } elseif ($singleRule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field][] = "The $field must be a valid email address.";
            }
        }
    }
    
    public function fails()
    {
        return !empty($this->errors);
    }
    
    public function errors()
    {
        return new MockMessageBag($this->errors);
    }
    
    public function getData()
    {
        return $this->data;
    }
}

class MockMessageBag
{
    protected $messages;
    
    public function __construct($messages)
    {
        $this->messages = $messages;
    }
    
    public function toArray()
    {
        return $this->messages;
    }
}

// Mock Laravel helper functions
if (!function_exists('now')) {
    function now() {
        return \Carbon\Carbon::now();
    }
}

if (!function_exists('session')) {
    function session($key = null) {
        if ($key === 'company') {
            return 'test-company-uuid';
        }
        return null;
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