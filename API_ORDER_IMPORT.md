# Order Import API Documentation

## Overview

The Order Import API provides a comprehensive system for bulk importing orders from CSV, Excel, or JSON files. The API follows a multi-step workflow:

1. **Upload** - Upload and parse the import file
2. **Mapping** - Auto-detect or manually configure field mappings
3. **Dry Run** - Preview and validate import results without creating orders
4. **Execute** - Perform the actual import to create orders
5. **Monitor** - Track progress and review results

## Base URL

All endpoints are prefixed with: `/int/v1/orders/import-sessions`

> **Note**: These are internal API routes requiring authentication via `fleetbase.protected` middleware.

## Authentication

All requests require:
- Valid authentication session
- `company_uuid` in session context
- Appropriate permissions for order management

---

## Endpoints

### 1. Upload Import File

Upload and parse a file to create a new import session.

**Endpoint:** `POST /int/v1/orders/import-sessions`

**Request Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes | CSV, Excel (xlsx/xls), or JSON file (max 10MB) |
| `name` | string | No | Descriptive name for the import session (max 255 chars). If not provided, auto-generated as "Import: {filename} - {timestamp}" |
| `template_id` | string | No | Public ID of existing import template to use |
| `auto_detect_mappings` | boolean | No | Auto-detect field mappings from headers (default: true) |

**Validation Rules:**
- File must be CSV, Excel, or JSON format
- Maximum file size: 10MB
- Supported MIME types: `text/csv`, `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/json`

**Response (201 Created):**

```json
{
  "success": true,
  "message": "File uploaded and parsed successfully",
  "data": {
    "session": {
      "id": "import_abc123",
      "name": "January Orders Import",
      "file_name": "orders.csv",
      "status": "ready"
    },
    "parsed": {
      "headers": ["Customer Name", "Phone", "Pickup Address", "Dropoff Address"],
      "total_rows": 150,
      "preview": [
        {
          "Customer Name": "John Doe",
          "Phone": "555-1234",
          "Pickup Address": "123 Main St",
          "Dropoff Address": "456 Elm St"
        }
      ],
      "delimiter": ",",
      "encoding": "UTF-8"
    },
    "mappings": {
      "mappings": {
        "Customer Name": "customer_name",
        "Phone": "customer_phone",
        "Pickup Address": "pickup_address",
        "Dropoff Address": "dropoff_address"
      },
      "confidence": 0.95,
      "unmapped": []
    },
    "next_step": "configure_mappings"
  }
}
```

**Error Response (500):**

```json
{
  "success": false,
  "message": "Failed to process file",
  "error": "Invalid CSV format: unexpected delimiter"
}
```

---

### 2. List Import Sessions

Retrieve all import sessions for the current company.

**Endpoint:** `GET /int/v1/orders/import-sessions`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by status (uploading, ready, processing, completed, failed, cancelled) |
| `template_id` | string | Filter by template public ID |
| `date_from` | date | Filter sessions created after this date |
| `date_to` | date | Filter sessions created before this date |
| `sort_by` | string | Sort field (default: created_at) |
| `sort_order` | string | Sort direction: asc/desc (default: desc) |
| `per_page` | integer | Results per page (default: 25) |

**Response (200 OK):**

```json
{
  "success": true,
  "data": [
    {
      "public_id": "import_abc123",
      "name": "January Orders Import",
      "status": "completed",
      "total_rows": 150,
      "imported_rows": 145,
      "failed_rows": 5,
      "created_at": "2025-11-19T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 25,
    "total": 67
  }
}
```

---

### 3. Get Session Details

Retrieve detailed information about a specific import session.

