<?php
/**
 * ProcessFlow Step Code Snippet Prototype
 * 
 * NOTE: This is a reference file with examples. The log_message() function
 * is injected into the execution context at runtime and is available in all
 * process step code snippets.
 * 
 * INSTRUCTIONS FOR CURSOR AGENT:
 * ===============================
 * 
 * 1. INTERFACE HANDLING:
 *    - Use $process_input (array) to receive data from previous step
 *    - Always return array with 'success' (bool) and 'data' (array) keys
 *    - Use 'error' key (array with 'code', 'message', 'details') for failures
 *    - Never use echo, print, var_dump, or print_r - use log_message() for debugging
 * 
 * 2. DATABASE ACCESS:
 *    - ALWAYS use $tenantDb (TenantDatabaseService) for ALL database operations
 *    - $db (PDO) is NOT available due to security guardrails - DO NOT use it
 *    - $tenantDb automatically enforces tenant isolation - no manual tenant_id needed
 *    - For global/system tables: Use $api service to call internal APIs instead
 * 
 * 3. FILE OPERATIONS:
 *    - Use $files service (ProcessFileService) when available (WebApp uploads)
 *    - For tenant-scoped file storage: use storage/tenantdata/{$tenant_id}/ paths
 *    - Validate all file paths to prevent directory traversal
 *    - Never expose full server paths - use relative paths only
 * 
 * 4. VARIABLE HANDLING:
 *    - Validate all input data before use
 *    - Use type checking (is_string, is_array, is_numeric, etc.)
 *    - Sanitize user input (htmlspecialchars for output, filter_var for validation)
 *    - Never trust input data - always validate and sanitize
 * 
 * 5. ERROR HANDLING:
 *    - Always return structured error responses
 *    - Use try-catch for operations that can fail
 *    - Log errors with log_message() for debugging
 *    - Never throw exceptions - return error arrays instead
 * 
 * 6. CODE CLEANUP:
 *    - Remove all comments and example code in final output
 *    - Keep only production-ready code
 *    - Remove debug statements (log_message calls can stay for production logging)
 *    - Ensure code follows PSR-12 style guidelines
 * 
 * 7. SECURITY:
 *    - Never concatenate user input into SQL queries
 *    - Always use prepared statements or $tenantDb methods
 *    - Validate file paths and prevent directory traversal
 *    - Use $tenant_id from context, never from user input
 *    - Sanitize all output data
 * 
 * 8. PERFORMANCE & EXECUTION TIME CONSTRAINTS:
 *    - Default timeout: 30 seconds per step (configurable up to 300 seconds)
 *    - Maximum total process time: 300 seconds (5 minutes)
 *    - Default memory limit: 64MB (configurable up to 256MB)
 *    - ALWAYS profile your code before deployment
 *    - Use log_message() to track execution time of critical sections
 *    - For long-running operations, consider async execution or splitting into multiple steps
 *    - Integration calls in sync mode should complete within step timeout
 *    - Database queries should be optimized (use indexes, limit result sets)
 *    - Avoid nested loops with large datasets
 *    - Use batch processing for large data operations
 * 
 * AVAILABLE VARIABLES:
 * ====================
 * $process_input (array) - Data from previous step
 * $raw_input (string) - Raw POST/JSON data (WebApp only)
 * $tenant_id (string) - Current tenant ID
 * $user_id (string) - Current user ID
 * $tenantDb (object) - Tenant-scoped database service (REQUIRED for all DB operations)
 * $db (PDO) - NOT AVAILABLE - blocked by security guardrails
 * $datapool (object) - DataPool service for schema-based data storage and querying
 * $integration (object) - Integration execution service (RECOMMENDED)
 * $api (object) - API service for HTTP requests (automatically includes execution authorization key)
 * $execution_auth_key (string|null) - Execution authorization key for internal API calls (HMAC-SHA256 hash)
 * $llm (object) - LLM service for AI operations
 * $email (object) - Email service
 * $notification (object) - Notification service
 * $files (object|null) - File service (only when execution_id is set)
 * $http_headers (array) - HTTP headers (WebApp only)
 * $request_method (string) - HTTP method (WebApp only)
 * $request_uri (string) - Request URI (WebApp only)
 * $remote_addr (string) - Client IP (WebApp only)
 * 
 * FUNCTION: log_message(string $message) - Log to execution log file and database
 * 
 * DATAPOOL SERVICE METHODS:
 * ========================
 * $datapool->createSchema(string $schemaName, array $schemaDefinition, string $schemaType = 'structured', string $storageBackend = 'database', ?string $description = null): string
 *   - Create a new DataPool schema
 *   - Returns schema_id
 * 
 * $datapool->getSchema(string $schemaName): ?array
 *   - Get schema definition by name
 *   - Returns schema array or null if not found
 * 
 * $datapool->listSchemas(): array
 *   - List all schemas for the tenant
 *   - Returns array of schema definitions
 * 
 * $datapool->insert(string $schemaName, array $data, array $options = []): string
 *   - Insert data into a schema
 *   - Returns data_id (UUID)
 *   - Options: auto_create_schema, auto_merge_schema, schema_type, storage_backend, description, source_type, source_id, entity_type, quality_score
 * 
 * $datapool->update(string $schemaName, string $dataId, array $data, array $options = []): bool
 *   - Update data in a schema
 *   - Returns true on success, false on failure
 * 
 * $datapool->delete(string $schemaName, string $dataId, array $options = []): bool
 *   - Delete data from a schema
 *   - Returns true on success, false on failure
 * 
 * $datapool->query(string $query, array $params = []): array
 *   - Execute SQL-like query (SELECT ... FROM schema_name ...)
 *   - Returns array of results
 *   - Note: Currently supports basic SELECT queries only
 * 
 * $datapool->table(string $schemaName): DataPoolQueryBuilder
 *   - Start fluent query builder
 *   - Returns DataPoolQueryBuilder instance for chaining
 * 
 * $datapool->bulkInsert(string $schemaName, array $dataArray, array $options = []): array
 *   - Insert multiple records at once
 *   - Returns array of inserted data_ids
 * 
 * QUERY BUILDER METHODS (via $datapool->table()):
 * ===============================================
 * ->where(string $field, $value, string $operator = '='): self
 * ->orWhere(string $field, $value, string $operator = '='): self
 * ->whereIn(string $field, array $values): self
 * ->whereBetween(string $field, $min, $max): self
 * ->orderBy(string $field, string $direction = 'ASC'): self
 * ->limit(int $limit): self
 * ->offset(int $offset): self
 * ->select(string ...$fields): self
 * ->groupBy(string ...$fields): self
 * ->having(string $field, $value, string $operator = '='): self
 * ->join(string $schemaName, string $onCondition, string $type = 'INNER'): self
 * ->get(): array - Execute query and get all results
 * ->first(): ?array - Execute query and get first result
 * ->count(): int - Execute query and get count
 */

