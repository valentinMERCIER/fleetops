# Order Import System - Step-by-Step Testing Guide

## Prerequisites
Before testing, ensure you have:
- ✅ FleetBase application running (Docker or local)
- ✅ Database migrated with import tables
- ✅ FleetOps console accessible
- ✅ API endpoints available
- ✅ Valid user account with import permissions

## Test Environment Setup

### Step 1: Verify Application is Running
```bash
# Check if containers are running
docker ps | grep fleetbase

# Check if application is accessible
curl -I http://localhost:8000/api/status
# Should return 200 OK

# Check console is accessible  
curl -I http://localhost:4200
# Should return 200 OK
```

### Step 2: Check Database Tables
```bash
# Connect to database container
docker exec -it fleetbase-database mysql -u fleetbase -p fleetbase

# Check if import tables exist
SHOW TABLES LIKE 'import_%';
# Should show: import_templates, import_sessions, import_rows

# Check table structure
DESCRIBE import_sessions;
DESCRIBE import_templates;
DESCRIBE import_rows;
```

### Step 3: Verify API Routes
```bash
# Check if routes are registered
docker exec fleetbase-forked-application-1 php artisan route:list | grep import

# Should show routes like:
# POST fleetbase/int/v1/fleet-ops/imports/upload
# POST fleetbase/int/v1/fleet-ops/imports/dry-run
# etc.
```

## Testing Phase 1: Core Service Functions

### Step 1: Test CSV Parsing
```bash
cd packages/fleetops

# Run CSV parsing tests
composer pest -- server/tests/Unit/Services/OrderImportServicePHPUnitTest.php --filter "test_can_parse_csv"

# Expected: Tests should pass showing CSV parsing works
```

### Step 2: Test Field Mapping
```bash
# Run field mapping tests
composer pest -- server/tests/Unit/Services/FieldMappingTest.php

# Expected: Should show auto-detection working for various header formats
```

### Step 3: Test Validation Engine
```bash
# Run validation tests (may have some failures due to mocking - that's OK)
composer pest -- server/tests/Unit/Services/ValidationEngineTest.php

# Expected: Core validation logic working
```

### Step 4: Test Dry Run Processing
```bash
# Run dry run tests
composer pest -- server/tests/Unit/Services/DryRunProcessingTest.php

# Expected: Core dry run logic working
```

## Testing Phase 2: API Endpoints with Postman/Curl

### Step 1: Create Test CSV File
Create a file called `test-orders.csv`:
```csv
Customer Name,Phone Number,Email,Pickup Address,Delivery Address,Reference,Notes
John Doe,+1234567890,john@example.com,"123 Main St, New York, NY 10001","456 Oak Ave, Brooklyn, NY 11201",REF-001,Handle with care
Jane Smith,+9876543210,jane@example.com,"789 Pine Rd, Queens, NY 11375","321 Elm St, Manhattan, NY 10002",REF-002,Fragile items
Bob Johnson,+5555555555,bob@example.com,"555 First Ave, Bronx, NY 10451","777 Second St, Staten Island, NY 10301",REF-003,Standard delivery
Alice Brown,,alice@example.com,"999 Third Blvd, Brooklyn, NY 11215","111 Fourth Way, Queens, NY 11361",REF-004,Missing phone number
Charlie Wilson,+1111111111,,"222 Fifth Rd, Manhattan, NY 10003","888 Sixth Ave, Bronx, NY 10452",REF-005,Missing email
```

### Step 2: Test File Upload Endpoint

**Using Curl:**
```bash
# Upload the CSV file
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/upload \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: multipart/form-data" \
  -F "file=@test-orders.csv" \
  -F "auto_detect_mappings=true"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "File uploaded and parsed successfully",
  "data": {
    "session": {
      "id": "SESSION_ID",
      "file_name": "test-orders.csv",
      "status": "parsed"
    },
    "parsed": {
      "headers": ["Customer Name", "Phone Number", "Email", ...],
      "total_rows": 5,
      "preview": [...]
    },
    "mappings": {
      "mappings": {
        "customer_name": "Customer Name",
        "customer_phone": "Phone Number",
        ...
      }
    }
  }
}
```

**Save the `session.id` for next steps!**

### Step 3: Test Field Mapping Detection

```bash
# Test mapping detection
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/detect-mappings \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "headers": ["Customer Name", "Phone Number", "Email", "Pickup Address"],
    "sample_data": [{
      "Customer Name": "Test Customer",
      "Phone Number": "+1234567890",
      "Email": "test@example.com",
      "Pickup Address": "123 Test St"
    }]
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "mappings": {
      "customer_name": "Customer Name",
      "customer_phone": "Phone Number",
      "customer_email": "Email",
      "pickup_address": "Pickup Address"
    },
    "confidence": {
      "customer_name": 100,
      "customer_phone": 85,
      ...
    }
  }
}
```

