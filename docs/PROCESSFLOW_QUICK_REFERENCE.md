# ProcessFlow Code Snippets Quick Reference

**Authority:** This quick reference is a condensed view. For the full contract and variable list, use [PROCESSFLOW_CODE_SNIPPETS_GUIDE.md](PROCESSFLOW_CODE_SNIPPETS_GUIDE.md) and [docs/interface/v1/](interface/v1/). Prefer `$process_input` (not `$input`) and `$integration` (not deprecated `$connectors`).

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

### Database Insert (use $tenantDb in tenant steps)
```php
<?php
try {
    $id = $tenantDb->insert('table', [
        'col1' => $process_input['val1'],
        'col2' => $process_input['val2']
    ]);
    
    if (!$id) {
        return [
            'success' => false,
            'error' => ['code' => 'DATABASE_ERROR', 'message' => 'Insert failed'],
            'data' => null
        ];
    }
    
    return [
        'success' => true,
        'data' => ['id' => $id],
        'message' => 'Record created'
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => ['code' => 'DATABASE_ERROR', 'message' => $e->getMessage()],
        'data' => null
    ];
}
```

### Database Select (use $tenantDb in tenant steps)
```php
<?php
try {
    $results = $tenantDb->query(
        "SELECT * FROM table WHERE status = ?",
        [$process_input['status']]
    );
    
    return [
        'success' => true,
        'data' => ['records' => $results, 'count' => count($results)],
        'message' => 'Records retrieved'
    ];
} catch (Exception $e) {
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
$output['full_name'] = $process_input['first_name'] . ' ' . $process_input['last_name'];
$output['email_domain'] = substr(strrchr($process_input['email'], "@"), 1);
$output['is_premium'] = $process_input['amount'] > 1000;
$output['processed_at'] = date('Y-m-d H:i:s');

return ['success' => true, 'data' => $output];
```

### Decision Logic
```php
<?php
if ($process_input['amount'] > 5000) {
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
    $result = $integration->executeSync('service-name', $process_input);
    
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

if ($process_input['amount'] < 0) {
    $errors[] = 'Amount cannot be negative';
}

if ($process_input['amount'] > 100000) {
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

return ['success' => true, 'data' => $process_input];
```

### Batch Processing
```php
<?php
$results = [];
$errors = [];

foreach ($process_input['items'] as $item) {
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
| `$process_input` | Array | Data from previous step |
| `$tenant_id` | String | Current tenant ID |
| `$user_id` | String | Current user ID |
| `$tenantDb` | Object | Tenant-scoped database (prefer over `$db`) |
| `$integration` | Object | Integration execution service |
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
