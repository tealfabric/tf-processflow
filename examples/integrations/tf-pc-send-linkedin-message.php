<?php
/**
 * Send LinkedIn Direct Message
 * 
 * Process step for sending a personalized LinkedIn direct message to a contact.
 * 
 * Functionality:
 * 1. Takes a contact full name as input
 * 2. Searches for the LinkedIn contact by name using LinkedIn connector integration
 * 3. Retrieves the contact's profile information by contact ID
 * 4. Uses LLM to generate a personalized message based on the contact's profile
 * 5. Returns the generated message title and body
 * 
 * Expected Process Input:
 * - contact_fullname (string, required): Full name of the LinkedIn contact to message
 * - linkedin_integration_id (string, optional): Integration ID for LinkedIn connector.
 *   If not provided, the system will attempt to find it automatically.
 * - system_prompt (string, optional): Custom system prompt for LLM message generation.
 *   If not provided, a default prompt will be used. This can be configured by sysadmin.
 * 
 * Expected Process Output:
 * - success (boolean): Whether the operation succeeded
 * - data (array): Contains:
 *   - contact_id (string): LinkedIn contact ID found
 *   - contact_name (string): Full name of the contact
 *   - profile_info (array): Contact's profile information retrieved from LinkedIn
 *   - message_title (string): Generated message title/subject
 *   - message_body (string): Generated personalized message body
 * - error (array, if failed): Error details with code, message, and details
 * 
 * Requirements:
 * - LinkedIn connector integration must be configured and active
 * - LLM service must be available and configured
 * - Contact must exist in LinkedIn and be searchable
 */

// Validate input
if (empty($process_input) || !is_array($process_input)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid input data',
            'details' => 'Input must be an array with contact_fullname'
        ],
        'data' => null
    ];
}

// Extract contact fullname
$contactFullname = $process_input['contact_fullname'] ?? '';
if (empty($contactFullname)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_CONTACT_NAME',
            'message' => 'Contact fullname is required',
            'details' => 'process_input.contact_fullname must be provided'
        ],
        'data' => null
    ];
}

// Get LinkedIn integration ID (from input or find automatically)
$linkedinIntegrationId = $process_input['linkedin_integration_id'] ?? null;

// System prompt for LLM (can be customized by sysadmin)
// TODO: Sysadmin should configure this prompt based on messaging goals and tone
$systemPrompt = $process_input['system_prompt'] ?? <<<'PROMPT'
You are a professional networking assistant. Create a short, personalized LinkedIn message that:
- Is warm and professional
- References specific details from the contact's profile
- Is concise (2-3 sentences maximum)
- Includes a clear call-to-action or next step
- Avoids being overly salesy or pushy

Generate both a message title (subject line, max 50 characters) and message body.
PROMPT;

