# Integration Connector Usage

This guide covers using integration connectors within ProcessFlow code snippets to interact with external services.

## Overview

The `$integration` service provides a unified interface for executing configured integrations (APIs, databases, cloud services). Integrations are pre-configured in the admin panel with credentials and connection settings.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│  $integration    │────▶│  External       │
│  (Execute)      │     │  Service         │     │  Service        │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$integration` | IntegrationHelper | **Recommended** - Execute integrations |
| `$connectors` | ConnectorService | **Deprecated** - Use $integration instead |

## IntegrationHelper Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `execute($id, $data, $options)` | array\|string | Execute sync or async |
| `executeSync($id, $data, $options)` | array | Execute synchronously (wait) |
| `executeAsync($id, $data, $options)` | string | Execute asynchronously (returns execution_id) |
| `getStatus($executionId)` | array | Get async execution status |
| `getResult($executionId)` | array\|null | Get async execution result |
| `cancel($executionId)` | bool | Cancel pending execution |

---

## Example 1: Synchronous Integration Execution

Execute integration and wait for result:

```php
<?php
$integrationId = $process_input['result']['integration_id'] ?? '';
$inputData = $process_input['result']['data'] ?? [];

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

try {
    $log_message("[INFO] Executing integration: $integrationId");
    
    // Execute synchronously - blocks until complete
    $result = $integration->executeSync($integrationId, $inputData);
    
    if ($result['success']) {
        $log_message("[INFO] Integration executed successfully");
        
        return [
            'success' => true,
            'data' => [
                'integration_result' => $result['result'] ?? $result['data'],
                'execution_time_ms' => $result['execution_time_ms'] ?? null
            ]
        ];
    } else {
        $log_message("[ERROR] Integration failed: " . ($result['error'] ?? 'Unknown error'));
        
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Integration execution failed',
            'data' => [
                'error_details' => $result
            ]
        ];
    }
} catch (\Exception $e) {
    $log_message("[ERROR] Integration exception: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => 'Integration error: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Asynchronous Execution

Execute without waiting (for long-running operations):

```php
<?php
$integrationId = $process_input['result']['integration_id'] ?? '';
$inputData = $process_input['result']['data'] ?? [];
$priority = $process_input['result']['priority'] ?? 'normal';
$callbackUrl = $process_input['result']['callback_url'] ?? null;

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

try {
    $options = [
        'priority' => $priority
    ];
    
    if ($callbackUrl) {
        $options['callback_url'] = $callbackUrl;
    }
    
    // Execute asynchronously - returns immediately
    $executionId = $integration->executeAsync($integrationId, $inputData, $options);
    
    $log_message("[INFO] Integration queued with execution ID: $executionId");
    
    return [
        'success' => true,
        'data' => [
            'execution_id' => $executionId,
            'status' => 'queued',
            'integration_id' => $integrationId,
            'message' => 'Integration execution queued. Use execution_id to check status.'
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Failed to queue integration: ' . $e->getMessage()
    ];
}
```

---

## Example 3: Check Async Execution Status

Poll for completion of async execution:

```php
<?php
$executionId = $process_input['result']['execution_id'] ?? '';

if (empty($executionId)) {
    return ['success' => false, 'error' => 'Execution ID required'];
}

try {
    $status = $integration->getStatus($executionId);
    
    if (!$status['found']) {
        return [
            'success' => false,
            'error' => 'Execution not found',
            'data' => ['execution_id' => $executionId]
        ];
    }
    
    $response = [
        'success' => true,
        'data' => [
            'execution_id' => $executionId,
            'status' => $status['status'],
            'execution_time_ms' => $status['execution_time_ms'] ?? null,
            'created_at' => $status['created_at'] ?? null
        ]
    ];
    
    // If completed, fetch result
    if ($status['status'] === 'completed') {
        $result = $integration->getResult($executionId);
        $response['data']['result'] = $result;
        $response['route'] = 'completed';
    } elseif ($status['status'] === 'failed') {
        $response['data']['error'] = $status['error_message'] ?? 'Unknown error';
        $response['route'] = 'failed';
    } elseif ($status['status'] === 'pending' || $status['status'] === 'running') {
        $response['route'] = 'pending';
    }
    
    return $response;
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Status check failed: ' . $e->getMessage()
    ];
}
```

---

## Example 4: REST API Integration

Execute a REST API integration:

```php
<?php
/**
 * Assumes an integration named "External CRM API" is configured
 * with base URL and authentication
 */

$integrationId = $process_input['result']['integration_id'] ?? '';
$endpoint = $process_input['result']['endpoint'] ?? '/customers';
$method = strtoupper($process_input['result']['method'] ?? 'GET');
$payload = $process_input['result']['payload'] ?? [];
$queryParams = $process_input['result']['query_params'] ?? [];

try {
    // Build request data
    $requestData = [
        'endpoint' => $endpoint,
        'method' => $method,
        'headers' => [
            'Content-Type' => 'application/json'
        ]
    ];
    
    if (!empty($queryParams)) {
        $requestData['query'] = $queryParams;
    }
    
    if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($payload)) {
        $requestData['body'] = $payload;
    }
    
    $result = $integration->executeSync($integrationId, $requestData);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'API call failed',
            'data' => [
                'status_code' => $result['status_code'] ?? null,
                'response' => $result['response'] ?? null
            ]
        ];
    }
    
    return [
        'success' => true,
        'data' => [
            'response' => $result['result'] ?? $result['data'],
            'status_code' => $result['status_code'] ?? 200
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'API integration error: ' . $e->getMessage()
    ];
}
```

---

## Example 5: Database Integration

Execute a database integration query:

```php
<?php
/**
 * Assumes an integration configured for external database
 */

$integrationId = $process_input['result']['integration_id'] ?? '';
$operation = $process_input['result']['operation'] ?? 'query';
$query = $process_input['result']['query'] ?? '';
$params = $process_input['result']['params'] ?? [];

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

try {
    $requestData = [
        'operation' => $operation,
        'query' => $query,
        'parameters' => $params
    ];
    
    $result = $integration->executeSync($integrationId, $requestData, [
        'timeout' => 30000 // 30 second timeout for DB queries
    ]);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Database query failed'
        ];
    }
    
    return [
        'success' => true,
        'data' => [
            'records' => $result['result']['rows'] ?? [],
            'affected_rows' => $result['result']['affected_rows'] ?? null,
            'insert_id' => $result['result']['insert_id'] ?? null
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Database integration error: ' . $e->getMessage()
    ];
}
```

---

## Example 6: Cloud Storage Integration

Execute cloud storage operations (S3, Azure Blob):

```php
<?php
/**
 * Assumes S3 or Azure Blob integration configured
 */

$integrationId = $process_input['result']['integration_id'] ?? '';
$operation = $process_input['result']['operation'] ?? 'list'; // list, get, put, delete
$bucket = $process_input['result']['bucket'] ?? '';
$key = $process_input['result']['key'] ?? '';
$content = $process_input['result']['content'] ?? null;

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

try {
    $requestData = [
        'operation' => $operation,
        'bucket' => $bucket
    ];
    
    switch ($operation) {
        case 'list':
            $requestData['prefix'] = $key;
            break;
            
        case 'get':
            $requestData['key'] = $key;
            break;
            
        case 'put':
            if (empty($key) || $content === null) {
                return ['success' => false, 'error' => 'Key and content required for put'];
            }
            $requestData['key'] = $key;
            $requestData['content'] = is_array($content) ? json_encode($content) : $content;
            $requestData['content_type'] = $process_input['result']['content_type'] ?? 'application/octet-stream';
            break;
            
        case 'delete':
            if (empty($key)) {
                return ['success' => false, 'error' => 'Key required for delete'];
            }
            $requestData['key'] = $key;
            break;
            
        default:
            return ['success' => false, 'error' => 'Unknown operation: ' . $operation];
    }
    
    $result = $integration->executeSync($integrationId, $requestData);
    
    return [
        'success' => $result['success'],
        'data' => [
            'operation' => $operation,
            'result' => $result['result'] ?? null,
            'error' => $result['error'] ?? null
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Storage integration error: ' . $e->getMessage()
    ];
}
```

---

## Example 7: Message Queue Integration

Send messages to SQS, Azure Service Bus, or Kafka:

```php
<?php
/**
 * Assumes queue integration configured
 */

$integrationId = $process_input['result']['integration_id'] ?? '';
$operation = $process_input['result']['operation'] ?? 'send'; // send, receive
$queueName = $process_input['result']['queue_name'] ?? '';
$message = $process_input['result']['message'] ?? [];

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

try {
    $requestData = [
        'operation' => $operation,
        'queue' => $queueName
    ];
    
    switch ($operation) {
        case 'send':
            $requestData['message'] = is_array($message) ? json_encode($message) : $message;
            $requestData['message_attributes'] = $process_input['result']['attributes'] ?? [];
            break;
            
        case 'receive':
            $requestData['max_messages'] = $process_input['result']['max_messages'] ?? 10;
            $requestData['wait_time_seconds'] = $process_input['result']['wait_time'] ?? 0;
            break;
            
        case 'delete':
            $requestData['receipt_handle'] = $process_input['result']['receipt_handle'] ?? '';
            break;
    }
    
    $result = $integration->executeSync($integrationId, $requestData);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Queue operation failed'
        ];
    }
    
    return [
        'success' => true,
        'data' => [
            'operation' => $operation,
            'queue' => $queueName,
            'result' => $result['result']
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Queue integration error: ' . $e->getMessage()
    ];
}
```

---

## Example 8: Batch Integration Execution

Execute multiple integrations in sequence or parallel:

```php
<?php
$executions = $process_input['result']['executions'] ?? [];
$mode = $process_input['result']['mode'] ?? 'sequential'; // sequential, parallel
$stopOnError = $process_input['result']['stop_on_error'] ?? true;

if (empty($executions)) {
    return ['success' => false, 'error' => 'No executions provided'];
}

$results = [];
$hasErrors = false;

try {
    if ($mode === 'parallel') {
        // Queue all executions asynchronously
        $executionIds = [];
        foreach ($executions as $index => $exec) {
            $execId = $integration->executeAsync(
                $exec['integration_id'],
                $exec['data'] ?? []
            );
            $executionIds[$index] = $execId;
        }
        
        // Poll for completion (simplified - in practice, use callback)
        $pending = $executionIds;
        $maxWaitSeconds = 60;
        $startTime = time();
        
        while (!empty($pending) && (time() - $startTime) < $maxWaitSeconds) {
            foreach ($pending as $index => $execId) {
                $status = $integration->getStatus($execId);
                
                if ($status['status'] === 'completed') {
                    $results[$index] = [
                        'success' => true,
                        'result' => $integration->getResult($execId)
                    ];
                    unset($pending[$index]);
                } elseif ($status['status'] === 'failed') {
                    $results[$index] = [
                        'success' => false,
                        'error' => $status['error_message'] ?? 'Failed'
                    ];
                    $hasErrors = true;
                    unset($pending[$index]);
                }
            }
            
            if (!empty($pending)) {
                usleep(500000); // Wait 0.5 seconds
            }
        }
        
        // Mark remaining as timeout
        foreach ($pending as $index => $execId) {
            $results[$index] = [
                'success' => false,
                'error' => 'Timeout waiting for completion'
            ];
            $hasErrors = true;
        }
    } else {
        // Sequential execution
        foreach ($executions as $index => $exec) {
            $result = $integration->executeSync(
                $exec['integration_id'],
                $exec['data'] ?? []
            );
            
            $results[$index] = [
                'success' => $result['success'],
                'result' => $result['result'] ?? null,
                'error' => $result['error'] ?? null
            ];
            
            if (!$result['success']) {
                $hasErrors = true;
                if ($stopOnError) {
                    break;
                }
            }
        }
    }
    
    return [
        'success' => !$hasErrors,
        'data' => [
            'mode' => $mode,
            'total_executions' => count($executions),
            'completed' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => $results
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Batch execution error: ' . $e->getMessage()
    ];
}
```

---

## Example 9: Integration with Retry Logic

Execute with automatic retry on transient failures:

```php
<?php
$integrationId = $process_input['result']['integration_id'] ?? '';
$inputData = $process_input['result']['data'] ?? [];
$maxRetries = (int)($process_input['result']['max_retries'] ?? 3);
$retryDelay = (int)($process_input['result']['retry_delay_ms'] ?? 1000);

// Retryable error codes
$retryableErrors = ['TIMEOUT', 'RATE_LIMITED', 'SERVICE_UNAVAILABLE', 'CONNECTION_ERROR'];

if (empty($integrationId)) {
    return ['success' => false, 'error' => 'Integration ID required'];
}

$attempt = 0;
$lastError = null;
$attempts = [];

while ($attempt < $maxRetries) {
    $attempt++;
    
    try {
        $log_message("[INFO] Integration attempt $attempt/$maxRetries");
        
        $result = $integration->executeSync($integrationId, $inputData);
        
        $attempts[] = [
            'attempt' => $attempt,
            'success' => $result['success'],
            'error' => $result['error'] ?? null
        ];
        
        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'result' => $result['result'] ?? $result['data'],
                    'attempts' => $attempts,
                    'total_attempts' => $attempt
                ]
            ];
        }
        
        // Check if error is retryable
        $errorCode = $result['error_code'] ?? '';
        if (!in_array($errorCode, $retryableErrors)) {
            // Non-retryable error - fail immediately
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Integration failed',
                'data' => ['attempts' => $attempts]
            ];
        }
        
        $lastError = $result['error'];
        
    } catch (\Exception $e) {
        $lastError = $e->getMessage();
        $attempts[] = [
            'attempt' => $attempt,
            'success' => false,
            'error' => $lastError
        ];
    }
    
    // Wait before retry (if not last attempt)
    if ($attempt < $maxRetries) {
        // Exponential backoff
        $delay = $retryDelay * pow(2, $attempt - 1);
        usleep($delay * 1000);
    }
}

return [
    'success' => false,
    'error' => 'Max retries exceeded. Last error: ' . $lastError,
    'data' => [
        'attempts' => $attempts,
        'total_attempts' => $attempt
    ]
];
```

---

## Best Practices

### 1. Use Meaningful Timeout Values
```php
$integration->executeSync($id, $data, [
    'timeout' => 30000 // 30 seconds for external APIs
]);
```

### 2. Handle Both Success and Failure
```php
if (!$result['success']) {
    $log_message('[ERROR] ' . $result['error']);
    return ['success' => false, 'error' => $result['error']];
}
```

### 3. Use Async for Long Operations
```php
// For operations > 30 seconds
$execId = $integration->executeAsync($id, $data);
// Check status later or use callback
```

### 4. Log Integration Calls
```php
$log_message("[INFO] Calling integration: $integrationId");
// ... execute
$log_message("[INFO] Integration response received");
```

---

## Error Handling

| Error Code | Description | Retry? |
|------------|-------------|--------|
| `TIMEOUT` | Request timed out | Yes |
| `RATE_LIMITED` | Too many requests | Yes (with backoff) |
| `SERVICE_UNAVAILABLE` | Service down | Yes |
| `AUTHENTICATION_FAILED` | Invalid credentials | No |
| `INVALID_REQUEST` | Bad request data | No |
| `NOT_FOUND` | Resource not found | No |

---

## See Also

- [ProcessFlow Code Snippets Guide](../PROCESSFLOW_CODE_SNIPPETS_GUIDE.md) (API service, HTTP calls)

Related topics (cloud storage, queue operations) may be covered in platform or planned docs.