// Stub function for linter (function is injected at runtime in execution context)
if (!function_exists('log_message')) {
    function log_message(string $message): void {
        // This is a stub - actual function is injected at runtime
    }
}

// ============================================================================
// EXAMPLE PATTERNS (Remove in final code - these are for reference only)
// ============================================================================

// Pattern 1: Basic Data Processing
$input = $process_input;
$result = [];

foreach ($input as $key => $value) {
    $result[$key] = strtoupper($value);
}

return [
    'success' => true,
    'data' => $result,
    'message' => 'Processing completed successfully'
];

// Pattern 2: Database Query with $tenantDb (RECOMMENDED)
$customer = $tenantDb->getOne(
    "SELECT * FROM Customers WHERE email = ?",
    [$process_input['email']]
);

if (!$customer) {
    return [
        'success' => false,
        'error' => [
            'code' => 'CUSTOMER_NOT_FOUND',
            'message' => 'Customer not found',
            'details' => 'No customer with email: ' . $process_input['email']
        ],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['customer' => $customer]];

// Pattern 3: Database Insert with $tenantDb
$orderId = $tenantDb->insert('Orders', [
    'customer_id' => $process_input['customer_id'],
    'amount' => floatval($process_input['amount']),
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s')
]);

if (!$orderId) {
    return [
        'success' => false,
        'error' => ['code' => 'INSERT_FAILED', 'message' => 'Failed to create order'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['order_id' => $orderId]];

// Pattern 4: Database Update with $tenantDb
$affectedRows = $tenantDb->update('Orders', [
    'status' => 'completed',
    'updated_at' => date('Y-m-d H:i:s')
], [
    'order_id' => $process_input['order_id']
]);

return ['success' => true, 'data' => ['updated' => $affectedRows > 0]];

// Pattern 5: Global Table Access via API Service (Countries, SubscriptionPlans, etc.)
// Note: $db (PDO) is not available due to security guardrails
// Use $api service to access global/system tables through internal APIs
$apiResult = $api->get('/api/v1/countries?iso_code=' . urlencode($process_input['country_code']));

if (!$apiResult['success']) {
    return [
        'success' => false,
        'error' => ['code' => 'API_ERROR', 'message' => 'Failed to fetch country data'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['country' => $apiResult['data'] ?? null]];

// Pattern 6: Integration Execution (Synchronous)
// Execute an integration and wait for the result
$result = $integration->executeSync($process_input['integration_id'], [
    'query' => $process_input['search_term'],
    'limit' => 10
]);

if (!$result['success']) {
    return [
        'success' => false,
        'error' => [
            'code' => 'INTEGRATION_ERROR',
            'message' => 'Integration execution failed',
            'details' => $result['error'] ?? 'Unknown error'
        ],
        'data' => null
    ];
}

return ['success' => true, 'data' => $result['result']];

// Pattern 6b: Integration Execution (Asynchronous)
// Execute an integration asynchronously and get execution ID
$executionId = $integration->executeAsync($process_input['integration_id'], [
    'query' => $process_input['search_term'],
    'limit' => 10
], [
    'priority' => 'high',
    'callback_url' => null  // Optional: URL to call when execution completes
]);

// Store execution ID for later retrieval
log_message("Integration execution started: {$executionId}");

// Check status later (in a subsequent step or polling)
$status = $integration->getStatus($executionId);
if ($status['found'] && $status['status'] === 'completed') {
    $result = $integration->getResult($executionId);
    return ['success' => true, 'data' => $result['result'] ?? null];
} elseif ($status['found'] && $status['status'] === 'failed') {
    return [
        'success' => false,
        'error' => ['code' => 'INTEGRATION_FAILED', 'message' => 'Integration execution failed'],
        'data' => null
    ];
}

// Still processing
return ['success' => true, 'data' => ['status' => 'pending', 'execution_id' => $executionId]];

// Pattern 6c: Fetch Available Integrations via API
// Use $api service to fetch list of available integrations
$integrationsResult = $api->get('/api/v1/integrations');

if (!$integrationsResult['success']) {
    return [
        'success' => false,
        'error' => ['code' => 'API_ERROR', 'message' => 'Failed to fetch integrations'],
        'data' => null
    ];
}

$integrations = $integrationsResult['data']['integrations'] ?? [];
log_message("Found " . count($integrations) . " available integrations");

// Filter integrations by type or name
$filteredIntegrations = array_filter($integrations, function($integration) {
    return isset($integration['type']) && $integration['type'] === 'api';
});

return ['success' => true, 'data' => ['integrations' => array_values($filteredIntegrations)]];

// Pattern 6d: Integration with Error Handling and Retry Logic
$maxRetries = 3;
$retryCount = 0;
$result = null;

while ($retryCount < $maxRetries) {
    $result = $integration->executeSync($process_input['integration_id'], [
        'query' => $process_input['search_term'],
        'limit' => 10
    ]);
    
    if ($result['success']) {
        break; // Success, exit retry loop
    }
    
    $retryCount++;
    log_message("Integration execution failed (attempt {$retryCount}/{$maxRetries}): " . ($result['error']['message'] ?? 'Unknown error'));
    
    if ($retryCount < $maxRetries) {
        sleep(1); // Wait 1 second before retry
    }
}

if (!$result['success']) {
    return [
        'success' => false,
        'error' => [
            'code' => 'INTEGRATION_ERROR',
            'message' => 'Integration execution failed after ' . $maxRetries . ' attempts',
            'details' => $result['error'] ?? 'Unknown error'
        ],
        'data' => null
    ];
}

return ['success' => true, 'data' => $result['result']];

// Pattern 7: API Service Usage
$apiResult = $api->get('/api/v1/entities');

if (!$apiResult['success']) {
    return [
        'success' => false,
        'error' => ['code' => 'API_ERROR', 'message' => 'Failed to fetch entities'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['entities' => $apiResult['data']['entities'] ?? []]];

// Pattern 7b: Spawning Async Processes via API (RECOMMENDED)
// The $api service automatically includes X-Process-Authorization-Key header for authentication
// This is the preferred way to spawn async processes from process steps
$baseUrl = 'https://tealfabric.io';

$result = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
    'process_id' => $process_input['target_process_id'],
    'input_data' => [
        'customer_id' => $process_input['customer_id'],
        'order_amount' => $process_input['amount'],
        'source_process' => 'order_processing'
    ],
    'async' => true,
    'options' => [
        'priority' => 'normal',
        'execution_strategy' => 'sequential'
    ]
]);

if ($result['success'] && isset($result['data']['queue_id'])) {
    log_message("Async process queued successfully: queue_id={$result['data']['queue_id']}");
    return [
        'success' => true,
        'data' => [
            'async_process_queue_id' => $result['data']['queue_id'],
            'async_process_status' => 'queued',
            'execution_mode' => 'async'
        ],
        'message' => 'Async process queued successfully'
    ];
} else {
    return [
        'success' => false,
        'error' => [
            'code' => 'ASYNC_PROCESS_ERROR',
            'message' => 'Failed to queue async process',
            'details' => $result['data']['error'] ?? 'Unknown error'
        ],
        'data' => null
    ];
}

// Pattern 7c: Spawning Multiple Async Processes
// Spawn multiple async processes for parallel execution
$baseUrl = 'https://tealfabric.io';
$spawnedProcesses = [];

// Spawn fraud check process
$fraudCheckResult = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
    'process_id' => 'process_fraud_check',
    'input_data' => [
        'order_id' => $process_input['order_id'],
        'amount' => $process_input['amount'],
        'customer_id' => $process_input['customer_id']
    ],
    'async' => true,
    'options' => ['priority' => 'urgent']
]);

if ($fraudCheckResult['success'] && isset($fraudCheckResult['data']['queue_id'])) {
    $spawnedProcesses['fraud_check'] = $fraudCheckResult['data']['queue_id'];
}

// Spawn notification process
$notificationResult = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
    'process_id' => 'process_send_notification',
    'input_data' => [
        'customer_id' => $process_input['customer_id'],
        'notification_type' => 'order_confirmed'
    ],
    'async' => true,
    'options' => ['priority' => 'normal']
]);

if ($notificationResult['success'] && isset($notificationResult['data']['queue_id'])) {
    $spawnedProcesses['notification'] = $notificationResult['data']['queue_id'];
}

return [
    'success' => true,
    'data' => [
        'spawned_processes' => $spawnedProcesses,
        'spawned_count' => count($spawnedProcesses)
    ]
];

// Pattern 7d: Conditional Async Process Spawning
// Spawn async process only if certain conditions are met
$baseUrl = 'https://tealfabric.io';
$amount = floatval($process_input['amount'] ?? 0);

if ($amount > 1000) {
    // High-value order - spawn VIP processing
    $result = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
        'process_id' => 'process_vip_handling',
        'input_data' => [
            'order_id' => $process_input['order_id'],
            'amount' => $amount,
            'customer_id' => $process_input['customer_id']
        ],
        'async' => true,
        'options' => ['priority' => 'high']
    ]);
    
    if ($result['success'] && isset($result['data']['queue_id'])) {
        log_message("VIP process queued: queue_id={$result['data']['queue_id']}");
        return [
            'success' => true,
            'data' => [
                'vip_processing' => true,
                'queue_id' => $result['data']['queue_id']
            ]
        ];
    }
}

