<?php
/**
 * Process Step: Notify Support Crew via Mattermost
 * 
 * This step sends a notification to the Mattermost support channel about the analyzed issue.
 * Uses RestAPI Generic connector to send message to Mattermost API.
 * 
 * Input from previous step ($process_input):
 * - analysis (string): Generated analysis text
 * - issue_iid (string): GitLab issue IID
 * - project_id (string): GitLab project ID
 * - issue_url (string): GitLab issue URL
 * - issue_data (array): Full GitLab issue object (optional, for additional details)
 * - labels_updated (array): Labels that were added (optional)
 * 
 * Output to next step:
 * - notification_sent (bool): Whether notification was successfully sent
 * - mattermost_message_id (string): Mattermost message ID if available
 */


// Integration ID placeholder - to be filled during deployment
$integrationId = 'b9214c42-f57d-442d-908f-8d222a0b24eb';

// Validate input
if (empty($process_input['analysis'])) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_ANALYSIS',
            'message' => 'Analysis is required',
            'details' => 'The process input must contain analysis from previous step'
        ],
        'data' => null
    ];
}

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

$analysis = $process_input['analysis'];
$issueIid = (string)$process_input['issue_iid'];
$issueUrl = $process_input['issue_url'] ?? '';
$issueData = $process_input['issue_data'] ?? [];
$labelsUpdated = $process_input['labels_updated'] ?? [];

$issueTitle = $issueData['title'] ?? "Issue #{$issueIid}";
$issueState = $issueData['state'] ?? '';
$issueLabels = $issueData['labels'] ?? [];

log_message("Preparing Mattermost notification for issue #{$issueIid}");

// Build Mattermost message
// Mattermost supports markdown formatting
$message = "## 🔍 Support Ticket Received and Analysis Completed\n\n";
$message .= "**Issue:** [{$issueTitle}]({$issueUrl})\n";
$message .= "**Issue #:** {$issueIid}\n";

if (!empty($issueState)) {
    $message .= "**State:** {$issueState}\n";
}

if (!empty($issueLabels)) {
    $labelsDisplay = implode(', ', array_map(function($label) {
        return "`{$label}`";
    }, $issueLabels));
    $message .= "**Labels:** {$labelsDisplay}\n";
}

if (!empty($labelsUpdated)) {
    $newLabelsDisplay = implode(', ', array_map(function($label) {
        return "`{$label}`";
    }, $labelsUpdated));
    $message .= "**New Labels Added:** {$newLabelsDisplay}\n";
}

$message .= "\n---\n\n";
$message .= "### Analysis\n\n";

// Truncate analysis if too long (Mattermost has message length limits)
// Keep full analysis but add a note if truncated
$maxAnalysisLength = 3000; // Mattermost typically supports up to 4000 characters per message
if (strlen($analysis) > $maxAnalysisLength) {
    $truncatedAnalysis = substr($analysis, 0, $maxAnalysisLength);
    $message .= $truncatedAnalysis . "\n\n";
    $message .= "*[Analysis truncated - full analysis available in GitLab issue comments]*\n\n";
    $message .= "**View full analysis:** [GitLab Issue #{$issueIid}]({$issueUrl})\n";
} else {
    $message .= $analysis . "\n\n";
}

$message .= "---\n\n";
$message .= "**View Issue:** [{$issueUrl}]({$issueUrl})\n";


// Mattermost webhook endpoint - path is configured in the integration
// The integration should be configured with the webhook URL path
// Pass data directly to the webhook (text and username)

log_message("Sending Mattermost notification to support channel");

// Send message to Mattermost using RestAPI Generic connector (webhook)
// Format matches working process flow - pass text and username directly
$result = $integration->executeSync($integrationId, [
    'text' => $message,
    'username' => 'Tealfabric IO Support'
]);

if (!$result['success']) {
    $errorMessage = $result['error'] ?? 'Unknown error occurred while sending Mattermost notification';
    log_message("Failed to send Mattermost notification: {$errorMessage}");
    
    return [
        'success' => false,
        'error' => [
            'code' => 'MATTERMOST_SEND_FAILED',
            'message' => 'Failed to send notification to Mattermost',
            'details' => $errorMessage
        ],
        'data' => [
            'notification_sent' => false,
            'message_prepared' => $message
        ]
    ];
}

log_message("Mattermost notification sent successfully");

// Extract message ID from response if available
$mattermostMessageId = null;
if (isset($result['data']) && is_array($result['data'])) {
    // Mattermost API returns post object with 'id' field
    $mattermostMessageId = $result['data']['id'] ?? $result['data'][0]['id'] ?? null;
}

// Get user_id and user info from process_input or context (for passing to next steps)
$userId = $process_input['user_id'] ?? $user_id ?? '';
$userEmail = $process_input['user_email'] ?? '';
$userFullName = $process_input['user_full_name'] ?? '';

// Return success with notification details
return [
    'success' => true,
    'data' => [
        'notification_sent' => true,
        'mattermost_message_id' => $mattermostMessageId,
        'issue_iid' => $issueIid,
        'issue_url' => $issueUrl,
        'issue_title' => $process_input['issue_title'] ?? '',  // Pass issue_title for email step
        'message_preview' => substr($message, 0, 200) . '...',
        'user_id' => $userId,  // Pass user_id to next step
        'user_email' => $userEmail,  // Pass user_email to next step
        'user_full_name' => $userFullName  // Pass user_full_name to next step
    ],
    'message' => "Successfully sent Mattermost notification for issue #{$issueIid}"
];

