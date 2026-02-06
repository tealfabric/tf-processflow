# ProcessFlow Code Snippets Guide

## Overview

ProcessFlow code snippets are PHP code blocks that execute within individual process steps. Each snippet receives input data from the previous step, processes it, and returns output data for the next step.

## Table of Contents

1. [Basic Structure](#basic-structure)
2. [Input and Output](#input-and-output)
3. [Available Variables](#available-variables)
4. [Error Handling](#error-handling)
5. [Code Examples](#code-examples)
6. [Best Practices](#best-practices)
7. [Common Patterns](#common-patterns)

## Basic Structure

### ✅ Correct Format

```php
<?php
// Your processing logic here
$output = [];
$output['result'] = 'success';
$output['data'] = $processed_data;
return $output;
```

### ❌ Incorrect Format

```php
<?php
// ❌ WRONG - Don't use echo or print
echo "Processing data...";
print_r($input);
// No return statement = no data passed to next step
```

## Input and Output

### Input Data
Each step receives data from the previous step via the `$input` variable:

```php
<?php
// Access input data
$customer_name = $input['name'];
$customer_email = $input['email'];
$order_amount = $input['amount'];
```

### Output Data
Each step must return an array that becomes the input for the next step:

```php
<?php
// Return structured data
return [
    'customer_id' => 'CUST_123',
    'status' => 'processed',
    'amount' => $processed_amount
];
```

## Available Variables

ProcessFlow provides several variables for use in code snippets:

| Variable | Type | Description |
|----------|------|-------------|
| `$process_input` | Array | Data from previous step |
| `$raw_input` | String | Raw, unmodified POST/JSON data (for signature verification) - **Available when triggered by WebApp** |
| `$process_output` | Array | Output data for next step |
| `$tenant_id` | String | Current tenant ID |
| `$user_id` | String | Current user ID |
| `$webapp_tenant_id` | String | WebApp tenant ID (same as tenant_id) |
| `$db` | PDO | Database connection (use with caution - prefer `$tenantDb` for tenant-scoped queries) |
| `$tenantDb` | Object | **Tenant-scoped database service (RECOMMENDED)** |
| `$integration` | Object | **Integration execution service (RECOMMENDED)** |
| `$connectors` | Object | Connector service for external APIs (DEPRECATED - use `$integration`) |
| `$llm` | Object | LLM service for AI operations |
| `$email` | Object | Email service |
| `$notification` | Object | Notification service |
| `$api` | Object | API service for HTTP calls (methods: `get()`, `post()`, `put()`, `delete()`) - **Automatically includes execution authorization key** |
| `$execution_auth_key` | String | **Execution authorization key for internal API calls** (HMAC-SHA256 hash, automatically generated) |
| `$files` | Object | **File service for accessing uploaded files (only available when execution_id is set)** |
| `$http_headers` | Array | HTTP headers from request (includes `Host`, `User-Agent`, etc.) - **Available when triggered by WebApp** |
| `$request_method` | String | HTTP request method (`GET`, `POST`) - **Available when triggered by WebApp** |
| `$request_uri` | String | Request URI path - **Available when triggered by WebApp** |
| `$remote_addr` | String | Client IP address - **Available when triggered by WebApp** |
| `$app_url` | String | Application base URL from `APP_URL` environment variable (e.g., `https://dev.tealfabric.io`) |
| `$app_url` | String | Application base URL from `APP_URL` environment variable (e.g., `https://dev.tealfabric.io`) |

### Example Usage

```php
<?php
// Use available variables
// ✅ RECOMMENDED: Use tenantDb for secure, tenant-scoped queries
$customer = $tenantDb->getOne("SELECT * FROM Customers WHERE email = ?", [$process_input['email']]);

// Access form data from WebApp
$customer_name = $process_input['name'];
$customer_email = $process_input['email'];

// Use WebApp tenant ID (same as tenant_id)
$webapp_tenant = $webapp_tenant_id;

// Access request information (when triggered by WebApp)
$host = $http_headers['Host'] ?? 'localhost';
$protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') ? 'https' : 'http';
$baseUrl = "{$protocol}://{$host}";

// Use app_url for API calls (recommended - uses APP_URL from .env)
$apiUrl = $app_url; // e.g., "https://dev.tealfabric.io"

// Access raw input for signature verification (e.g., Stripe webhooks)
$rawPayload = $raw_input ?? '';

// Use integration service (RECOMMENDED)
$result = $integration->executeSync($integrationId, $data);

// Use API service to call internal or external APIs
$apiResult = $api->get('/api/v1/entities');
if ($apiResult['success']) {
    $entities = $apiResult['data']['entities'] ?? [];
}
```

return [
    'customer' => $customer,
    'integration_result' => $result,
    'entities' => $entities ?? [],
    'processed_by' => $user_id
];
```

### WebApp-Specific Variables

When a process is triggered by a WebApp form submission, the following additional variables are available:

- **`$raw_input`**: Raw POST/JSON data (useful for webhook signature verification)
- **`$http_headers`**: HTTP headers array (includes `Host`, `User-Agent`, `Content-Type`, etc.)
- **`$request_method`**: HTTP method (`GET`, `POST`)
- **`$request_uri`**: Request URI path
- **`$remote_addr`**: Client IP address

**Example: Using app_url for API calls (Recommended)**

```php
<?php
// ✅ RECOMMENDED: Use $app_url from environment (APP_URL from .env)
// This is available in all code snippets and avoids manual URL construction
$apiUrl = "{$app_url}/api/v1/registration/check-email";
$result = $api->post($apiUrl, ['email' => $email]);
```

**Alternative: Getting base URL from WebApp request (when needed)**

```php
<?php
// Alternative: Get base URL from HTTP headers (only when triggered by WebApp)
// Use this only if you need the actual request URL, otherwise use $app_url
$host = $http_headers['Host'] ?? 'localhost';
$protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') 
    ? 'https' 
    : 'http';
$baseUrl = "{$protocol}://{$host}";

// Use base URL for API calls
$apiUrl = "{$baseUrl}/api/v1/registration/check-email";
$result = $api->post($apiUrl, ['email' => $email]);
```

## Error Handling

### Standard Error Response Format

Always return this structure for errors:

```php
<?php
return [
    'success' => false,           // Always false for errors
    'error' => [
        'code' => 'ERROR_CODE',   // Machine-readable error code
        'message' => 'Human readable error message',
        'details' => 'Additional error details (optional)'
    ],
    'data' => null                // No data on error
];
```

### Success Response Format

```php
<?php
return [
    'success' => true,
    'data' => [
        'result' => 'processed_data',
        'id' => 'generated_id',
        'status' => 'completed'
    ],
    'message' => 'Operation completed successfully'
];
```

### Error Handling Examples

#### Validation Errors

```php
<?php
// Validate input data
if (empty($input['email'])) {
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Email is required',
            'details' => 'Missing required field: email'
        ],
        'data' => null
    ];
}

if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EMAIL',
            'message' => 'Invalid email format',
            'details' => 'Email must be a valid email address'
        ],
        'data' => null
    ];
}

// Continue with processing...
return ['success' => true, 'data' => $processed_data];
```

#### Database Errors

```php
<?php
try {
    $stmt = $db->prepare("INSERT INTO Customers (name, email, tenant_id) VALUES (?, ?, ?)");
    $result = $stmt->execute([$input['name'], $input['email'], $tenant_id]);
    
    if (!$result) {
        return [
            'success' => false,
            'error' => [
                'code' => 'DATABASE_ERROR',
                'message' => 'Failed to save customer',
                'details' => 'Database insert operation failed'
            ],
            'data' => null
        ];
    }
    
    $customer_id = $db->lastInsertId();
    return [
        'success' => true,
        'data' => ['customer_id' => $customer_id],
        'message' => 'Customer created successfully'
    ];
    
} catch (\PDOException $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_CONNECTION_ERROR',
            'message' => 'Database operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
```

#### External API Errors

```php
<?php
try {
    $result = $connectors->execute('payment-gateway', 'process_payment', $input);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => [
                'code' => 'PAYMENT_GATEWAY_ERROR',
                'message' => 'Payment processing failed',
                'details' => $result['error']['message'] ?? 'Unknown payment error'
            ],
            'data' => null
        ];
    }
    
    return [
        'success' => true,
        'data' => $result['data'],
        'message' => 'Payment processed successfully'
    ];
    
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'CONNECTOR_ERROR',
            'message' => 'External service unavailable',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
```

## Code Examples

### Data Transformation

```php
<?php
// Transform customer data
$output = [];
$output['customer_id'] = 'CUST_' . strtoupper(substr($input['email'], 0, 5));
$output['full_name'] = $input['first_name'] . ' ' . $input['last_name'];
$output['is_premium'] = $input['amount'] > 1000;
$output['processed_at'] = date('Y-m-d H:i:s');

return [
    'success' => true,
    'data' => $output,
    'message' => 'Data transformed successfully'
];
```

### Database Operations

#### Standard Tenant-Scoped Queries (Recommended)

For regular tenant processes, use `$tenantDb` for secure, tenant-scoped database operations:

```php
<?php
// ✅ RECOMMENDED: Use tenantDb for tenant-scoped queries
$customer = $tenantDb->getOne("SELECT * FROM Customers WHERE email = ?", [$input['email']]);
if (!$customer) {
    $customerId = $tenantDb->insert('Customers', [
        'name' => $input['full_name'],
        'email' => $input['email'],
        'created_by' => $user_id
        // tenant_id is automatically added by tenantDb
    ]);
} else {
    $customerId = $customer['customer_id'];
}

return [
    'success' => true,
    'data' => ['customer_id' => $customerId]
];
```

#### Direct Database Access (Advanced - System-Level Processes Only)

For system-level processes (`/root` tenant) that need cross-tenant database access:

```php
<?php
// ⚠️ ADVANCED: Direct database access (only for /root tenant system processes)
// Use this only when you need to query across tenants (e.g., system-level webhook handlers)
try {
    $stmt = $db->prepare("SELECT tenant_id FROM Subscriptions WHERE stripe_customer_id = ?");
    $stmt->execute([$stripeCustomerId]);
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$result) {
        return [
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Tenant not found']
        ];
    }
    
    return [
        'success' => true,
        'data' => ['tenant_id' => $result['tenant_id']]
    ];
} catch (\PDOException $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Database operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
```

**Important Notes:**
- **PDOException** is allowed for exception handling in all processes
- **PDO class references** are only allowed for `/root` tenant processes (system-level)
- **`new PDO()` instantiation is always blocked** - use the provided `$db` connection
- For regular tenant processes, always use `$tenantDb` for tenant-scoped queries

### Decision Logic

```php
<?php
// Make approval decision
$amount = floatval($input['amount']);

if ($amount > 5000) {
    return [
        'success' => true,
        'data' => [
            'approval_required' => true,
            'next_step' => 'manager_approval',
            'reason' => 'High amount requires manager approval',
            'amount' => $amount
        ],
        'message' => 'Approval required for high amount'
    ];
} else {
    return [
        'success' => true,
        'data' => [
            'approval_required' => false,
            'next_step' => 'process_payment',
            'reason' => 'Amount within auto-approval limit',
            'amount' => $amount
        ],
        'message' => 'Auto-approved'
    ];
}
```

### API Service Usage

The `$api` service provides methods to make HTTP requests to internal Tealfabric APIs or external services. All requests automatically include tenant and user context headers.

#### Available Methods

**GET Request:**
```php
$result = $api->get(string $url, array $headers = []): array
```

**POST Request:**
```php
$result = $api->post(string $url, array $data = [], array $headers = []): array
```

**PUT Request:**
```php
$result = $api->put(string $url, array $data = [], array $headers = []): array
```

**DELETE Request:**
```php
$result = $api->delete(string $url, array $headers = []): array
```

#### Response Format

All methods return the same structure:
```php
[
    'status_code' => int,    // HTTP status code (200, 404, etc.)
    'data' => mixed,         // Decoded JSON response
    'success' => bool        // true if status 200-299
]
```

#### Example: Internal API Calls

```php
<?php
// Get entities from Tealfabric API
$result = $api->get('/api/v1/entities');

if ($result['success']) {
    $entities = $result['data']['entities'] ?? [];
    return [
        'success' => true,
        'data' => ['entities' => $entities, 'count' => count($entities)]
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'API_ERROR',
            'message' => 'Failed to fetch entities',
            'details' => "HTTP {$result['status_code']}"
        ]
    ];
}
```

#### Example: Create Resource via API

```php
<?php
// Create a notification via API
$notificationData = [
    'title' => 'Order Created',
    'message' => "Order #{$input['order_id']} has been created",
    'type' => 'success'
];

$result = $api->post('/api/v1/notifications', $notificationData);

if ($result['success']) {
    return [
        'success' => true,
        'data' => [
            'notification_id' => $result['data']['notification_id'],
            'status' => 'created'
        ]
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'NOTIFICATION_ERROR',
            'message' => 'Failed to create notification'
        ]
    ];
}
```

#### Example: Update Resource via API

```php
<?php
// Update an entity
$entityId = $input['entity_id'];
$updateData = [
    'name' => $input['new_name'],
    'metadata' => $input['metadata'] ?? []
];