// Continue with normal processing
return ['success' => true, 'data' => ['vip_processing' => false]];

// Pattern 7e: Checking Async Process Status
// Check the status of a previously spawned async process
$baseUrl = 'https://tealfabric.io';
$queueId = $process_input['async_process_queue_id'] ?? null;

if ($queueId) {
    $statusResult = $api->get("{$baseUrl}/api/v1/processflow?action=queue-status&queue_id=" . urlencode($queueId));
    
    if ($statusResult['success'] && isset($statusResult['data'])) {
        $status = $statusResult['data']['status'] ?? 'unknown';
        $process_output['async_status'] = $status;
        $process_output['async_started_at'] = $statusResult['data']['started_at'] ?? null;
        $process_output['async_completed_at'] = $statusResult['data']['completed_at'] ?? null;
        
        if ($status === 'failed') {
            $process_output['async_error'] = $statusResult['data']['error_message'] ?? 'Unknown error';
        }
    }
}

return ['success' => true, 'data' => $process_output];

// Pattern 8: File Operations (when $files is available)
if (isset($files) && $files !== null) {
    $uploadedFiles = $files->getFiles();
    $fileData = [];
    
    foreach ($uploadedFiles as $file) {
        $fileData[] = [
            'name' => $file['original_name'],
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }
    
    return ['success' => true, 'data' => ['files' => $fileData]];
}

return ['success' => true, 'data' => ['files' => []]];

// Pattern 9: Input Validation
$errors = [];

if (empty($process_input['email'])) {
    $errors[] = 'Email is required';
}

if (!empty($process_input['email']) && !filter_var($process_input['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format';
}

if (!empty($process_input['amount']) && floatval($process_input['amount']) < 0) {
    $errors[] = 'Amount cannot be negative';
}

if (!empty($errors)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Input validation failed',
            'details' => implode(', ', $errors)
        ],
        'data' => null
    ];
}

// Pattern 10: Error Handling with Try-Catch
try {
    $result = $tenantDb->query(
        "SELECT * FROM Orders WHERE customer_id = ? AND status = ?",
        [$process_input['customer_id'], 'pending']
    );
    
    return ['success' => true, 'data' => ['orders' => $result]];
    
} catch (Exception $e) {
    log_message("Database error: " . $e->getMessage());
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

// Pattern 11: Logging for Debugging
log_message("Processing order for customer: " . $process_input['customer_id']);
log_message("Order amount: " . $process_input['amount']);

// Pattern 12: Conditional Processing
if ($process_input['amount'] > 1000) {
    $discount = 0.15;
    $priority = 'high';
} else {
    $discount = 0.05;
    $priority = 'normal';
}

return [
    'success' => true,
    'data' => [
        'discount_rate' => $discount,
        'priority' => $priority,
        'final_amount' => $process_input['amount'] * (1 - $discount)
    ]
];

// Pattern 13: Batch Processing
$results = [];
$errors = [];

foreach ($process_input['items'] as $item) {
    try {
        // Replace processItem() with your actual processing logic
        $processed = [
            'id' => $item['id'],
            'status' => 'processed',
            'data' => $item['data']
        ];
        $results[] = $processed;
    } catch (Exception $e) {
        $errors[] = ['item' => $item, 'error' => $e->getMessage()];
    }
}

if (!empty($errors)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'BATCH_ERROR',
            'message' => 'Some items failed',
            'details' => $errors
        ],
        'data' => ['processed' => $results]
    ];
}

