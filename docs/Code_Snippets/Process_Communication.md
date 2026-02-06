# Process-to-Process Communication

This guide covers calling other processes from within ProcessFlow code snippets.

## Overview

The `$api` service enables one process to invoke another, enabling modular, reusable workflows. This is essential for event-driven architectures and process orchestration.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Process A      │────▶│    $api->post()  │────▶│  Process B      │
│  (Caller)       │     │    Service       │     │  (Invoked)      │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$api` | TenantAPIService | HTTP client with tenant context |
| `$execution_auth_key` | string | Authorization key for internal calls |
| `$app_url` | string | Application base URL from `APP_URL` environment variable (e.g., `https://dev.tealfabric.io`) |

## TenantAPIService Methods

| Method | Description |
|--------|-------------|
| `get($url, $headers)` | GET request |
| `post($url, $data, $headers)` | POST request |
| `put($url, $data, $headers)` | PUT request |
| `delete($url, $headers)` | DELETE request |

---

## Example 1: Call Another Process (Sync)

Invoke another process and wait for result:

```php
<?php
$targetProcessId = $process_input['result']['process_id'] ?? '';
$inputData = $process_input['result']['data'] ?? [];

if (empty($targetProcessId)) {
    return ['success' => false, 'error' => 'Target process ID required'];
}

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";

try {
    $log_message("[INFO] Calling process: $targetProcessId");
    
    $response = $api->post($endpoint, [
        'process_id' => $targetProcessId,
        'input_data' => $inputData
        // Sync execution is default - no need to specify
    ]);
    
    if (!$response['success'] || $response['status_code'] >= 400) {
        $log_message("[ERROR] Process call failed: " . json_encode($response));
        
        return [
            'success' => false,
            'error' => 'Process invocation failed',
            'data' => [
                'status_code' => $response['status_code'] ?? null,
                'response' => $response['data'] ?? null
            ]
        ];
    }
    
    $log_message("[INFO] Process completed successfully");
    
    return [
        'success' => true,
        'data' => [
            'process_id' => $targetProcessId,
            'result' => $response['data']['result'] ?? $response['data'],
            'execution_id' => $response['data']['execution_id'] ?? null
        ]
    ];
} catch (\Exception $e) {
    $log_message("[ERROR] Process call exception: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => 'Process call failed: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Call Process Asynchronously

Fire-and-forget process invocation:

```php
<?php
$targetProcessId = $process_input['result']['process_id'] ?? '';
$inputData = $process_input['result']['data'] ?? [];
$priority = $process_input['result']['priority'] ?? 'normal';

if (empty($targetProcessId)) {
    return ['success' => false, 'error' => 'Target process ID required'];
}

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";