$result = $api->put("/api/v1/entities/{$entityId}", $updateData);

if ($result['success']) {
    return ['success' => true, 'data' => ['updated' => true]];
} else {
    return [
        'success' => false,
        'error' => ['code' => 'UPDATE_ERROR', 'message' => 'Update failed']
    ];
}
```

#### Example: Delete Resource via API

```php
<?php
// Delete a resource
$resourceId = $input['resource_id'];
$result = $api->delete("/api/v1/resources/{$resourceId}");

if ($result['success']) {
    return ['success' => true, 'data' => ['deleted' => true]];
} else {
    return [
        'success' => false,
        'error' => ['code' => 'DELETE_ERROR', 'message' => 'Delete failed']
    ];
}
```

#### Example: External API Calls

```php
<?php
// Call external service with custom headers
try {
    $apiData = [
        'name' => $input['full_name'],
        'email' => $input['email'],
        'amount' => $input['amount']
    ];

    $result = $api->post('https://api.example.com/payment', $apiData, [
        'Authorization' => 'Bearer ' . $apiKey,
        'X-Custom-Header' => 'value'
    ]);

    if ($result['success']) {
        return [
            'success' => true,
            'data' => [
                'payment_id' => $result['data']['payment_id'],
                'status' => $result['data']['status']
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => [
                'code' => 'PAYMENT_FAILED',
                'message' => 'Payment processing failed',
                'details' => "HTTP {$result['status_code']}"
            ]
        ];
    }
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'API_EXCEPTION',
            'message' => 'API call failed',
            'details' => $e->getMessage()
        ]
    ];
}
```

#### Custom Headers

You can add custom headers to any request, which will be merged with the automatic headers:

```php
<?php
// Add custom Authorization header
$result = $api->get('/api/v1/entities', [
    'Authorization' => 'Bearer YOUR_TOKEN',
    'X-Custom-Header' => 'value'
]);

