<?php
/**
 * Process Step: Analyze GitLab Issue and Post Analysis
 * 
 * This step analyzes a GitLab issue using LLM and posts the analysis as a comment.
 * It replicates the functionality from gitlab-issue-analyzer.php.
 * 
 * Input from previous step ($process_input):
 * - issue_data (array): Full GitLab issue object
 * - issue_iid (string): GitLab issue IID
 * - project_id (string): GitLab project ID or path
 * 
 * Output to next step:
 * - analysis (string): Generated analysis text
 * - comment_posted (bool): Whether comment was successfully posted
 * - labels_updated (array): Labels that were added/updated
 */

 // Integration ID placeholder - to be filled during deployment
// The integration with Gitlab API connector or Generic RestAPI connector
//$integrationId = '27f20b63-4b7b-4cd0-a669-b4eb9c6ab695';
// Gitlab project ID to connect
$projectId = 0;


// Validate input
if (empty($process_input['issue_data'])) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_ISSUE_DATA',
            'message' => 'Issue data is required',
            'details' => 'The process input must contain issue_data from previous step'
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

$issueData = $process_input['issue_data'];
$issueIid = (string)$process_input['issue_iid'];

$issueTitle = $issueData['title'] ?? 'Untitled Issue';
$issueDescription = $issueData['description'] ?? '';
$issueState = $issueData['state'] ?? '';
$issueLabels = $issueData['labels'] ?? [];
$issueAuthor = $issueData['author'] ?? null;
$issueUrl = $issueData['web_url'] ?? '';

log_message("Analyzing GitLab issue #{$issueIid}: {$issueTitle}");

// Build prompt for LLM analysis
// Replicate the analysis guidelines from gitlab-issue-analyzer.php
$analysisPrompt = "You are a technical support analyst reviewing a GitLab issue. Provide detailed, factual, technical analysis for developers.

Analysis Guidelines:
- Provide detailed, factual, technical analysis for developers
- Include root cause identification and specific fix proposals
- Avoid vague remarks - be specific and actionable
- Include code examples, file locations, and testing steps if applicable
- Tag with \"Fix Review\" if fix is identified, \"Agent Question\" if clarification needed

Issue Details:
Title: {$issueTitle}
State: {$issueState}
Labels: " . implode(', ', $issueLabels) . "
Author: " . ($issueAuthor['name'] ?? 'Unknown') . " (" . ($issueAuthor['username'] ?? 'unknown') . ")
Created: " . ($issueData['created_at'] ?? 'Unknown') . "
Updated: " . ($issueData['updated_at'] ?? 'Unknown') . "

Description:
{$issueDescription}

Provide a comprehensive technical analysis of this issue. Include:
1. Root cause analysis (if identifiable)
2. Specific fix proposals (if applicable)
3. Code examples or file locations (if relevant)
4. Testing steps (if applicable)
5. Any questions or clarifications needed

Format your response in markdown for better readability.";

// Call LLM to generate analysis
log_message("Calling LLM to generate issue analysis");

