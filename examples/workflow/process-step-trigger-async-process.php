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

// Construct base URL once
$host = $http_headers['Host'] ?? 'localhost';
$protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') 
    ? 'https' 
    : (isset($http_headers['Host']) ? 'https' : 'http');
$baseUrl = "{$protocol}://{$host}";

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
        log_message("Process not found by name: {$processName}");
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
    
    log_message("Found process_id: {$targetProcessId} for process name: {$processName}");
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
log_message("Triggering async process: {$targetProcessId}");
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
    
    log_message("Failed to trigger async process (HTTP {$statusCode}): {$errorMessage}");
    log_message("Full API response: " . json_encode($apiResponse));
    
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

log_message("Async process triggered successfully. Queue ID: " . ($queueId ?? 'N/A'));

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