// Custom headers are merged with automatic headers:
// - X-Tenant-ID (automatic)
// - X-User-ID (automatic)
// - Content-Type: application/json (automatic)
// - User-Agent: ProcessFlow/1.0 (automatic)
// - Authorization: Bearer YOUR_TOKEN (custom)
// - X-Custom-Header: value (custom)
```

**Note**: Custom headers override automatic headers if they have the same name.

#### Error Handling

```php
<?php
try {
    $result = $api->get('/api/v1/entities');
    
    if (!$result['success']) {
        switch ($result['status_code']) {
            case 400:
                return ['success' => false, 'error' => ['code' => 'BAD_REQUEST']];
            case 401:
                return ['success' => false, 'error' => ['code' => 'UNAUTHORIZED']];
            case 404:
                return ['success' => false, 'error' => ['code' => 'NOT_FOUND']];
            case 429:
                return ['success' => false, 'error' => ['code' => 'RATE_LIMIT']];
            default:
                return ['success' => false, 'error' => ['code' => 'API_ERROR']];
        }
    }
    
    return ['success' => true, 'data' => $result['data']];
    
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'EXCEPTION',
            'message' => $e->getMessage()
        ]
    ];
}
```

#### Timeout and Limits

The `$api` service has the following built-in limits:

- **Request timeout**: 30 seconds (total time for request)
- **Connection timeout**: 10 seconds (time to establish connection)
- **Maximum redirects**: 3 (automatically follows redirects)
- **JSON handling**: Automatic encoding/decoding (no manual conversion needed)
- **SSL verification**: Disabled by default (for development; should be enabled in production)

**Note**: These limits are hardcoded in the `TenantAPIService` class. For custom timeout values, you would need to use cURL directly or modify the service.

For more detailed API usage examples, see [WebApp API Usage Guide](./WEBAPP_API_USAGE_GUIDE.md).

### Files Service Usage

The `$files` service provides secure access to files uploaded via WebApp forms. The service is only available when an `execution_id` is set (typically when files are uploaded through a WebApp).

**Note:** The `$files` service may be `null` if no files were uploaded or if the process step is not part of a file upload workflow.

#### Available Methods

**Get All Files:**
```php
$files = $files->getFiles(): array
```
Returns an array of file metadata with relative paths only (no server paths exposed).

**Get File by Name:**
```php
$file = $files->getFile(string $fileName): array|null
```
Returns file metadata for a specific file by original name or stored name.

**Read File Content:**
```php
$content = $files->readFile(string $fileName): string
```
Reads the file content as a string. Throws an exception if file not found.

**Get File Info:**
```php
$info = $files->getFileInfo(string $fileName): array
```
Returns detailed file information (size, type, etc.).

**Upload File to External Service:**
```php
$result = $files->uploadToExternal(string $fileName, string $targetUrl, array $options = []): array
```
Uploads a file to an external service (e.g., GitLab) using cURL. Returns upload result.

#### Example: Access Uploaded Files

```php
<?php
// Check if files service is available
if (isset($files) && $files !== null) {
    try {
        // Get all uploaded files
        $uploadedFiles = $files->getFiles();
        
        // Process each file
        $fileInfo = [];
        foreach ($uploadedFiles as $file) {
            $fileInfo[] = [
                'name' => $file['original_name'],
                'size' => $file['size'],
                'type' => $file['type'],
                'relative_path' => $file['relative_path']
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'files' => $fileInfo,
                'count' => count($uploadedFiles)
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => [
                'code' => 'FILE_ERROR',
                'message' => 'Failed to access files: ' . $e->getMessage()
            ]
        ];
    }
} else {
    // No files uploaded or files service not available
    return [
        'success' => true,
        'data' => ['files' => [], 'count' => 0]
    ];
}
```

#### Example: Read File Content

```php
<?php
if (isset($files) && $files !== null) {
    try {
        // Read a specific file
        $fileContent = $files->readFile('document.pdf');
        
        // Process file content
        // ... your processing logic ...
        
        return [
            'success' => true,
            'data' => ['processed' => true]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => [
                'code' => 'FILE_READ_ERROR',
                'message' => $e->getMessage()
            ]
        ];
    }
}
```

#### Example: Upload File to External Service (e.g., GitLab)

```php
<?php
if (isset($files) && $files !== null) {
    try {
        $uploadedFiles = $files->getFiles();
        
        foreach ($uploadedFiles as $file) {
            // Upload to GitLab
            $gitlabUrl = "https://git.tealfabric.io/api/v4/projects/123/uploads";
            $result = $files->uploadToExternal(
                $file['original_name'],
                $gitlabUrl,
                [
                    'headers' => [
                        'PRIVATE-TOKEN: your-gitlab-token'
                    ]
                ]
            );
            
            if ($result['success']) {
                // File uploaded successfully
                error_log("File {$file['original_name']} uploaded to GitLab");
            }
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => ['code' => 'UPLOAD_ERROR', 'message' => $e->getMessage()]
        ];
    }
}
```

#### File Metadata Structure

Files returned by `getFiles()` and `getFile()` have the following structure:

```php
[
    'field_name' => string,      // Form field name (e.g., 'attachment')
    'index' => int,              // File index (0, 1, 2...)
    'original_name' => string,   // Original filename
    'stored_name' => string,     // Stored filename (unique)
    'relative_path' => string,   // Relative path (safe to expose)
    'size' => int,               // File size in bytes
    'type' => string,           // MIME type
    'uploaded_at' => string     // ISO 8601 timestamp
]
```

#### Security Considerations

1. **Relative Paths Only**: All file paths in metadata are relative to prevent exposing server full paths.
2. **Tenant Isolation**: Files are stored in tenant-scoped directories (`storage/tenantdata/{tenant_id}/processdata/{execution_id}/`).
3. **Execution Scoping**: Files are scoped to a specific execution ID, ensuring process isolation.
4. **Path Validation**: The service validates all file paths to prevent directory traversal attacks.

For more detailed file upload documentation, see [WebApp File Upload Guide](./WEBAPP_FILE_UPLOAD_GUIDE.md).

---

## Tenant File Operations

Process step code snippets have access to tenant-scoped file and directory operations that match standard PHP function signatures. All operations are automatically scoped to `storage/tenantdata/<tenant_id>/` directory, with automatic path validation to prevent directory traversal attacks.

### Available File Operations

All standard PHP file operations are available with the same syntax, but automatically scoped to the tenant directory:

#### File Operations

- **`file_get_contents(string $filename, ...)`** - Read entire file into a string
- **`file_put_contents(string $filename, mixed $data, ...)`** - Write data to a file
- **`fopen(string $filename, string $mode, ...)`** - Open file or URL
- **`fclose(resource $stream)`** - Close an open file pointer
- **`fread(resource $stream, int $length)`** - Binary-safe file read
- **`fwrite(resource $stream, string $data, ...)`** - Binary-safe file write
- **`fgets(resource $stream, ...)`** - Gets line from file pointer
- **`unlink(string $filename, ...)`** - Delete a file
- **`copy(string $from, string $to, ...)`** - Copy file
- **`rename(string $oldname, string $newname, ...)`** - Rename/move a file or directory

#### Directory Operations

- **`mkdir(string $directory, int $permissions = 0755, bool $recursive = false, ...)`** - Create directory
- **`rmdir(string $directory, ...)`** - Remove empty directory
- **`chmod(string $filename, int $permissions)`** - Change file mode
- **`chown(string $filename, string|int $user)`** - Change file owner (returns false - not allowed)
- **`scandir(string $directory, ...)`** - List files and directories
- **`opendir(string $directory, ...)`** - Open directory handle
- **`readdir(resource $dir_handle)`** - Read entry from directory handle
- **`closedir(resource $dir_handle)`** - Close directory handle
- **`glob(string $pattern, int $flags = 0)`** - Find pathnames matching a pattern

#### File Information Functions

- **`file_exists(string $filename)`** - Check if file or directory exists
- **`is_dir(string $filename)`** - Check if path is a directory
- **`is_file(string $filename)`** - Check if path is a file
- **`is_readable(string $filename)`** - Check if file is readable
- **`is_writable(string $filename)`** - Check if file is writable
- **`filesize(string $filename)`** - Get file size in bytes
- **`filemtime(string $filename)`** - Get file modification time
- **`filectime(string $filename)`** - Get file creation time
- **`fileatime(string $filename)`** - Get file access time
- **`fileperms(string $filename)`** - Get file permissions
- **`filetype(string $filename)`** - Get file type

### Usage Examples

#### Reading and Writing Files

```php
<?php
// Read a configuration file
$config = json_decode(file_get_contents('config/app.json'), true);

// Write data to a file
$data = ['status' => 'success', 'timestamp' => time()];
file_put_contents('output/result.json', json_encode($data, JSON_PRETTY_PRINT));

// Append to a file
file_put_contents('logs/activity.log', "New entry\n", FILE_APPEND | LOCK_EX);
```

#### Stream Operations

```php
<?php
// Read large file line by line
$handle = fopen('data/large.csv', 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        // Process each line
        $data = str_getcsv($line);
        // ... your processing logic ...
    }
    fclose($handle);
}

