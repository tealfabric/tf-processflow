# ProcessFlow Event Handling Guide

This guide explains how to implement event-driven process orchestration in Tealfabric using the Event Broker pattern. The Event Broker allows processes to communicate asynchronously by emitting and listening for events.

## Overview

The Event Broker is a ProcessFlow code snippet that acts as a central hub for routing events to their designated listener processes. When a process emits an event, the Event Broker:

1. Receives the event with its type and payload
2. Looks up registered listeners for that event type
3. Triggers each listener process asynchronously
4. Notifies tenant administrators of the event processing
5. Returns a summary of signaled processes

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Source Process │────▶│  Event Broker   │────▶│ Listener Process│
│  (Emits Event)  │     │  (Routes Event) │     │ (Handles Event) │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                               ▼
                        ┌─────────────────┐
                        │ Listener Process│
                        │   (Another)     │
                        └─────────────────┘
```

## Setting Up the Event Broker

### Step 1: Create the Event Broker Process

1. Create a new ProcessFlow process in your tenant
2. Add a single "Code Snippet" step
3. Copy the Event Broker code snippet below into the step
4. Configure the `$event_listener_table` with your event types and listener process IDs
5. Save and activate the process

### Event Broker Code Snippet

```php
<?php

// Configure this table to map the event type to the target process ID.
$event_listener_table = [
    'example_event_type' => ['x', 'y'], // Insert Process ID here of the target process (i.e., event listener process)
    'another_example_event_type' => ['z'] // Insert Process ID here of the target process (i.e., event listener process)
];
// Everything after this point should remain static


/**
 * This Event broker is used to trigger processes based on events.
 * It is used to trigger processes based on events.
 * It takes calling processe's id, calling object, event, and input data 
 * Based on the switch case, it will trigger the processes based on the event.
 */

 /** 
  * Example event data:
  *  $event_id = "evt_1234567890abcdef";
  *  $event_type = "user_created";
  *  $tenant_id = "tenant_123";
  *  $user_id = "user_456";
  *  $timestamp = "2025-01-09T10:30:00Z";
  *  $source = "UserService";
  *  "data": {
  *    "user_id": "user_456",
  *    "email": "user@example.com",
  *    "user_type": "premium",
  *    "registration_source": "website"
  *  },
  *  $metadata = {
  *    "ip_address": "192.168.1.100",
  *    "user_agent": "Mozilla/5.0...",
  *    "session_id": "sess_789"
  *  };
  *  $correlation_id = "corr_abc123";
  *  $priority = "normal";
  */


    $i = $process_input['result'];

    // Create an event ID for this call
    if (!isset($i['event_id'])) {
        $eventID = "evt_".uniqid();
    } else {
        $eventID = $i['event_id'];
    }

    $targets = $event_listener_table[$i['event_type']] ?? [];
    if (empty($targets)) {
        $notification->createAdminNotification([
            'title' => 'Event received: '.$i['event_type'],
            'message' => 'No event listener found for event type: '.$i['event_type'].'. Event data: '.json_encode($i),
            'tenant_id' => $i['tenant_id'],
            'user_id' => $i['user_id']
        ]);

        // If no event type is found, no processes will be triggered.
        return [
            'success' => true,
            'event_id' => $eventID,
            'signaled' => 0,
            'succeeded' => 0,
            'dlq' => [],
            'message' => ['No event listener found for event type: '.$i['event_type']]
        ];
    }
    $dlq = [];
    foreach($targets as $target) {
        // Call an async process
        $result = $api->post('/api/v1/processflow?action=execute-process', [
                'process_id' => $target,
                'input_data' => $i,
                'async' => true,
                'options' => [
                    'priority' => $i['priority'] ?? 'low'
                ]
            ]);
        if (!$result['success']) {
            $dlq[] = [
                'process'=>$target,
                'job_id'=>$result['queue_id'],
                'error'=>$result['error'],
                'message' => ['Process execution failed for process: '.$target]
            ];
        }
    }
    $notification->createAdminNotification([
        'title' => 'Event received: '.$i['event_type'],
        'message' => count($targets).' processes signalled. Event data: '.json_encode($i),
        'tenant_id' => $i['tenant_id'],
        'user_id' => $i['user_id']
    ]);
    return [
        'success' => true,
        'event_id' => $eventID,
        'signaled' => count($targets),
        'succeeded' => count($targets) - count($dlq),
        'dlq' => $dlq,
        'message' => ['Processes triggered for event type: '.$i['event_type']]
    ];
