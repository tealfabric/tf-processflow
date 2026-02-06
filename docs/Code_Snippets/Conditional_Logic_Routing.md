# Conditional Logic & Routing

This guide covers decision-making and workflow routing within ProcessFlow code snippets.

## Overview

Conditional logic controls process flow based on data conditions. These operations evaluate input and determine the next step, enabling branching workflows.

```
                                    ┌─────────────┐
                              ┌────▶│  Route A    │
┌─────────────────┐     ┌─────┴─────┐     └─────────────┘
│   Input Data    │────▶│ Condition │
└─────────────────┘     │  Check    │     ┌─────────────┐
                        └─────┬─────┘────▶│  Route B    │
                              │           └─────────────┘
                              └──────────▶┌─────────────┐
                                          │  Route C    │
                                          └─────────────┘
```

## Routing Output

ProcessFlow uses the `route` field in output to control branching:

```php
return [
    'success' => true,
    'route' => 'approved',  // Determines next step
    'data' => $processedData
];
```

---

## Example 1: Simple Condition Check

Basic boolean condition evaluation:

```php
<?php
$data = $process_input['result'] ?? [];
$condition = $data['condition'] ?? [];

// Evaluate condition
$field = $condition['field'] ?? '';
$operator = $condition['operator'] ?? 'equals';
$value = $condition['value'] ?? null;
$targetValue = $data[$field] ?? null;

$result = false;

switch ($operator) {
    case 'equals':
    case '==':
        $result = $targetValue == $value;
        break;
    case 'not_equals':
    case '!=':
        $result = $targetValue != $value;
        break;
    case 'greater_than':
    case '>':
        $result = $targetValue > $value;
        break;
    case 'less_than':
    case '<':
        $result = $targetValue < $value;
        break;
    case 'greater_or_equal':
    case '>=':
        $result = $targetValue >= $value;
        break;
    case 'less_or_equal':
    case '<=':
        $result = $targetValue <= $value;
        break;
    case 'contains':
        $result = strpos($targetValue, $value) !== false;
        break;
    case 'not_contains':
        $result = strpos($targetValue, $value) === false;
        break;
    case 'starts_with':
        $result = strpos($targetValue, $value) === 0;
        break;
    case 'ends_with':
        $result = substr($targetValue, -strlen($value)) === $value;
        break;
    case 'is_empty':
        $result = empty($targetValue);
        break;
    case 'is_not_empty':
        $result = !empty($targetValue);
        break;
    case 'in':
        $result = in_array($targetValue, (array)$value);
        break;
    case 'not_in':
        $result = !in_array($targetValue, (array)$value);
        break;
    case 'regex':
        $result = preg_match($value, $targetValue) === 1;
        break;
}

return [
    'success' => true,
    'route' => $result ? 'true' : 'false',
    'data' => [
        'condition_met' => $result,
        'field' => $field,
        'operator' => $operator,
        'value' => $value,
        'actual_value' => $targetValue
    ]
];
```

---

## Example 2: Multi-Condition Evaluation (AND/OR)

Evaluate multiple conditions with logical operators:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$rules = $process_input['result']['rules'] ?? [];
$logic = strtoupper($process_input['result']['logic'] ?? 'AND');

if (empty($rules)) {
    return [
        'success' => true,
        'route' => 'no_rules',
        'data' => ['message' => 'No rules to evaluate']
    ];
}

// Evaluate single condition
$evaluateCondition = function($data, $rule) {
    $field = $rule['field'] ?? '';
    $operator = $rule['operator'] ?? 'equals';
    $value = $rule['value'] ?? null;
    
    // Get nested field value using dot notation
    $fieldValue = $data;
    foreach (explode('.', $field) as $key) {
        $fieldValue = $fieldValue[$key] ?? null;
    }
    
    switch ($operator) {
        case 'equals': return $fieldValue == $value;
        case 'not_equals': return $fieldValue != $value;
        case 'greater_than': return $fieldValue > $value;
        case 'less_than': return $fieldValue < $value;
        case 'greater_or_equal': return $fieldValue >= $value;
        case 'less_or_equal': return $fieldValue <= $value;
        case 'contains': return strpos((string)$fieldValue, (string)$value) !== false;
        case 'is_empty': return empty($fieldValue);
        case 'is_not_empty': return !empty($fieldValue);
        case 'in': return in_array($fieldValue, (array)$value);
        case 'between': return $fieldValue >= $value[0] && $fieldValue <= $value[1];
        default: return false;
    }
};