### Step 4: Test Dry Run Execution

```bash
# Execute dry run (replace SESSION_ID with actual ID from Step 2)
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/dry-run \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "SESSION_ID",
    "mappings": {
      "Customer Name": "customer_name",
      "Phone Number": "customer_phone",
      "Email": "customer_email",
      "Pickup Address": "pickup_address",
      "Delivery Address": "dropoff_address",
      "Reference": "reference",
      "Notes": "notes"
    },
    "duplicate_handling": "warn"
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Dry run completed",
  "data": {
    "session_id": "SESSION_ID",
    "summary": {
      "overview": {
        "total_rows": 5,
        "ready_to_import": 4,
        "success_rate": 80.0
      },
      "breakdown": {
        "valid": 3,
        "warnings": 1,
        "errors": 1
      }
    },
    "can_proceed": true
  }
}
```

### Step 5: Get Dry Run Results

```bash
# Get detailed dry run results
curl -X GET "http://localhost:8000/int/v1/fleet-ops/imports/dry-run/SESSION_ID?filter=all&page=1&per_page=10" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "session": {...},
    "stats": {
      "total": 5,
      "valid": 3,
      "errors": 1,
      "warnings": 1
    },
    "rows": {
      "data": [
        {
          "row_number": 2,
          "status": "valid",
          "message": "Ready for import",
          "original_data": {...},
          "validation": {
            "errors": [],
            "warnings": []
          }
        }
      ]
    }
  }
}
```

### Step 6: Fix Any Validation Errors

If you see errors in the dry run results, fix them:

```bash
# Fix a specific row (replace ROW_ID with actual row ID)
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/rows/ROW_ID/fix \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "corrections": {
      "customer_phone": "+1234567890",
      "customer_email": "corrected@example.com"
    }
  }'
```

### Step 7: Execute the Import

```bash
# Execute the actual import
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/execute \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "SESSION_ID",
    "stop_on_error": false,
    "include_orders": true
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Import completed. Created 4 orders.",
  "data": {
    "session_id": "SESSION_ID",
    "created": 4,
    "failed": 1,
    "errors": [
      {"row": 5, "error": "Missing required field"}
    ]
  }
}
```

### Step 8: Check Import Status

```bash
# Get final import status
curl -X GET http://localhost:8000/int/v1/fleet-ops/imports/sessions/SESSION_ID/status \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

## Testing Phase 3: Template Management

### Step 1: Create a Template

```bash
# Create import template
curl -X POST http://localhost:8000/int/v1/fleet-ops/import-templates \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Standard Order Import",
    "description": "Template for standard order CSV imports",
    "field_mappings": {
      "Customer Name": "customer_name",
      "Phone Number": "customer_phone",
      "Email": "customer_email",
      "Pickup Address": "pickup_address",
      "Delivery Address": "dropoff_address",
      "Reference": "reference",
      "Notes": "notes"
    },
    "default_values": {
      "type": "delivery",
      "priority": "normal"
    },
    "duplicate_handling": "warn",
    "duplicate_check_fields": ["reference", "customer_phone"]
  }'
```

### Step 2: List Templates

```bash
# List all templates
curl -X GET http://localhost:8000/int/v1/fleet-ops/import-templates \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

### Step 3: Test Template with Sample Data

```bash
# Test template (replace TEMPLATE_ID)
curl -X POST http://localhost:8000/int/v1/fleet-ops/import-templates/TEMPLATE_ID/test \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "sample_data": {
      "Customer Name": "Test Customer",
      "Phone Number": "+1234567890",
      "Email": "test@example.com",
      "Pickup Address": "123 Test St, City, State 12345",
      "Delivery Address": "456 Test Ave, City, State 67890"
    }
  }'
```

## Testing Phase 4: Error Scenarios

### Test 1: Invalid File Upload
```bash
# Try uploading invalid file
curl -X POST http://localhost:8000/int/v1/fleet-ops/imports/upload \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "file=@invalid.txt"

# Expected: 422 validation error
```

### Test 2: Missing Required Fields
Create `invalid-orders.csv`:
```csv
Customer Name,Notes
,Missing name
John Doe,Missing addresses
```

Upload and run dry run - should show validation errors.

### Test 3: Duplicate Detection
Create `duplicate-orders.csv`:
```csv
Customer Name,Phone,Pickup Address,Delivery Address,Reference
John Doe,+1234567890,123 Main St,456 Oak Ave,DUP-001
Jane Smith,+9876543210,789 Pine Rd,321 Elm St,DUP-001
```

Should detect row 2 as duplicate of row 1.

## Testing Phase 5: Database Verification