// Write to file using streams
$handle = fopen('output/report.txt', 'w');
if ($handle) {
    fwrite($handle, "Report Header\n");
    fwrite($handle, "Report Content\n");
    fclose($handle);
}
```

#### Directory Operations

```php
<?php
// Create directory structure
mkdir('reports/2025/01', 0755, true);

// List directory contents
$files = scandir('documents');
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        if (is_file("documents/$file")) {
            $size = filesize("documents/$file");
            error_log("File: $file, Size: $size bytes");
        }
    }
}

// Using directory handle
$dir = opendir('documents');
if ($dir) {
    while (($file = readdir($dir)) !== false) {
        if ($file !== '.' && $file !== '..') {
            error_log("Found: $file");
        }
    }
    closedir($dir);
}
```

#### File Management

```php
<?php
// Copy file
copy('source/data.json', 'backup/data.json');

// Rename/move file
rename('temp/file.txt', 'archive/file.txt');

// Delete file
if (file_exists('temp/cache.dat')) {
    unlink('temp/cache.dat');
}

// Check file properties
if (is_file('data/config.json')) {
    $size = filesize('data/config.json');
    $modified = filemtime('data/config.json');
    $readable = is_readable('data/config.json');
    $writable = is_writable('data/config.json');
    
    error_log("File size: $size, Modified: " . date('Y-m-d H:i:s', $modified));
}
```

#### Pattern Matching with glob()

```php
<?php
// Find all JSON files
$jsonFiles = glob('data/*.json');