**Endpoint:** `GET /int/v1/orders/import-sessions/{id}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Response (200 OK):**

```json
{
  "success": true,
  "data": {
    "session": {
      "id": "import_abc123",
      "file_name": "orders.csv",
      "file_type": "text/csv",
      "status": "completed",
      "created_at": "2025-11-19T10:30:00Z"
    },
    "stats": {
      "total_rows": 150,
      "processed_rows": 150,
      "imported_rows": 145,
      "failed_rows": 5,
      "valid_rows": 148,
      "warning_rows": 3,
      "duplicate_rows": 2,
      "success_rate": 96.67
    },
    "recent_errors": [
      {
        "row_number": 12,
        "errors": {
          "customer_phone": ["Phone number is invalid"],
          "dropoff_address": ["Address could not be geocoded"]
        },
        "message": "Validation failed"
      }
    ],
    "timeline": {
      "created": "2025-11-19T10:30:00Z",
      "parsed": "2025-11-19T10:30:15Z",
      "dry_run_completed": "2025-11-19T10:32:00Z",
      "import_started": "2025-11-19T10:35:00Z",
      "completed": "2025-11-19T10:37:30Z"
    }
  }
}
```

---

### 4. Auto-Detect Field Mappings

Automatically detect how CSV headers map to order fields.

**Endpoint:** `POST /int/v1/orders/import-sessions/{id}/detect-mappings`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Response (200 OK):**

```json
{
  "success": true,
  "data": {
    "mappings": {
      "Customer Name": "customer_name",
      "Phone": "customer_phone",
      "Email": "customer_email",
      "Pickup": "pickup_address",
      "Delivery": "dropoff_address",
      "Notes": "notes"
    },
    "confidence": 0.92,
    "unmapped": ["Internal ID"],
    "required_fields": {
      "customer_name": "Customer name",
      "customer_phone": "Customer phone (or email)",
      "customer_email": "Customer email (or phone)",
      "pickup_address": "Pickup address",
      "dropoff_address": "Dropoff address"
    },
    "optional_fields": {
      "reference": "Order reference",
      "scheduled_at": "Scheduled date/time",
      "notes": "Notes/instructions",
      "quantity": "Number of packages",
      "weight": "Weight",
      "type": "Order type",
      "priority": "Priority level"
    },
    "validation_preview": {
      "is_valid": true,
      "errors": [],
      "warnings": ["Phone format doesn't match standard pattern"]
    }
  }
}
```

---

### 5. Execute Dry Run

Validate and preview import results without creating actual orders.

**Endpoint:** `POST /int/v1/orders/import-sessions/{id}/dry-run`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Request Body:**

```json
{
  "template_id": "template_xyz789",
  "mappings": {
    "Customer Name": "customer_name",
    "Phone": "customer_phone",
    "Pickup": "pickup_address",
    "Delivery": "dropoff_address"
  },
  "validation_rules": {
    "customer_phone": "required|phone",
    "customer_email": "email"
  },
  "default_values": {
    "type": "delivery",
    "status": "pending"
  },
  "duplicate_handling": "warn",
  "stop_on_error": false
}
```

**Request Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `template_id` | string | No* | Public ID of import template (*required if mappings not provided) |
| `mappings` | object | No* | Field mapping configuration (*required if template_id not provided) |
| `validation_rules` | object | No | Custom validation rules |
| `default_values` | object | No | Default values for unmapped fields |
| `duplicate_handling` | string | No | How to handle duplicates: allow/warn/reject (default: warn) |
| `stop_on_error` | boolean | No | Stop processing on first error (default: false) |

**Response (200 OK):**

```json
{
  "success": true,
  "message": "Dry run completed",
  "data": {
    "session_id": "import_abc123",
    "summary": {
      "total": 150,
      "valid": 145,
      "warnings": 3,
      "errors": 5,
      "duplicates": 2
    },
    "stats": {
      "can_proceed": true,
      "estimated_success_rate": 96.67,
      "critical_errors": 2,
      "recoverable_errors": 3
    },
    "can_proceed": true,
    "sample_errors": [
      {
        "row_number": 12,
        "errors": {
          "customer_phone": ["Phone number is required"],
          "dropoff_address": ["Address is invalid"]
        },
        "original_data": {
          "Customer Name": "Jane Smith",
          "Phone": "",
          "Pickup": "123 Main St",
          "Delivery": "Invalid Address"
        }
      }
    ],
    "sample_warnings": [
      {
        "row_number": 5,
        "warnings": ["Phone format may be incorrect"],
        "suggestions": ["Verify phone number format: 555-1234"]
      }
    ]
  }
}
```

---

### 6. Get Dry Run Results

Retrieve paginated dry run results with filtering options.

**Endpoint:** `GET /int/v1/orders/import-sessions/{id}/dry-run`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Results per page (default: 50) |
| `filter` | string | Filter type: all/valid/errors/warnings/duplicates (default: all) |

**Response (200 OK):**

```json
{
  "success": true,
  "data": {
    "session": {
      "id": "import_abc123",
      "status": "dry_run_completed",
      "file_name": "orders.csv"
    },
    "stats": {
      "total": 150,
      "valid": 145,
      "errors": 5,
      "warnings": 3
    },
    "can_proceed": true,
    "rows": {
      "data": [
        {
          "id": 1,
          "row_number": 1,
          "status": "valid",
          "message": "Ready for import",
          "original_data": {
            "Customer Name": "John Doe",
            "Phone": "555-1234"
          },
          "validation_errors": null,
          "validation_warnings": null,
          "can_import": true
        }
      ],
      "meta": {
        "current_page": 1,
        "per_page": 50,
        "total": 150,
        "last_page": 3
      }
    },
    "filters": {
      "all": 150,
      "valid": 145,
      "errors": 5,
      "warnings": 3
    }
  }
}
```

---

### 7. Execute Import

Create actual orders from validated import data.

**Endpoint:** `POST /int/v1/orders/import-sessions/{id}/execute`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Request Body:**

```json
{
  "stop_on_error": false,
  "include_orders": false
}
```

**Request Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `stop_on_error` | boolean | No | Stop import on first error (default: false) |
| `include_orders` | boolean | No | Include created order data in response (default: false) |

**Response (200 OK):**

```json
{
  "success": true,
  "message": "Import completed. Created 145 orders.",
  "data": {
    "session_id": "import_abc123",
    "created": 145,
    "failed": 5,
    "errors": [
      {
        "row_number": 12,
        "error": "Customer phone is required"
      }
    ],
    "orders": null
  }
}
```

**Response with Orders (include_orders=true):**

```json
{
  "success": true,
  "message": "Import completed. Created 145 orders.",
  "data": {
    "session_id": "import_abc123",
    "created": 145,
    "failed": 5,
    "errors": [],
    "orders": [
      {
        "public_id": "order_123abc",
        "tracking_number": "FLB-001234",
        "status": "pending"
      }
    ]
  }
}
```

**Error Response (422):**

```json
{
  "success": false,
  "message": "Session is not ready for import. Please run dry-run first."
}
```

---

### 8. Get Import Status

Poll the status of an ongoing import (for progress tracking).

**Endpoint:** `GET /int/v1/orders/import-sessions/{id}/status`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Response (200 OK):**

```json
{
  "success": true,
  "data": {
    "session_id": "import_abc123",
    "status": "importing",
    "is_complete": false,
    "progress": {
      "total": 150,
      "processed": 75,
      "percentage": 50.0,
      "current_action": "importing",
      "estimated_time_remaining": "2 minutes"
    },
    "results": null
  }
}
```

**Response (Completed):**

```json
{
  "success": true,
  "data": {
    "session_id": "import_abc123",
    "status": "completed",
    "is_complete": true,
    "progress": null,
    "results": {
      "imported": 145,
      "failed": 5,
      "errors": []
    }
  }
}
```

---

### 9. Fix Import Row

Manually correct and revalidate a specific row with errors.

**Endpoint:** `POST /int/v1/orders/import-sessions/{id}/rows/{rowId}/fix`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |
| `rowId` | string | Import row ID |

**Request Body:**

```json
{
  "corrections": {
    "customer_name": "John Doe",
    "customer_phone": "555-1234",
    "customer_email": "john@example.com",
    "pickup_address": "123 Main St, City, State 12345",
    "dropoff_address": "456 Elm St, City, State 12345",
    "reference": "ORD-12345",
    "notes": "Deliver before 5pm"
  }
}
```

**Request Parameters:**

| Field | Type | Description |
|-------|------|-------------|
| `corrections` | object | Corrected field values |
| `corrections.customer_name` | string | Customer name (max 255 chars) |
| `corrections.customer_phone` | string | Customer phone (max 50 chars) |
| `corrections.customer_email` | string | Customer email |
| `corrections.pickup_address` | string | Pickup address (max 500 chars) |
| `corrections.dropoff_address` | string | Dropoff address (max 500 chars) |
| `corrections.reference` | string | Order reference (max 100 chars) |
| `corrections.notes` | string | Notes (max 1000 chars) |

**Response (200 OK):**

```json
{
  "success": true,
  "message": "Row fixed successfully and ready for import",
  "data": {
    "row": {
      "id": 42,
      "row_number": 12,
      "status": "valid",
      "message": "Validation passed",
      "can_import": true
    },
    "validation": {
      "errors": null,
      "warnings": [],
      "suggestions": []
    }
  }
}
```

---

### 10. Cancel Import Session

Cancel an in-progress import or rollback a completed import.

**Endpoint:** `DELETE /int/v1/orders/import-sessions/{id}`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Import session public ID |

**Request Body:**

```json
{
  "action": "cancel"
}
```

**Request Parameters:**

| Field | Type | Description |
|-------|------|-------------|
| `action` | string | Action type: `cancel` or `rollback` (default: cancel) |

**Cancel Response (200 OK):**

```json
{
  "success": true,
  "message": "Import cancelled successfully",
  "data": {
    "session_id": "import_abc123",
    "status": "cancelled"
  }
}
```

**Rollback Response (200 OK):**

```json
{
  "success": true,
  "message": "Successfully rolled back 145 orders",
  "data": {
    "session_id": "import_abc123",
    "orders_deleted": 145,
    "status": "rolled_back"
  }
}
```

**Error Response (422):**

```json
{
  "success": false,
  "message": "Cannot cancel import in current status"
}
```

---

## Data Models

### ImportSession

| Field | Type | Description |
|-------|------|-------------|
| `public_id` | string | Public identifier (e.g., "import_abc123") |
| `company_uuid` | uuid | Company UUID |
| `template_uuid` | uuid | Import template UUID (optional) |
| `file_uuid` | uuid | Uploaded file UUID |
| `name` | string | Session name |
| `status` | string | Current status (see statuses below) |
| `file_name` | string | Original filename |
| `file_type` | string | MIME type |
| `file_path` | string | Storage path |
| `field_mappings` | object | Column to field mappings |
| `total_rows` | integer | Total rows in file |
| `processed_rows` | integer | Rows processed |
| `imported_rows` | integer | Successfully imported rows |
| `failed_rows` | integer | Failed rows |
| `valid_rows` | integer | Valid rows |
| `warning_rows` | integer | Rows with warnings |
| `duplicate_rows` | integer | Duplicate rows found |
| `meta` | object | Additional metadata (headers, preview, etc.) |
| `errors` | array | Error messages |
| `parsed_at` | datetime | When file was parsed |
| `dry_run_completed_at` | datetime | When dry run completed |
| `import_started_at` | datetime | When import started |
| `completed_at` | datetime | When import completed |
| `cancelled_at` | datetime | When cancelled |

### Session Statuses

| Status | Description |
|--------|-------------|
| `uploading` | File is being uploaded |
| `validating` | File is being validated |
| `ready` | Ready for configuration |
| `processing_dry_run` | Dry run in progress |
| `dry_run_completed` | Dry run finished |
| `processed` | Dry run complete, ready to import |
| `importing` | Import in progress |
| `completed` | Import completed successfully |
| `completed_with_errors` | Import completed with some errors |
| `failed` | Import failed |
| `cancelled` | Import cancelled by user |

### ImportRow

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Row ID |
| `session_uuid` | uuid | Parent session UUID |
| `row_number` | integer | Row number in file |
| `original_data` | object | Original CSV row data |
| `mapped_data` | object | Data after field mapping |
| `processing_status` | string | Status (valid, error, warning) |
| `processing_message` | string | Status message |
| `validation_errors` | object | Validation error messages |
| `validation_warnings` | array | Warning messages |
| `suggestions` | array | Suggested fixes |
| `is_duplicate` | boolean | Is duplicate order |
| `can_import` | boolean | Can be imported |

---

## Field Mappings

### Required Fields

At least one contact method (phone OR email) is required.

| Target Field | Description | Validation |
|--------------|-------------|------------|
| `customer_name` | Customer name | Required, max 255 chars |
| `customer_phone` | Customer phone | Required (or email), phone format |
| `customer_email` | Customer email | Required (or phone), email format |
| `pickup_address` | Pickup address | Required, geocodable |
| `dropoff_address` | Dropoff address | Required, geocodable |

### Optional Fields

| Target Field | Description | Validation |
|--------------|-------------|------------|
| `reference` | Order reference/ID | String, max 100 chars |
| `scheduled_at` | Scheduled date/time | Valid datetime |
| `notes` | Order notes | String, max 1000 chars |
| `quantity` | Number of packages | Integer, min 1 |
| `weight` | Weight (kg) | Numeric, min 0 |
| `type` | Order type | Valid type from system |
| `priority` | Priority level | low/normal/high/urgent |
| `pickup_name` | Pickup contact | String, max 255 chars |
| `dropoff_name` | Dropoff contact | String, max 255 chars |

---

## Error Handling

### Common Error Codes

| Code | Description |
|------|-------------|
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Authentication required |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Session not found |
| 422 | Unprocessable Entity - Validation failed |
| 500 | Internal Server Error - Server error |

### Error Response Format

```json
{
  "success": false,
  "message": "Human-readable error message",
  "error": "Detailed error information"
}
```

---

## Workflow Example

### Complete Import Workflow

```bash
# 1. Upload file
POST /int/v1/orders/import-sessions
{
  "file": <file>,
  "name": "January Orders",
  "auto_detect_mappings": true
}