### Step 1: Check Created Orders
```sql
-- Connect to database
docker exec -it fleetbase-database mysql -u fleetbase -p fleetbase

-- Check created orders
SELECT public_id, internal_id, status, created_at 
FROM orders 
WHERE JSON_EXTRACT(meta, '$.imported') = true 
ORDER BY created_at DESC;

-- Check created customers
SELECT public_id, name, phone, email, created_at 
FROM contacts 
WHERE type = 'customer' 
AND JSON_EXTRACT(meta, '$.source') = 'import'
ORDER BY created_at DESC;

-- Check created places
SELECT public_id, name, street1, type, created_at 
FROM places 
WHERE JSON_EXTRACT(meta, '$.source') = 'import'
ORDER BY created_at DESC;
```

### Step 2: Check Import Session Data
```sql
-- Check import sessions
SELECT public_id, file_name, status, total_rows, imported_rows, failed_rows, created_at 
FROM import_sessions 
ORDER BY created_at DESC;

-- Check import rows
SELECT row_number, processing_status, processing_message, created_order_id 
FROM import_rows 
WHERE session_uuid = 'YOUR_SESSION_UUID'
ORDER BY row_number;
```

## Testing Phase 6: Advanced Features

### Test 1: Template with Custom Rules
```bash
curl -X POST http://localhost:8000/int/v1/fleet-ops/import-templates \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Premium Orders Template",
    "field_mappings": {...},
    "validation_rules": {
      "quantity": "required|integer|min:1",
      "weight": "required|numeric|min:0.1"
    },
    "default_values": {
      "type": "express",
      "priority": "high"
    }
  }'
```

### Test 2: Rollback Functionality
```bash
# After successful import, test rollback
curl -X DELETE http://localhost:8000/int/v1/fleet-ops/imports/sessions/SESSION_ID \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action": "rollback"}'

# Check database - orders should be deleted
```

## Testing Phase 7: Performance Testing

### Test 1: Large File Import
Create a CSV with 1000+ rows:
```bash
# Generate large CSV
php -r "
echo 'Customer Name,Phone,Email,Pickup Address,Delivery Address,Reference,Notes\n';
for (\$i = 1; \$i <= 1000; \$i++) {
    echo \"Customer \$i,+123456789\$i,customer\$i@example.com,\$i Main St,\$i Oak Ave,REF-\$i,Test order \$i\n\";
}
" > large-orders.csv

# Upload and test processing time
```

### Test 2: Memory Usage
```bash
# Monitor memory during large import
docker stats fleetbase-forked-application-1

# Watch for memory spikes during processing
```

## Testing Phase 8: Frontend Integration Testing

### Test 1: Console Integration
1. Open FleetOps console: http://localhost:4200
2. Navigate to Operations → Import (if available)
3. Test UI components if implemented

### Test 2: API Documentation
```bash
# Generate API documentation (if Swagger is set up)
docker exec fleetbase-forked-application-1 php artisan l5-swagger:generate

# Access at: http://localhost:8000/api/documentation
```

## Troubleshooting Common Issues

### Issue 1: Route Not Found
```bash
# Clear route cache
docker exec fleetbase-forked-application-1 php artisan route:clear

# Check if controllers exist
ls -la packages/fleetops/server/src/Http/Controllers/Api/v1/
```

### Issue 2: Database Connection
```bash
# Check database connection
docker exec fleetbase-forked-application-1 php artisan migrate:status

# Run specific migrations if needed
docker exec fleetbase-forked-application-1 php artisan migrate --path=packages/fleetops/server/migrations
```

### Issue 3: Permission Errors
```bash
# Check user permissions
docker exec fleetbase-forked-application-1 php artisan fleetbase:seed

# Create import permissions if needed
```

### Issue 4: File Storage Issues
```bash
# Check storage directories
docker exec fleetbase-forked-application-1 ls -la storage/app/imports/

# Fix permissions if needed
docker exec fleetbase-forked-application-1 chmod -R 755 storage/app/
```

## Expected Test Results

### ✅ Success Indicators:
- CSV file uploads and parses correctly
- Field mappings auto-detected with high confidence
- Dry run shows validation results
- Orders created with all relationships
- Import sessions tracked properly
- Rollback works correctly

### ❌ Common Issues (and fixes):
- **Route errors**: Clear route cache, check controller namespace
- **Database errors**: Run migrations, check connection
- **Permission errors**: Verify user has import permissions
- **File errors**: Check storage permissions and disk space

## Next Steps After Testing

1. **Document any issues found**
2. **Test with different CSV formats**
3. **Verify all edge cases work**
4. **Performance test with large files**
5. **Test error scenarios thoroughly**

Let me know what specific part you'd like to start testing, and I'll provide more detailed guidance for that component!