// Find all files in subdirectories
$allFiles = glob('reports/**/*.txt');

// Find files matching pattern
$logFiles = glob('logs/app-*.log');
foreach ($logFiles as $logFile) {
    error_log("Found log file: $logFile");
}
```

### Path Handling

All paths are relative to the tenant directory (`storage/tenantdata/<tenant_id>/`). You can use:

- **Relative paths**: `'data/config.json'` → `storage/tenantdata/<tenant_id>/data/config.json`
- **Absolute paths (leading slash)**: `'/data/config.json'` → `storage/tenantdata/<tenant_id>/data/config.json`

**Important**: Directory traversal attempts (`../`) are automatically blocked for security.

### Security Features

1. **Automatic Path Validation**: All paths are validated to ensure they stay within the tenant directory
2. **Directory Traversal Prevention**: Attempts to access files outside the tenant directory are blocked
3. **Tenant Isolation**: Each tenant can only access their own files
4. **Automatic Directory Creation**: Directories are automatically created when needed for write operations
5. **Operation Logging**: All file operations are logged for audit purposes

### Error Handling

All file operations return the same values as standard PHP functions:
- **File operations** return `false` on failure (check with `=== false`)
- **Directory operations** return `false` on failure
- **File information functions** return `false` on failure

```php
<?php
// Safe file reading
$content = file_get_contents('data/config.json');
if ($content === false) {
    error_log("Failed to read config file");
    return ['error' => 'Config file not found'];
}

// Safe file writing
$result = file_put_contents('output/result.txt', $data);
if ($result === false) {
    error_log("Failed to write result file");
    return ['error' => 'Failed to write file'];
}
```

### Notes

- **`chown()`** always returns `false` - changing file ownership is not allowed for security reasons
- **`rmdir()`** only works on empty directories - use file operations to remove files first
- **`move_uploaded_file()`** is not available - use the `$files` service for uploaded files (see File Upload section above)
- All operations are automatically logged for debugging and audit purposes

### Debug Logging

The `log_message()` function is available in code snippets for debugging and logging purposes. This function logs messages to multiple destinations:

1. **Server Error Log**: Full message is logged to PHP's `error_log()`
2. **JSON File**: Full message is stored in a JSON file at `storage/tenantdata/<tenant_id>/processflows/<process_id>/<execution_id>.json`
3. **Database**: Truncated message (max 1000 bytes) is stored in `ProcessStepLogs` table

#### Usage

```php
<?php
// Log a simple message
log_message("Processing customer data");

// Log with variable interpolation
log_message("Found " . count($rows) . " rows to process");

// Log complex data (will be JSON encoded)
log_message("Headers: " . json_encode($headers));
```

#### Log File Structure

Each execution creates a separate JSON file containing all log entries:

**File Path:** `storage/tenantdata/<tenant_id>/processflows/<process_id>/<execution_id>.json`

**File Format:**
```json
[
  {
    "timestamp": "2025-11-11 05:15:17",
    "level": "info",
    "message": "Full log message here...",
    "execution_id": "step_exec_..."
  },
  {
    "timestamp": "2025-11-11 05:15:18",
    "level": "info",
    "message": "Another log message...",
    "execution_id": "step_exec_..."
  }
]
```

#### Important Notes

- **Full Logs in Files**: Complete, untruncated log messages are stored in JSON files
- **Truncated DB Logs**: Database logs are limited to 1000 bytes to save space (messages longer than 1000 bytes are truncated with `... [truncated]` suffix)
- **Per-Execution Files**: Each execution gets its own JSON file, making it easy to purge old logs by deleting old files
- **Error Handling**: If file logging fails, execution continues (logging is non-blocking)
- **Tenant Isolation**: Log files are stored in tenant-scoped directories

#### Example: Debugging Data Processing

```php
<?php
log_message("Starting data processing");
log_message("Input data keys: " . implode(', ', array_keys($process_input)));

// Process data
$processed = [];
foreach ($process_input['items'] as $item) {
    log_message("Processing item: " . json_encode($item));
    $processed[] = processItem($item);
}

log_message("Processed " . count($processed) . " items");
return ['success' => true, 'data' => $processed];
```

### Business Logic Validation

```php
<?php
// Validate business rules
$errors = [];

// Check required fields
if (empty($input['email'])) {
    $errors[] = 'Email is required';
}

if (empty($input['amount'])) {
    $errors[] = 'Amount is required';
}

// Validate email format
if (!empty($input['email']) && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format';
}

// Validate amount
if (!empty($input['amount'])) {
    $amount = floatval($input['amount']);
    if ($amount < 0) {
        $errors[] = 'Amount cannot be negative';
    }
    if ($amount > 100000) {
        $errors[] = 'Amount exceeds maximum limit';
    }
}