try {
    // Step 1: Find LinkedIn integration if not provided
    if (empty($linkedinIntegrationId)) {
        $integrationStmt = $db->prepare("
            SELECT integration_id 
            FROM Integrations 
            WHERE type = 'linkedin' 
            AND status = 'active' 
            AND is_active = 1
            LIMIT 1
        ");
        $integrationStmt->execute();
        $integrationResult = $integrationStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$integrationResult || empty($integrationResult['integration_id'])) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'LINKEDIN_INTEGRATION_NOT_FOUND',
                    'message' => 'LinkedIn integration not found',
                    'details' => 'No active LinkedIn integration found. Please configure LinkedIn connector integration.'
                ],
                'data' => null
            ];
        }
        
        $linkedinIntegrationId = $integrationResult['integration_id'];
    }
    
    // Step 2: Search for contact by name using LinkedIn integration
    // Assumes LinkedIn connector supports 'search_contact' action with 'name' parameter
    $searchResult = $integration->executeSync($linkedinIntegrationId, [
        'action' => 'search_contact',
        'name' => $contactFullname
    ]);
    
    if (!$searchResult['success']) {
        $errorMessage = $searchResult['error'] ?? 'Unknown error occurred while searching LinkedIn contact';
        return [
            'success' => false,
            'error' => [
                'code' => 'LINKEDIN_SEARCH_FAILED',
                'message' => 'Failed to search LinkedIn contact',
                'details' => $errorMessage
            ],
            'data' => null
        ];
    }
    
    // Extract contact ID from search result
    // Adjust based on actual LinkedIn connector response structure
    $contactId = $searchResult['result']['contact_id'] ?? 
                 $searchResult['result']['id'] ?? 
                 $searchResult['data']['contact_id'] ?? 
                 $searchResult['data']['id'] ?? null;
    
    if (empty($contactId)) {
        return [
            'success' => false,
            'error' => [
                'code' => 'CONTACT_NOT_FOUND',
                'message' => 'LinkedIn contact not found',
                'details' => "No contact found with name: {$contactFullname}"
            ],
            'data' => null
        ];
    }
    
    // Step 3: Get contact profile information by contact ID
    // Assumes LinkedIn connector supports 'get_profile' action with 'contact_id' parameter
    $profileResult = $integration->executeSync($linkedinIntegrationId, [
        'action' => 'get_profile',
        'contact_id' => $contactId
    ]);
    
    if (!$profileResult['success']) {
        $errorMessage = $profileResult['error'] ?? 'Unknown error occurred while fetching profile';
        return [
            'success' => false,
            'error' => [
                'code' => 'LINKEDIN_PROFILE_FETCH_FAILED',
                'message' => 'Failed to fetch LinkedIn profile',
                'details' => $errorMessage
            ],
            'data' => null
        ];
    }
    
    // Extract profile information
    // Adjust based on actual LinkedIn connector response structure
    $profileInfo = $profileResult['result'] ?? $profileResult['data'] ?? [];
    $contactName = $profileInfo['full_name'] ?? $profileInfo['name'] ?? $contactFullname;
    
    // Step 4: Prepare LLM prompt with contact profile information
    $profileSummary = json_encode($profileInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    $llmPrompt = <<<PROMPT
{$systemPrompt}

Contact Information:
Name: {$contactName}
Profile Data:
{$profileSummary}

Generate a personalized LinkedIn message for this contact. Return your response in JSON format with two fields:
- "title": A short message title/subject (max 50 characters)
- "body": The message body (2-3 sentences, professional and personalized)

JSON Response:
PROMPT;
    
    // Step 5: Call LLM to generate personalized message
    $llmResult = $llm->callLLM($llmPrompt, [
        'max_tokens' => 500,
        'temperature' => 0.7
    ]);
    
    if (!$llmResult['success']) {
        $errorMessage = $llmResult['error'] ?? 'Unknown error occurred while generating message';
        return [
            'success' => false,
            'error' => [
                'code' => 'LLM_MESSAGE_GENERATION_FAILED',
                'message' => 'Failed to generate message using LLM',
                'details' => $errorMessage
            ],
            'data' => null
        ];
    }
    
    // Extract LLM response
    $llmResponse = $llmResult['response']['response'] ?? '';
    
    // Parse JSON response from LLM
    $messageData = json_decode($llmResponse, true);
    
    // If JSON parsing fails, try to extract title and body from text response
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($messageData)) {
        // Fallback: Try to extract from markdown or plain text
        if (preg_match('/title["\']?\s*:\s*["\']?([^"\']+)["\']?/i', $llmResponse, $titleMatch)) {
            $messageTitle = trim($titleMatch[1]);
        } else {
            $messageTitle = "Message for {$contactName}";
        }
        
        if (preg_match('/body["\']?\s*:\s*["\']?([^"\']+)["\']?/i', $llmResponse, $bodyMatch)) {
            $messageBody = trim($bodyMatch[1]);
        } else {
            // Use the entire response as body if no structured format found
            $messageBody = trim($llmResponse);
        }
    } else {
        $messageTitle = $messageData['title'] ?? "Message for {$contactName}";
        $messageBody = $messageData['body'] ?? $llmResponse;
    }
    
    // Clean up message title and body
    $messageTitle = trim($messageTitle);
    $messageBody = trim($messageBody);
    
    // Ensure title doesn't exceed 50 characters
    if (strlen($messageTitle) > 50) {
        $messageTitle = substr($messageTitle, 0, 47) . '...';
    }
    
    // Return success response
    return [
        'success' => true,
        'data' => [
            'contact_id' => $contactId,
            'contact_name' => $contactName,
            'profile_info' => $profileInfo,
            'message_title' => $messageTitle,
            'message_body' => $messageBody
        ],
        'message' => 'LinkedIn message generated successfully'
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