try {
    $log_message("[INFO] Queuing async process: $targetProcessId");
    
    $response = $api->post($endpoint, [
        'process_id' => $targetProcessId,
        'input_data' => $inputData,
        'async' => true,  // Don't wait
        'options' => [
            'priority' => $priority
        ]
    ]);
    
    if (!$response['success'] || $response['status_code'] >= 400) {
        return [
            'success' => false,
            'error' => 'Failed to queue process',
            'data' => $response
        ];
    }
    
    $executionId = $response['data']['execution_id'] ?? null;
    $log_message("[INFO] Process queued with execution ID: $executionId");
    
    return [
        'success' => true,
        'data' => [
            'process_id' => $targetProcessId,
            'execution_id' => $executionId,
            'status' => 'queued',
            'message' => 'Process execution queued successfully'
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Failed to queue process: ' . $e->getMessage()
    ];
}
```

---

## Example 3: Event Broadcasting

Broadcast event to multiple listener processes:

```php
<?php
/**
 * Event broadcasting pattern:
 * One event triggers multiple processes
 */

$eventType = $process_input['result']['event_type'] ?? '';
$eventData = $process_input['result']['event_data'] ?? [];
$listeners = $process_input['result']['listeners'] ?? [];

if (empty($eventType)) {
    return ['success' => false, 'error' => 'Event type required'];
}

// If no listeners specified, use event handler endpoint
if (empty($listeners)) {
    // Call central event handler
    $endpoint = "{$app_url}/api/v1/events/publish";
    
    try {
        $response = $api->post($endpoint, [
            'event_type' => $eventType,
            'payload' => $eventData,
            'timestamp' => date('c'),
            'source_tenant' => $tenant_id
        ]);
        
        return [
            'success' => $response['success'],
            'data' => [
                'event_type' => $eventType,
                'published' => $response['success'],
                'response' => $response['data'] ?? null
            ]
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => 'Event publishing failed: ' . $e->getMessage()
        ];
    }
}

// Broadcast to specific listeners
$results = [
    'triggered' => [],
    'failed' => []
];

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";

foreach ($listeners as $listener) {
    $processId = is_array($listener) ? $listener['process_id'] : $listener;
    
    try {
        $response = $api->post($endpoint, [
            'process_id' => $processId,
            'input_data' => [
                'event_type' => $eventType,
                'event_data' => $eventData,
                'timestamp' => date('c')
            ],
            'async' => true
        ]);
        
        if ($response['success']) {
            $results['triggered'][] = [
                'process_id' => $processId,
                'execution_id' => $response['data']['execution_id'] ?? null
            ];
        } else {
            $results['failed'][] = [
                'process_id' => $processId,
                'error' => $response['data']['error'] ?? 'Unknown error'
            ];
        }
    } catch (\Exception $e) {
        $results['failed'][] = [
            'process_id' => $processId,
            'error' => $e->getMessage()
        ];
    }
}

$log_message(sprintf(
    "[INFO] Event '%s' broadcast: %d triggered, %d failed",
    $eventType,
    count($results['triggered']),
    count($results['failed'])
));

return [
    'success' => count($results['failed']) === 0,
    'data' => [
        'event_type' => $eventType,
        'listeners_count' => count($listeners),
        'triggered_count' => count($results['triggered']),
        'failed_count' => count($results['failed']),
        'results' => $results
    ]
];
```

---

## Example 4: Chain Multiple Processes

Execute processes in sequence:

```php
<?php
/**
 * Process chain pattern:
 * Process A → Process B → Process C
 */

$processChain = $process_input['result']['chain'] ?? [];
$initialInput = $process_input['result']['input'] ?? [];
$stopOnError = $process_input['result']['stop_on_error'] ?? true;

if (empty($processChain)) {
    return ['success' => false, 'error' => 'Process chain required'];
}

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";
$currentInput = $initialInput;
$chainResults = [];
$chainSuccess = true;

foreach ($processChain as $index => $step) {
    $processId = is_array($step) ? $step['process_id'] : $step;
    $stepName = is_array($step) ? ($step['name'] ?? "Step $index") : "Step $index";
    
    $log_message("[INFO] Executing chain step $index: $processId");
    
    try {
        $response = $api->post($endpoint, [
            'process_id' => $processId,
            'input_data' => $currentInput
            // Sync execution is default - no need to specify
        ]);
        
        $stepSuccess = $response['success'] && $response['status_code'] < 400;
        
        $chainResults[] = [
            'step' => $index,
            'name' => $stepName,
            'process_id' => $processId,
            'success' => $stepSuccess,
            'execution_id' => $response['data']['execution_id'] ?? null,
            'output' => $response['data']['result'] ?? null
        ];
        
        if ($stepSuccess) {
            // Pass output to next step
            $currentInput = $response['data']['result'] ?? $response['data'];
        } else {
            $chainSuccess = false;
            $log_message("[ERROR] Chain step $index failed");
            
            if ($stopOnError) {
                break;
            }
        }
    } catch (\Exception $e) {
        $chainResults[] = [
            'step' => $index,
            'name' => $stepName,
            'process_id' => $processId,
            'success' => false,
            'error' => $e->getMessage()
        ];
        
        $chainSuccess = false;
        
        if ($stopOnError) {
            break;
        }
    }
}

return [
    'success' => $chainSuccess,
    'data' => [
        'chain_completed' => $chainSuccess,
        'steps_total' => count($processChain),
        'steps_completed' => count(array_filter($chainResults, fn($r) => $r['success'])),
        'final_output' => $currentInput,
        'chain_results' => $chainResults
    ]
];
```

---

## Example 5: Fan-Out / Fan-In Pattern

Parallel execution with result aggregation:

```php
<?php
/**
 * Fan-out: Distribute work to multiple processes
 * Fan-in: Collect and aggregate results
 */

$tasks = $process_input['result']['tasks'] ?? [];
$aggregatorProcessId = $process_input['result']['aggregator_process_id'] ?? null;
$maxWaitSeconds = (int)($process_input['result']['max_wait_seconds'] ?? 60);

if (empty($tasks)) {
    return ['success' => false, 'error' => 'Tasks required'];
}

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";

// Fan-out: Queue all tasks
$executions = [];
foreach ($tasks as $index => $task) {
    $processId = $task['process_id'] ?? '';
    $taskData = $task['data'] ?? [];
    
    try {
        $response = $api->post($endpoint, [
            'process_id' => $processId,
            'input_data' => $taskData,
            'async' => true
        ]);
        
        if ($response['success']) {
            $executions[$index] = [
                'process_id' => $processId,
                'execution_id' => $response['data']['execution_id'],
                'status' => 'pending'
            ];
        }
    } catch (\Exception $e) {
        $executions[$index] = [
            'process_id' => $processId,
            'status' => 'failed',
            'error' => $e->getMessage()
        ];
    }
}

$log_message("[INFO] Fan-out: " . count($executions) . " tasks queued");

// Fan-in: Wait for and collect results
$startTime = time();
$allComplete = false;
$results = [];

while (!$allComplete && (time() - $startTime) < $maxWaitSeconds) {
    $allComplete = true;
    
    foreach ($executions as $index => &$exec) {
        if ($exec['status'] === 'pending' && isset($exec['execution_id'])) {
            // Check status
            $statusResponse = $api->get("/api/v1/processflow/executions/{$exec['execution_id']}/status");
            
            if ($statusResponse['success']) {
                $status = $statusResponse['data']['status'] ?? 'unknown';
                
                if ($status === 'completed') {
                    $exec['status'] = 'completed';
                    $exec['result'] = $statusResponse['data']['result'] ?? null;
                    $results[$index] = $exec['result'];
                } elseif ($status === 'failed') {
                    $exec['status'] = 'failed';
                    $exec['error'] = $statusResponse['data']['error'] ?? 'Unknown error';
                } else {
                    $allComplete = false;
                }
            }
        }
    }
    
    if (!$allComplete) {
        usleep(500000); // Wait 0.5 seconds
    }
}

$log_message("[INFO] Fan-in: " . count($results) . " results collected");

// Call aggregator if specified
$aggregatedResult = null;
if ($aggregatorProcessId && !empty($results)) {
    try {
        $aggResponse = $api->post($endpoint, [
            'process_id' => $aggregatorProcessId,
            'input_data' => ['results' => $results]
            // Sync execution is default - no need to specify
        ]);
        
        if ($aggResponse['success']) {
            $aggregatedResult = $aggResponse['data']['result'] ?? null;
        }
    } catch (\Exception $e) {
        $log_message("[ERROR] Aggregator failed: " . $e->getMessage());
    }
}

return [
    'success' => $allComplete,
    'data' => [
        'tasks_total' => count($tasks),
        'tasks_completed' => count(array_filter($executions, fn($e) => $e['status'] === 'completed')),
        'tasks_failed' => count(array_filter($executions, fn($e) => $e['status'] === 'failed')),
        'executions' => $executions,
        'results' => $results,
        'aggregated_result' => $aggregatedResult
    ]
];
```

---

## Example 6: Saga Pattern (Compensating Transactions)

Execute with rollback on failure:

```php
<?php
/**
 * Saga pattern: Execute steps with compensation on failure
 */

$steps = $process_input['result']['steps'] ?? [];
$sagaId = $process_input['result']['saga_id'] ?? uniqid('saga_');

if (empty($steps)) {
    return ['success' => false, 'error' => 'Steps required'];
}

// Use app_url from environment (recommended)
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";
$completedSteps = [];
$sagaSuccess = true;
$failedAt = null;

// Forward execution
foreach ($steps as $index => $step) {
    $processId = $step['process_id'] ?? '';
    $compensateProcessId = $step['compensate_process_id'] ?? null;
    $stepData = $step['data'] ?? [];
    
    $log_message("[INFO] Saga $sagaId: Executing step $index");
    
    try {
        $response = $api->post($endpoint, [
            'process_id' => $processId,
            'input_data' => array_merge($stepData, ['saga_id' => $sagaId])
            // Sync execution is default - no need to specify
        ]);
        
        if ($response['success'] && $response['status_code'] < 400) {
            $completedSteps[] = [
                'index' => $index,
                'process_id' => $processId,
                'compensate_process_id' => $compensateProcessId,
                'result' => $response['data']['result'] ?? null
            ];
        } else {
            $sagaSuccess = false;
            $failedAt = $index;
            $log_message("[ERROR] Saga $sagaId: Step $index failed");
            break;
        }
    } catch (\Exception $e) {
        $sagaSuccess = false;
        $failedAt = $index;
        $log_message("[ERROR] Saga $sagaId: Step $index exception: " . $e->getMessage());
        break;
    }
}

// Compensation (rollback) if failed
$compensationResults = [];
if (!$sagaSuccess && !empty($completedSteps)) {
    $log_message("[INFO] Saga $sagaId: Starting compensation");
    
    // Reverse order for compensation
    $stepsToCompensate = array_reverse($completedSteps);
    
    foreach ($stepsToCompensate as $step) {
        if (empty($step['compensate_process_id'])) {
            continue;
        }
        
        try {
            $response = $api->post($endpoint, [
                'process_id' => $step['compensate_process_id'],
                'input_data' => [
                    'saga_id' => $sagaId,
                    'original_result' => $step['result'],
                    'compensation' => true
                ]
                // Sync execution is default - no need to specify
            ]);
            
            $compensationResults[] = [
                'original_step' => $step['index'],
                'compensate_process_id' => $step['compensate_process_id'],
                'success' => $response['success']
            ];
        } catch (\Exception $e) {
            $compensationResults[] = [
                'original_step' => $step['index'],
                'compensate_process_id' => $step['compensate_process_id'],
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

return [
    'success' => $sagaSuccess,
    'data' => [
        'saga_id' => $sagaId,
        'completed' => $sagaSuccess,
        'failed_at_step' => $failedAt,
        'steps_completed' => count($completedSteps),
        'steps_total' => count($steps),
        'completed_steps' => $completedSteps,
        'compensation_applied' => !$sagaSuccess && !empty($compensationResults),
        'compensation_results' => $compensationResults
    ]
];
```

---

## Best Practices

### 1. Use Async for Non-Blocking Operations
```php
// Don't block if you don't need the result
// Use app_url from environment for full URL
$endpoint = "{$app_url}/api/v1/processflow?action=execute-process";
$api->post($endpoint, [
    'process_id' => $processId,
    'input_data' => [],
    'async' => true
]);
```

### 2. Handle Timeouts
```php
// Set reasonable timeouts
// Process-to-process calls should complete quickly
```

### 3. Log All Inter-Process Calls
```php
$log_message("[INFO] Calling process: $processId");
// ... call
$log_message("[INFO] Process response received");
```

### 4. Pass Context Through Chain
```php
// Include correlation ID for tracing
$input['correlation_id'] = $process_input['correlation_id'] ?? uniqid();
```

---

## See Also

- [Event Publishing](Event_Publishing.md)
- [REST API Calls](REST_API_Calls.md)
- [Integration Connectors](Integration_Connectors.md)
