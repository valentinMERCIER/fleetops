<?php

/**
 * Import Sessions Schema Validation Script
 * 
 * Validates that all required import_sessions table columns and constraints exist.
 * Run this script after applying import-related migrations to ensure schema consistency.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "🔍 Validating import_sessions table schema...\n\n";

// Check table exists
if (!Schema::hasTable('import_sessions')) {
    echo "❌ ERROR: import_sessions table does not exist\n";
    exit(1);
}

// Required columns from all three migrations
$requiredColumns = [
    // Base table (Migration 1)
    'uuid', 'public_id', 'company_uuid', 'template_uuid', 'file_uuid', 'name', 'status',
    'is_dry_run', 'dry_run_summary', 'estimated_success_count', 'estimated_warning_count',
    'estimated_error_count', 'total_rows', 'processed_rows', 'failed_rows', 'errors',
    'meta', 'started_at', 'completed_at', 'created_at', 'updated_at', 'deleted_at',
    
    // Additional columns (Migration 3)  
    'file_path', 'file_name', 'file_type', 'field_mappings', 'parsed_at',
    'dry_run_completed_at', 'import_started_at', 'cancelled_at', 'imported_rows',
    'valid_rows', 'warning_rows', 'duplicate_rows'
];

// Check all columns exist
$missingColumns = [];
foreach ($requiredColumns as $column) {
    if (!Schema::hasColumn('import_sessions', $column)) {
        $missingColumns[] = $column;
    }
}

if (!empty($missingColumns)) {
    echo "❌ ERROR: Missing columns: " . implode(', ', $missingColumns) . "\n";
    exit(1);
}

// Check ENUM values (Migration 2)
$enumQuery = "SHOW COLUMNS FROM import_sessions WHERE Field = 'status'";
$statusColumn = DB::select($enumQuery)[0];
$enumValues = $statusColumn->Type;

$requiredEnumValues = [
    'created', 'uploading', 'validating', 'mapping', 'processing', 
    'completed', 'failed', 'cancelled', 'ready', 'has_errors', 'has_warnings'
];

$missingEnumValues = [];
foreach ($requiredEnumValues as $value) {
    if (strpos($enumValues, "'$value'") === false) {
        $missingEnumValues[] = $value;
    }
}

if (!empty($missingEnumValues)) {
    echo "❌ ERROR: Missing ENUM values: " . implode(', ', $missingEnumValues) . "\n";
    exit(1);
}

echo "✅ SUCCESS: All import_sessions schema validations passed!\n";
echo "📊 Table has " . count($requiredColumns) . " columns\n";
echo "📊 Status ENUM has " . count($requiredEnumValues) . " values\n";
echo "🎉 Import system schema is complete and ready!\n";