<?php
/**
 * Process Step: Fetch Issue Content from GitLab
 * 
 * This step fetches issue content from GitLab using the GitLab API connector.
 * It expects issue_iid and project_id from the process input (from support ticket).
 * 
 * Input from previous step ($process_input):
 * - issue_iid (string|int): GitLab issue IID (issue number within project)
 * - project_id (string|int): GitLab project ID or path (e.g., "2" or "group/project")
 * 
 * Output to next step:
 * - issue_data (array): Full GitLab issue object with all fields
 * - issue_iid (string): Issue IID
 * - project_id (string): Project ID
 */


 // Integration ID placeholder - to be filled during deployment
$integrationId = '27f20b63-4b7b-4cd0-a669-b4eb9c6ab695';
// Tealfabric IO Support project ID
$projectId = 6;

// Validate input
if (empty($process_input['issue_iid'])) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_ISSUE_IID',
            'message' => 'Issue IID is required',
            'details' => 'The process input must contain issue_iid field'
        ],
        'data' => null
    ];
}


$issueIid = (string)$process_input['issue_iid'];

log_message("Fetching GitLab issue #{$issueIid} from project {$projectId}");



// Fetch issue from GitLab using the GitLab API connector
$result = $integration->executeSync($integrationId, [
    'operation' => 'receive',
    'endpoint' => "projects/{$projectId}/issues/{$issueIid}"
]);

if (!$result['success']) {
    $errorMessage = $result['error'] ?? 'Unknown error occurred while fetching issue';
    log_message("Failed to fetch GitLab issue: {$errorMessage}");
    
    return [
        'success' => false,
        'error' => [
            'code' => 'GITLAB_FETCH_FAILED',
            'message' => 'Failed to fetch issue from GitLab',
            'details' => $errorMessage
        ],
        'data' => null
    ];
}

// Extract issue data from response
// IntegrationHelper should unwrap the result, but handle both wrapped and unwrapped cases
// Wrapped: { success: true, execution_id: '...', result: { success: true, data: [...], ... } }
// Unwrapped: { success: true, data: [...], message_count: 1, ... }
$issueData = null;
$dataSource = null;

// Check if result is wrapped (has 'result' key with nested structure)
if (isset($result['result']) && is_array($result['result'])) {
    // Result is wrapped - use nested result
    $dataSource = $result['result'];
} else {
    // Result is unwrapped - use directly
    $dataSource = $result;
}

// Extract data from the appropriate source
if (isset($dataSource['data']) && is_array($dataSource['data'])) {
    // Data is an array - check if it's a numeric array (list) or associative array (single object)
    if (!empty($dataSource['data']) && isset($dataSource['data'][0])) {
        // Numeric array with at least one element - take first element
        $issueData = $dataSource['data'][0];
    } elseif (!empty($dataSource['data']) && (isset($dataSource['data']['iid']) || isset($dataSource['data']['id']))) {
        // Associative array with issue-like keys - it's the issue object itself
        $issueData = $dataSource['data'];
    } else {
        // Empty array or unexpected structure
        $issueData = null;
    }
} elseif (isset($dataSource['data'])) {
    // Data exists but is not an array - might be a single issue object (unlikely but handle it)
    $issueData = $dataSource['data'];
}

// Validate that we have valid issue data
if (empty($issueData) || !is_array($issueData) || (!isset($issueData['iid']) && !isset($issueData['id']))) {
    log_message("GitLab API returned empty or invalid issue data. Debug info: " . json_encode([
        'result_keys' => isset($result) && is_array($result) ? array_keys($result) : [],
        'has_result_key' => isset($result['result']),
        'result_result_keys' => isset($result['result']) && is_array($result['result']) ? array_keys($result['result']) : [],
        'data_source_keys' => isset($dataSource) && is_array($dataSource) ? array_keys($dataSource) : [],
        'has_data_in_source' => isset($dataSource['data']),
        'data_type' => isset($dataSource['data']) ? gettype($dataSource['data']) : 'not_set',
        'data_is_array' => isset($dataSource['data']) && is_array($dataSource['data']),
        'data_count' => isset($dataSource['data']) && is_array($dataSource['data']) ? count($dataSource['data']) : 'N/A',
        'has_index_0' => isset($dataSource['data']) && is_array($dataSource['data']) && isset($dataSource['data'][0]),
        'has_iid_key' => isset($dataSource['data']) && is_array($dataSource['data']) && isset($dataSource['data']['iid']),
        'message_count' => $dataSource['message_count'] ?? $result['message_count'] ?? 'not_set'
    ]));
    
    return [
        'success' => false,
        'error' => [
            'code' => 'EMPTY_ISSUE_DATA',
            'message' => 'GitLab API returned empty issue data',
            'details' => 'The issue may not exist or access may be denied. Check logs for response structure details.'
        ],
        'data' => null
    ];
}

log_message("Successfully fetched GitLab issue #{$issueIid}: {$issueData['title']}");

// Get user_id and user info from process_input or context (for passing to next steps)
$userId = $process_input['user_id'] ?? $user_id ?? '';
$userEmail = $process_input['user_email'] ?? '';
$userFullName = $process_input['user_full_name'] ?? '';

// Return issue data for next step
return [
    'success' => true,
    'data' => [
        'issue_data' => $issueData,
        'issue_iid' => $issueIid,
        'project_id' => $projectId,
        'issue_title' => $issueData['title'] ?? '',
        'issue_description' => $issueData['description'] ?? '',
        'issue_state' => $issueData['state'] ?? '',
        'issue_labels' => $issueData['labels'] ?? [],
        'issue_author' => $issueData['author'] ?? null,
        'issue_created_at' => $issueData['created_at'] ?? null,
        'issue_updated_at' => $issueData['updated_at'] ?? null,
        'issue_url' => $issueData['web_url'] ?? null,
        'user_id' => $userId,  // Pass user_id to next step
        'user_email' => $userEmail,  // Pass user_email to next step
        'user_full_name' => $userFullName  // Pass user_full_name to next step
    ],
    'message' => "Successfully fetched issue #{$issueIid} from GitLab"
];

