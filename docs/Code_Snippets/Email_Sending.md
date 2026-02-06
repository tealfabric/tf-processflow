# Email Sending

This guide covers sending emails within ProcessFlow code snippets using the email service.

## Overview

The `$email` service provides email sending capabilities with template support, priority queuing, and delivery tracking.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│    $email        │────▶│  Email Queue    │
│  (Send)         │     │    Service       │     │  → Delivery     │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$email` | EmailService | Email sending service |
| `$notification` | NotificationService | In-app notifications |

## Email Service Methods

The email service primarily works through the email queue for reliable delivery.

---

## Example 1: Send Simple Email

Send a basic email:

```php
<?php
$to = $process_input['result']['to'] ?? '';
$subject = $process_input['result']['subject'] ?? '';
$body = $process_input['result']['body'] ?? '';
$isHtml = $process_input['result']['is_html'] ?? true;

// Validate required fields
if (empty($to) || empty($subject) || empty($body)) {
    return [
        'success' => false,
        'error' => 'Missing required fields: to, subject, and body are required'
    ];
}

// Validate email format
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return [
        'success' => false,
        'error' => 'Invalid email address'
    ];
}

try {
    // Build HTML email if needed
    $htmlBody = $isHtml ? $body : nl2br(htmlspecialchars($body));
    
    // The email service uses queue for reliable delivery
    $result = $email->sendEmail($to, $subject, $htmlBody);
    
    if ($result) {
        $log_message("[INFO] Email queued successfully to: $to");
        
        return [
            'success' => true,
            'data' => [
                'recipient' => $to,
                'subject' => $subject,
                'queued' => true
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to queue email'
        ];
    }
} catch (\Exception $e) {
    $log_message("[ERROR] Email failed: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => 'Email error: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Email with Template

Send email using HTML template:

```php
<?php
$to = $process_input['result']['to'] ?? '';
$subject = $process_input['result']['subject'] ?? '';
$templateData = $process_input['result']['template_data'] ?? [];
$templateName = $process_input['result']['template'] ?? 'default';

if (empty($to) || empty($subject)) {
    return ['success' => false, 'error' => 'Recipient and subject required'];
}

// Define email templates
$templates = [
    'welcome' => '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h1 style="color: #333;">Welcome, {{name}}!</h1>
            <p>Thank you for joining us. We\'re excited to have you on board.</p>
            <p>Your account has been created successfully.</p>
            <div style="margin: 20px 0;">
                <a href="{{login_url}}" style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
                    Get Started
                </a>
            </div>
            <p style="color: #666; font-size: 12px;">
                If you didn\'t create this account, please contact support.
            </p>
        </div>
    ',
    'notification' => '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;">{{title}}</h2>
            <p>{{message}}</p>
            {{#if action_url}}
            <div style="margin: 20px 0;">
                <a href="{{action_url}}" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
                    {{action_text}}
                </a>
            </div>
            {{/if}}
        </div>
    ',
    'alert' => '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border-left: 4px solid #dc3545; padding-left: 15px;">
            <h2 style="color: #dc3545;">⚠️ {{title}}</h2>
            <p>{{message}}</p>
            <p style="color: #666; font-size: 12px;">
                Time: {{timestamp}}
            </p>
        </div>
    ',
    'default' => '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            {{content}}
        </div>
    '
];

// Get template
$template = $templates[$templateName] ?? $templates['default'];

// Replace placeholders
$htmlBody = $template;
foreach ($templateData as $key => $value) {
    $htmlBody = str_replace('{{' . $key . '}}', htmlspecialchars($value), $htmlBody);
}

// Handle conditional sections (simplified)
$htmlBody = preg_replace('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function($matches) use ($templateData) {
    $condition = $matches[1];
    $content = $matches[2];
    return !empty($templateData[$condition]) ? $content : '';
}, $htmlBody);

// Remove any remaining unresolved placeholders
$htmlBody = preg_replace('/\{\{[^}]+\}\}/', '', $htmlBody);

try {
    $result = $email->sendEmail($to, $subject, $htmlBody);
    
    return [
        'success' => (bool)$result,
        'data' => [
            'recipient' => $to,
            'subject' => $subject,
            'template' => $templateName,
            'queued' => (bool)$result
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Email error: ' . $e->getMessage()
    ];
}
```

---

## Example 3: Batch Email Sending

Send emails to multiple recipients:

```php
<?php
$recipients = $process_input['result']['recipients'] ?? [];
$subject = $process_input['result']['subject'] ?? '';
$bodyTemplate = $process_input['result']['body_template'] ?? '';

if (empty($recipients) || empty($subject) || empty($bodyTemplate)) {
    return ['success' => false, 'error' => 'Recipients, subject, and body_template required'];
}

$results = [
    'sent' => [],
    'failed' => [],
    'invalid' => []
];

foreach ($recipients as $recipient) {
    // Extract email and personalization data
    $toEmail = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
    $personalData = is_array($recipient) ? $recipient : ['email' => $toEmail];
    
    // Validate email
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $results['invalid'][] = [
            'email' => $toEmail,
            'error' => 'Invalid email format'
        ];
        continue;
    }
    
    // Personalize body
    $body = $bodyTemplate;
    foreach ($personalData as $key => $value) {
        $body = str_replace('{{' . $key . '}}', htmlspecialchars($value), $body);
    }
    
    // Personalize subject
    $personalizedSubject = $subject;
    foreach ($personalData as $key => $value) {
        $personalizedSubject = str_replace('{{' . $key . '}}', $value, $personalizedSubject);
    }
    
    try {
        $sent = $email->sendEmail($toEmail, $personalizedSubject, $body);
        
        if ($sent) {
            $results['sent'][] = $toEmail;
        } else {
            $results['failed'][] = [
                'email' => $toEmail,
                'error' => 'Failed to queue'
            ];
        }
    } catch (\Exception $e) {
        $results['failed'][] = [
            'email' => $toEmail,
            'error' => $e->getMessage()
        ];
    }
}

$log_message(sprintf(
    "[INFO] Batch email: %d sent, %d failed, %d invalid",
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

## Example 4: Order Confirmation Email

Send formatted order confirmation:

```php
<?php
$order = $process_input['result']['order'] ?? [];
$customerEmail = $order['customer_email'] ?? '';

if (empty($customerEmail) || empty($order)) {
    return ['success' => false, 'error' => 'Order data and customer email required'];
}

// Build order items HTML
$itemsHtml = '';
$subtotal = 0;

foreach ($order['items'] ?? [] as $item) {
    $itemTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
    $subtotal += $itemTotal;
    
    $itemsHtml .= sprintf('
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #eee;">%s</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: center;">%d</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">€%.2f</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">€%.2f</td>
        </tr>
    ',
        htmlspecialchars($item['name'] ?? 'Product'),
        $item['quantity'] ?? 1,
        $item['price'] ?? 0,
        $itemTotal
    );
}

$tax = $subtotal * 0.24; // 24% VAT
$total = $subtotal + $tax + ($order['shipping'] ?? 0);

$subject = 'Order Confirmation #' . ($order['order_number'] ?? 'N/A');

$body = sprintf('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <h1 style="color: #333;">Thank you for your order!</h1>
        <p>Hi %s,</p>
        <p>We\'ve received your order and are processing it now.</p>
        
        <div style="background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <strong>Order Number:</strong> %s<br>
            <strong>Order Date:</strong> %s
        </div>
        
        <h2 style="color: #333;">Order Details</h2>
        <table style="width: 100%%; border-collapse: collapse;">
            <thead>
                <tr style="background: #333; color: white;">
                    <th style="padding: 10px; text-align: left;">Product</th>
                    <th style="padding: 10px; text-align: center;">Qty</th>
                    <th style="padding: 10px; text-align: right;">Price</th>
                    <th style="padding: 10px; text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                %s
            </tbody>
        </table>
        
        <div style="margin-top: 20px; text-align: right;">
            <p>Subtotal: €%.2f</p>
            <p>VAT (24%%): €%.2f</p>
            <p>Shipping: €%.2f</p>
            <p style="font-size: 18px; font-weight: bold;">Total: €%.2f</p>
        </div>
        
        <h2 style="color: #333;">Shipping Address</h2>
        <p>
            %s<br>
            %s<br>
            %s %s<br>
            %s
        </p>
        
        <p style="margin-top: 30px;">
            If you have any questions, reply to this email or contact our support.
        </p>
    </div>
',
    htmlspecialchars($order['customer_name'] ?? 'Customer'),
    htmlspecialchars($order['order_number'] ?? 'N/A'),
    date('F j, Y', strtotime($order['created_at'] ?? 'now')),
    $itemsHtml,
    $subtotal,
    $tax,
    $order['shipping'] ?? 0,
    $total,
    htmlspecialchars($order['shipping_address']['name'] ?? ''),
    htmlspecialchars($order['shipping_address']['street'] ?? ''),
    htmlspecialchars($order['shipping_address']['postal_code'] ?? ''),
    htmlspecialchars($order['shipping_address']['city'] ?? ''),
    htmlspecialchars($order['shipping_address']['country'] ?? '')
);

try {
    $result = $email->sendEmail($customerEmail, $subject, $body);
    
    return [
        'success' => (bool)$result,
        'data' => [
            'recipient' => $customerEmail,
            'order_number' => $order['order_number'] ?? 'N/A',
            'queued' => (bool)$result
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Failed to send order confirmation: ' . $e->getMessage()
    ];
}
```

---

## Example 5: Alert/Notification Email

Send system alerts and notifications:

```php
<?php
$alertType = $process_input['result']['type'] ?? 'info'; // info, warning, error, critical
$title = $process_input['result']['title'] ?? '';
$message = $process_input['result']['message'] ?? '';
$recipients = $process_input['result']['recipients'] ?? [];
$details = $process_input['result']['details'] ?? [];

if (empty($title) || empty($message) || empty($recipients)) {
    return ['success' => false, 'error' => 'Title, message, and recipients required'];
}

// Alert styling based on type
$styles = [
    'info' => ['color' => '#17a2b8', 'icon' => 'ℹ️', 'label' => 'Information'],
    'warning' => ['color' => '#ffc107', 'icon' => '⚠️', 'label' => 'Warning'],
    'error' => ['color' => '#dc3545', 'icon' => '❌', 'label' => 'Error'],
    'critical' => ['color' => '#721c24', 'icon' => '🚨', 'label' => 'Critical']
];

$style = $styles[$alertType] ?? $styles['info'];

// Build details section
$detailsHtml = '';
if (!empty($details)) {
    $detailsHtml = '<div style="background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 5px; font-family: monospace; font-size: 12px;">';
    foreach ($details as $key => $value) {
        $displayValue = is_array($value) ? json_encode($value) : $value;
        $detailsHtml .= sprintf('<strong>%s:</strong> %s<br>', 
            htmlspecialchars($key), 
            htmlspecialchars($displayValue)
        );
    }
    $detailsHtml .= '</div>';
}

$subject = sprintf('[%s] %s', $style['label'], $title);

$body = sprintf('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <div style="border-left: 4px solid %s; padding-left: 15px;">
            <h1 style="color: %s;">%s %s</h1>
            <p style="font-size: 16px;">%s</p>
        </div>
        
        %s
        
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;">
            <p>Timestamp: %s</p>
            <p>Alert Type: %s</p>
            <p>Tenant: %s</p>
        </div>
    </div>
',
    $style['color'],
    $style['color'],
    $style['icon'],
    htmlspecialchars($title),
    nl2br(htmlspecialchars($message)),
    $detailsHtml,
    date('Y-m-d H:i:s T'),
    $style['label'],
    htmlspecialchars($tenant_id ?? 'N/A')
);

$results = ['sent' => [], 'failed' => []];

foreach ((array)$recipients as $recipient) {
    try {
        $sent = $email->sendEmail($recipient, $subject, $body);
        if ($sent) {
            $results['sent'][] = $recipient;
        } else {
            $results['failed'][] = $recipient;
        }
    } catch (\Exception $e) {
        $results['failed'][] = $recipient;
    }
}

$log_message(sprintf("[%s] Alert sent: %s - %d recipients", 
    strtoupper($alertType), $title, count($results['sent'])));

return [
    'success' => count($results['failed']) === 0,
    'data' => [
        'alert_type' => $alertType,
        'title' => $title,
        'sent_count' => count($results['sent']),
        'failed_count' => count($results['failed']),
        'results' => $results
    ]
];
```

---

## Example 6: Email with Attachments Reference

Reference files for email (actual attachment handling via integration):

```php
<?php
/**
 * Note: Direct file attachments require integration setup.
 * This example shows how to reference files in email body.
 */

$to = $process_input['result']['to'] ?? '';
$subject = $process_input['result']['subject'] ?? '';
$message = $process_input['result']['message'] ?? '';
$attachmentUrls = $process_input['result']['attachment_urls'] ?? [];

if (empty($to) || empty($subject)) {
    return ['success' => false, 'error' => 'Recipient and subject required'];
}

// Build attachment links section
$attachmentsHtml = '';
if (!empty($attachmentUrls)) {
    $attachmentsHtml = '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px;">';
    $attachmentsHtml .= '<h3 style="margin-top: 0;">📎 Attachments</h3>';
    $attachmentsHtml .= '<ul style="list-style: none; padding: 0;">';
    
    foreach ($attachmentUrls as $attachment) {
        $name = is_array($attachment) ? ($attachment['name'] ?? 'File') : basename($attachment);
        $url = is_array($attachment) ? ($attachment['url'] ?? $attachment) : $attachment;
        
        $attachmentsHtml .= sprintf(
            '<li style="margin: 5px 0;"><a href="%s" style="color: #007bff;">%s</a></li>',
            htmlspecialchars($url),
            htmlspecialchars($name)
        );
    }
    
    $attachmentsHtml .= '</ul></div>';
}

$body = sprintf('
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        %s
        %s
    </div>
',
    nl2br(htmlspecialchars($message)),
    $attachmentsHtml
);

try {
    $result = $email->sendEmail($to, $subject, $body);
    
    return [
        'success' => (bool)$result,
        'data' => [
            'recipient' => $to,
            'subject' => $subject,
            'attachment_count' => count($attachmentUrls),
            'queued' => (bool)$result
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Email error: ' . $e->getMessage()
    ];
}
```

---

## Best Practices

### 1. Validate Email Addresses
```php
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return ['success' => false, 'error' => 'Invalid email'];
}
```

### 2. Use HTML Email Templates
```php
// Keep templates consistent and branded
$body = $template;
foreach ($data as $key => $value) {
    $body = str_replace('{{' . $key . '}}', htmlspecialchars($value), $body);
}
```

### 3. Log Email Operations
```php
$log_message("[INFO] Email sent to: $recipient - Subject: $subject");
```

### 4. Handle Failures Gracefully
```php
if (!$result) {
    // Queue for retry or notify admin
    $notification->createSystemNotification(
        'Email Delivery Failed',
        "Failed to send email to $recipient",
        $tenant_id
    );
}
```

---

## Security Considerations

| ⚠️ Warning | Solution |
|------------|----------|
| Don't expose internal data | Sanitize all dynamic content |
| Validate recipient addresses | Use `filter_var()` |
| Don't include sensitive data in URLs | Use secure tokens |
| Rate limit bulk sending | Process in batches |

---

## See Also

- [SMS/Push Notifications](Notifications.md)
- [Template Rendering](Template_Rendering.md)
- [Batch Data Import/Export](Batch_Import_Export.md)