// Evaluate all rules
$results = [];
foreach ($rules as $index => $rule) {
    $passed = $evaluateCondition($data, $rule);
    $results[] = [
        'rule_index' => $index,
        'field' => $rule['field'],
        'passed' => $passed
    ];
}

// Apply logic
$passedCount = count(array_filter($results, fn($r) => $r['passed']));
$totalRules = count($rules);

$finalResult = match($logic) {
    'AND' => $passedCount === $totalRules,
    'OR' => $passedCount > 0,
    'NONE' => $passedCount === 0,
    'ALL' => $passedCount === $totalRules,
    'ANY' => $passedCount > 0,
    default => $passedCount === $totalRules
};

return [
    'success' => true,
    'route' => $finalResult ? 'pass' : 'fail',
    'data' => [
        'result' => $finalResult,
        'logic' => $logic,
        'rules_passed' => $passedCount,
        'rules_total' => $totalRules,
        'rule_results' => $results
    ]
];
```

---

## Example 3: Approval Workflow Routing

Route based on approval thresholds:

```php
<?php
$request = $process_input['result'] ?? [];

$amount = floatval($request['amount'] ?? 0);
$requestType = $request['type'] ?? 'standard';
$priority = $request['priority'] ?? 'normal';
$department = $request['department'] ?? '';
$requesterLevel = intval($request['requester_level'] ?? 1);

// Define approval rules
$rules = [
    'auto_approve' => [
        'max_amount' => 100,
        'types' => ['supplies', 'maintenance'],
        'min_requester_level' => 3
    ],
    'manager_approval' => [
        'max_amount' => 5000,
        'types' => ['standard', 'supplies', 'maintenance', 'equipment']
    ],
    'director_approval' => [
        'max_amount' => 25000,
        'types' => ['standard', 'equipment', 'services']
    ],
    'executive_approval' => [
        'max_amount' => 100000,
        'types' => ['standard', 'equipment', 'services', 'capital']
    ]
];

// Determine route
$route = 'executive_approval'; // Default to highest level
$approvalLevel = 4;
$reason = '';

// Check auto-approve first
if ($amount <= $rules['auto_approve']['max_amount'] &&
    in_array($requestType, $rules['auto_approve']['types']) &&
    $requesterLevel >= $rules['auto_approve']['min_requester_level']) {
    $route = 'auto_approve';
    $approvalLevel = 0;
    $reason = 'Within auto-approval limits';
}
// Manager approval
elseif ($amount <= $rules['manager_approval']['max_amount'] &&
        in_array($requestType, $rules['manager_approval']['types'])) {
    $route = 'manager_approval';
    $approvalLevel = 1;
    $reason = 'Requires manager approval';
}
// Director approval
elseif ($amount <= $rules['director_approval']['max_amount'] &&
        in_array($requestType, $rules['director_approval']['types'])) {
    $route = 'director_approval';
    $approvalLevel = 2;
    $reason = 'Requires director approval';
}
// Executive approval
elseif ($amount <= $rules['executive_approval']['max_amount']) {
    $route = 'executive_approval';
    $approvalLevel = 3;
    $reason = 'Requires executive approval';
}
// Board approval
else {
    $route = 'board_approval';
    $approvalLevel = 4;
    $reason = 'Requires board approval';
}

// Priority override
if ($priority === 'urgent' && $approvalLevel > 0) {
    $route = 'urgent_' . $route;
    $reason .= ' (Urgent priority)';
}

return [
    'success' => true,
    'route' => $route,
    'data' => [
        'approval_level' => $approvalLevel,
        'reason' => $reason,
        'request_details' => [
            'amount' => $amount,
            'type' => $requestType,
            'priority' => $priority,
            'department' => $department
        ]
    ]
];
```

---

## Example 4: Status-Based Routing

Route based on entity status:

```php
<?php
$entity = $process_input['result']['entity'] ?? [];
$action = $process_input['result']['action'] ?? '';

$currentStatus = $entity['status'] ?? 'unknown';
$entityType = $entity['type'] ?? 'generic';

