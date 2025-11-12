<?php

namespace Fleetbase\FleetOps\Services;

use Fleetbase\FleetOps\Models\ImportTemplate;
use Fleetbase\FleetOps\Models\ImportSession;
use Fleetbase\FleetOps\Models\ImportRow;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Customer;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Models\File;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use League\Csv\Reader;
use League\Csv\Statement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Exception;

/**
 * Service for handling order imports from CSV, Excel, and JSON files.
 * 
 * This service provides comprehensive import functionality including:
 * - File parsing (CSV, Excel, JSON)
 * - Field mapping and transformation
 * - Data validation with detailed error reporting
 * - Dry-run processing for preview/validation
 * - Order creation with customer management
 * - Duplicate detection and handling
 * 
 * @package Fleetbase\FleetOps\Services
 */
class OrderImportService
{
    /**
     * Supported file formats for import
     */
    protected array $supportedFormats = ['csv', 'xlsx', 'xls', 'json'];

    /**
     * Maximum number of rows to process in a single batch
     */
    protected int $batchSize = 100;

    /**
     * Maximum file size in bytes (50MB)
     */
    protected int $maxFileSize = 52428800;

    /**
     * Common field mapping patterns for auto-detection
     */
    protected array $commonMappings = [
        // Customer fields
        'customer_name' => ['customer', 'client', 'name', 'customer_name', 'client_name', 'contact_name'],
        'customer_phone' => ['phone', 'telephone', 'mobile', 'contact', 'customer_phone', 'contact_phone'],
        'customer_email' => ['email', 'e-mail', 'mail', 'customer_email', 'contact_email'],
        
        // Address fields
        'pickup_address' => ['pickup', 'origin', 'from', 'pickup_address', 'collection', 'pickup_location'],
        'dropoff_address' => ['dropoff', 'destination', 'to', 'delivery', 'dropoff_address', 'delivery_address'],
        
        // Order details
        'scheduled_at' => ['scheduled', 'date', 'time', 'scheduled_at', 'delivery_date', 'pickup_date'],
        'order_number' => ['order', 'reference', 'order_id', 'order_number', 'ref', 'tracking'],
        'notes' => ['notes', 'comments', 'instructions', 'description', 'remarks'],
        
        // Package details
        'package_weight' => ['weight', 'package_weight', 'item_weight', 'kg', 'pounds'],
        'package_dimensions' => ['dimensions', 'size', 'package_size', 'length_width_height'],
        'package_value' => ['value', 'package_value', 'item_value', 'declared_value', 'price'],
        
        // Service type
        'service_type' => ['service', 'service_type', 'delivery_type', 'shipping_type'],
    ];

