<?php
/**
 * WebApp API Router Process Template
 * 
 * This process handles API request routing for webapps with security guardrails:
 * - Validates JWT token type (document_review vs access)
 * - Checks allowed endpoints against tenant-specific configuration
 * - Proxies requests only if validation passes
 * 
 * Process Configuration (stored in Processes.configuration JSON):
 * {
 *   "allowed_endpoints": [
 *     "/api/v1/document-reviews/*",
 *     "/api/v1/documents?action=view"
 *   ],
 *   "token_types": ["document_review"],
 *   "rate_limit": {
 *     "requests_per_minute": 60
 *   }
 * }
 */

// Extract request data
$requestUri = $process_input['request_uri'] ?? '';
$method = $process_input['request_method'] ?? 'GET';
$headers = $process_input['request_headers'] ?? [];
$body = $process_input['request_body'] ?? '';
$token = $process_input['token'] ?? '';

// Load process configuration
// Configuration is stored in Processes.configuration JSON field
// It should be passed via process_input['process_config'] or loaded from database
$processConfig = $process_input['process_config'] ?? [];
$allowedEndpoints = $processConfig['allowed_endpoints'] ?? [];
$allowedTokenTypes = $processConfig['token_types'] ?? ['document_review'];

// Validate token presence
if (empty($token)) {
    return [
        'success' => false,
        'http_code' => 401,
        'body' => json_encode(['success' => false, 'error' => 'Missing authentication token']),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// Validate token using JWTManager (injected via execution context as $jwt)
if (!isset($jwt)) {
    return [
        'success' => false,
        'http_code' => 500,
        'body' => json_encode(['success' => false, 'error' => 'JWT validation service not available']),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

$payload = $jwt->validateAccessToken($token);
if (!$payload) {
    return [
        'success' => false,
        'http_code' => 401,
        'body' => json_encode(['success' => false, 'error' => 'Invalid or expired token']),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// Validate token type
$tokenType = $payload['type'] ?? null;
if (!in_array($tokenType, $allowedTokenTypes)) {
    return [
        'success' => false,
        'http_code' => 403,
        'body' => json_encode([
            'success' => false, 
            'error' => 'Token type not allowed for this endpoint',
            'token_type' => $tokenType,
            'allowed_types' => $allowedTokenTypes
        ]),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// Validate endpoint against allowed patterns
$isAllowed = false;
foreach ($allowedEndpoints as $pattern) {
    // Use fnmatch for pattern matching (supports * wildcards)
    if (fnmatch($pattern, $requestUri)) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    return [
        'success' => false,
        'http_code' => 403,
        'body' => json_encode([
            'success' => false, 
            'error' => 'Endpoint not allowed',
            'requested_endpoint' => $requestUri,
            'allowed_patterns' => $allowedEndpoints
        ]),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// Proxy request to main API using TenantAPIService (injected as $api)
if (!isset($api)) {
    return [
        'success' => false,
        'http_code' => 500,
        'body' => json_encode(['success' => false, 'error' => 'API service not available']),
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// Build API URL
$appUrl = $app_url ?? 'https://dev.tealfabric.io';
$apiUrl = rtrim($appUrl, '/') . $requestUri;
if (!empty($process_input['query_string'])) {
    $apiUrl .= '?' . $process_input['query_string'];
}

// Prepare headers for API request
$apiHeaders = [];
foreach ($headers as $name => $value) {
    // Forward relevant headers (preserve Authorization, Content-Type, Accept)
    $lowerName = strtolower($name);
    if (in_array($lowerName, ['content-type', 'authorization', 'accept', 'x-requested-with'])) {
        $apiHeaders[$name] = $value;
    }
}

// Add tenant and webapp context headers
$apiHeaders['X-Tenant-ID'] = $tenant_id;
if (isset($process_input['webapp_id'])) {
    $apiHeaders['X-WebApp-ID'] = $process_input['webapp_id'];
}

// Make API request using TenantAPIService
$apiResponse = $api->makeRequest($apiUrl, [
    'method' => $method,
    'headers' => $apiHeaders,
    'body' => $body
]);

// Extract response details
$httpCode = $apiResponse['status_code'] ?? 200;
$responseBody = $apiResponse['body'] ?? ($apiResponse['raw_response'] ?? '');
$responseHeaders = $apiResponse['headers'] ?? [];

// Normalize response headers to array format
if (is_string($responseHeaders)) {
    // Parse header string if needed
    $responseHeaders = ['Content-Type' => 'application/json'];
} elseif (!is_array($responseHeaders)) {
    $responseHeaders = ['Content-Type' => 'application/json'];
}

// Ensure Content-Type is set
if (!isset($responseHeaders['Content-Type']) && !isset($responseHeaders['content-type'])) {
    $responseHeaders['Content-Type'] = 'application/json';
}

// Return response for router.php to handle
return [
    'success' => true,
    'http_code' => $httpCode,
    'headers' => $responseHeaders,
    'body' => $responseBody
];