// Define valid state transitions
$stateTransitions = [
    'order' => [
        'draft' => ['submit' => 'pending', 'delete' => 'deleted'],
        'pending' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
        'approved' => ['process' => 'processing', 'cancel' => 'cancelled'],
        'processing' => ['ship' => 'shipped', 'fail' => 'failed'],
        'shipped' => ['deliver' => 'delivered', 'return' => 'returned'],
        'delivered' => ['complete' => 'completed', 'return' => 'returned'],
        'rejected' => ['resubmit' => 'pending'],
        'cancelled' => ['reopen' => 'draft'],
        'failed' => ['retry' => 'processing', 'cancel' => 'cancelled']
    ],
    'ticket' => [
        'new' => ['assign' => 'assigned', 'close' => 'closed'],
        'assigned' => ['start' => 'in_progress', 'reassign' => 'assigned', 'close' => 'closed'],
        'in_progress' => ['resolve' => 'resolved', 'escalate' => 'escalated', 'block' => 'blocked'],
        'blocked' => ['unblock' => 'in_progress', 'escalate' => 'escalated'],
        'escalated' => ['resolve' => 'resolved', 'close' => 'closed'],
        'resolved' => ['reopen' => 'in_progress', 'close' => 'closed'],
        'closed' => ['reopen' => 'new']
    ]
];

$transitions = $stateTransitions[$entityType] ?? [];
$validActions = $transitions[$currentStatus] ?? [];

// Check if action is valid
if (!isset($validActions[$action])) {
    return [
        'success' => false,
        'route' => 'invalid_transition',
        'data' => [
            'error' => "Action '$action' not allowed for status '$currentStatus'",
            'current_status' => $currentStatus,
            'requested_action' => $action,
            'valid_actions' => array_keys($validActions)
        ]
    ];
}

$newStatus = $validActions[$action];

return [
    'success' => true,
    'route' => $action,
    'data' => [
        'entity_type' => $entityType,
        'previous_status' => $currentStatus,
        'action' => $action,
        'new_status' => $newStatus,
        'entity' => array_merge($entity, ['status' => $newStatus]),
        'valid_next_actions' => array_keys($stateTransitions[$entityType][$newStatus] ?? [])
    ]
];
```

---

## Example 5: Decision Tree Evaluation

Evaluate a decision tree structure:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$tree = $process_input['result']['decision_tree'] ?? [];

/**
 * Decision tree node format:
 * {
 *   "condition": { "field": "amount", "operator": ">", "value": 1000 },
 *   "true_branch": { ... next node or result },
 *   "false_branch": { ... next node or result },
 *   "result": "final_outcome"  // Only in leaf nodes
 * }
 */

$evaluateCondition = function($data, $condition) {
    $field = $condition['field'] ?? '';
    $operator = $condition['operator'] ?? '==';
    $value = $condition['value'] ?? null;
    
    $fieldValue = $data[$field] ?? null;
    
    return match($operator) {
        '==', 'equals' => $fieldValue == $value,
        '!=', 'not_equals' => $fieldValue != $value,
        '>' => $fieldValue > $value,
        '<' => $fieldValue < $value,
        '>=' => $fieldValue >= $value,
        '<=' => $fieldValue <= $value,
        'in' => in_array($fieldValue, (array)$value),
        'contains' => strpos((string)$fieldValue, (string)$value) !== false,
        default => false
    };
};

$evaluateNode = function($node, $data, $path = []) use (&$evaluateNode, $evaluateCondition) {
    // Leaf node - return result
    if (isset($node['result'])) {
        return [
            'result' => $node['result'],
            'path' => $path
        ];
    }
    
    // Decision node
    if (!isset($node['condition'])) {
        return ['result' => 'error', 'error' => 'Invalid node structure'];
    }
    
    $conditionMet = $evaluateCondition($data, $node['condition']);
    $path[] = [
        'condition' => $node['condition'],
        'result' => $conditionMet
    ];
    
    $nextNode = $conditionMet 
        ? ($node['true_branch'] ?? null) 
        : ($node['false_branch'] ?? null);
    
    if ($nextNode === null) {
        return ['result' => 'no_path', 'path' => $path];
    }
    
    return $evaluateNode($nextNode, $data, $path);
};

if (empty($tree)) {
    return [
        'success' => false,
        'route' => 'no_tree',
        'data' => ['error' => 'No decision tree provided']
    ];
}

$evaluation = $evaluateNode($tree, $data);

return [
    'success' => true,
    'route' => $evaluation['result'],
    'data' => [
        'decision' => $evaluation['result'],
        'evaluation_path' => $evaluation['path'],
        'input_data' => $data
    ]
];
```

---

## Example 6: Rule Engine

