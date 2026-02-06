# WebApp Asynchronous Process Trigger Guide

**Last Updated:** 2026-01-08  
**Version:** 1.0.0

## Overview

This guide explains how to use asynchronous process execution with WebApps and Webhooks. When a WebApp or Webhook is attached to a process flow, it executes synchronously by default, which means the WebApp/Webhook request waits for the entire process to complete before returning a response. This can cause timeouts for long-running processes.

The **Async Process Trigger** code snippet solves this problem by creating an intermediate stub process that immediately triggers the actual process asynchronously, allowing the WebApp/Webhook to return quickly while the process runs in the background.

## The Problem

### Synchronous Execution Issues

When a WebApp or Webhook is directly attached to a process flow:

1. **Request Timeout**: The HTTP request waits for the entire process to complete
2. **Long-Running Processes**: Processes with LLM calls, API integrations, or complex operations can take minutes
3. **Resource Blocking**: The connection remains open, consuming server resources
4. **User Experience**: Users may see loading indicators for extended periods
5. **Error Handling**: Timeouts can cause incomplete executions without proper error reporting

### Example Scenario

```
WebApp Request → Process Flow (with LLM call taking 3 minutes) → Wait 3 minutes → Response
```

If the process takes 3 minutes, the WebApp request also takes 3 minutes, which can cause:
- HTTP timeout errors (typically 30-60 seconds)
- Poor user experience
- Resource exhaustion

## The Solution

### Async Process Trigger Pattern

The solution uses a **two-process architecture**:

1. **Stub Process** (synchronous): A lightweight one-step process that triggers the actual process asynchronously
2. **Actual Process** (asynchronous): The real process that runs in the background

```
WebApp Request → Stub Process (triggers async) → Immediate Response (with queue_id)
                                              ↓
                                    Actual Process (runs in background)
```

### Benefits

- ✅ **Immediate Response**: WebApp/Webhook returns in milliseconds
- ✅ **No Timeouts**: Long-running processes don't block the request
- ✅ **Queue Tracking**: Returns `queue_id` for status monitoring
- ✅ **Better UX**: Users get immediate feedback
- ✅ **Resource Efficiency**: Connections are released quickly

## Implementation

### Step 1: Create the Stub Process

1. Go to **ProcessFlow** → **Create New Process**
2. Name it: `"Async Trigger - [Your Process Name]"`
3. Add a single **Code Snippet** step
4. Copy the code snippet below into the step

### Step 2: Attach to WebApp/Webhook

1. In your WebApp or Webhook configuration
2. Set the **Process Flow** to the stub process you created
3. The stub process will handle triggering the actual process asynchronously

### Step 3: Configure Process Identification

The stub process can identify the target process in two ways:

**Option A: By Process ID** (Recommended for production)
```json
{
  "process_id": "abc-123-def-456-ghi-789",
  "your_data": "value"
}
```

**Option B: By Process Name** (Convenient for development)
```json
{
  "process_name": "My Long Running Process",
  "your_data": "value"
}
```

## Code Snippet

Here is the complete code snippet to use in your stub process:

```php
<?php
/**
 * Copyright (c) 2026 Tealfabric Inc. All rights reserved.
 * 
 * Process Step: Trigger Async Process - v1.0.0
 * 
 * This step acts as a stub between webapp/webhook (sync) and actual process (async).
 * Receives input from webapp, triggers the actual process asynchronously via API.
 * 
 * Input from webapp/webhook ($process_input):
 * - process_id (string): Target process ID to trigger (optional if process_name provided)
 * - process_name (string): Target process name to trigger (optional if process_id provided)
 * - All other fields: Forwarded as input_data to the target process
 * 
 * Output:
 * - queue_id (string): Queue ID if async execution was queued
 * - status (string): 'queued' if successful
 */

// You can set the process manually in the process_input or you can use the process name to fetch the process_id.
$targetProcessId = $process_input['process_id'] ?? '';
$processName = $process_input['process_name'] ?? '';

// #########################################################################################
// No changes required below this line
// #########################################################################################

// Use app_url from environment (recommended - uses APP_URL from .env)
// This avoids needing to construct base URL from HTTP headers
$baseUrl = $app_url;

// Fetch process_id by name if needed
if (empty($targetProcessId) && !empty($processName)) {
    log_message("Fetching process_id by name: {$processName}");
    $apiResponse = $api->get("{$baseUrl}/api/v1/processes?action=list&search=" . urlencode($processName) . "&limit=100");
    
    if (!($apiResponse['success'] ?? false)) {
        $errorMsg = $apiResponse['error'] ?? 'Unknown error';
        log_message("Failed to fetch process by name: {$errorMsg}");
        return [
            'success' => false,
            'error' => [
                'code' => 'PROCESS_FETCH_FAILED',
                'message' => 'Failed to fetch process by name',
                'details' => $errorMsg
            ],
            'data' => null
        ];
    }
    
    // Find exact match by name (case-insensitive)
    $processes = $apiResponse['data']['processes'] ?? [];
    foreach ($processes as $process) {
        if (strcasecmp($process['name'] ?? '', $processName) === 0) {
            $targetProcessId = $process['process_id'] ?? null;
            break;
        }
    }
    
    if (empty($targetProcessId)) {
        if (function_exists('log_message')) {
            log_message("Process not found by name: {$processName}");
        }
        return [
            'success' => false,
            'error' => [
                'code' => 'PROCESS_NOT_FOUND',
                'message' => 'Process not found by name',
                'details' => "No process found with name: {$processName}"
            ],
            'data' => null
        ];
    }
    
    if (function_exists('log_message')) {
        log_message("Found process_id: {$targetProcessId} for process name: {$processName}");
    }
}

// Validate process_id
if (empty($targetProcessId)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_PROCESS_IDENTIFIER',
            'message' => 'Process ID or name is required',
            'details' => 'The process_input must contain either process_id or process_name field'
        ],
        'data' => null
    ];
}

// Prepare input_data: forward all except process_id and process_name
$inputData = array_diff_key($process_input, ['process_id' => '', 'process_name' => '']);

// Trigger async process execution
if (function_exists('log_message')) {
    log_message("Triggering async process: {$targetProcessId}");
}
$apiResponse = $api->post("{$baseUrl}/api/v1/processflow?action=execute-process", [
    'process_id' => $targetProcessId,
    'input_data' => $inputData,
    'options' => ['async' => true]
]);

if (!($apiResponse['success'] ?? false)) {
    // Extract error from nested data structure
    $responseData = $apiResponse['data'] ?? [];
    $errorMessage = $responseData['error'] ?? $responseData['message'] ?? 'Unknown error occurred while triggering async process';
    $statusCode = $apiResponse['status_code'] ?? 0;
    
    if (function_exists('log_message')) {
        log_message("Failed to trigger async process (HTTP {$statusCode}): {$errorMessage}");
        log_message("Full API response: " . json_encode($apiResponse));
    }
    
    return [
        'success' => false,
        'error' => [
            'code' => 'ASYNC_TRIGGER_FAILED',
            'message' => 'Failed to trigger async process',
            'details' => "HTTP {$statusCode}: {$errorMessage}"
        ],
        'data' => null
    ];
}

// Extract queue_id from nested data structure
$responseData = $apiResponse['data'] ?? [];
$queueId = $responseData['queue_id'] ?? null;
$status = $responseData['status'] ?? 'queued';

if (function_exists('log_message')) {
    log_message("Async process triggered successfully. Queue ID: " . ($queueId ?? 'N/A'));
}

// Return success immediately (webapp gets response, actual process runs async)
return [
    'success' => true,
    'data' => [
        'queue_id' => $queueId,
        'status' => $status,
        'process_id' => $targetProcessId,
        'execution_mode' => 'async'
    ],
    'message' => "Process {$targetProcessId} queued for async execution"
];
```

## Response Format

### Success Response

When the process is successfully queued, the WebApp/Webhook receives:

```json
{
  "success": true,
  "data": {
    "queue_id": "queue_abc123def456",
    "status": "queued",
    "process_id": "process-xyz-789",
    "execution_mode": "async"
  },
  "message": "Process process-xyz-789 queued for async execution"
}
```

### Error Response

