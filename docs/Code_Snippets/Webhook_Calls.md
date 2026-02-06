# Webhook Calls

This guide covers making outbound webhook calls from ProcessFlow code snippets.

## Overview

Webhooks enable real-time communication with external systems. The `$api` service provides HTTP methods for calling external endpoints.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│    $api          │────▶│  External       │
│  (Webhook)      │     │    Service       │     │  Endpoint       │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$api` | TenantAPIService | HTTP client with tenant context |
| `$integration` | IntegrationHelper | Pre-configured integrations |

---

## Example 1: Simple POST Webhook

Send data to an external endpoint:

```php
<?php
$webhookUrl = $process_input['result']['webhook_url'] ?? '';
$payload = $process_input['result']['payload'] ?? [];
$headers = $process_input['result']['headers'] ?? [];

if (empty($webhookUrl)) {
    return ['success' => false, 'error' => 'Webhook URL required'];
}

// Validate URL format
if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    return ['success' => false, 'error' => 'Invalid webhook URL'];
}

try {
    $log_message("[INFO] Calling webhook: $webhookUrl");
    
    // Add timestamp and source to payload
    $payload['timestamp'] = date('c');
    $payload['source'] = 'processflow';
    $payload['tenant_id'] = $tenant_id;
    
    $response = $api->post($webhookUrl, $payload, $headers);
    
    $log_message("[INFO] Webhook response: " . $response['status_code']);
    
    return [
        'success' => $response['success'],
        'data' => [
            'status_code' => $response['status_code'],
            'response' => $response['data'],
            'webhook_url' => $webhookUrl
        ]
    ];
} catch (\Exception $e) {
    $log_message("[ERROR] Webhook failed: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => 'Webhook call failed: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Webhook with Authentication

Include authentication headers:

```php
<?php
$webhookUrl = $process_input['result']['webhook_url'] ?? '';
$payload = $process_input['result']['payload'] ?? [];
$authType = $process_input['result']['auth_type'] ?? 'bearer'; // bearer, basic, api_key
$authValue = $process_input['result']['auth_value'] ?? '';
$apiKeyHeader = $process_input['result']['api_key_header'] ?? 'X-API-Key';

if (empty($webhookUrl) || empty($authValue)) {
    return ['success' => false, 'error' => 'Webhook URL and auth value required'];
}

$headers = [];

switch ($authType) {
    case 'bearer':
        $headers['Authorization'] = 'Bearer ' . $authValue;
        break;
    case 'basic':
        $headers['Authorization'] = 'Basic ' . base64_encode($authValue);
        break;
    case 'api_key':
        $headers[$apiKeyHeader] = $authValue;
        break;
}

$headers['Content-Type'] = 'application/json';

try {
    $response = $api->post($webhookUrl, $payload, $headers);
    
    return [
        'success' => $response['success'],
        'data' => [
            'status_code' => $response['status_code'],
            'response' => $response['data']
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Webhook error: ' . $e->getMessage()
    ];
}
```

---

## Example 3: Webhook with Signature

Sign webhook payload for verification:

```php
<?php
$webhookUrl = $process_input['result']['webhook_url'] ?? '';
$payload = $process_input['result']['payload'] ?? [];
$secretKey = $process_input['result']['secret_key'] ?? '';
$signatureHeader = $process_input['result']['signature_header'] ?? 'X-Webhook-Signature';

if (empty($webhookUrl) || empty($secretKey)) {
    return ['success' => false, 'error' => 'Webhook URL and secret key required'];
}

// Add metadata to payload
$payload['timestamp'] = time();
$payload['nonce'] = bin2hex(random_bytes(16));

// Create payload string for signing
$payloadString = json_encode($payload, JSON_UNESCAPED_SLASHES);

// Generate HMAC signature
$signature = hash_hmac('sha256', $payloadString, $secretKey);

$headers = [
    'Content-Type' => 'application/json',
    $signatureHeader => 'sha256=' . $signature,
    'X-Webhook-Timestamp' => (string)$payload['timestamp']
];

try {
    $response = $api->post($webhookUrl, $payload, $headers);
    
    return [
        'success' => $response['success'],
        'data' => [
            'status_code' => $response['status_code'],
            'response' => $response['data'],
            'signature' => $signature
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Signed webhook failed: ' . $e->getMessage()
    ];
}
```

---

## Example 4: Webhook with Retry

Retry failed webhook calls:

```php
<?php
$webhookUrl = $process_input['result']['webhook_url'] ?? '';
$payload = $process_input['result']['payload'] ?? [];
$maxRetries = (int)($process_input['result']['max_retries'] ?? 3);
$retryDelayMs = (int)($process_input['result']['retry_delay_ms'] ?? 1000);

if (empty($webhookUrl)) {
    return ['success' => false, 'error' => 'Webhook URL required'];
}

$attempt = 0;
$lastError = null;
$attempts = [];

// Retryable status codes
$retryableCodes = [408, 429, 500, 502, 503, 504];

while ($attempt < $maxRetries) {
    $attempt++;
    
    try {
        $response = $api->post($webhookUrl, $payload);
        
        $statusCode = $response['status_code'] ?? 0;
        
        $attempts[] = [
            'attempt' => $attempt,
            'status_code' => $statusCode,
            'success' => $response['success']
        ];
        
        // Success
        if ($response['success'] && $statusCode < 400) {
            return [
                'success' => true,
                'data' => [
                    'status_code' => $statusCode,
                    'response' => $response['data'],
                    'attempts' => $attempts,
                    'total_attempts' => $attempt
                ]
            ];
        }
        
        // Non-retryable error
        if (!in_array($statusCode, $retryableCodes)) {
            return [
                'success' => false,
                'error' => 'Non-retryable error',
                'data' => [
                    'status_code' => $statusCode,
                    'response' => $response['data'],
                    'attempts' => $attempts
                ]
            ];
        }
        
        $lastError = "HTTP $statusCode";
        
    } catch (\Exception $e) {
        $lastError = $e->getMessage();
        $attempts[] = [
            'attempt' => $attempt,
            'error' => $lastError
        ];
    }
    
    // Wait before retry (exponential backoff)
    if ($attempt < $maxRetries) {
        $delay = $retryDelayMs * pow(2, $attempt - 1);
        usleep($delay * 1000);
    }
}

return [
    'success' => false,
    'error' => 'Max retries exceeded: ' . $lastError,
    'data' => [
        'attempts' => $attempts,
        'total_attempts' => $attempt
    ]
];
```

---

## Example 5: Event Webhook

Send event notification:

```php
<?php
$webhookUrl = $process_input['result']['webhook_url'] ?? '';
$eventType = $process_input['result']['event_type'] ?? '';
$eventData = $process_input['result']['event_data'] ?? [];
$eventId = $process_input['result']['event_id'] ?? uniqid('evt_');

if (empty($webhookUrl) || empty($eventType)) {
    return ['success' => false, 'error' => 'Webhook URL and event type required'];
}

// Build standard event payload
$payload = [
    'id' => $eventId,
    'type' => $eventType,
    'created' => time(),
    'data' => $eventData,
    'api_version' => '2024-01-01',
    'livemode' => true,
    'object' => 'event'
];

try {
    $response = $api->post($webhookUrl, $payload, [
        'Content-Type' => 'application/json',
        'X-Event-Type' => $eventType,
        'X-Event-ID' => $eventId
    ]);
    
    $log_message("[INFO] Event webhook sent: $eventType ($eventId)");
    
    return [
        'success' => $response['success'],
        'data' => [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status_code' => $response['status_code'],
            'delivered' => $response['success']
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Event webhook failed: ' . $e->getMessage(),
        'data' => ['event_id' => $eventId]
    ];
}
```

---

## Example 6: Slack Webhook

Send Slack notification:

```php
<?php
$slackWebhookUrl = $process_input['result']['slack_webhook_url'] ?? '';
$message = $process_input['result']['message'] ?? '';
$channel = $process_input['result']['channel'] ?? null;
$username = $process_input['result']['username'] ?? 'ProcessFlow Bot';
$iconEmoji = $process_input['result']['icon_emoji'] ?? ':robot_face:';
$attachments = $process_input['result']['attachments'] ?? [];

if (empty($slackWebhookUrl) || empty($message)) {
    return ['success' => false, 'error' => 'Slack webhook URL and message required'];
}

$payload = [
    'text' => $message,
    'username' => $username,
    'icon_emoji' => $iconEmoji
];

if ($channel) {
    $payload['channel'] = $channel;
}

// Build attachments if provided
if (!empty($attachments)) {
    $payload['attachments'] = array_map(function($att) {
        return [
            'color' => $att['color'] ?? '#36a64f',
            'title' => $att['title'] ?? '',
            'text' => $att['text'] ?? '',
            'fields' => array_map(function($f) {
                return [
                    'title' => $f['title'] ?? '',
                    'value' => $f['value'] ?? '',
                    'short' => $f['short'] ?? false
                ];
            }, $att['fields'] ?? []),
            'footer' => $att['footer'] ?? 'ProcessFlow',
            'ts' => time()
        ];
    }, $attachments);
}

try {
    $response = $api->post($slackWebhookUrl, $payload);
    
    return [
        'success' => $response['status_code'] === 200,
        'data' => [
            'delivered' => $response['status_code'] === 200,
            'channel' => $channel
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Slack webhook failed: ' . $e->getMessage()
    ];
}
```

---

## Example 7: Microsoft Teams Webhook

Send Teams notification:

```php
<?php
$teamsWebhookUrl = $process_input['result']['teams_webhook_url'] ?? '';
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$themeColor = $process_input['result']['theme_color'] ?? '0076D7';
$facts = $process_input['result']['facts'] ?? [];

if (empty($teamsWebhookUrl) || empty($message)) {
    return ['success' => false, 'error' => 'Teams webhook URL and message required'];
}

// Build Teams message card
$payload = [
    '@type' => 'MessageCard',
    '@context' => 'http://schema.org/extensions',
    'themeColor' => $themeColor,
    'summary' => $title ?: substr($message, 0, 50),
    'sections' => [
        [
            'activityTitle' => $title,
            'text' => $message,
            'facts' => array_map(function($key, $value) {
                return ['name' => $key, 'value' => $value];
            }, array_keys($facts), array_values($facts)),
            'markdown' => true
        ]
    ]
];

try {
    $response = $api->post($teamsWebhookUrl, $payload);
    
    return [
        'success' => $response['status_code'] === 200,
        'data' => [
            'delivered' => $response['status_code'] === 200,
            'title' => $title
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Teams webhook failed: ' . $e->getMessage()
    ];
}
```

---

## Example 8: Batch Webhooks

Send to multiple endpoints:

```php
<?php
$webhooks = $process_input['result']['webhooks'] ?? [];
$payload = $process_input['result']['payload'] ?? [];
$continueOnError = $process_input['result']['continue_on_error'] ?? true;

if (empty($webhooks)) {
    return ['success' => false, 'error' => 'Webhooks list required'];
}

$results = [
    'success' => [],
    'failed' => []
];

foreach ($webhooks as $index => $webhook) {
    $url = is_array($webhook) ? ($webhook['url'] ?? '') : $webhook;
    $headers = is_array($webhook) ? ($webhook['headers'] ?? []) : [];
    $name = is_array($webhook) ? ($webhook['name'] ?? "Webhook $index") : "Webhook $index";
    
    if (empty($url)) {
        $results['failed'][] = [
            'name' => $name,
            'error' => 'Missing URL'
        ];
        continue;
    }
    
    try {
        $response = $api->post($url, $payload, $headers);
        
        if ($response['success'] && $response['status_code'] < 400) {
            $results['success'][] = [
                'name' => $name,
                'url' => $url,
                'status_code' => $response['status_code']
            ];
        } else {
            $results['failed'][] = [
                'name' => $name,
                'url' => $url,
                'status_code' => $response['status_code'],
                'error' => $response['data']['error'] ?? 'Request failed'
            ];
            
            if (!$continueOnError) {
                break;
            }
        }
    } catch (\Exception $e) {
        $results['failed'][] = [
            'name' => $name,
            'url' => $url,
            'error' => $e->getMessage()
        ];
        
        if (!$continueOnError) {
            break;
        }
    }
}

$allSucceeded = count($results['failed']) === 0;

$log_message(sprintf(
    "[INFO] Batch webhooks: %d succeeded, %d failed",
    count($results['success']),
    count($results['failed'])
));

return [
    'success' => $allSucceeded,
    'data' => [
        'total' => count($webhooks),
        'succeeded' => count($results['success']),
        'failed' => count($results['failed']),
        'results' => $results
    ]
];
```

---

## Best Practices

### 1. Validate URLs
```php
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return ['success' => false, 'error' => 'Invalid URL'];
}
```

### 2. Use Timeouts
```php
// Default timeout is 30 seconds
// For faster failures, consider using async
```

### 3. Log All Webhook Calls
```php
$log_message("[INFO] Webhook to: $url - Status: " . $response['status_code']);
```

### 4. Handle Failures Gracefully
```php
// Queue for retry if webhook fails
// Or notify admin of delivery failure
```

---

## Security Considerations

| ⚠️ Warning | Solution |
|------------|----------|
| Don't log secrets | Mask authentication values in logs |
| Validate SSL | Use HTTPS endpoints |
| Sign payloads | Include HMAC signatures for verification |
| Rate limiting | Implement backoff for rate-limited endpoints |

---

## See Also

- [Process-to-Process Communication](Process_Communication.md)
- [Integration Connectors](Integration_Connectors.md)
- [Event Publishing](Event_Publishing.md)