```

### Step 2: Configure Event Listeners

Edit the `$event_listener_table` at the top of the code snippet to map your event types to listener process IDs:

```php
$event_listener_table = [
    'user_created' => ['proc-welcome-email', 'proc-setup-defaults'],
    'order_completed' => ['proc-send-confirmation', 'proc-update-inventory', 'proc-notify-warehouse'],
    'payment_failed' => ['proc-retry-payment', 'proc-notify-customer'],
    'document_uploaded' => ['proc-process-document']
];
```

Each event type can have multiple listener processes that will all be triggered when that event is emitted.

## Emitting Events

### From Another ProcessFlow Code Snippet

To emit an event from another process, call the Event Broker process via the `$api` service. **Note:** Both `action=execute-process` and `action=execute-process-advanced` are valid; this guide uses `execute-process`. Use `execute-process-advanced` if you need options such as `priority` or `execution_strategy`.

```php
<?php
// Emit a user_created event
$result = $api->post('/api/v1/processflow?action=execute-process', [
    'process_id' => 'YOUR_EVENT_BROKER_PROCESS_ID',
    'input_data' => [
        'result' => [
            'event_type' => 'user_created',
            'tenant_id' => $tenant_id,
            'user_id' => $user_id,
            'timestamp' => date('c'),
            'source' => 'UserRegistrationProcess',
            'data' => [
                'user_id' => 'user_456',
                'email' => 'newuser@example.com',
                'user_type' => 'premium',
                'registration_source' => 'website'
            ],
            'metadata' => [
                'ip_address' => $process_input['ip_address'] ?? null,
                'user_agent' => $process_input['user_agent'] ?? null
            ],
            'correlation_id' => $process_input['correlation_id'] ?? uniqid('corr_'),
            'priority' => 'normal'
        ]
    ],
    'async' => true  // Fire and forget
]);

return [
    'success' => true,
    'data' => ['event_emitted' => $result['success']]
];
```

### Event Payload Structure

When emitting an event, use this standard structure:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `event_type` | string | Yes | The type of event (e.g., `user_created`, `order_completed`) |
| `tenant_id` | string | Yes | The tenant ID for multi-tenant routing |
| `user_id` | string | No | The user who triggered the event (if applicable) |
| `event_id` | string | No | Unique event ID (auto-generated if not provided) |
| `timestamp` | string | No | ISO 8601 timestamp (auto-generated if not provided) |
| `source` | string | No | The source process or service that emitted the event |
| `data` | object | Yes | The event payload with relevant data |
| `metadata` | object | No | Additional context (IP, user agent, session, etc.) |
| `correlation_id` | string | No | ID for tracing related events |
| `priority` | string | No | Event priority: `low`, `normal`, `high` (default: `low`) |

### Example: Emitting Different Event Types

#### Order Completed Event

```php
$api->post('/api/v1/processflow?action=execute-process', [
    'process_id' => 'event-broker-process-id',
    'input_data' => [
        'result' => [
            'event_type' => 'order_completed',
            'tenant_id' => $tenant_id,
            'timestamp' => date('c'),
            'source' => 'CheckoutProcess',
            'data' => [
                'order_id' => 'ord_12345',
                'customer_id' => 'cust_789',
                'total_amount' => 149.99,
                'currency' => 'EUR',
                'items' => [
                    ['sku' => 'PROD-001', 'quantity' => 2],
                    ['sku' => 'PROD-002', 'quantity' => 1]
                ]
            ],
            'priority' => 'high'
        ]
    ],
    'async' => true
]);
```

#### Document Uploaded Event

```php
$api->post('/api/v1/processflow?action=execute-process', [
    'process_id' => 'event-broker-process-id',
    'input_data' => [
        'result' => [
            'event_type' => 'document_uploaded',
            'tenant_id' => $tenant_id,
            'user_id' => $user_id,
            'timestamp' => date('c'),
            'source' => 'FileUploadProcess',
            'data' => [
                'document_id' => 'doc_abc123',
                'filename' => 'invoice_2025.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 245678,
                'storage_path' => 'documents/invoices/invoice_2025.pdf'
            ],
            'priority' => 'normal'
        ]
    ],
    'async' => true
]);
```

## Creating Event Listener Processes

Event listener processes receive the full event payload as `$process_input['result']`. Here's how to create a listener:

### Example: Welcome Email Listener

```php
<?php
// This process listens for 'user_created' events
$event = $process_input['result'];

// Validate this is the expected event type
if ($event['event_type'] !== 'user_created') {
    return [
        'success' => false,
        'error' => [
            'code' => 'UNEXPECTED_EVENT_TYPE',
            'message' => 'Unexpected event type: ' . $event['event_type']
        ],
        'data' => null
    ];
}

// Extract user data from the event
$userData = $event['data'];
$userEmail = $userData['email'];
$userName = $userData['full_name'] ?? 'Valued Customer';

// Send welcome email using the email service
$emailResult = $email->send([
    'to' => $userEmail,
    'subject' => 'Welcome to Our Platform!',
    'template' => 'welcome_email',
    'data' => [
        'name' => $userName,
        'user_type' => $userData['user_type']
    ]
]);