// Return errors if any
if (!empty($errors)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Validation failed',
            'details' => implode(', ', $errors)
        ],
        'data' => null
    ];
}

// Continue with processing
return [
    'success' => true,
    'data' => [
        'validated' => true,
        'amount' => floatval($input['amount']),
        'email' => $input['email']
    ],
    'message' => 'Validation passed'
];
```

## Best Practices

### 1. Always Return Arrays
```php
<?php
// ✅ Good
return ['success' => true, 'data' => $result];

// ❌ Bad
return $result; // Not an array
echo $result;   // No return
```

### 2. Use Descriptive Keys
```php
<?php
// ✅ Good - Clear structure
return [
    'success' => true,
    'data' => [
        'customer_id' => $id,
        'order_status' => 'pending',
        'total_amount' => $amount
    ]
];

// ❌ Bad - Unclear structure
return [
    'success' => true,
    'data' => [
        'a' => $id,
        'b' => 'pending',
        'c' => $amount
    ]
];
```

### 3. Handle Errors Gracefully
```php
<?php
try {
    // Your processing logic
    $result = processData($input);
    return ['success' => true, 'data' => $result];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'PROCESSING_ERROR',
            'message' => $e->getMessage()
        ],
        'data' => null
    ];
}
```

### 4. Keep Steps Atomic
Each step should do one thing well:

```php
<?php
// ✅ Good - Single responsibility
// Step 1: Validate input
if (empty($input['email'])) {
    return ['success' => false, 'error' => ['code' => 'MISSING_EMAIL']];
}

// Step 2: Save to database
$stmt = $db->prepare("INSERT INTO customers (email) VALUES (?)");
$stmt->execute([$input['email']]);

// Step 3: Send notification
$email->send($input['email'], 'Welcome!', 'Your account is created');
```

### 5. Use Consistent Error Codes
```php
<?php
// Standard error codes
const ERROR_CODES = [
    'VALIDATION_ERROR' => 'Input validation failed',
    'DATABASE_ERROR' => 'Database operation failed',
    'CONNECTOR_ERROR' => 'External service error',
    'BUSINESS_RULE_ERROR' => 'Business logic violation',
    'PERMISSION_ERROR' => 'Access denied',
    'TIMEOUT_ERROR' => 'Operation timed out',
    'RESOURCE_ERROR' => 'Resource not available',
    'UNKNOWN_ERROR' => 'Unexpected error'
];
```

## Spawning Async Processes from Process Steps

You can spawn asynchronous process executions from within a process step code snippet. This is useful when you need to trigger long-running processes without blocking the current step execution.

**Recommended Approach: API Call with Execution Authorization Key** - The simplest and most secure method using the built-in `$api` service with automatic authentication.

### Approach 1: API Call with Execution Authorization Key (RECOMMENDED)

**Pros:**
- ✅ **Simplest code** - Just use `$api->post()` with automatic authentication
- ✅ **Automatic headers** - Execution auth key is automatically included
- ✅ **Secure** - Uses HMAC-SHA256 hash based on execution context
- ✅ **No manual setup** - Works out of the box
- ✅ **Consistent** - Same pattern as other API calls

**How it works:**
The `$api` service automatically includes an `X-Process-Authorization-Key` header generated from your execution context. This key is a strong HMAC-SHA256 hash of `execution_id|tenant_id|user_id` signed with the system secret.

```php
<?php
// Get base URL from HTTP headers (when triggered by WebApp)
$host = $http_headers['Host'] ?? 'localhost';
$protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') 
    ? 'https' 
    : 'http';
$baseUrl = "{$protocol}://{$host}";

// Spawn async process via API - execution auth key is automatically included!
$result = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
    'process_id' => 'process_abc123',
    'input_data' => [
        'customer_id' => $process_input['customer_id'],
        'order_amount' => $process_input['amount']
    ],
    'async' => true,
    'options' => [
        'priority' => 'normal'
    ]
]);

if ($result['success'] && isset($result['data']['queue_id'])) {
    $process_output['async_process_queue_id'] = $result['data']['queue_id'];
    $process_output['async_process_status'] = 'queued';
    
    return [
        'success' => true,
        'data' => $process_output,
        'message' => 'Async process queued successfully'
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'API_ERROR',
            'message' => 'Failed to queue async process',
            'details' => $result['data']['error'] ?? 'Unknown error'
        ]
    ];
}
```

**Note:** The `execution_auth_key` variable is also available directly if you need it for custom headers:

```php
<?php
// Access execution auth key directly (if needed for custom headers)
$customHeaders = [
    'X-Custom-Header' => 'value',
    'X-Process-Authorization-Key' => $execution_auth_key
];
$result = $api->post($url, $data, $customHeaders);
```

### Approach 2: Direct Service Call (ONLY FOR TEALFABRIC INTERNAL USERS)

**Pros:**
- ✅ Direct database access, faster
- ✅ Works immediately

**Cons:**
- ❌ Requires `require_once` and importing service class
- ❌ More verbose code
- ❌ Only for internal Tealfabric developers

```php
<?php
// Spawn an async process from a process step
require_once __DIR__ . '/../../src/Services/BackgroundJobQueueService.php';

use App\Services\BackgroundJobQueueService;

// Create queue service for ProcessExecutionQueue
$queueService = new BackgroundJobQueueService($db, 'ProcessExecutionQueue');

// Prepare job data
$jobData = [
    'process_id' => 'process_abc123',  // Process ID to execute
    'tenant_id' => $tenant_id,          // Current tenant ID
    'input_data' => [                   // Input data for the process
        'customer_id' => $process_input['customer_id'],
        'order_amount' => $process_input['amount']
    ],
    'raw_input' => null                 // Optional raw input string
];

// Enqueue options
$options = [
    'async' => true,                    // Always true for async execution
    'priority' => 'normal',             // 'low', 'normal', 'high', or 'urgent'
    'execution_strategy' => 'sequential' // Optional: execution strategy
];