Configurable rule-based routing:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$rules = $process_input['result']['rules'] ?? [];
$defaultRoute = $process_input['result']['default_route'] ?? 'default';

/**
 * Rule format:
 * {
 *   "name": "High Value Customer",
 *   "priority": 1,
 *   "conditions": [
 *     { "field": "total_spend", "operator": ">", "value": 10000 },
 *     { "field": "membership", "operator": "in", "value": ["gold", "platinum"] }
 *   ],
 *   "logic": "AND",
 *   "route": "premium_support"
 * }
 */

$evaluateCondition = function($data, $cond) {
    $fieldPath = $cond['field'] ?? '';
    $operator = $cond['operator'] ?? '==';
    $value = $cond['value'] ?? null;
    
    // Get nested value
    $fieldValue = $data;
    foreach (explode('.', $fieldPath) as $key) {
        $fieldValue = $fieldValue[$key] ?? null;
    }
    
    return match($operator) {
        '==', 'equals' => $fieldValue == $value,
        '!=', 'not_equals' => $fieldValue != $value,
        '>' => is_numeric($fieldValue) && $fieldValue > $value,
        '<' => is_numeric($fieldValue) && $fieldValue < $value,
        '>=' => is_numeric($fieldValue) && $fieldValue >= $value,
        '<=' => is_numeric($fieldValue) && $fieldValue <= $value,
        'in' => in_array($fieldValue, (array)$value),
        'not_in' => !in_array($fieldValue, (array)$value),
        'contains' => is_string($fieldValue) && strpos($fieldValue, $value) !== false,
        'is_empty' => empty($fieldValue),
        'is_not_empty' => !empty($fieldValue),
        'regex' => is_string($fieldValue) && preg_match($value, $fieldValue),
        default => false
    };
};

// Sort rules by priority
usort($rules, fn($a, $b) => ($a['priority'] ?? 999) - ($b['priority'] ?? 999));

$matchedRule = null;
$evaluatedRules = [];

foreach ($rules as $rule) {
    $conditions = $rule['conditions'] ?? [];
    $logic = strtoupper($rule['logic'] ?? 'AND');
    
    $conditionResults = array_map(
        fn($c) => $evaluateCondition($data, $c),
        $conditions
    );
    
    $passes = match($logic) {
        'AND' => !in_array(false, $conditionResults, true),
        'OR' => in_array(true, $conditionResults, true),
        default => !in_array(false, $conditionResults, true)
    };
    
    $evaluatedRules[] = [
        'name' => $rule['name'] ?? 'Unnamed',
        'priority' => $rule['priority'] ?? 999,
        'matched' => $passes,
        'route' => $rule['route'] ?? 'unknown'
    ];
    
    if ($passes && $matchedRule === null) {
        $matchedRule = $rule;
    }
}

$route = $matchedRule['route'] ?? $defaultRoute;

return [
    'success' => true,
    'route' => $route,
    'data' => [
        'matched_rule' => $matchedRule['name'] ?? null,
        'evaluated_rules' => $evaluatedRules,
        'total_rules' => count($rules)
    ]
];
```

---

## Example 7: Time-Based Routing

Route based on time/schedule:

```php
<?php
$data = $process_input['result'] ?? [];
$timezone = $data['timezone'] ?? 'UTC';

// Get current time in timezone
$now = new \DateTime('now', new \DateTimeZone($timezone));
$hour = (int)$now->format('H');
$dayOfWeek = (int)$now->format('N'); // 1=Monday, 7=Sunday
$dayOfMonth = (int)$now->format('j');
$month = (int)$now->format('n');

// Define time-based rules
$route = 'standard';
$reason = '';

// Business hours check (9 AM - 5 PM, Mon-Fri)
$isBusinessHours = ($hour >= 9 && $hour < 17) && ($dayOfWeek >= 1 && $dayOfWeek <= 5);

// Weekend check
$isWeekend = ($dayOfWeek >= 6);

// After hours check
$isAfterHours = ($hour < 9 || $hour >= 17);

// Holiday check (simple example)
$holidays = [
    '1-1' => 'New Year',
    '12-25' => 'Christmas',
    '12-26' => 'Boxing Day'
];
$dateKey = "{$month}-{$dayOfMonth}";
$isHoliday = isset($holidays[$dateKey]);

// Peak hours (10 AM - 2 PM)
$isPeakHours = ($hour >= 10 && $hour < 14);

