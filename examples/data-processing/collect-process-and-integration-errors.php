<?php
/**
 * Collect Process and Integration Errors
 * 
 * Retrieves all process errors and integration errors for the past hour.
 * Log entries are stored to a JSON file in the tenantdata structure.
 * 
 * Returns the created path/filename as a return value.
 * If no errors found, no file is created and no filename is returned.
 * 
 * Input: None required
 * Output: Success with file path if errors found, or success with no file if no errors
 */

try {
    // Calculate timestamp for past hour
    $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    $allErrors = [];
    
    // 1. Get process execution errors from database
    $processErrorsStmt = $db->prepare("
        SELECT 
            pel.execution_id,
            pel.process_id,
            p.name as process_name,
            pel.execution_status,
            pel.error_message,
            pel.created_at,
            pel.execution_time_ms,
            pel.orchestration_context_build_ms,
            pel.orchestration_validation_ms,
            pel.orchestration_total_ms
        FROM ProcessExecutionLogs pel
        LEFT JOIN Processes p ON pel.process_id = p.process_id
        WHERE pel.tenant_id = ?
        AND pel.created_at >= ?
        AND (pel.execution_status = 'error' OR pel.execution_status = 'failed')
        ORDER BY pel.created_at DESC
    ");
    $processErrorsStmt->execute([$tenant_id, $oneHourAgo]);
    $processErrors = $processErrorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($processErrors as $error) {
        $allErrors[] = [
            'type' => 'process_execution',
            'execution_id' => $error['execution_id'],
            'process_id' => $error['process_id'],
            'process_name' => $error['process_name'],
            'status' => $error['execution_status'],
            'error_message' => $error['error_message'],
            'execution_time_ms' => $error['execution_time_ms'],
            'orchestration_context_build_ms' => $error['orchestration_context_build_ms'],
            'orchestration_validation_ms' => $error['orchestration_validation_ms'],
            'orchestration_total_ms' => $error['orchestration_total_ms'],
            'created_at' => $error['created_at']
        ];
    }
    
    // 2. Get integration execution errors from IntegrationExecutionQueue
    $integrationQueueErrorsStmt = $db->prepare("
        SELECT 
            ieq.execution_id,
            ieq.integration_id,
            i.name as integration_name,
            ieq.status,
            ieq.error_message,
            ieq.error_code,
            ieq.execution_time_ms,
            ieq.retry_count,
            ieq.completed_at,
            ieq.queued_at,
            ieq.started_at
        FROM IntegrationExecutionQueue ieq
        LEFT JOIN Integrations i ON ieq.integration_id = i.integration_id
        WHERE ieq.tenant_id = ?
        AND (ieq.status = 'failed' OR ieq.status = 'error')
        AND (ieq.completed_at >= ? OR (ieq.completed_at IS NULL AND ieq.queued_at >= ?))
        ORDER BY COALESCE(ieq.completed_at, ieq.queued_at) DESC
    ");
    $integrationQueueErrorsStmt->execute([$tenant_id, $oneHourAgo, $oneHourAgo]);
    $integrationQueueErrors = $integrationQueueErrorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($integrationQueueErrors as $error) {
        $allErrors[] = [
            'type' => 'integration_execution',
            'execution_id' => $error['execution_id'],
            'integration_id' => $error['integration_id'],
            'integration_name' => $error['integration_name'],
            'status' => $error['status'],
            'error_message' => $error['error_message'],
            'error_code' => $error['error_code'],
            'execution_time_ms' => $error['execution_time_ms'],
            'retry_count' => $error['retry_count'],
            'queued_at' => $error['queued_at'],
            'started_at' => $error['started_at'],
            'completed_at' => $error['completed_at']
        ];
    }
    
    // 3. Get integration log errors from IntegrationLogs
    $integrationLogErrorsStmt = $db->prepare("
        SELECT 
            il.log_id,
            il.integration_id,
            i.name as integration_name,
            il.status,
            il.message,
            il.request_data,
            il.response_data,
            il.execution_time,
            il.created_at
        FROM IntegrationLogs il
        LEFT JOIN Integrations i ON il.integration_id = i.integration_id
        WHERE il.tenant_id = ?
        AND il.status = 'error'
        AND il.created_at >= ?
        ORDER BY il.created_at DESC
    ");
    $integrationLogErrorsStmt->execute([$tenant_id, $oneHourAgo]);
    $integrationLogErrors = $integrationLogErrorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($integrationLogErrors as $error) {
        $allErrors[] = [
            'type' => 'integration_log',
            'log_id' => $error['log_id'],
            'integration_id' => $error['integration_id'],
            'integration_name' => $error['integration_name'],
            'status' => $error['status'],
            'message' => $error['message'],
            'request_data' => $error['request_data'] ? json_decode($error['request_data'], true) : null,
            'response_data' => $error['response_data'] ? json_decode($error['response_data'], true) : null,
            'execution_time' => $error['execution_time'],
            'created_at' => $error['created_at']
        ];
    }
    
    // 4. Scan process log JSON files for error-level entries
    $tenantDataPath = BASE_PATH . '/storage/tenantdata/' . trim($tenant_id, '/');
    $processflowsPath = $tenantDataPath . '/processflows';
    
    if (is_dir($processflowsPath)) {
        // Get all process directories
        $processDirs = glob($processflowsPath . '/*', GLOB_ONLYDIR);
        
        foreach ($processDirs as $processDir) {
            $processId = basename($processDir);
            
            // Get all JSON log files in this process directory
            $logFiles = glob($processDir . '/*.json');
            
            foreach ($logFiles as $logFile) {
                $executionId = basename($logFile, '.json');
                $fileMtime = filemtime($logFile);
                
                // Only check files modified in the past hour
                if ($fileMtime && $fileMtime >= strtotime('-1 hour')) {
                    $logContent = file_get_contents($logFile);
                    if ($logContent) {
                        $logs = json_decode($logContent, true);
                        if (is_array($logs)) {
                            foreach ($logs as $logEntry) {
                                // Check if log entry is an error and within the past hour
                                $logLevel = strtolower($logEntry['level'] ?? '');
                                $logTimestamp = $logEntry['timestamp'] ?? '';
                                
                                if (in_array($logLevel, ['error', 'critical', 'fatal']) && 
                                    $logTimestamp && 
                                    strtotime($logTimestamp) >= strtotime('-1 hour')) {
                                    
                                    $allErrors[] = [
                                        'type' => 'process_log',
                                        'execution_id' => $logEntry['execution_id'] ?? $executionId,
                                        'process_id' => $processId,
                                        'log_file' => 'processflows/' . $processId . '/' . basename($logFile),
                                        'level' => $logLevel,
                                        'message' => $logEntry['message'] ?? '',
                                        'timestamp' => $logTimestamp
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // If no errors found, return success without creating file
    if (empty($allErrors)) {
        return [
            'success' => true,
            'data' => [
                'errors_found' => 0,
                'file_created' => false,
                'message' => 'No errors found in the past hour'
            ]
        ];
    }
    
    // Prepare error summary
    $errorSummary = [
        'generated_at' => date('Y-m-d H:i:s'),
        'time_range' => [
            'from' => $oneHourAgo,
            'to' => date('Y-m-d H:i:s')
        ],
        'total_errors' => count($allErrors),
        'error_counts' => [
            'process_execution' => count(array_filter($allErrors, fn($e) => $e['type'] === 'process_execution')),
            'integration_execution' => count(array_filter($allErrors, fn($e) => $e['type'] === 'integration_execution')),
            'integration_log' => count(array_filter($allErrors, fn($e) => $e['type'] === 'integration_log')),
            'process_log' => count(array_filter($allErrors, fn($e) => $e['type'] === 'process_log'))
        ],
        'errors' => $allErrors
    ];
    
    // Create filename with timestamp
    $filename = 'error-report-' . date('Y-m-d_H-i-s') . '.json';
    $filePath = $tenantDataPath . '/error-reports/' . $filename;
    
    // Ensure directory exists
    $errorReportsDir = $tenantDataPath . '/error-reports';
    if (!is_dir($errorReportsDir)) {
        mkdir($errorReportsDir, 0755, true);
    }
    
    // Write error report to file
    $jsonContent = json_encode($errorSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $writeResult = file_put_contents($filePath, $jsonContent, LOCK_EX);
    
    if ($writeResult === false) {
        return [
            'success' => false,
            'error' => [
                'code' => 'FILE_WRITE_ERROR',
                'message' => 'Failed to write error report file',
                'details' => 'Could not write to: ' . $filePath
            ],
            'data' => null
        ];
    }
    
    // Return relative path from tenantdata root
    $relativePath = 'error-reports/' . $filename;
    
    return [
        'success' => true,
        'data' => [
            'errors_found' => count($allErrors),
            'file_created' => true,
            'file_path' => $relativePath,
            'full_path' => $filePath,
            'file_size' => $writeResult,
            'summary' => [
                'total_errors' => $errorSummary['total_errors'],
                'error_counts' => $errorSummary['error_counts']
            ]
        ],
        'message' => 'Error report created successfully'
    ];
    
} catch (PDOException $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Database operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'UNKNOWN_ERROR',
            'message' => 'Unexpected error occurred',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