// Enqueue the process for async execution
$queueId = $queueService->enqueue('processflow', $jobData, $options);

// Store queue_id in output for tracking
$process_output['async_process_queue_id'] = $queueId;
$process_output['async_process_status'] = 'queued';

return [
    'success' => true,
    'data' => $process_output,
    'message' => 'Async process queued successfully'
];
```

### Approach 2: API Call (Simpler, but Requires Authentication) (ALL EXTERNAL USERS)

**Pros:**
- ✅ Simpler code (just HTTP call)
- ✅ Consistent with external integrations
- ✅ Better separation of concerns

**Cons:**
- ❌ Requires authentication (API key or Bearer token)
- ❌ HTTP overhead
- ⚠️ **Currently not fully supported** - API key authentication is planned but not yet implemented

```php
<?php
// Spawn async process via API call (simpler code)
// Note: Requires API key authentication (planned feature)

// Get base URL from HTTP headers (when triggered by WebApp)
$host = $http_headers['Host'] ?? 'localhost';
$protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') 
    ? 'https' 
    : 'http';
$baseUrl = "{$protocol}://{$host}";

// Call the API endpoint
$result = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
    'process_id' => 'process_abc123',
    'input_data' => [
        'customer_id' => $process_input['customer_id'],
        'order_amount' => $process_input['amount']
    ],
    'async' => true,
    'options' => [
        'priority' => 'normal'
    ]
], [
    // TODO: Add API key authentication header when implemented
    // 'Authorization' => 'Bearer YOUR_API_KEY'
    // or
    // 'X-API-Key' => 'YOUR_API_KEY'
]);

if ($result['success'] && isset($result['data']['queue_id'])) {
    $process_output['async_process_queue_id'] = $result['data']['queue_id'];
    $process_output['async_process_status'] = 'queued';
    
    return [
        'success' => true,
        'data' => $process_output,
        'message' => 'Async process queued successfully'
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'API_ERROR',
            'message' => 'Failed to queue async process',
            'details' => $result['data']['error'] ?? 'Unknown error'
        ]
    ];
}
```

**Note:** API key authentication is planned (see `docs/PLANS/Sprint-2025-12/API_KEY_AUTHENTICATION_PLAN.md`). Once implemented, API calls will be the recommended approach for simplicity. For now, use **Approach 1 (Direct Service Call)**.

### Example: Triggering Follow-up Process

```php
<?php
// After processing an order, trigger a follow-up process asynchronously
require_once __DIR__ . '/../../src/Services/BackgroundJobQueueService.php';

use App\Services\BackgroundJobQueueService;

try {
    $queueService = new BackgroundJobQueueService($db, 'ProcessExecutionQueue');
    
    // Spawn async process for order fulfillment
    $queueId = $queueService->enqueue('processflow', [
        'process_id' => 'process_order_fulfillment',
        'tenant_id' => $tenant_id,
        'input_data' => [
            'order_id' => $process_input['order_id'],
            'customer_id' => $process_input['customer_id'],
            'items' => $process_input['items']
        ]
    ], [
        'async' => true,
        'priority' => 'high'  // High priority for order fulfillment
    ]);
    
    // Continue with current step (don't wait for fulfillment)
    return [
        'success' => true,
        'data' => [
            'order_processed' => true,
            'fulfillment_queue_id' => $queueId,
            'status' => 'order_queued_for_fulfillment'
        ]
    ];
    
} catch (Exception $e) {
    // Log error but don't fail the current step
    error_log("Failed to queue async process: " . $e->getMessage());
    
    return [
        'success' => true,  // Current step succeeds even if async queue fails
        'data' => [
            'order_processed' => true,
            'fulfillment_queue_id' => null,
            'fulfillment_error' => 'Failed to queue fulfillment process'
        ],
        'warning' => 'Async process queue failed, but order was processed'
    ];
}
```

### Example: Conditional Async Process Spawning

```php
<?php
// Conditionally spawn async process based on business logic
require_once __DIR__ . '/../../src/Services/BackgroundJobQueueService.php';

use App\Services\BackgroundJobQueueService;

$amount = floatval($process_input['amount'] ?? 0);
$spawnedProcesses = [];

// For high-value orders, spawn multiple async processes
if ($amount > 1000) {
    // Spawn fraud check process
    $queueService = new BackgroundJobQueueService($db, 'ProcessExecutionQueue');
    
    $fraudCheckQueueId = $queueService->enqueue('processflow', [
        'process_id' => 'process_fraud_check',
        'tenant_id' => $tenant_id,
        'input_data' => [
            'order_id' => $process_input['order_id'],
            'amount' => $amount,
            'customer_id' => $process_input['customer_id']
        ]
    ], [
        'async' => true,
        'priority' => 'urgent'  // Urgent priority for fraud checks
    ]);
    
    $spawnedProcesses['fraud_check'] = $fraudCheckQueueId;
    
    // Spawn VIP customer notification process
    $vipNotificationQueueId = $queueService->enqueue('processflow', [
        'process_id' => 'process_vip_notification',
        'tenant_id' => $tenant_id,
        'input_data' => [
            'customer_id' => $process_input['customer_id'],
            'order_amount' => $amount
        ]
    ], [
        'async' => true,
        'priority' => 'normal'
    ]);
    
    $spawnedProcesses['vip_notification'] = $vipNotificationQueueId;
}

return [
    'success' => true,
    'data' => [
        'order_id' => $process_input['order_id'],
        'amount' => $amount,
        'async_processes' => $spawnedProcesses,
        'spawned_count' => count($spawnedProcesses)
    ]
];
```

### Example: Spawning Process with Scheduled Execution

```php
<?php
// Spawn a process that should execute at a specific time
require_once __DIR__ . '/../../src/Services/BackgroundJobQueueService.php';

use App\Services\BackgroundJobQueueService;

$queueService = new BackgroundJobQueueService($db, 'ProcessExecutionQueue');

// Calculate scheduled time (e.g., 1 hour from now)
$scheduledTime = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Enqueue with scheduled_at option
$queueId = $queueService->enqueue('processflow', [
    'process_id' => 'process_send_reminder',
    'tenant_id' => $tenant_id,
    'input_data' => [
        'customer_id' => $process_input['customer_id'],
        'reminder_type' => 'payment_due'
    ]
], [
    'async' => true,
    'priority' => 'normal',
    'scheduled_at' => $scheduledTime  // Process will execute at this time
]);