try {
    $llmResult = $llm->callLLM($analysisPrompt, [
        'max_tokens' => 10000,
        'temperature' => 0.3
    ]);
    
    if (!$llmResult['success']) {
        $errorMessage = $llmResult['error'] ?? 'Unknown error occurred while generating analysis';
        log_message("LLM analysis failed: {$errorMessage}");
        
        return [
            'success' => false,
            'error' => [
                'code' => 'LLM_ANALYSIS_FAILED',
                'message' => 'Failed to generate analysis using LLM',
                'details' => $errorMessage
            ],
            'data' => null
        ];
    }
    
    // Extract analysis text from LLM response
    $analysis = $llmResult['response']['response'] ?? '';
    
    if (empty($analysis)) {
        log_message("LLM returned empty analysis");
        
        return [
            'success' => false,
            'error' => [
                'code' => 'EMPTY_ANALYSIS',
                'message' => 'LLM returned empty analysis',
                'details' => 'The LLM service did not generate any analysis content'
            ],
            'data' => null
        ];
    }
    
    log_message("Analysis generated successfully (length: " . strlen($analysis) . " characters)");
    
} catch (Exception $e) {
    log_message("Exception during LLM analysis: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => [
            'code' => 'LLM_EXCEPTION',
            'message' => 'Exception occurred during LLM analysis',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}



// Post analysis as comment to GitLab issue
log_message("Posting analysis comment to GitLab issue #{$issueIid}");

$commentResult = $integration->executeSync($integrationId, [
    'operation' => 'send',
    'endpoint' => "projects/{$projectId}/issues/{$issueIid}/notes",
    'method' => 'POST',
    'data' => [
        'body' => $analysis
    ]
]);

if (!$commentResult['success']) {
    $errorMessage = $commentResult['error'] ?? 'Unknown error occurred while posting comment';
    log_message("Failed to post comment to GitLab: {$errorMessage}");
    
    // Return partial success - analysis was generated but comment posting failed
    return [
        'success' => false,
        'error' => [
            'code' => 'COMMENT_POST_FAILED',
            'message' => 'Analysis generated but failed to post comment',
            'details' => $errorMessage
        ],
        'data' => [
            'analysis' => $analysis,
            'comment_posted' => false
        ]
    ];
}

log_message("Analysis comment posted successfully to GitLab issue #{$issueIid}");

// Determine labels based on analysis (replicate logic from gitlab-issue-analyzer.php)
$labelsToAdd = [];
$titleLower = strtolower($issueTitle);
$descriptionLower = strtolower($issueDescription);
$analysisLower = strtolower($analysis);
$content = $titleLower . ' ' . $descriptionLower . ' ' . $analysisLower;

// Check for "Fix Review" tag
if (strpos($analysis, 'Fix Review') !== false) {
    $labelsToAdd[] = 'Fix Review';
}

// Check for "Agent Question" tag
if (strpos($analysis, 'Agent Question') !== false) {
    $labelsToAdd[] = 'Agent Question';
}

// Check for "Ready for AUT/UAT" tags
if (stripos($analysis, 'Ready for AUT') !== false || stripos($analysis, 'Ready for Automated') !== false) {
    $labelsToAdd[] = 'Ready for AUT';
}
if (stripos($analysis, 'Ready for UAT') !== false) {
    $labelsToAdd[] = 'Ready for UAT';
}

// Priority-based labels
if (strpos($content, 'critical') !== false || strpos($content, 'urgent') !== false) {
    $labelsToAdd[] = 'Priority: High';
} elseif (strpos($content, 'bug') !== false || strpos($content, 'error') !== false) {
    $labelsToAdd[] = 'Priority: Medium';
} else {
    $labelsToAdd[] = 'Priority: Low';
}

// Category-based labels
if (strpos($content, 'ssl') !== false && strpos($content, 'connector') !== false) {
    $labelsToAdd[] = 'Component: Connectors';
}
if (strpos($content, 'authentication') !== false || strpos($content, 'login') !== false) {
    $labelsToAdd[] = 'Component: Auth';
}
if (strpos($content, 'ui') !== false || strpos($content, 'frontend') !== false || strpos($content, 'css') !== false) {
    $labelsToAdd[] = 'Component: Frontend';
}
if (strpos($content, 'api') !== false || strpos($content, 'backend') !== false) {
    $labelsToAdd[] = 'Component: Backend';
}
if (strpos($content, 'database') !== false || strpos($content, 'sql') !== false) {
    $labelsToAdd[] = 'Component: Database';
}

// Analysis status labels
if (strpos($analysis, 'Root Cause') !== false) {
    $labelsToAdd[] = 'Status: Analyzed';
}

$labelsToAdd = array_unique($labelsToAdd);

// Update issue labels if any new labels were determined
$labelsUpdated = [];
if (!empty($labelsToAdd)) {
    log_message("Updating issue labels: " . implode(', ', $labelsToAdd));
    
    // Merge with existing labels
    $allLabels = array_unique(array_merge($issueLabels, $labelsToAdd));
    
    $labelUpdateResult = $integration->executeSync($integrationId, [
        'operation' => 'send',
        'endpoint' => "projects/{$projectId}/issues/{$issueIid}",
        'method' => 'PUT',
        'data' => [
            'labels' => implode(',', $allLabels)
        ]
    ]);
    
    if ($labelUpdateResult['success']) {
        $labelsUpdated = $labelsToAdd;
        log_message("Labels updated successfully");
    } else {
        log_message("Failed to update labels: " . ($labelUpdateResult['error'] ?? 'Unknown error'));
    }
}

// Get user_id from process_input or context (for passing to next steps)
$userId = $process_input['user_id'] ?? $user_id ?? '';

// Get user info from process_input (for passing to next steps)
$userEmail = $process_input['user_email'] ?? '';
$userFullName = $process_input['user_full_name'] ?? '';

// Return success with analysis and results
return [
    'success' => true,
    'data' => [
        'analysis' => $analysis,
        'comment_posted' => true,
        'labels_updated' => $labelsUpdated,
        'issue_iid' => $issueIid,
        'project_id' => $projectId,
        'issue_url' => $issueUrl,
        'issue_title' => $issueTitle,  // Add issue_title for email step
        'user_id' => $userId,  // Pass user_id to next step
        'user_email' => $userEmail,  // Pass user_email to next step
        'user_full_name' => $userFullName  // Pass user_full_name to next step
    ],
    'message' => "Successfully analyzed and posted analysis to issue #{$issueIid}"
];

