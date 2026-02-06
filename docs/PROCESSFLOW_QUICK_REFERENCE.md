# ProcessFlow Code Snippets Quick Reference

## Basic Template

```php
<?php
try {
    // Your processing logic here
    
    return [
        'success' => true,
        'data' => $processed_data,
        'message' => 'Operation completed successfully'
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'ERROR_CODE',
            'message' => $e->getMessage(),
            'details' => $e->getTraceAsString()
        ],
        'data' => null
    ];
}
```

## Common Patterns

### Data Validation
```php
<?php
// Validate required fields
if (empty($process_input['email'])) {
    return [
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Email required'],
        'data' => null
    ];
}

// Validate format
if (!filter_var($process_input['email'], FILTER_VALIDATE_EMAIL)) {
    return [
        'success' => false,
        'error' => ['code' => 'INVALID_EMAIL', 'message' => 'Invalid email format'],
        'data' => null
    ];
}

return ['success' => true, 'data' => $process_input];
```

### Database Insert
```php
<?php
try {
    $stmt = $db->prepare("INSERT INTO table (col1, col2, tenant_id) VALUES (?, ?, ?)");
    $result = $stmt->execute([$process_input['val1'], $process_input['val2'], $tenant_id]);
    
    if (!$result) {
        return [
            'success' => false,
            'error' => ['code' => 'DATABASE_ERROR', 'message' => 'Insert failed'],
            'data' => null
        ];
    }
    
    $id = $db->lastInsertId();
    return [
        'success' => true,
        'data' => ['id' => $id],
        'message' => 'Record created'
    ];
} catch (PDOException $e) {
    return [
        'success' => false,
        'error' => ['code' => 'DATABASE_ERROR', 'message' => $e->getMessage()],
        'data' => null
    ];
}
```

### Database Select
```php
<?php
try {
    $stmt = $db->prepare("SELECT * FROM table WHERE tenant_id = ? AND status = ?");
    $stmt->execute([$tenant_id, $input['status']]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'data' => ['records' => $results, 'count' => count($results)],
        'message' => 'Records retrieved'
    ];
} catch (PDOException $e) {
    return [
        'success' => false,
        'error' => ['code' => 'DATABASE_ERROR', 'message' => $e->getMessage()],
        'data' => null
    ];
}
```

### Data Transformation
```php
<?php
$output = [];
$output['full_name'] = $input['first_name'] . ' ' . $input['last_name'];
$output['email_domain'] = substr(strrchr($input['email'], "@"), 1);
$output['is_premium'] = $input['amount'] > 1000;
$output['processed_at'] = date('Y-m-d H:i:s');

return ['success' => true, 'data' => $output];
```

### Decision Logic
```php
<?php
if ($input['amount'] > 5000) {
    return [
        'success' => true,
        'data' => [
            'approval_required' => true,
            'next_step' => 'manager_approval'
        ]
    ];
} else {
    return [
        'success' => true,
        'data' => [
            'approval_required' => false,
            'next_step' => 'process_payment'
        ]
    ];
}
```

### External API Call
```php
<?php
try {
    $result = $connectors->execute('service-name', 'method', $input);
    
    if (!$result['success']) {
        return [
            'success' => false,
            'error' => ['code' => 'API_ERROR', 'message' => $result['error']],
            'data' => null
        ];
    }
    
    return [
        'success' => true,
        'data' => $result['data'],
        'message' => 'API call successful'
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => ['code' => 'CONNECTOR_ERROR', 'message' => $e->getMessage()],
        'data' => null
    ];
}
```

### Business Rule Validation
```php
<?php
$errors = [];

if ($input['amount'] < 0) {
    $errors[] = 'Amount cannot be negative';
}

if ($input['amount'] > 100000) {
    $errors[] = 'Amount exceeds limit';
}

if (!empty($errors)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'BUSINESS_RULE_ERROR',
            'message' => 'Validation failed',
            'details' => implode(', ', $errors)
        ],
        'data' => null
    ];
}

return ['success' => true, 'data' => $input];
```

### Batch Processing
```php
<?php
$results = [];
$errors = [];

foreach ($input['items'] as $item) {
    try {
        $processed = processItem($item);
        $results[] = $processed;
    } catch (Exception $e) {
        $errors[] = ['item' => $item, 'error' => $e->getMessage()];
    }
}

if (!empty($errors)) {
    return [
        'success' => false,
        'error' => ['code' => 'BATCH_ERROR', 'message' => 'Some items failed'],
        'data' => ['processed' => $results, 'errors' => $errors]
    ];
}

return ['success' => true, 'data' => ['processed_items' => $results]];
```

## Available Variables

| Variable | Type | Description |
|----------|------|-------------|
| `$input` | Array | Data from previous step |
| `$tenant_id` | String | Current tenant ID |
| `$user_id` | String | Current user ID |
| `$db` | PDO | Database connection |
| `$connectors` | Object | Connector service |
| `$llm` | Object | LLM service |

## Error Codes

| Code | Description |
|------|-------------|
| `VALIDATION_ERROR` | Input validation failed |
| `DATABASE_ERROR` | Database operation failed |
| `CONNECTOR_ERROR` | External service error |
| `BUSINESS_RULE_ERROR` | Business logic violation |
| `PERMISSION_ERROR` | Access denied |
| `TIMEOUT_ERROR` | Operation timed out |
| `RESOURCE_ERROR` | Resource not available |
| `UNKNOWN_ERROR` | Unexpected error |

## Response Formats

### Success Response
```php
return [
    'success' => true,
    'data' => $your_data,
    'message' => 'Optional success message'
];
```

### Error Response
```php
return [
    'success' => false,
    'error' => [
        'code' => 'ERROR_CODE',
        'message' => 'Human readable message',
        'details' => 'Optional additional details'
    ],
    'data' => null
];
```

## Security Checklist

- [ ] Validate all input data
- [ ] Use prepared statements for database queries
- [ ] Escape output data to prevent XSS
- [ ] Always include `$tenant_id` in database queries
- [ ] Check user permissions before operations
- [ ] Limit resource usage (memory, time)
- [ ] Log security-relevant events

## Performance Tips

- Keep steps lightweight
- Use database indexes
- Batch operations when possible
- Cache frequently accessed data
- Monitor resource usage
- Avoid nested loops
- Use efficient data structures