// Determine routing
if ($isHoliday) {
    $route = 'holiday_queue';
    $reason = 'Holiday: ' . ($holidays[$dateKey] ?? 'Unknown');
} elseif ($isWeekend) {
    $route = 'weekend_queue';
    $reason = 'Weekend support';
} elseif (!$isBusinessHours) {
    $route = 'after_hours_queue';
    $reason = 'Outside business hours';
} elseif ($isPeakHours) {
    $route = 'peak_hours_queue';
    $reason = 'Peak hours - additional capacity';
} else {
    $route = 'standard_queue';
    $reason = 'Normal business hours';
}

// Allow priority override
$priority = $data['priority'] ?? 'normal';
if ($priority === 'urgent') {
    $route = 'urgent_' . $route;
    $reason .= ' (Urgent priority override)';
}

return [
    'success' => true,
    'route' => $route,
    'data' => [
        'reason' => $reason,
        'time_info' => [
            'current_time' => $now->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
            'hour' => $hour,
            'day_of_week' => $dayOfWeek,
            'is_business_hours' => $isBusinessHours,
            'is_weekend' => $isWeekend,
            'is_holiday' => $isHoliday,
            'is_peak_hours' => $isPeakHours
        ]
    ]
];
```

---

## Example 8: Error Handling and Retry Logic

Route based on errors and retry attempts:

```php
<?php
$execution = $process_input['result'] ?? [];

$success = $execution['success'] ?? false;
$error = $execution['error'] ?? null;
$errorCode = $execution['error_code'] ?? null;
$retryCount = (int)($execution['retry_count'] ?? 0);
$maxRetries = (int)($execution['max_retries'] ?? 3);

// Categorize errors
$retryableErrors = [
    'TIMEOUT', 'CONNECTION_REFUSED', 'RATE_LIMITED',
    'SERVICE_UNAVAILABLE', 'TEMPORARY_ERROR'
];

$fatalErrors = [
    'AUTHENTICATION_FAILED', 'INVALID_REQUEST', 'NOT_FOUND',
    'VALIDATION_ERROR', 'FORBIDDEN'
];

$route = 'unknown';
$action = 'unknown';
$delay = 0;

if ($success) {
    $route = 'success';
    $action = 'continue';
} elseif (in_array($errorCode, $fatalErrors)) {
    $route = 'fatal_error';
    $action = 'abort';
} elseif (in_array($errorCode, $retryableErrors)) {
    if ($retryCount < $maxRetries) {
        $route = 'retry';
        $action = 'retry';
        // Exponential backoff
        $delay = min(pow(2, $retryCount) * 1000, 30000); // Max 30 seconds
    } else {
        $route = 'max_retries_exceeded';
        $action = 'escalate';
    }
} else {
    // Unknown error - handle based on retry count
    if ($retryCount < $maxRetries) {
        $route = 'retry';
        $action = 'retry';
        $delay = 5000; // 5 second delay for unknown errors
    } else {
        $route = 'unknown_error';
        $action = 'manual_review';
    }
}

return [
    'success' => true,
    'route' => $route,
    'data' => [
        'action' => $action,
        'original_success' => $success,
        'error_code' => $errorCode,
        'error_message' => $error,
        'retry_info' => [
            'current_attempt' => $retryCount + 1,
            'max_attempts' => $maxRetries,
            'should_retry' => $action === 'retry',
            'delay_ms' => $delay,
            'next_retry_at' => $delay > 0 ? date('Y-m-d H:i:s', time() + ($delay / 1000)) : null
        ]
    ]
];
```

---

## Best Practices

### 1. Use Clear Route Names
```php
// Good
$route = 'approve_standard';
$route = 'reject_insufficient_funds';
$route = 'escalate_to_manager';

// Avoid
$route = 'r1';
$route = 'path_a';
```

### 2. Always Have a Default Route
```php
$route = $matchedRoute ?? 'default';
```

### 3. Log Routing Decisions
```php
$log_message('[INFO] Routing to: ' . $route . ' - Reason: ' . $reason);
```

### 4. Return Decision Context
```php
return [
    'success' => true,
    'route' => $route,
    'data' => [
        'reason' => $reason,
        'evaluated_conditions' => $conditions,
        'input_summary' => $summary
    ]
];
```

---

## See Also

- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
- [Process-to-Process Communication](Process_Communication.md)
- [Event Publishing](Event_Publishing.md)
