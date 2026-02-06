<?php
/**
 * Process Step: Notify User via Email
 * 
 * This step sends an email notification to the user who created the support ticket.
 * Informs them that the request has been received and will be addressed soon.
 * Also mentions they can discuss the topic with the Support agent in Tealfabric IO.
 * 
 * Input from previous step ($process_input):
 * - user_id (string): User ID to send email to (can also use $user_id from context)
 * - issue_iid (string): GitLab issue IID (optional, for reference)
 * - issue_url (string): GitLab issue URL (optional, for reference)
 * 
 * Note: $user_id is also available in execution context, but we'll use process_input
 * to ensure it flows through the process chain explicitly.
 */

 // Integration ID placeholder - to be filled during deployment
$integrationId = '9c2d8c1e-3af6-4cec-b30a-d0805b716ab2';

// Get user_id from process_input or fallback to context variable
$userId = $process_input['user_id'] ?? $user_id ?? '';

if (empty($userId)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_USER_ID',
            'message' => 'User ID is required',
            'details' => 'The process input must contain user_id field or $user_id must be available in context'
        ],
        'data' => null
    ];
}

// Get user email and full_name from process_input (passed from support ticket creation callback)
// This avoids the need for API lookup which requires session authentication
$userEmail = $process_input['user_email'] ?? '';
$userFullName = $process_input['user_full_name'] ?? 'User';

// If email is not in process_input, try to get it from API (fallback)
// Note: This may fail due to tenant scoping if user is in different tenant
if (empty($userEmail)) {
    log_message("User email not in process_input, attempting API lookup for user_id: {$userId}");
    
    // Get base URL from http_headers (available in ProcessFlow context) or use default
    $host = $http_headers['Host'] ?? 'localhost';
    $protocol = (!empty($http_headers['X-Forwarded-Proto']) && $http_headers['X-Forwarded-Proto'] === 'https') 
        ? 'https' 
        : (isset($http_headers['Host']) ? 'https' : 'http'); // Default to https for production
    $baseUrl = "{$protocol}://{$host}";
    
    $apiResponse = $api->get("{$baseUrl}/api/v1/users/{$userId}");
    
    if ($apiResponse && isset($apiResponse['success']) && $apiResponse['success']) {
        $responseData = $apiResponse['data'] ?? null;
        $user = $responseData['user'] ?? $responseData ?? null;
        if ($user && !empty($user['email'])) {
            $userEmail = $user['email'];
            $userFullName = $user['full_name'] ?? 'User';
        }
    }
}

if (empty($userEmail)) {
    log_message("User email not found for user_id: {$userId}");
    
    return [
        'success' => false,
        'error' => [
            'code' => 'USER_EMAIL_NOT_FOUND',
            'message' => 'User email address is required',
            'details' => "Could not find email address for user ID: {$userId}. Email should be passed in process_input from support ticket creation."
        ],
        'data' => null
    ];
}

log_message("Sending email notification to: {$userEmail} ({$userFullName})");

// Get issue information for email content (optional)
$issueIid = $process_input['issue_iid'] ?? '';
$issueUrl = $process_input['issue_url'] ?? '';
$issueTitle = $process_input['issue_title'] ?? 'Support Request';

// Build email subject
$emailSubject = "Support Request Received - We'll Get Back to You Soon";

// Build email body (HTML format)
$emailBodyHtml = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
$emailBodyHtml .= "<div style='max-width: 600px; margin: 0 auto; padding: 20px;'>";
$emailBodyHtml .= "<h2 style='color: #2c3e50;'>Support Request Received</h2>";
$emailBodyHtml .= "<p>Dear {$userFullName},</p>";
$emailBodyHtml .= "<p>Thank you for contacting us. We have received your support request and our team is reviewing it.</p>";

if (!empty($issueIid) && !empty($issueUrl)) {
    $emailBodyHtml .= "<p><strong>Request Reference:</strong> <a href='{$issueUrl}' style='color: #3498db;'>Issue #{$issueIid}</a></p>";
}

$emailBodyHtml .= "<p>We will get back to you as soon as possible. Our support team typically responds within 24 hours during business days.</p>";

$emailBodyHtml .= "<h3 style='color: #2c3e50; margin-top: 30px;'>Need Immediate Assistance?</h3>";
$emailBodyHtml .= "<p>You can also discuss your topic with our Support agent directly in Tealfabric IO:</p>";
$emailBodyHtml .= "<p style='margin: 20px 0;'>";
$emailBodyHtml .= "<a href='https://tealfabric.io' style='background-color: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>Open Support Chat</a>";
$emailBodyHtml .= "</p>";

$emailBodyHtml .= "<p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #7f8c8d; font-size: 0.9em;'>";
$emailBodyHtml .= "Best regards,<br>";
$emailBodyHtml .= "Tealfabric Support Team";
$emailBodyHtml .= "</p>";

$emailBodyHtml .= "</div>";
$emailBodyHtml .= "</body></html>";

// Build plain text version for email clients that don't support HTML
$emailBodyText = "Support Request Received\n\n";
$emailBodyText .= "Dear {$userFullName},\n\n";
$emailBodyText .= "Thank you for contacting us. We have received your support request and our team is reviewing it.\n\n";

if (!empty($issueIid) && !empty($issueUrl)) {
    $emailBodyText .= "Request Reference: Issue #{$issueIid}\n";
    $emailBodyText .= "View issue: {$issueUrl}\n\n";
}

$emailBodyText .= "We will get back to you as soon as possible. Our support team typically responds within 24 hours during business days.\n\n";
$emailBodyText .= "Need Immediate Assistance?\n\n";
$emailBodyText .= "You can also discuss your topic with our Support agent directly in Tealfabric IO:\n";
$emailBodyText .= "https://tealfabric.io\n\n";
$emailBodyText .= "Best regards,\n";
$emailBodyText .= "Tealfabric Support Team";


// Send email using SMTP Email connector
log_message("Sending email via SMTP connector");

$result = $integration->executeSync($integrationId, [
    'operation' => 'send',
    'to' => $userEmail,
    'subject' => $emailSubject,
    'body' => $emailBodyText,
    'html_body' => $emailBodyHtml,
    'is_html' => true  // Indicate that html_body contains HTML content
]);

if (!$result['success']) {
    $errorMessage = $result['error'] ?? 'Unknown error occurred while sending email';
    log_message("Failed to send email notification: {$errorMessage}");
    
    return [
        'success' => false,
        'error' => [
            'code' => 'EMAIL_SEND_FAILED',
            'message' => 'Failed to send email notification',
            'details' => $errorMessage
        ],
        'data' => [
            'email_sent' => false,
            'user_email' => $userEmail,
            'user_full_name' => $userFullName
        ]
    ];
}

log_message("Email notification sent successfully to: {$userEmail}");

// Return success with email details
return [
    'success' => true,
    'data' => [
        'email_sent' => true,
        'user_id' => $userId,
        'user_email' => $userEmail,
        'user_full_name' => $userFullName,
        'email_subject' => $emailSubject,
        'issue_iid' => $issueIid,
        'issue_url' => $issueUrl
    ],
    'message' => "Successfully sent email notification to {$userFullName} ({$userEmail})"
];