return ['success' => true, 'data' => ['processed_items' => $results]];

// Pattern 14: DataPool - Create Schema
$schemaId = $datapool->createSchema(
    'customers',
    [
        'customer_id' => 'string',
        'name' => 'string',
        'email' => 'string',
        'created_at' => 'datetime'
    ],
    'structured',
    'database',
    'Customer data schema'
);

return ['success' => true, 'data' => ['schema_id' => $schemaId]];

// Pattern 15: DataPool - Get Schema
$schema = $datapool->getSchema('customers');

if (!$schema) {
    return [
        'success' => false,
        'error' => ['code' => 'SCHEMA_NOT_FOUND', 'message' => 'Schema not found'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['schema' => $schema]];

// Pattern 16: DataPool - List Schemas
$schemas = $datapool->listSchemas();
log_message("Found " . count($schemas) . " schemas");

return ['success' => true, 'data' => ['schemas' => $schemas]];

// Pattern 17: DataPool - Insert Data
$dataId = $datapool->insert('customers', [
    'customer_id' => $process_input['customer_id'],
    'name' => $process_input['name'],
    'email' => $process_input['email'],
    'created_at' => date('Y-m-d H:i:s')
], [
    'source_type' => 'process',
    'source_id' => $process_input['process_execution_id'] ?? null
]);

return ['success' => true, 'data' => ['data_id' => $dataId]];

// Pattern 18: DataPool - Insert with Auto-Create Schema
// Automatically creates schema if it doesn't exist
$dataId = $datapool->insert('orders', [
    'order_id' => $process_input['order_id'],
    'customer_id' => $process_input['customer_id'],
    'amount' => floatval($process_input['amount']),
    'status' => 'pending'
], [
    'auto_create_schema' => true,
    'schema_type' => 'structured',
    'storage_backend' => 'database',
    'description' => 'Order data'
]);

return ['success' => true, 'data' => ['data_id' => $dataId]];

// Pattern 19: DataPool - Update Data
$success = $datapool->update('customers', $process_input['data_id'], [
    'email' => $process_input['new_email'],
    'updated_at' => date('Y-m-d H:i:s')
]);

if (!$success) {
    return [
        'success' => false,
        'error' => ['code' => 'UPDATE_FAILED', 'message' => 'Failed to update data'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['updated' => true]];

// Pattern 20: DataPool - Delete Data
$success = $datapool->delete('customers', $process_input['data_id']);

if (!$success) {
    return [
        'success' => false,
        'error' => ['code' => 'DELETE_FAILED', 'message' => 'Failed to delete data'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['deleted' => true]];

// Pattern 21: DataPool - Query with SQL
$results = $datapool->query(
    "SELECT * FROM customers WHERE email = ?",
    [$process_input['email']]
);

return ['success' => true, 'data' => ['customers' => $results]];

// Pattern 22: DataPool - Fluent Query Builder
$customers = $datapool->table('customers')
    ->where('status', 'active')
    ->where('created_at', date('Y-m-d'), '>=')
    ->orderBy('created_at', 'DESC')
    ->limit(100)
    ->get();

return ['success' => true, 'data' => ['customers' => $customers]];

// Pattern 23: DataPool - Query Builder with Multiple Conditions
$orders = $datapool->table('orders')
    ->where('customer_id', $process_input['customer_id'])
    ->whereIn('status', ['pending', 'processing'])
    ->whereBetween('amount', 100, 1000)
    ->orderBy('created_at', 'DESC')
    ->limit(50)
    ->get();

return ['success' => true, 'data' => ['orders' => $orders]];

// Pattern 24: DataPool - Query Builder First Result
$customer = $datapool->table('customers')
    ->where('email', $process_input['email'])
    ->first();

if (!$customer) {
    return [
        'success' => false,
        'error' => ['code' => 'CUSTOMER_NOT_FOUND', 'message' => 'Customer not found'],
        'data' => null
    ];
}

return ['success' => true, 'data' => ['customer' => $customer]];

// Pattern 25: DataPool - Query Builder Count
$activeCustomerCount = $datapool->table('customers')
    ->where('status', 'active')
    ->count();

return ['success' => true, 'data' => ['count' => $activeCustomerCount]];

// Pattern 26: DataPool - Bulk Insert
$orders = [
    ['customer_id' => '123', 'amount' => 100.50, 'status' => 'pending'],
    ['customer_id' => '124', 'amount' => 200.75, 'status' => 'pending'],
    ['customer_id' => '125', 'amount' => 150.25, 'status' => 'pending']
];

$insertedIds = $datapool->bulkInsert('orders', $orders, [
    'source_type' => 'batch_import'
]);

log_message("Inserted " . count($insertedIds) . " orders");

return ['success' => true, 'data' => ['inserted_ids' => $insertedIds]];

// Pattern 27: DataPool - Error Handling
try {
    $dataId = $datapool->insert('customers', [
        'name' => $process_input['name'],
        'email' => $process_input['email']
    ]);
    
    return ['success' => true, 'data' => ['data_id' => $dataId]];
    
} catch (\Exception $e) {
    log_message("DataPool error: " . $e->getMessage());
    return [
        'success' => false,
        'error' => [
            'code' => 'DATAPOOL_ERROR',
            'message' => 'DataPool operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}

// Pattern 28: DataPool - Complex Workflow
// 1. Check if schema exists, create if not
$schema = $datapool->getSchema('order_items');
if (!$schema) {
    $datapool->createSchema('order_items', [
        'order_id' => 'string',
        'product_id' => 'string',
        'quantity' => 'integer',
        'price' => 'decimal'
    ], 'structured', 'database', 'Order items schema');
}

// 2. Insert order items
$itemIds = [];
foreach ($process_input['items'] as $item) {
    $itemId = $datapool->insert('order_items', [
        'order_id' => $process_input['order_id'],
        'product_id' => $item['product_id'],
        'quantity' => intval($item['quantity']),
        'price' => floatval($item['price'])
    ]);
    $itemIds[] = $itemId;
}

// 3. Query inserted items
$insertedItems = $datapool->table('order_items')
    ->where('order_id', $process_input['order_id'])
    ->get();

return [
    'success' => true,
    'data' => [
        'item_ids' => $itemIds,
        'items' => $insertedItems
    ]
];

// ============================================================================
// PERFORMANCE PROFILING & OPTIMIZATION GUIDE:
// ============================================================================
// 
// EXECUTION TIME CONSTRAINTS:
// - Default step timeout: 30 seconds (configurable in step settings)
// - Maximum step timeout: 300 seconds (5 minutes)
// - Maximum total process time: 300 seconds (5 minutes)
// - Default memory limit: 64MB (configurable up to 256MB)
// 
// CRITICAL: Synchronous execution mode requires all operations to complete
// within the step timeout. If your code exceeds the timeout, the execution
// will be terminated and marked as failed.
//
// PROFILING TECHNIQUES:
// =====================
//
// 1. Measure Total Execution Time:
$stepStartTime = microtime(true);
// ... your code here ...
$stepExecutionTime = microtime(true) - $stepStartTime;
log_message("Step execution time: " . round($stepExecutionTime, 3) . " seconds");

// 2. Profile Critical Sections:
$dbStartTime = microtime(true);
$data = $tenantDb->query("SELECT * FROM Entities WHERE status = ?", ['active']);
$dbTime = microtime(true) - $dbStartTime;
log_message("Database query time: " . round($dbTime, 3) . " seconds");

$integrationStartTime = microtime(true);
$result = $integration->executeSync($integrationId, $data);
$integrationTime = microtime(true) - $integrationStartTime;
log_message("Integration execution time: " . round($integrationTime, 3) . " seconds");

// 3. Profile Loop Iterations:
$loopStartTime = microtime(true);
$processedCount = 0;
foreach ($process_input['items'] as $item) {
    $itemStartTime = microtime(true);
    // Process item
    $processedCount++;
    $itemTime = microtime(true) - $itemStartTime;
    if ($itemTime > 0.1) { // Log slow items (>100ms)
        log_message("Slow item processing: item_id={$item['id']}, time=" . round($itemTime, 3) . "s");
    }
}
$loopTime = microtime(true) - $loopStartTime;
log_message("Loop processed {$processedCount} items in " . round($loopTime, 3) . " seconds");

// 4. Check Time Remaining (for long operations):
$maxExecutionTime = 30; // Default timeout
$elapsedTime = microtime(true) - $stepStartTime;
$remainingTime = $maxExecutionTime - $elapsedTime;

if ($remainingTime < 5) {
    log_message("WARNING: Less than 5 seconds remaining. Consider early exit or async execution.");
    // Optionally return early with partial results
    return [
        'success' => true,
        'data' => ['partial_results' => $results, 'warning' => 'Execution time limit approaching']
    ];
}

// OPTIMIZATION STRATEGIES:
// ========================
//
// 1. Database Query Optimization:
// ❌ BAD: Fetching all records then filtering in PHP
// $allEntities = $tenantDb->query("SELECT * FROM Entities");
// $activeEntities = array_filter($allEntities, fn($e) => $e['status'] === 'active');
//
// ✅ GOOD: Filter in database query
// $activeEntities = $tenantDb->query("SELECT * FROM Entities WHERE status = ?", ['active']);
//
// ❌ BAD: N+1 query problem
// foreach ($ids as $id) {
//     $entity = $tenantDb->query("SELECT * FROM Entities WHERE entity_id = ?", [$id]);
// }
//
// ✅ GOOD: Single query with IN clause
// $placeholders = implode(',', array_fill(0, count($ids), '?'));
// $entities = $tenantDb->query("SELECT * FROM Entities WHERE entity_id IN ($placeholders)", $ids);
//
// 2. Limit Result Sets:
// ✅ GOOD: Always use LIMIT for potentially large result sets
// $entities = $tenantDb->query("SELECT * FROM Entities WHERE status = ? LIMIT 1000", ['active']);
//
// 3. Batch Processing for Large Datasets:
// ❌ BAD: Processing all items in one loop (may timeout)
// foreach ($process_input['items'] as $item) {
//     // Process each item synchronously
// }
//
// ✅ GOOD: Process in batches with time checks
// $batchSize = 100;
// $batches = array_chunk($process_input['items'], $batchSize);
// foreach ($batches as $batchIndex => $batch) {
//     $batchStartTime = microtime(true);
//     foreach ($batch as $item) {
//         // Process item
//     }
//     $batchTime = microtime(true) - $batchStartTime;
//     log_message("Batch {$batchIndex} processed in " . round($batchTime, 3) . " seconds");
//     
//     // Check if we have enough time for next batch
//     $elapsed = microtime(true) - $stepStartTime;
//     if ($elapsed > 25) { // Leave 5 seconds buffer
//         log_message("Time limit approaching, stopping batch processing");
//         break;
//     }
// }
//
// 4. Integration Execution Optimization:
// ❌ BAD: Multiple sequential integration calls
// $result1 = $integration->executeSync($id1, $data1);
// $result2 = $integration->executeSync($id2, $data2);
// $result3 = $integration->executeSync($id3, $data3);
//
// ✅ GOOD: Use async execution for independent calls, or combine into single call
// $executionId1 = $integration->executeAsync($id1, $data1);
// $executionId2 = $integration->executeSync($id2, $data2); // If result2 is needed immediately
// // Process result2 while result1 is executing
// $result1 = $integration->getResult($executionId1);
//
// 5. Memory Optimization:
// ✅ GOOD: Process data in chunks instead of loading everything into memory
// $offset = 0;
// $limit = 1000;
// while (true) {
//     $chunk = $tenantDb->query("SELECT * FROM Entities LIMIT ? OFFSET ?", [$limit, $offset]);
//     if (empty($chunk)) break;
//     
//     foreach ($chunk as $entity) {
//         // Process entity
//     }
//     
//     $offset += $limit;
//     unset($chunk); // Free memory
// }
//
// 6. Early Exit Strategies:
// ✅ GOOD: Return early if conditions aren't met
// if (empty($process_input['required_field'])) {
//     return ['success' => false, 'error' => ['code' => 'MISSING_FIELD', 'message' => 'Required field missing']];
// }
//
// ✅ GOOD: Skip unnecessary processing
// if ($process_input['skip_expensive_operation']) {
//     return ['success' => true, 'data' => ['skipped' => true]];
// }
//
// 7. Caching Expensive Operations:
// ✅ GOOD: Cache results of expensive operations within the same step
// static $cachedResult = null;
// if ($cachedResult === null) {
//     $cachedResult = $integration->executeSync($integrationId, $data);
// }
// // Use $cachedResult
//
// WHEN TO USE ASYNC EXECUTION:
// ============================
// Use async execution for:
// 1. Integration calls ($integration->executeAsync()) when:
//    - Integration call takes > 5 seconds
//    - You have multiple independent integration calls
//    - The result is not needed immediately in the current step
//    - You can check status/result in a subsequent step
//
// 2. Process spawning (via $api->post() with async: true) when:
//    - Process contains LLM calls (2-5 minutes)
//    - Process takes > 30 seconds
//    - You need to spawn follow-up processes without blocking
//    - You want parallel execution of multiple processes
//
// Example - Integration Async:
// $executionId = $integration->executeAsync($integrationId, $data);
// return [
//     'success' => true,
//     'data' => ['execution_id' => $executionId, 'status' => 'pending']
// ];
// // Check result in next step:
// $status = $integration->getStatus($executionId);
// if ($status['status'] === 'completed') {
//     $result = $integration->getResult($executionId);
// }
//
// Example - Process Async (RECOMMENDED):
// $baseUrl = 'https://tealfabric.io';
// $result = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process-advanced", [
//     'process_id' => 'process_long_running',
//     'input_data' => [...],
//     'async' => true,
//     'options' => ['priority' => 'normal']
// ]);
// if ($result['success'] && isset($result['data']['queue_id'])) {
//     return ['success' => true, 'data' => ['queue_id' => $result['data']['queue_id']]];
// }
//
// TESTING PERFORMANCE:
// ====================
// 1. Test with realistic data volumes (not just 1-2 records)
// 2. Test with maximum expected data volumes
// 3. Monitor execution logs for timing information
// 4. Set appropriate timeout in step configuration (default 30s, max 300s)
// 5. Use execution history to review actual execution times
// 6. Set up alerts for timeout failures
//
// COMMON PERFORMANCE PITFALLS:
// ============================
// 1. ❌ Processing large datasets without batching
// 2. ❌ N+1 database queries
// 3. ❌ Fetching all records when only a subset is needed
// 4. ❌ Synchronous integration calls for long-running operations
// 5. ❌ Not checking time remaining for long loops
// 6. ❌ Loading entire files into memory
// 7. ❌ Complex nested loops without early exits
// 8. ❌ Not using database indexes (ensure WHERE clauses use indexed columns)
//
// ============================================================================
// SECURITY BEST PRACTICES:
// ============================================================================
// 1. Always validate input: if (empty($input['field'])) { return error; }
// 2. Use $tenantDb for ALL database operations (automatic tenant isolation)
// 3. $db (PDO) is NOT available - use $api service for global/system tables
// 4. Never concatenate user input into SQL: ❌ "SELECT * FROM Users WHERE name = '{$name}'"
// 5. Sanitize output: htmlspecialchars($data) when displaying to users
// 6. Validate file paths: realpath() and check if within allowed directory
// 7. Use type checking: is_string(), is_array(), is_numeric(), filter_var()
// 8. Never trust $process_input - always validate before use
// 9. Use $tenant_id from context, never from user input
// 10. Log security-relevant operations with log_message()
// ============================================================================