    /**
     * Date formats to try when parsing date fields
     */
    protected array $dateFormats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'm/d/Y',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
        'd-m-Y',
        'Y/m/d H:i:s',
        'Y/m/d H:i',
        'Y/m/d',
    ];

    // ============================================
    // FILE PARSING METHODS (Day 4 - Task 1.4.1-4)
    // ============================================

    /**
     * Parse uploaded file and return structured data
     * 
     * @param UploadedFile $file The uploaded file to parse
     * @return array ['headers' => array, 'rows' => array, 'total' => int]
     * @throws Exception If file format is not supported or parsing fails
     */
    public function parseFile(UploadedFile $file): array
    {
        // Validate file size
        if ($file->getSize() > $this->maxFileSize) {
            throw new Exception("File size exceeds maximum allowed size of " . ($this->maxFileSize / 1024 / 1024) . "MB");
        }

        // Get file extension
        $extension = strtolower($file->getClientOriginalExtension());
        
        // Validate file format
        if (!in_array($extension, $this->supportedFormats)) {
            throw new Exception("Unsupported file format: {$extension}. Supported formats: " . implode(', ', $this->supportedFormats));
        }

        Log::info("Parsing file", ['filename' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'extension' => $extension]);

        // Route to appropriate parser based on file type
        return match($extension) {
            'csv' => $this->parseCsv($file),
            'xlsx', 'xls' => $this->parseExcel($file),
            'json' => $this->parseJson($file),
            default => throw new Exception("Parser not implemented for file type: {$extension}")
        };
    }

    /**
     * Parse CSV file with automatic delimiter detection and encoding handling
     * 
     * @param UploadedFile $file
     * @return array
     * @throws Exception
     */
    protected function parseCsv(UploadedFile $file): array
    {
        try {
            // Create CSV reader from uploaded file
            $csv = Reader::createFromPath($file->getPathname(), 'r');
            
            // Detect and set delimiter
            $delimiters = [',', ';', "\t", '|'];
            $delimiter = $this->detectCsvDelimiter($file->getPathname(), $delimiters);
            $csv->setDelimiter($delimiter);
            
            // Detect encoding and handle if needed
            $fileContent = file_get_contents($file->getPathname());
            $encoding = mb_detect_encoding($fileContent, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            
            // Convert to UTF-8 if necessary
            if ($encoding && $encoding !== 'UTF-8') {
                $convertedContent = mb_convert_encoding($fileContent, 'UTF-8', $encoding);
                $tempFile = tempnam(sys_get_temp_dir(), 'csv_import_');
                file_put_contents($tempFile, $convertedContent);
                $csv = Reader::createFromPath($tempFile, 'r');
                $csv->setDelimiter($delimiter);
            }
            
            // Set header offset (assume first row is header)
            $csv->setHeaderOffset(0);
            
            // Get headers
            $headers = $csv->getHeader();
            
            // Clean up headers
            $headers = array_map('trim', $headers);
            
            // Get records with row processing
            $records = [];
            $rowCount = 0;
            
            foreach ($csv->getRecords() as $offset => $record) {
                // Clean up the record data
                $cleanRecord = array_map('trim', $record);
                
                $records[] = $cleanRecord;
                $rowCount++;
                
                // Limit for large files to prevent memory issues
                if ($rowCount >= 10000) {
                    Log::warning("CSV parsing limited to 10,000 rows for memory efficiency", [
                        'filename' => $file->getClientOriginalName(),
                        'total_processed' => $rowCount
                    ]);
                    break;
                }
            }
            
            // Clean up temporary file if created
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            Log::info("CSV parsed successfully", [
                'filename' => $file->getClientOriginalName(),
                'headers' => count($headers),
                'rows' => count($records),
                'delimiter' => $delimiter,
                'encoding' => $encoding ?: 'UTF-8'
            ]);
            
            return [
                'headers' => $headers,
                'rows' => $records,
                'total' => count($records),
                'delimiter' => $delimiter,
                'encoding' => $encoding ?: 'UTF-8'
            ];
            
        } catch (Exception $e) {
            Log::error("CSV parsing failed", [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to parse CSV file: " . $e->getMessage());
        }
    }

    /**
     * Parse Excel file (xlsx, xls) with multiple sheet support
     * 
     * @param UploadedFile $file
     * @return array
     * @throws Exception
     */
    protected function parseExcel(UploadedFile $file): array
    {
        // TODO: Implement Excel parsing using PhpSpreadsheet
        // Handle multiple sheets, date cells
        // Convert to array format
        
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Get all data as array
            $data = $worksheet->toArray();
            
            if (empty($data)) {
                throw new Exception("Excel file appears to be empty");
            }
            
            // First row is headers
            $headers = array_shift($data);
            $headers = array_map('trim', $headers);
            
            $rows = [];
            $rowNumber = 1;
            
            foreach ($data as $row) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Combine headers with row data
                $rowData = array_combine($headers, $row);
                
                // Convert Excel date values to proper dates
                foreach ($rowData as $key => $value) {
                    if (Date::isDateTime($worksheet->getCell($this->getColumnLetter(array_search($key, $headers) + 1) . ($rowNumber + 1)))) {
                        $rowData[$key] = Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
                    }
                }
                
                $rows[] = [
                    'row_number' => $rowNumber++,
                    'data' => $rowData
                ];
            }
            
            Log::info("Excel parsed successfully", ['headers' => count($headers), 'rows' => count($rows)]);
            
            return [
                'headers' => $headers,
                'rows' => $rows,
                'total' => count($rows)
            ];
            
        } catch (Exception $e) {
            Log::error("Excel parsing failed", ['error' => $e->getMessage()]);
            throw new Exception("Failed to parse Excel file: " . $e->getMessage());
        }
    }

    /**
     * Parse JSON file with nested structure support
     * 
     * @param UploadedFile $file
     * @return array
     * @throws Exception
     */
    protected function parseJson(UploadedFile $file): array
    {
        // TODO: Implement JSON parsing
        // Handle nested structures
        // Validate and convert to standard format
        
        try {
            $content = file_get_contents($file->getRealPath());
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON: " . json_last_error_msg());
            }
            
            // Handle different JSON structures
            if (isset($data['orders']) && is_array($data['orders'])) {
                // Format: {"orders": [...]}
                $orders = $data['orders'];
            } elseif (is_array($data) && isset($data[0]) && is_array($data[0])) {
                // Format: [{...}, {...}, ...]
                $orders = $data;
            } else {
                throw new Exception("Unsupported JSON structure. Expected array of orders or {orders: [...]} format");
            }
            
            // Extract headers from first record
            $headers = !empty($orders) ? array_keys($this->flattenArray($orders[0])) : [];
            
            $rows = [];
            $rowNumber = 1;
            
            foreach ($orders as $order) {
                $flattened = $this->flattenArray($order);
                
                $rows[] = [
                    'row_number' => $rowNumber++,
                    'data' => $flattened
                ];
            }
            
            Log::info("JSON parsed successfully", ['headers' => count($headers), 'rows' => count($rows)]);
            
            return [
                'headers' => $headers,
                'rows' => $rows,
                'total' => count($rows)
            ];
            
        } catch (Exception $e) {
            Log::error("JSON parsing failed", ['error' => $e->getMessage()]);
            throw new Exception("Failed to parse JSON file: " . $e->getMessage());
        }
    }

    // =============================================
    // FIELD MAPPING METHODS (Day 5 - Task 1.5.1-3)
    // =============================================

    /**
     * Map row fields according to template mappings and apply transformations
     * 
     * @param array $row Raw row data from file
     * @param ImportTemplate $template Import template with field mappings
     * @return array Mapped and transformed data
     */
    public function mapFields(array $row, ImportTemplate $template): array
    {
        // TODO: Implement field mapping logic
        // Apply template field_mappings
        // Apply default values
        // Return mapped array
        
        $mappedData = [];
        $fieldMappings = $template->field_mappings ?? [];
        $defaultValues = $template->default_values ?? [];
        
        // Apply field mappings
        foreach ($fieldMappings as $csvColumn => $modelField) {
            if (isset($row[$csvColumn])) {
                $value = $row[$csvColumn];
                $mappedData[$modelField] = $this->transformValue($value, $modelField, $template);
            }
        }
        
        // Apply default values for missing fields
        foreach ($defaultValues as $field => $value) {
            if (!isset($mappedData[$field]) || $mappedData[$field] === null || $mappedData[$field] === '') {
                $mappedData[$field] = $value;
            }
        }
        
        // Ensure company UUID is set
        if (!isset($mappedData['company_uuid'])) {
            $mappedData['company_uuid'] = $template->company_uuid;
        }
        
        return $mappedData;
    }

    /**
     * Auto-detect field mappings from headers using pattern matching
     * 
     * @param array $headers Column headers from file
     * @return array Suggested mappings with confidence scores
     */
    public function detectFieldMappings(array $headers): array
    {
        // TODO: Implement auto-detection algorithm
        // Use $commonMappings patterns
        // Return confidence scores
        
        $suggestions = [];
        
        foreach ($headers as $header) {
            $headerLower = strtolower(trim($header));
            $bestMatch = null;
            $bestScore = 0;
            
            foreach ($this->commonMappings as $field => $patterns) {
                foreach ($patterns as $pattern) {
                    $score = $this->calculateSimilarity($headerLower, $pattern);
                    
                    if ($score > $bestScore && $score > 0.7) { // 70% similarity threshold
                        $bestScore = $score;
                        $bestMatch = $field;
                    }
                }
            }
            
            if ($bestMatch) {
                $suggestions[$header] = [
                    'field' => $bestMatch,
                    'confidence' => round($bestScore * 100, 2)
                ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Transform field value based on type and format specifications
     * 
     * @param mixed $value Raw value from file
     * @param string $field Target field name
     * @param ImportTemplate $template Import template
     * @return mixed Transformed value
     */
    protected function transformValue($value, string $field, ImportTemplate $template)
    {
        // TODO: Implement data transformation
        // Handle dates, phone numbers, etc.
        // Apply template-specific formats
        
        if ($value === null || $value === '') {
            return null;
        }
        
        // Apply field-specific transformations
        switch ($field) {
            case 'scheduled_at':
            case 'pickup_date':
            case 'delivery_date':
                return $this->parseDateField($value);
                
            case 'customer_phone':
            case 'contact_phone':
                return $this->normalizePhoneNumber($value);
                
            case 'customer_email':
            case 'contact_email':
                return strtolower(trim($value));
                
            case 'package_weight':
                return $this->normalizeWeight($value);
                
            case 'package_value':
                return $this->normalizePrice($value);
                
            default:
                return is_string($value) ? trim($value) : $value;
        }
    }

    /**
     * Parse date field with multiple format support
     * 
     * @param string $value Date string
     * @param string|null $format Specific format to try first
     * @return Carbon|null Parsed date or null if parsing fails
     */
    protected function parseDateField(string $value, ?string $format = null): ?Carbon
    {
        // TODO: Implement date parsing
        // Handle multiple formats
        // Return Carbon instance or null
        
        if (empty(trim($value))) {
            return null;
        }
        
        $value = trim($value);
        
        // Try specific format first if provided
        if ($format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (Exception $e) {
                // Fall through to try other formats
            }
        }
        
        // Try common date formats
        foreach ($this->dateFormats as $dateFormat) {
            try {
                return Carbon::createFromFormat($dateFormat, $value);
            } catch (Exception $e) {
                continue;
            }
        }
        
        // Try Carbon's flexible parser as last resort
        try {
            return Carbon::parse($value);
        } catch (Exception $e) {
            Log::warning("Failed to parse date", ['value' => $value]);
            return null;
        }
    }

    // ==========================================
    // VALIDATION METHODS (Day 6 - Task 1.6.1-3)
    // ==========================================

    /**
     * Validate a single row of mapped data against template rules
     * 
     * @param array $row Mapped row data
     * @param ImportTemplate $template Import template with validation rules
     * @return ValidationResult Validation result with errors and warnings
     */
    public function validateRow(array $row, ImportTemplate $template): ValidationResult
    {
        // TODO: Implement row validation
        // Apply validation rules
        // Return ValidationResult object
        
        $rules = $template->getValidationRules();
        $validator = Validator::make($row, $rules);
        
        $result = new ValidationResult($validator);
        
        // Add custom validations
        $this->addCustomValidations($result, $row, $template);
        
        return $result;
    }

    /**
     * Validate batch of rows with progress tracking
     * 
     * @param array $rows Array of rows to validate
     * @param ImportTemplate $template Import template
     * @return array Array of validation results
     */
    public function validateBatch(array $rows, ImportTemplate $template): array
    {
        // TODO: Implement batch validation
        // Track progress
        // Return array of errors
        
        $results = [];
        $totalRows = count($rows);
        
        foreach ($rows as $index => $row) {
            $mappedRow = $this->mapFields($row['data'], $template);
            $validation = $this->validateRow($mappedRow, $template);
            
            $results[] = [
                'row_number' => $row['row_number'],
                'line_number' => $index + 2, // +1 for 0-based index, +1 for header row
                'validation' => $validation,
                'mapped_data' => $mappedRow
            ];
            
            // Log progress for large batches
            if ($totalRows > 100 && ($index + 1) % 50 === 0) {
                Log::info("Validation progress", ['completed' => $index + 1, 'total' => $totalRows]);
            }
        }
        
        return $results;
    }

    /**
     * Format validation errors for user-friendly display
     * 
     * @param array $errors Raw validation errors
     * @return array Formatted errors grouped by type
     */
    public function formatErrors(array $errors): array
    {
        // TODO: Format errors for user-friendly display
        // Group by type
        // Include row numbers
        
        $formatted = [
            'critical' => [],
            'error' => [],
            'warning' => [],
            'info' => []
        ];
        
        foreach ($errors as $error) {
            $severity = $error['severity'] ?? 'error';
            $formatted[$severity][] = [
                'row' => $error['row_number'] ?? null,
                'field' => $error['field'] ?? null,
                'message' => $error['message'] ?? 'Unknown error',
                'code' => $error['code'] ?? 'validation_error'
            ];
        }
        
        return $formatted;
    }

    // =============================================
    // DRY RUN METHODS (New requirement)
    // =============================================

    /**
     * Process row in dry-run mode without creating orders
     * 
     * @param array $row Raw row data
     * @param ImportTemplate $template Import template
     * @return array Detailed processing results
     */
    public function processRowDryRun(array $row, ImportTemplate $template): array
    {
        // TODO: Implement dry run processing
        // Map fields, validate, check duplicates
        // Return detailed results without persisting
        
        try {
            // Map fields
            $mappedData = $this->mapFields($row, $template);
            
            // Normalize data
            $normalizedData = $this->normalizeData($mappedData, $template);
            
            // Validate
            $validation = $this->validateRow($normalizedData, $template);
            
            // Check for duplicates
            $duplicate = $this->checkDuplicate($normalizedData, $template);
            
            // Determine status
            $status = 'pending';
            $errors = [];
            $warnings = [];
            $severity = null;
            
            if ($validation->hasErrors()) {
                $status = 'validation_failed';
                $errors = $validation->getErrors();
                $severity = 'error';
            } elseif ($duplicate) {
                $status = 'duplicate';
                $warnings[] = [
                    'field' => 'duplicate',
                    'message' => "Duplicate order found: {$duplicate->public_id}",
                    'code' => 'duplicate_detected'
                ];
                $severity = 'warning';
            }
            
            if ($validation->hasWarnings()) {
                $warnings = array_merge($warnings, $validation->getWarnings());
                if (!$severity) {
                    $severity = 'warning';
                }
            }
            
            // Generate suggestions for errors
            $suggestions = $this->generateFixSuggestions($normalizedData, $validation);
            
            return [
                'original' => $row,
                'mapped' => $mappedData,
                'normalized' => $normalizedData,
                'status' => $status,
                'errors' => $errors,
                'warnings' => $warnings,
                'severity' => $severity,
                'is_resolvable' => $status !== 'validation_failed' || !empty($suggestions),
                'suggestions' => $suggestions,
                'duplicate_order' => $duplicate ? $duplicate->public_id : null
            ];
            
        } catch (Exception $e) {
            Log::error("Dry run processing failed", ['row' => $row, 'error' => $e->getMessage()]);
            
            return [
                'original' => $row,
                'mapped' => null,
                'normalized' => null,
                'status' => 'failed',
                'errors' => [['field' => 'processing', 'message' => $e->getMessage(), 'code' => 'processing_error']],
                'warnings' => [],
                'severity' => 'critical',
                'is_resolvable' => false,
                'suggestions' => []
            ];
        }
    }

    /**
     * Generate intelligent fix suggestions for validation errors
     * 
     * @param array $data Row data with errors
     * @param ValidationResult $validation Validation result
     * @return array Array of suggested fixes
     */
    protected function generateFixSuggestions(array $data, ValidationResult $validation): array
    {
        // TODO: Implement smart suggestions
        // Based on error type, suggest fixes
        // Use AI/rules for suggestions
        
        $suggestions = [];
        $errors = $validation->getErrors();
        
        foreach ($errors as $error) {
            $field = $error['field'] ?? '';
            $message = $error['message'] ?? '';
            
            switch ($error['code'] ?? '') {
                case 'required':
                    $suggestions[] = [
                        'field' => $field,
                        'suggestion' => "Field '{$field}' is required. Please provide a value.",
                        'suggested_value' => $this->getSuggestedDefaultValue($field)
                    ];
                    break;
                    
                case 'email':
                    $suggestions[] = [
                        'field' => $field,
                        'suggestion' => "Invalid email format. Please check the email address.",
                        'suggested_value' => $this->suggestEmailFix($data[$field] ?? '')
                    ];
                    break;
                    
                case 'date':
                    $suggestions[] = [
                        'field' => $field,
                        'suggestion' => "Invalid date format. Try formats like: Y-m-d or d/m/Y",
                        'suggested_value' => null
                    ];
                    break;
                    
                default:
                    $suggestions[] = [
                        'field' => $field,
                        'suggestion' => "Please review and correct the value for '{$field}'",
                        'suggested_value' => null
                    ];
            }
        }
        
        return $suggestions;
    }

    /**
     * Generate dry run summary statistics from processing results
     * 
     * @param array $results Array of dry run results
     * @return array Summary statistics
     */
    public function generateDryRunSummary(array $results): array
    {
        // TODO: Calculate summary stats
        // Count success/warning/error
        // Group by error type
        
        $summary = [
            'total_rows' => count($results),
            'estimated_success_count' => 0,
            'estimated_warning_count' => 0,
            'estimated_error_count' => 0,
            'duplicate_count' => 0,
            'resolvable_errors' => 0,
            'error_breakdown' => [],
            'warning_breakdown' => []
        ];
        
        foreach ($results as $result) {
            switch ($result['status']) {
                case 'pending':
                    $summary['estimated_success_count']++;
                    break;
                case 'duplicate':
                    $summary['duplicate_count']++;
                    $summary['estimated_warning_count']++;
                    break;
                case 'validation_failed':
                case 'failed':
                    $summary['estimated_error_count']++;
                    if ($result['is_resolvable']) {
                        $summary['resolvable_errors']++;
                    }
                    break;
            }
            
            // Count error types
            foreach ($result['errors'] as $error) {
                $code = $error['code'] ?? 'unknown';
                $summary['error_breakdown'][$code] = ($summary['error_breakdown'][$code] ?? 0) + 1;
            }
            
            // Count warning types
            foreach ($result['warnings'] as $warning) {
                $code = $warning['code'] ?? 'unknown';
                $summary['warning_breakdown'][$code] = ($summary['warning_breakdown'][$code] ?? 0) + 1;
            }
        }
        
        return $summary;
    }

    // ============================================
    // ORDER CREATION METHODS (Day 7 - Task 1.7.1-3)
    // ============================================

    /**
     * Create order from validated and mapped row data
     * 
     * @param array $mappedData Validated and mapped order data
     * @return Order Created order instance
     * @throws Exception If order creation fails
     */
    public function createOrderFromRow(array $mappedData): Order
    {
        // TODO: Implement order creation
        // Extract customer data
        // Create/find customer
        // Build and create order
        
        return DB::transaction(function () use ($mappedData) {
            // Extract and create/find customer
            $customerData = $this->extractCustomerData($mappedData);
            $customer = $this->findOrCreateCustomer($customerData);
            
            // Process addresses
            $pickupPlace = null;
            $dropoffPlace = null;
            
            if (isset($mappedData['pickup_address'])) {
                $pickupPlace = $this->processAddress([
                    'address' => $mappedData['pickup_address'],
                    'type' => 'pickup'
                ]);
            }
            
            if (isset($mappedData['dropoff_address'])) {
                $dropoffPlace = $this->processAddress([
                    'address' => $mappedData['dropoff_address'],
                    'type' => 'dropoff'
                ]);
            }
            
            // Build order data
            $orderData = [
                'company_uuid' => $mappedData['company_uuid'],
                'customer_uuid' => $customer->uuid,
                'pickup_uuid' => $pickupPlace ? $pickupPlace->uuid : null,
                'dropoff_uuid' => $dropoffPlace ? $dropoffPlace->uuid : null,
                'scheduled_at' => $mappedData['scheduled_at'] ?? null,
                'notes' => $mappedData['notes'] ?? null,
                'meta' => []
            ];
            
            // Add package information if available
            if (isset($mappedData['package_weight']) || isset($mappedData['package_value'])) {
                $orderData['meta']['package'] = [
                    'weight' => $mappedData['package_weight'] ?? null,
                    'value' => $mappedData['package_value'] ?? null,
                    'dimensions' => $mappedData['package_dimensions'] ?? null
                ];
            }
            
            // Create the order
            $order = Order::create($orderData);
            
            Log::info("Order created from import", ['order_id' => $order->public_id, 'customer' => $customer->name]);
            
            return $order;
        });
    }

    /**
     * Find existing customer or create new one from customer data
     * 
     * @param array $customerData Customer information
     * @return Customer Customer instance
     */
    protected function findOrCreateCustomer(array $customerData): Customer
    {
        // TODO: Implement customer management
        // Check for duplicates
        // Create if not exists
        // Update if needed
        
        $companyUuid = $customerData['company_uuid'];
        
        // Try to find existing customer by email or phone
        $existingCustomer = null;
        
        if (!empty($customerData['email'])) {
            $existingCustomer = Customer::where('company_uuid', $companyUuid)
                ->where('email', $customerData['email'])
                ->first();
        }
        
        if (!$existingCustomer && !empty($customerData['phone'])) {
            $existingCustomer = Customer::where('company_uuid', $companyUuid)
                ->where('phone', $customerData['phone'])
                ->first();
        }
        
        if ($existingCustomer) {
            // Update existing customer with any new information
            $existingCustomer->update(array_filter($customerData));
            return $existingCustomer;
        }
        
        // Create new customer
        return Customer::create($customerData);
    }

    /**
     * Extract customer-related data from mapped row
     * 
     * @param array $mappedData Complete mapped row data
     * @return array Customer-specific data
     */
    protected function extractCustomerData(array $mappedData): array
    {
        // TODO: Extract customer fields
        // Handle nested data
        // Return customer array
        
        return [
            'company_uuid' => $mappedData['company_uuid'],
            'name' => $mappedData['customer_name'] ?? null,
            'phone' => $mappedData['customer_phone'] ?? null,
            'email' => $mappedData['customer_email'] ?? null,
            'type' => 'customer'
        ];
    }

    /**
     * Process address string and create place record with geocoding
     * 
     * @param array $addressData Address information
     * @return Place|null Created place or null if processing fails
     */
    protected function processAddress(array $addressData): ?Place
    {
        // TODO: Process address
        // Geocode if needed
        // Create place record
        
        if (empty($addressData['address'])) {
            return null;
        }
        
        try {
            $placeData = [
                'name' => $addressData['type'] === 'pickup' ? 'Pickup Location' : 'Delivery Location',
                'street1' => $addressData['address'],
                'type' => $addressData['type']
            ];
            
            // TODO: Implement geocoding service integration
            // For now, create basic place without coordinates
            
            return Place::create($placeData);
            
        } catch (Exception $e) {
            Log::error("Failed to process address", ['address' => $addressData, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ============================================
    // DUPLICATE DETECTION METHODS
    // ============================================

    /**
     * Check if order is duplicate based on template configuration
     * 
     * @param array $mappedData Mapped order data
     * @param ImportTemplate $template Import template with duplicate rules
     * @return Order|null Existing order if duplicate found
     */
    protected function checkDuplicate(array $mappedData, ImportTemplate $template): ?Order
    {
        // TODO: Check for duplicate orders
        // Use template's duplicate_check_fields
        // Return existing order if found
        
        $duplicateFields = $template->getDuplicateCheckFields();
        
        if (empty($duplicateFields)) {
            return null;
        }
        
        $query = Order::where('company_uuid', $mappedData['company_uuid']);
        
        foreach ($duplicateFields as $field) {
            if (isset($mappedData[$field]) && $mappedData[$field] !== null) {
                $query->where($field, $mappedData[$field]);
            }
        }
        
        return $query->first();
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Normalize and clean data after mapping
     * 
     * @param array $mapped Mapped data
     * @param ImportTemplate $template Import template
     * @return array Normalized data
     */
    protected function normalizeData(array $mapped, ImportTemplate $template): array
    {
        // TODO: Apply final transformations
        // Clean phone numbers, format dates
        // Return normalized data
        
        $normalized = $mapped;
        
        // Normalize specific fields
        foreach ($normalized as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            
            switch ($field) {
                case 'customer_phone':
                case 'contact_phone':
                    $normalized[$field] = $this->normalizePhoneNumber($value);
                    break;
                    
                case 'customer_email':
                case 'contact_email':
                    $normalized[$field] = strtolower(trim($value));
                    break;
                    
                case 'customer_name':
                    $normalized[$field] = ucwords(strtolower(trim($value)));
                    break;
            }
        }
        
        return $normalized;
    }

    /**
     * Process complete import session with all rows
     * 
     * @param ImportSession $session Import session to process
     * @return void
     * @throws Exception If processing fails
     */
    public function processImportSession(ImportSession $session): void
    {
        // TODO: Main processing method
        // Parse file, validate, create orders
        // Update session status and progress
        
        try {
            $session->markAsStarted();
            
            $template = $session->template;
            $file = $session->file;
            
            if (!$template || !$file) {
                throw new Exception("Session missing template or file");
            }
            
            // Parse the file
            $parsedData = $this->parseFile(new UploadedFile(
                $file->path,
                $file->original_filename,
                $file->content_type,
                null,
                true
            ));
            
            // Update session with total rows
            $session->update(['total_rows' => $parsedData['total']]);
            
            // Process rows in batches
            $rows = $parsedData['rows'];
            $batches = array_chunk($rows, $this->batchSize);
            
            foreach ($batches as $batch) {
                foreach ($batch as $row) {
                    $importRow = ImportRow::create([
                        'session_uuid' => $session->uuid,
                        'row_number' => $row['row_number'],
                        'line_number' => $row['row_number'] + 1, // +1 for header
                        'original_data' => $row['data'],
                        'status' => 'pending'
                    ]);
                    
                    $this->processImportRow($importRow, $template);
                }
                
                // Update session progress
                $session->updateProcessedCount();
                $session->updateFailedCount();
            }
            
            $session->markAsCompleted();
            
        } catch (Exception $e) {
            Log::error("Import session failed", ['session' => $session->uuid, 'error' => $e->getMessage()]);
            $session->markAsFailed(['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Process individual import row
     * 
     * @param ImportRow $row Import row to process
     * @param ImportTemplate $template Import template
     * @return void
     */
    protected function processImportRow(ImportRow $row, ImportTemplate $template): void
    {
        // TODO: Process individual import row
        // Map, validate, create order
        // Update row status
        
        try {
            $row->update(['status' => 'processing']);
            
            // Map fields
            $mappedData = $this->mapFields($row->original_data, $template);
            $row->update(['mapped_data' => $mappedData]);
            
            // Normalize data
            $normalizedData = $this->normalizeData($mappedData, $template);
            $row->update(['normalized_data' => $normalizedData]);
            
            // Validate
            $validation = $this->validateRow($normalizedData, $template);
            
            if ($validation->hasErrors()) {
                $row->update([
                    'status' => 'validation_failed',
                    'errors' => $validation->getErrors(),
                    'error_severity' => 'error'
                ]);
                return;
            }
            
            // Check for duplicates
            $duplicate = $this->checkDuplicate($normalizedData, $template);
            
            if ($duplicate) {
                if ($template->shouldSkipDuplicates()) {
                    $row->update([
                        'status' => 'skipped',
                        'warnings' => [['field' => 'duplicate', 'message' => 'Duplicate order skipped']]
                    ]);
                    return;
                }
                // TODO: Implement other duplicate strategies (update, merge, create_new)
            }
            
            // Create order
            $order = $this->createOrderFromRow($normalizedData);
            
            $row->update([
                'status' => 'processed',
                'order_uuid' => $order->uuid,
                'warnings' => $validation->hasWarnings() ? $validation->getWarnings() : null
            ]);
            
        } catch (Exception $e) {
            Log::error("Failed to process import row", ['row' => $row->uuid, 'error' => $e->getMessage()]);
            
            $row->update([
                'status' => 'failed',
                'errors' => [['field' => 'processing', 'message' => $e->getMessage()]],
                'error_severity' => 'critical'
            ]);
        }
    }

    /**
     * Detect CSV delimiter from file content by analyzing the first few lines
     * 
     * @param string $filepath Path to the CSV file
     * @param array $delimiters Array of possible delimiters to test
     * @return string The most likely delimiter
     */
    protected function detectCsvDelimiter(string $filepath, array $delimiters = [',', ';', "\t", '|']): string
    {
        $handle = fopen($filepath, 'r');
        
        // Read first few lines for better detection
        $lines = [];
        for ($i = 0; $i < 3 && !feof($handle); $i++) {
            $line = fgets($handle);
            if ($line !== false) {
                $lines[] = trim($line);
            }
        }
        fclose($handle);
        
        if (empty($lines)) {
            return ','; // Default to comma if can't read file
        }
        
        $counts = [];
        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = 0;
            
            // Count delimiter occurrences across all sample lines
            foreach ($lines as $line) {
                $counts[$delimiter] += substr_count($line, $delimiter);
            }
            
            // Average across lines for consistency check
            if (count($lines) > 1) {
                $counts[$delimiter] = $counts[$delimiter] / count($lines);
            }
        }
        
        // Find delimiter with highest average count
        $maxCount = max($counts);
        
        // If no delimiters found, default to comma
        if ($maxCount == 0) {
            Log::warning("No delimiters detected in CSV, defaulting to comma");
            return ',';
        }
        
        // Return the delimiter with the highest count
        $detectedDelimiter = array_search($maxCount, $counts);
        
        Log::debug("CSV delimiter detection", [
            'detected' => $detectedDelimiter === "\t" ? 'TAB' : $detectedDelimiter,
            'counts' => array_map(function($d, $c) {
                return ['delimiter' => $d === "\t" ? 'TAB' : $d, 'count' => $c];
            }, array_keys($counts), $counts)
        ]);
        
        return $detectedDelimiter ?: ',';
    }

    /**
     * Convert column index to Excel column letter
     * 
     * @param int $index Column index (1-based)
     * @return string Column letter(s)
     */
    protected function getColumnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26);
        }
        return $letter;
    }

    /**
     * Flatten nested array with dot notation keys
     * 
     * @param array $array Nested array
     * @param string $prefix Key prefix
     * @return array Flattened array
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '.' . $key;
            
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Calculate similarity between two strings
     * 
     * @param string $str1
     * @param string $str2
     * @return float Similarity score between 0 and 1
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        // Use Levenshtein distance for similarity calculation
        $maxLength = max(strlen($str1), strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLength);
    }

    /**
     * Normalize phone number to consistent format
     * 
     * @param string $phone Raw phone number
     * @return string Normalized phone number
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $digits = preg_replace('/[^\d]/', '', $phone);
        
        // Basic formatting (can be enhanced based on requirements)
        if (strlen($digits) >= 10) {
            return $digits;
        }
        
        return $phone; // Return original if can't normalize
    }

    /**
     * Normalize weight value to consistent unit (kg)
     * 
     * @param string|float $weight Raw weight value
     * @return float|null Normalized weight in kg
     */
    protected function normalizeWeight($weight): ?float
    {
        if (is_numeric($weight)) {
            return (float) $weight;
        }
        
        if (is_string($weight)) {
            // Extract numeric value and unit
            preg_match('/^([\d.]+)\s*(.*)$/', trim($weight), $matches);
            
            if (count($matches) >= 2) {
                $value = (float) $matches[1];
                $unit = strtolower(trim($matches[2] ?? ''));
                
                // Convert to kg
                switch ($unit) {
                    case 'lb':
                    case 'lbs':
                    case 'pounds':
                        return $value * 0.453592;
                    case 'g':
                    case 'grams':
                        return $value / 1000;
                    case 'kg':
                    case 'kgs':
                    case 'kilograms':
                    case '':
                    default:
                        return $value;
                }
            }
        }
        
        return null;
    }

    /**
     * Normalize price value to float
     * 
     * @param string|float $price Raw price value
     * @return float|null Normalized price
     */
    protected function normalizePrice($price): ?float
    {
        if (is_numeric($price)) {
            return (float) $price;
        }
        
        if (is_string($price)) {
            // Remove currency symbols and normalize
            $cleaned = preg_replace('/[^\d.]/', '', $price);
            return is_numeric($cleaned) ? (float) $cleaned : null;
        }
        
        return null;
    }

    /**
     * Get suggested default value for required field
     * 
     * @param string $field Field name
     * @return mixed Suggested default value
     */
    protected function getSuggestedDefaultValue(string $field)
    {
        $defaults = [
            'customer_name' => 'Unknown Customer',
            'service_type' => 'standard',
            'status' => 'pending',
            'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s')
        ];
        
        return $defaults[$field] ?? null;
    }

    /**
     * Suggest fix for malformed email
     * 
     * @param string $email Malformed email
     * @return string|null Suggested corrected email
     */
    protected function suggestEmailFix(string $email): ?string
    {
        // Simple fixes for common email mistakes
        $fixes = [
            'gmail.co' => 'gmail.com',
            'gmial.com' => 'gmail.com',
            'yahoo.co' => 'yahoo.com',
            'hotmail.co' => 'hotmail.com'
        ];
        
        foreach ($fixes as $wrong => $correct) {
            if (str_contains($email, $wrong)) {
                return str_replace($wrong, $correct, $email);
            }
        }
        
        return null;
    }

    /**
     * Add custom validation rules beyond standard Laravel validation
     * 
     * @param ValidationResult $result Validation result to modify
     * @param array $row Row data
     * @param ImportTemplate $template Import template
     * @return void
     */
    protected function addCustomValidations(ValidationResult $result, array $row, ImportTemplate $template): void
    {
        // TODO: Add custom validations specific to orders
        // Check address validity, customer data consistency, etc.
        
        // Example: Validate that at least one address is provided
        if (empty($row['pickup_address']) && empty($row['dropoff_address'])) {
            $result->addError('addresses', 'At least one pickup or delivery address is required', 'missing_address');
        }
        
        // Example: Validate customer contact information
        if (empty($row['customer_phone']) && empty($row['customer_email'])) {
            $result->addWarning('contact', 'No customer contact information provided', 'missing_contact');
        }
    }
}

/**
 * Value object for validation results
 */
class ValidationResult
{
    protected array $errors = [];
    protected array $warnings = [];
    protected bool $valid;

    /**
     * Create validation result from Laravel validator or manual data
     * 
     * @param \Illuminate\Contracts\Validation\Validator|null $validator
     */
    public function __construct($validator = null)
    {
        if ($validator) {
            $this->valid = !$validator->fails();
            
            foreach ($validator->errors()->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->errors[] = [
                        'field' => $field,
                        'message' => $message,
                        'code' => 'validation_error'
                    ];
                }
            }
        } else {
            $this->valid = true;
        }
    }

    /**
     * Check if validation passed
     * 
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid && empty($this->errors);
    }

    /**
     * Check if there are validation errors
     * 
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if there are validation warnings
     * 
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get all validation errors
     * 
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all validation warnings
     * 
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Add a validation error
     * 
     * @param string $field
     * @param string $message
     * @param string $code
     * @return void
     */
    public function addError(string $field, string $message, string $code = 'validation_error'): void
    {
        $this->errors[] = [
            'field' => $field,
            'message' => $message,
            'code' => $code
        ];
        $this->valid = false;
    }

    /**
     * Add a validation warning
     * 
     * @param string $field
     * @param string $message
     * @param string $code
     * @return void
     */
    public function addWarning(string $field, string $message, string $code = 'validation_warning'): void
    {
        $this->warnings[] = [
            'field' => $field,
            'message' => $message,
            'code' => $code
        ];
    }
}