return [
    'success' => true,
    'data' => [
        'reminder_queued' => true,
        'queue_id' => $queueId,
        'scheduled_at' => $scheduledTime
    ]
];
```

### Important Notes

1. **Always use `async: true`**: When spawning from code snippets, always set `async: true` in options
2. **Tenant ID**: Always use `$tenant_id` variable (not hardcoded values)
3. **Error Handling**: Consider whether async queue failures should fail the current step or just log warnings
4. **Queue ID Tracking**: Store `queue_id` in output if you need to track the spawned process later
5. **Priority Levels**: Use appropriate priority:
   - `urgent` - Critical business operations
   - `high` - Important background tasks
   - `normal` - Standard operations (default)
   - `low` - Non-critical tasks

### Checking Async Process Status

After spawning an async process, you can check its status using the queue_id:

```php
<?php
// Get status of a queued process
require_once __DIR__ . '/../../src/Services/BackgroundJobQueueService.php';

use App\Services\BackgroundJobQueueService;

$queueService = new BackgroundJobQueueService($db, 'ProcessExecutionQueue');
$queueId = $process_input['async_process_queue_id'] ?? null;

if ($queueId) {
    $job = $queueService->getJob($queueId);
    
    if ($job) {
        $process_output['async_status'] = $job['status'];  // 'pending', 'running', 'completed', 'failed'
        $process_output['async_started_at'] = $job['started_at'] ?? null;
        $process_output['async_completed_at'] = $job['completed_at'] ?? null;
        
        if ($job['status'] === 'failed') {
            $process_output['async_error'] = $job['error_message'] ?? 'Unknown error';
        }
    }
}

return ['success' => true, 'data' => $process_output];
```

### Use Cases

- **Long-running operations**: Spawn processes with LLM calls or heavy computation
- **Follow-up workflows**: Trigger downstream processes after main process completes
- **Parallel processing**: Spawn multiple processes to run concurrently
- **Scheduled tasks**: Queue processes to execute at specific times
- **Background notifications**: Send emails/notifications without blocking main flow

## Common Patterns

### 1. Data Transformation Pipeline
```php
<?php
// Transform and enrich data
$output = $input; // Start with input

// Add computed fields
$output['full_name'] = $input['first_name'] . ' ' . $input['last_name'];
$output['email_domain'] = substr(strrchr($input['email'], "@"), 1);
$output['is_vip'] = $input['total_orders'] > 10;

// Add metadata
$output['processed_at'] = date('Y-m-d H:i:s');
$output['processed_by'] = $user_id;

return ['success' => true, 'data' => $output];
```

### 2. Conditional Processing
```php
<?php
// Process based on conditions
if ($input['customer_type'] === 'premium') {
    $output = [
        'discount_rate' => 0.15,
        'priority' => 'high',
        'support_level' => 'dedicated'
    ];
} elseif ($input['customer_type'] === 'standard') {
    $output = [
        'discount_rate' => 0.05,
        'priority' => 'normal',
        'support_level' => 'standard'
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_CUSTOMER_TYPE',
            'message' => 'Unknown customer type'
        ],
        'data' => null
    ];
}

return ['success' => true, 'data' => $output];
```

### 3. Batch Processing
```php
<?php
// Process multiple items
$results = [];
$errors = [];

foreach ($input['items'] as $item) {
    try {
        $processed = processItem($item);
        $results[] = $processed;
    } catch (Exception $e) {
        $errors[] = [
            'item' => $item,
            'error' => $e->getMessage()
        ];
    }
}

if (!empty($errors)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'BATCH_PROCESSING_ERROR',
            'message' => 'Some items failed to process',
            'details' => $errors
        ],
        'data' => ['processed' => $results]
    ];
}

return [
    'success' => true,
    'data' => [
        'processed_items' => $results,
        'total_count' => count($results)
    ]
];
```

## Error Code Reference

| Error Code | Description | When to Use |
|------------|-------------|-------------|
| `VALIDATION_ERROR` | Input validation failed | Missing or invalid input data |
| `DATABASE_ERROR` | Database operation failed | SQL errors, connection issues |
| `CONNECTOR_ERROR` | External service error | API failures, service unavailable |
| `BUSINESS_RULE_ERROR` | Business logic violation | Rule violations, policy breaches |
| `PERMISSION_ERROR` | Access denied | Insufficient permissions |
| `TIMEOUT_ERROR` | Operation timed out | Long-running operations |
| `RESOURCE_ERROR` | Resource not available | File not found, service down |
| `UNKNOWN_ERROR` | Unexpected error | Catch-all for unexpected issues |

## Process Flow Behavior

The ProcessFlow system handles your return values as follows:

### Success Case (`success: true`)
1. Extracts the `data` field
2. Passes it as `$input` to the next step
3. Continues process execution
4. Logs success message

### Error Case (`success: false`)
1. Logs the error details
2. Stops process execution
3. Returns error information to the user
4. Does not proceed to next steps

This ensures that errors are handled gracefully and don't crash the entire process.

## Security Considerations

1. **Input Validation**: Always validate input data
2. **SQL Injection**: Use prepared statements
3. **XSS Prevention**: Escape output data
4. **Resource Limits**: Respect memory and time limits
5. **Tenant Isolation**: Always use `$tenant_id` in queries

## Performance Tips

1. **Keep Steps Light**: Avoid heavy operations in single steps
2. **Use Indexes**: Ensure database queries use proper indexes
3. **Batch Operations**: Process multiple items together when possible
4. **Cache Results**: Store frequently accessed data
5. **Monitor Resources**: Track memory and execution time usage

---

For more information, see:
- [ProcessFlow Architecture Guide](PROCESSFLOW_ARCHITECTURE.md)
- [ProcessFlow API Reference](PROCESSFLOW_API_REFERENCE.md)
- [ProcessFlow Security Guide](PROCESSFLOW_SECURITY.md)