If something goes wrong:

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable error message",
    "details": "Detailed error information"
  },
  "data": null
}
```

## Monitoring Execution Status

### Using Queue ID

The `queue_id` returned in the response can be used to check the execution status:

**API Endpoint:**
```
GET /api/v1/processflow?action=queue-status&queue_id={queue_id}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "queue_id": "queue_abc123def456",
    "status": "completed",
    "process_id": "process-xyz-789",
    "created_at": "2026-01-15T10:30:00Z",
    "started_at": "2026-01-15T10:30:05Z",
    "completed_at": "2026-01-15T10:33:22Z",
    "result": {
      "success": true,
      "data": { ... }
    }
  }
}
```

### Status Values

- **`pending`**: Job is queued, waiting for worker
- **`running`**: Job is currently being processed
- **`completed`**: Job finished successfully
- **`failed`**: Job failed with an error
- **`retrying`**: Job failed and is scheduled for retry
- **`cancelled`**: Job was cancelled before completion

## Debugging Common Issues

### Issue 1: Process Not Found

**Error Code:** `PROCESS_NOT_FOUND` or `MISSING_PROCESS_IDENTIFIER`

**Symptoms:**
- Error message: "Process not found by name" or "Process ID or name is required"
- Process fails to trigger

**Solutions:**
1. **Check Process Name**: Ensure the process name matches exactly (case-insensitive)
2. **Use Process ID**: For production, use `process_id` instead of `process_name` for reliability
3. **Verify Process Exists**: Check that the target process exists and is active
4. **Check Tenant Scope**: Ensure the process is in the same tenant as the WebApp

**Debug Steps:**
```php
// Add logging to see what's being searched
log_message("Searching for process: {$processName}");
log_message("Found processes: " . json_encode($processes));
```

### Issue 2: Process Fetch Failed

**Error Code:** `PROCESS_FETCH_FAILED`

**Symptoms:**
- Error message: "Failed to fetch process by name"
- API call to list processes fails

**Solutions:**
1. **Check API Permissions**: Ensure the process step has permission to call the processes API
2. **Verify Base URL**: Use `$app_url` variable (automatically available from `APP_URL` environment variable)
3. **Check Network**: Ensure internal API calls are working
4. **Review Logs**: Check process execution logs for detailed error messages

**Debug Steps:**
```php
// Check what URL is being called
if (function_exists('log_message')) {
    log_message("API URL: {$app_url}/api/v1/processes?action=list&search=" . urlencode($processName));
    log_message("API Response: " . json_encode($apiResponse));
    log_message("Using app_url: {$app_url}");
}
```

### Issue 3: Async Trigger Failed

**Error Code:** `ASYNC_TRIGGER_FAILED`

**Symptoms:**
- Error message: "Failed to trigger async process"
- Process is not queued for execution

**Solutions:**
1. **Verify Process ID**: Ensure the `process_id` is valid and exists
2. **Check Process Status**: Ensure the target process is active
3. **Review Queue Service**: Check that the background job queue service is running
4. **Check Permissions**: Verify the process step has permission to trigger other processes
5. **Review Logs**: Check detailed error messages in process execution logs

**Debug Steps:**
```php
// Log the exact request being made
log_message("Triggering process: {$targetProcessId}");
log_message("Input data: " . json_encode($inputData));
log_message("Full API response: " . json_encode($apiResponse));
log_message("Response data: " . json_encode($apiResponse['data'] ?? []));
log_message("HTTP status: " . ($apiResponse['status_code'] ?? 'N/A'));
```

### Issue 4: Missing Queue ID

**Symptoms:**
- Response doesn't include `queue_id`
- Cannot track process execution

**Solutions:**
1. **Check API Response**: Verify the async execution API returns `queue_id`
2. **Review Response Structure**: Ensure the API response includes the queue ID
3. **Check Queue Service**: Verify the background job queue service is properly configured

**Debug Steps:**
```php
// Log the full API response
if (function_exists('log_message')) {
    log_message("Full API Response: " . json_encode($apiResponse));
    log_message("Queue ID: " . ($apiResponse['queue_id'] ?? 'NOT FOUND'));
}
```

### Issue 5: Process Still Running Synchronously

**Symptoms:**
- WebApp/Webhook still waits for process completion
- Timeout errors persist

**Solutions:**
1. **Verify Stub Process**: Ensure the WebApp is attached to the stub process, not the actual process
2. **Check Code Snippet**: Verify the code snippet is correctly copied
3. **Test Async Flag**: Ensure `'async' => true` is set in the API call
4. **Check Process Flow**: Verify the stub process only has one step (the code snippet)

**Debug Steps:**
```php
// Verify async flag is set
if (function_exists('log_message')) {
    log_message("Async option: " . json_encode(['async' => true]));
    log_message("API Request: " . json_encode([
        'process_id' => $targetProcessId,
        'input_data' => $inputData,
        'options' => ['async' => true]
    ]));
    log_message("Using app_url: {$app_url}");
}
```

## Best Practices

### 1. Use Process ID in Production

While `process_name` is convenient for development, use `process_id` in production for:
- **Reliability**: Process names can change, IDs are permanent
- **Performance**: No API lookup required
- **Security**: Less chance of triggering wrong process

### 2. Handle Queue ID in Your Application

Always capture and store the `queue_id` from the response:
- Store it in your database
- Use it for status polling
- Display it to users for tracking
- Use it for error reporting

### 3. Implement Status Polling

For better user experience, implement status polling:
```javascript
// Example: Poll for status every 5 seconds
async function pollStatus(queueId) {
  const maxAttempts = 60; // 5 minutes max
  let attempts = 0;
  
  while (attempts < maxAttempts) {
    const response = await fetch(`/api/v1/processflow?action=queue-status&queue_id=${queueId}`);
    const data = await response.json();
    
    if (data.data.status === 'completed' || data.data.status === 'failed') {
      return data;
    }
    
    await new Promise(resolve => setTimeout(resolve, 5000));
    attempts++;
  }
  
  throw new Error('Status polling timeout');
}
```

### 4. Error Handling

Always handle errors gracefully:
- Check for `success: false` in responses
- Display user-friendly error messages
- Log errors for debugging
- Provide fallback behavior when possible

### 5. Logging

The code snippet includes comprehensive logging. Monitor logs for:
- Process lookup operations
- API call failures
- Queue ID generation
- Execution status changes

## Example Use Cases

### Use Case 1: WebApp Form Submission

**Scenario:** User submits a form that triggers a long-running data processing workflow.

**Implementation:**
1. Create stub process: "Async Trigger - Data Processing"
2. Attach stub process to WebApp form submission
3. Stub process triggers actual "Data Processing" process asynchronously
4. WebApp immediately shows "Processing started" message
5. User can check status using returned `queue_id`

### Use Case 2: Webhook Integration

**Scenario:** External system sends webhook that triggers LLM-based analysis.

**Implementation:**
1. Create stub process: "Async Trigger - LLM Analysis"
2. Configure webhook to use stub process
3. Webhook returns immediately with `queue_id`
4. External system can poll for results
5. LLM analysis runs in background without blocking webhook

### Use Case 3: Scheduled Batch Processing

**Scenario:** WebApp triggers batch processing that takes 10+ minutes.

**Implementation:**
1. Create stub process: "Async Trigger - Batch Processing"
2. Attach to WebApp action button
3. User clicks button → immediate response
4. Batch processing runs in background
5. User receives notification when complete

## Related Documentation

- [ProcessFlow API Documentation](./PROCESSFLOW_API_DOCUMENTATION.md) - API reference
- [Async ProcessFlow Queue Implementation](./ASYNC_PROCESSFLOW_QUEUE_IMPLEMENTATION.md) - Technical details
- [WebApp API Usage Guide](./WEBAPP_API_USAGE_GUIDE.md) - WebApp integration
- [ProcessFlow Triggers Guide](./PROCESSFLOW_TRIGGERS_GUIDE.md) - Trigger configuration

## Support

If you encounter issues not covered in this guide:

1. Check the process execution logs for detailed error messages
2. Verify all configuration steps were followed correctly
3. Review the debugging section for your specific error code
4. Contact support with:
   - Error code and message
   - Process execution log excerpts
   - Steps to reproduce the issue

---

**Copyright (c) 2026 Tealfabric Inc. All rights reserved.**