# 2. Review auto-detected mappings (if needed)
POST /int/v1/orders/import-sessions/import_abc123/detect-mappings

# 3. Run dry run with mappings
POST /int/v1/orders/import-sessions/import_abc123/dry-run
{
  "mappings": { ... },
  "duplicate_handling": "warn"
}

# 4. Review dry run results
GET /int/v1/orders/import-sessions/import_abc123/dry-run?filter=errors

# 5. Fix any problematic rows
POST /int/v1/orders/import-sessions/import_abc123/rows/42/fix
{
  "corrections": { ... }
}

# 6. Execute import
POST /int/v1/orders/import-sessions/import_abc123/execute

# 7. Poll status
GET /int/v1/orders/import-sessions/import_abc123/status

# 8. Get final results
GET /int/v1/orders/import-sessions/import_abc123
```

---

## Best Practices

1. **Always run dry-run first** - Preview results before creating orders
2. **Use templates** - Save validated mappings for recurring imports
3. **Handle errors gracefully** - Review and fix errors before production import
4. **Monitor progress** - Poll status endpoint for long-running imports
5. **Validate data** - Ensure addresses are geocodable and phone numbers are valid
6. **Use proper file formats** - UTF-8 CSV with proper headers recommended
7. **Batch processing** - For very large files (>10,000 rows), consider splitting
8. **Rollback capability** - Test rollback in staging before production imports

---

## Rate Limiting

Import operations are resource-intensive. Consider:
- Maximum 5 concurrent import sessions per company
- Maximum file size: 10MB
- Recommended batch size: 1,000-5,000 rows per session

---

## Support

For issues or questions:
- Check validation errors in dry-run results
- Review import session logs via GET /import-sessions/{id}
- Contact support with session ID for troubleshooting
