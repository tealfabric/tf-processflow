# SMS/Push Notifications

This guide covers sending notifications within ProcessFlow code snippets using the notification service.

## Overview

The `$notification` service provides in-app and system notification capabilities. For SMS and external push notifications, use integration connectors.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│  $notification   │────▶│  Users          │
│  (Notify)       │     │  Service         │     │  (In-App)       │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$notification` | NotificationService | In-app notifications |
| `$email` | EmailService | Email notifications |
| `$integration` | IntegrationHelper | SMS/Push via connectors |

## NotificationService Methods

| Method | Description |
|--------|-------------|
| `createNotification($data, $context)` | Send to specific user |
| `createSystemNotification($title, $msg, $tenantId)` | System-wide notification |
| `createAdminNotification($title, $msg, $tenantId)` | All tenant admins |

---

## In-App Notifications

### Example 1: Send User Notification

Notify a specific user:

```php
<?php
$userId = $process_input['result']['user_id'] ?? '';
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$type = $process_input['result']['type'] ?? 'info'; // info, success, warning, error
$actionUrl = $process_input['result']['action_url'] ?? null;

if (empty($userId) || empty($title) || empty($message)) {
    return ['success' => false, 'error' => 'user_id, title, and message required'];
}

try {
    $notificationData = [
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'action_url' => $actionUrl,
        'metadata' => [
            'source' => 'processflow',
            'created_at' => date('c')
        ]
    ];
    
    $userContext = [
        'tenant_id' => $tenant_id,
        'user_id' => $userId
    ];
    
    $result = $notification->createNotification($notificationData, $userContext);
    
    if ($result['success']) {
        $log_message("[INFO] Notification sent to user: $userId");
        
        return [
            'success' => true,
            'data' => [
                'notification_id' => $result['notification']['notification_id'] ?? null,
                'user_id' => $userId,
                'title' => $title
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Failed to send notification'
        ];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Notification error: ' . $e->getMessage()
    ];
}
```

---

### Example 2: Admin Notification

Notify all tenant administrators:

```php
<?php
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$priority = $process_input['result']['priority'] ?? 'normal'; // low, normal, high, urgent

if (empty($title) || empty($message)) {
    return ['success' => false, 'error' => 'Title and message required'];
}

try {
    $options = [
        'priority' => $priority,
        'type' => 'admin_alert',
        'metadata' => [
            'source' => 'processflow',
            'timestamp' => date('c')
        ]
    ];
    
    $result = $notification->createAdminNotification(
        $title,
        $message,
        $tenant_id,
        $options
    );
    
    $log_message("[INFO] Admin notification sent: $title");
    
    return [
        'success' => $result['success'],
        'data' => [
            'title' => $title,
            'recipients_count' => $result['count'] ?? 0,
            'priority' => $priority
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Admin notification error: ' . $e->getMessage()
    ];
}
```

---

### Example 3: System Notification

Send system-wide notification:

```php
<?php
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$category = $process_input['result']['category'] ?? 'system';

if (empty($title) || empty($message)) {
    return ['success' => false, 'error' => 'Title and message required'];
}

try {
    $options = [
        'category' => $category,
        'expires_at' => date('c', strtotime('+7 days')),
        'dismissible' => true
    ];
    
    $result = $notification->createSystemNotification(
        $title,
        $message,
        $tenant_id,
        $options
    );
    
    return [
        'success' => $result['success'],
        'data' => [
            'title' => $title,
            'category' => $category,
            'notification_id' => $result['notification']['notification_id'] ?? null
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'System notification error: ' . $e->getMessage()
    ];
}
```

---

## SMS Notifications (via Integration)

### Example 4: Send SMS via Twilio Integration

```php
<?php
/**
 * Requires Twilio integration configured
 */

$twilioIntegrationId = $process_input['result']['twilio_integration_id'] ?? '';
$phoneNumber = $process_input['result']['phone_number'] ?? '';
$message = $process_input['result']['message'] ?? '';

if (empty($twilioIntegrationId) || empty($phoneNumber) || empty($message)) {
    return ['success' => false, 'error' => 'Integration ID, phone number, and message required'];
}

// Validate phone format
$cleanPhone = preg_replace('/[^0-9+]/', '', $phoneNumber);
if (strlen($cleanPhone) < 10) {
    return ['success' => false, 'error' => 'Invalid phone number'];
}

try {
    $result = $integration->executeSync($twilioIntegrationId, [
        'action' => 'send_sms',
        'to' => $cleanPhone,
        'body' => $message
    ]);
    
    if ($result['success']) {
        $log_message("[INFO] SMS sent to: $cleanPhone");
        
        return [
            'success' => true,
            'data' => [
                'phone_number' => $cleanPhone,
                'message_length' => strlen($message),
                'message_sid' => $result['result']['sid'] ?? null
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'SMS sending failed'
        ];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'SMS error: ' . $e->getMessage()
    ];
}
```

---

### Example 5: Bulk SMS

Send SMS to multiple recipients:

```php
<?php
$integrationId = $process_input['result']['integration_id'] ?? '';
$recipients = $process_input['result']['recipients'] ?? [];
$messageTemplate = $process_input['result']['message_template'] ?? '';

if (empty($integrationId) || empty($recipients) || empty($messageTemplate)) {
    return ['success' => false, 'error' => 'Integration ID, recipients, and message template required'];
}

$results = [
    'sent' => [],
    'failed' => [],
    'invalid' => []
];

foreach ($recipients as $recipient) {
    $phone = is_array($recipient) ? ($recipient['phone'] ?? '') : $recipient;
    $personalData = is_array($recipient) ? $recipient : ['phone' => $phone];
    
    // Validate phone
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    if (strlen($cleanPhone) < 10) {
        $results['invalid'][] = ['phone' => $phone, 'error' => 'Invalid format'];
        continue;
    }
    
    // Personalize message
    $message = $messageTemplate;
    foreach ($personalData as $key => $value) {
        if ($key !== 'phone') {
            $message = str_replace('{{' . $key . '}}', $value, $message);
        }
    }
    
    try {
        $result = $integration->executeSync($integrationId, [
            'action' => 'send_sms',
            'to' => $cleanPhone,
            'body' => $message
        ]);
        
        if ($result['success']) {
            $results['sent'][] = $cleanPhone;
        } else {
            $results['failed'][] = [
                'phone' => $cleanPhone,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }
    } catch (\Exception $e) {
        $results['failed'][] = [
            'phone' => $cleanPhone,
            'error' => $e->getMessage()
        ];
    }
}

$log_message(sprintf(
    "[INFO] Bulk SMS: %d sent, %d failed, %d invalid",
    count($results['sent']),
    count($results['failed']),
    count($results['invalid'])
));

return [
    'success' => count($results['failed']) === 0 && count($results['invalid']) === 0,
    'data' => [
        'total_recipients' => count($recipients),
        'sent_count' => count($results['sent']),
        'failed_count' => count($results['failed']),
        'invalid_count' => count($results['invalid']),
        'results' => $results
    ]
];
```

---

## Push Notifications (via Integration)

### Example 6: Firebase Cloud Messaging

```php
<?php
/**
 * Requires FCM integration configured
 */

$fcmIntegrationId = $process_input['result']['fcm_integration_id'] ?? '';
$deviceToken = $process_input['result']['device_token'] ?? '';
$title = $process_input['result']['title'] ?? '';
$body = $process_input['result']['body'] ?? '';
$data = $process_input['result']['data'] ?? [];

if (empty($fcmIntegrationId) || empty($deviceToken) || empty($title)) {
    return ['success' => false, 'error' => 'Integration ID, device token, and title required'];
}

try {
    $payload = [
        'action' => 'send_push',
        'token' => $deviceToken,
        'notification' => [
            'title' => $title,
            'body' => $body
        ],
        'data' => array_merge($data, [
            'timestamp' => date('c'),
            'tenant_id' => $tenant_id
        ])
    ];
    
    $result = $integration->executeSync($fcmIntegrationId, $payload);
    
    if ($result['success']) {
        $log_message("[INFO] Push notification sent to device");
        
        return [
            'success' => true,
            'data' => [
                'title' => $title,
                'message_id' => $result['result']['message_id'] ?? null
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Push notification failed'
        ];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Push notification error: ' . $e->getMessage()
    ];
}
```

---

### Example 7: Topic-Based Push

Send to FCM topic subscribers:

```php
<?php
$fcmIntegrationId = $process_input['result']['fcm_integration_id'] ?? '';
$topic = $process_input['result']['topic'] ?? '';
$title = $process_input['result']['title'] ?? '';
$body = $process_input['result']['body'] ?? '';

if (empty($fcmIntegrationId) || empty($topic) || empty($title)) {
    return ['success' => false, 'error' => 'Integration ID, topic, and title required'];
}

try {
    $result = $integration->executeSync($fcmIntegrationId, [
        'action' => 'send_topic',
        'topic' => $topic,
        'notification' => [
            'title' => $title,
            'body' => $body
        ],
        'data' => [
            'topic' => $topic,
            'timestamp' => date('c')
        ]
    ]);
    
    return [
        'success' => $result['success'],
        'data' => [
            'topic' => $topic,
            'title' => $title,
            'result' => $result['result'] ?? null
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Topic push error: ' . $e->getMessage()
    ];
}
```

---

## Multi-Channel Notifications

### Example 8: Send via Multiple Channels

```php
<?php
$channels = $process_input['result']['channels'] ?? ['in_app']; // in_app, email, sms, push
$recipient = $process_input['result']['recipient'] ?? [];
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$integrations = $process_input['result']['integrations'] ?? [];

if (empty($channels) || empty($title) || empty($message)) {
    return ['success' => false, 'error' => 'Channels, title, and message required'];
}

$results = [];

foreach ($channels as $channel) {
    try {
        switch ($channel) {
            case 'in_app':
                if (!empty($recipient['user_id'])) {
                    $result = $notification->createNotification(
                        ['title' => $title, 'message' => $message, 'type' => 'info'],
                        ['tenant_id' => $tenant_id, 'user_id' => $recipient['user_id']]
                    );
                    $results['in_app'] = ['success' => $result['success']];
                }
                break;
                
            case 'email':
                if (!empty($recipient['email'])) {
                    $htmlBody = "<h2>$title</h2><p>" . nl2br(htmlspecialchars($message)) . "</p>";
                    $result = $email->sendEmail($recipient['email'], $title, $htmlBody);
                    $results['email'] = ['success' => (bool)$result];
                }
                break;
                
            case 'sms':
                if (!empty($recipient['phone']) && !empty($integrations['sms'])) {
                    $result = $integration->executeSync($integrations['sms'], [
                        'action' => 'send_sms',
                        'to' => $recipient['phone'],
                        'body' => "$title\n\n$message"
                    ]);
                    $results['sms'] = ['success' => $result['success']];
                }
                break;
                
            case 'push':
                if (!empty($recipient['device_token']) && !empty($integrations['push'])) {
                    $result = $integration->executeSync($integrations['push'], [
                        'action' => 'send_push',
                        'token' => $recipient['device_token'],
                        'notification' => ['title' => $title, 'body' => $message]
                    ]);
                    $results['push'] = ['success' => $result['success']];
                }
                break;
        }
    } catch (\Exception $e) {
        $results[$channel] = ['success' => false, 'error' => $e->getMessage()];
    }
}

$successCount = count(array_filter($results, fn($r) => $r['success'] ?? false));

return [
    'success' => $successCount > 0,
    'data' => [
        'channels_attempted' => count($channels),
        'channels_succeeded' => $successCount,
        'results' => $results
    ]
];
```

---

## Best Practices

### 1. Validate Contact Information
```php
// Email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { ... }

// Phone
if (strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) { ... }
```

### 2. Use Appropriate Channels
- **Urgent**: SMS + Push
- **Important**: Email + In-App
- **Informational**: In-App only

### 3. Respect User Preferences
```php
// Check notification preferences before sending
// (implement preference checking in your process)
```

### 4. Log All Notifications
```php
$log_message("[INFO] Notification sent via $channel to $recipient");
```

---

## See Also

- [Email Sending](Email_Sending.md)
- [Integration Connectors](Integration_Connectors.md)
- [Process-to-Process Communication](Process_Communication.md)