return [
    'success' => $emailResult['success'],
    'data' => [
        'event_id' => $event['event_id'] ?? 'unknown',
        'email_sent' => $emailResult['success'],
        'recipient' => $userEmail
    ]
];
```

### Example: Inventory Update Listener

```php
<?php
// This process listens for 'order_completed' events
$event = $process_input['result'];
$orderData = $event['data'];

$updatedItems = [];
foreach ($orderData['items'] as $item) {
    // Update inventory via integration
    $result = $integration->executeSync('inventory-service-integration', [
        'operation' => 'decrease_stock',
        'sku' => $item['sku'],
        'quantity' => $item['quantity']
    ]);
    
    $updatedItems[] = [
        'sku' => $item['sku'],
        'updated' => $result['success']
    ];
}

return [
    'success' => true,
    'data' => [
        'event_id' => $event['event_id'] ?? 'unknown',
        'order_id' => $orderData['order_id'],
        'items_updated' => $updatedItems
    ]
];
```

## Event Broker Response

The Event Broker returns a response with the following structure:

```json
{
    "success": true,
    "event_id": "evt_6789abcdef12345",
    "signaled": 3,
    "succeeded": 3,
    "dlq": [],
    "message": ["Processes triggered for event type: order_completed"]
}
```

| Field | Description |
|-------|-------------|
| `success` | Whether the event was processed successfully |
| `event_id` | The unique ID for this event |
| `signaled` | Number of listener processes that were triggered |
| `succeeded` | Number of processes successfully queued |
| `dlq` | Dead Letter Queue - array of failed process triggers |
| `message` | Human-readable status message |

### Dead Letter Queue (DLQ)

If a listener process fails to be triggered, it's added to the DLQ:

```json
{
    "dlq": [
        {
            "process": "proc-failed-listener",
            "job_id": "job_123",
            "error": "Process not found",
            "message": ["Process execution failed for process: proc-failed-listener"]
        }
    ]
}
```

## Notifications

The Event Broker automatically notifies all tenant administrators when:

1. **An event has no registered listeners** - Alerts admins that an event type is not being handled
2. **Events are successfully processed** - Confirms how many processes were signaled

Notifications appear in the Tealfabric notification center (bell icon) for all users with the `tenant_admin` role.

## Best Practices

### 1. Use Descriptive Event Types

Use clear, action-based event type names:
- ✅ `user_created`, `order_completed`, `payment_failed`
- ❌ `event1`, `data`, `process`

### 2. Keep Event Payloads Focused

Include only the data that listeners need. Use IDs to reference related entities rather than embedding large objects.

### 3. Set Appropriate Priorities

- `high` - Time-sensitive events (payment processing, alerts)
- `normal` - Standard business events (order updates, notifications)
- `low` - Background tasks (analytics, cleanup)

### 4. Use Correlation IDs

Include a `correlation_id` to trace related events across your system:

```php
$correlationId = $process_input['correlation_id'] ?? uniqid('corr_');

// Pass to emitted events
'correlation_id' => $correlationId
```

### 5. Handle Missing Event Types Gracefully

The Event Broker notifies admins when an event type has no listeners. Review these notifications regularly to ensure all event types are properly configured.

### 6. Design Idempotent Listeners

Listeners may occasionally receive duplicate events. Design your listeners to handle the same event multiple times safely. Use `$tenantDb` (tenant-scoped) in listener steps:

```php
// Check if already processed
$existing = $tenantDb->getOne("SELECT * FROM processed_events WHERE event_id = ?", [$event['event_id']]);
if ($existing) {
    return ['success' => true, 'data' => ['already_processed' => true]];
}

// Process and mark as handled
// ... processing logic ...
$tenantDb->insert('processed_events', [
    'event_id' => $event['event_id'],
    'processed_at' => date('Y-m-d H:i:s')
]);
```

## Troubleshooting

### Events Not Being Processed

1. Verify the Event Broker process is active
2. Check that the `event_type` matches exactly (case-sensitive)
3. Confirm listener process IDs are correct in `$event_listener_table`
4. Check the DLQ in the response for failed triggers

### Listeners Not Receiving Data

1. Ensure you're accessing `$process_input['result']` (not `$process_input` directly)
2. Verify the event payload structure matches what the listener expects

### Missing Notifications

1. Confirm your user has the `tenant_admin` role
2. Check notification preferences in your user settings
3. Verify the `tenant_id` in the event matches your tenant

## Summary

The Event Broker pattern provides a flexible, scalable way to orchestrate processes through events:

1. **Deploy** the Event Broker process in your tenant
2. **Configure** the event listener table with your event types and process IDs
3. **Emit** events from your processes using `$api->post()`
4. **Create** listener processes that handle specific event types
5. **Monitor** notifications for event processing status

This decoupled architecture allows you to add, modify, or remove event listeners without changing the processes that emit events.
