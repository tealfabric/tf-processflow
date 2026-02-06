# Data Transformation & Mapping

This guide covers techniques for transforming and mapping data structures within ProcessFlow code snippets.

## Overview

Data transformation is one of the most common operations in ProcessFlow. These are **idempotent operations** - they take input data and produce output data without side effects, making them safe and predictable.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Input Data    │────▶│  Transformation  │────▶│  Output Data    │
│   (JSON/Array)  │     │    Code Snippet  │     │   (JSON/Array)  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Context Variables

| Variable | Type | Description |
|----------|------|-------------|
| `$process_input` | array | Input data from previous step |
| `$tenant_id` | string | Current tenant ID |
| `$user_id` | string | Executing user ID |
| `$log_message` | callable | Logging function |

## Core PHP Functions Available

Data transformation uses standard PHP functions. The following are **allowed**:

| Category | Functions |
|----------|-----------|
| Array | `array_map`, `array_filter`, `array_reduce`, `array_merge`, `array_combine`, `array_keys`, `array_values`, `array_column`, `array_unique`, `array_flip`, `array_search`, `in_array`, `usort`, `uksort`, `array_slice`, `array_chunk` |
| String | `trim`, `strtolower`, `strtoupper`, `ucfirst`, `ucwords`, `str_replace`, `preg_replace`, `substr`, `strlen`, `explode`, `implode`, `sprintf`, `number_format` |
| JSON | `json_encode`, `json_decode` |
| Type | `intval`, `floatval`, `strval`, `boolval`, `is_array`, `is_string`, `is_numeric`, `gettype` |
| Math | `round`, `floor`, `ceil`, `abs`, `max`, `min`, `array_sum`, `count` |

---

## Example 1: Field Mapping (Rename Keys)

Transform field names from one format to another:

```php
<?php
$data = $process_input['result']['data'] ?? [];

if (empty($data)) {
    return ['success' => false, 'error' => 'No input data'];
}

// Define field mapping: source_field => target_field
$fieldMap = [
    'firstName' => 'first_name',
    'lastName' => 'last_name', 
    'emailAddress' => 'email',
    'phoneNumber' => 'phone',
    'companyName' => 'company',
    'jobTitle' => 'position'
];

// Transform single record
$transformed = [];
foreach ($fieldMap as $source => $target) {
    if (isset($data[$source])) {
        $transformed[$target] = $data[$source];
    }
}

// Add any fields not in the mapping (pass-through)
foreach ($data as $key => $value) {
    if (!isset($fieldMap[$key]) && !isset($transformed[$key])) {
        $transformed[$key] = $value;
    }
}

return [
    'success' => true,
    'data' => $transformed
];
```

---

## Example 2: Batch Field Mapping (Multiple Records)

Transform an array of records:

```php
<?php
$records = $process_input['result']['records'] ?? [];

if (empty($records)) {
    return ['success' => true, 'data' => ['records' => [], 'count' => 0]];
}

$fieldMap = [
    'id' => 'customer_id',
    'name' => 'customer_name',
    'email' => 'contact_email',
    'created' => 'registration_date'
];

$transformed = array_map(function($record) use ($fieldMap) {
    $result = [];
    foreach ($fieldMap as $source => $target) {
        $result[$target] = $record[$source] ?? null;
    }
    return $result;
}, $records);

return [
    'success' => true,
    'data' => [
        'records' => $transformed,
        'count' => count($transformed)
    ]
];
```

---

## Example 3: Flatten Nested Structure

Convert nested data to flat structure:

```php
<?php
/**
 * Input:
 * {
 *   "user": {
 *     "profile": { "name": "John", "age": 30 },
 *     "contact": { "email": "john@example.com", "phone": "123" }
 *   }
 * }
 * 
 * Output:
 * {
 *   "user_profile_name": "John",
 *   "user_profile_age": 30,
 *   "user_contact_email": "john@example.com",
 *   "user_contact_phone": "123"
 * }
 */

$data = $process_input['result']['data'] ?? [];

// Recursive flatten function
$flatten = function($array, $prefix = '') use (&$flatten) {
    $result = [];
    foreach ($array as $key => $value) {
        $newKey = $prefix ? "{$prefix}_{$key}" : $key;
        if (is_array($value) && !isset($value[0])) {
            // Associative array - recurse
            $result = array_merge($result, $flatten($value, $newKey));
        } else {
            $result[$newKey] = $value;
        }
    }
    return $result;
};

$flattened = $flatten($data);

return [
    'success' => true,
    'data' => $flattened
];
```

---

## Example 4: Unflatten to Nested Structure

Convert flat keys back to nested structure:

```php
<?php
/**
 * Input: { "user_name": "John", "user_email": "john@ex.com", "address_city": "NYC" }
 * Output: { "user": { "name": "John", "email": "john@ex.com" }, "address": { "city": "NYC" } }
 */

$data = $process_input['result']['data'] ?? [];

$unflatten = function($array, $delimiter = '_') {
    $result = [];
    foreach ($array as $key => $value) {
        $keys = explode($delimiter, $key);
        $current = &$result;
        
        foreach ($keys as $i => $part) {
            if ($i === count($keys) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }
    }
    return $result;
};

$nested = $unflatten($data);

return [
    'success' => true,
    'data' => $nested
];
```

---

## Example 5: Data Type Conversion

Convert and normalize data types:

```php
<?php
$record = $process_input['result']['record'] ?? [];

// Define type specifications
$typeSpec = [
    'id' => 'int',
    'name' => 'string',
    'price' => 'float',
    'quantity' => 'int',
    'is_active' => 'bool',
    'tags' => 'array',
    'created_at' => 'datetime'
];

$converted = [];

foreach ($typeSpec as $field => $type) {
    $value = $record[$field] ?? null;
    
    if ($value === null) {
        $converted[$field] = null;
        continue;
    }
    
    switch ($type) {
        case 'int':
            $converted[$field] = intval($value);
            break;
        case 'float':
            $converted[$field] = floatval($value);
            break;
        case 'string':
            $converted[$field] = trim(strval($value));
            break;
        case 'bool':
            $converted[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            break;
        case 'array':
            $converted[$field] = is_array($value) ? $value : [$value];
            break;
        case 'datetime':
            $converted[$field] = is_numeric($value) 
                ? date('Y-m-d H:i:s', $value) 
                : date('Y-m-d H:i:s', strtotime($value));
            break;
        default:
            $converted[$field] = $value;
    }
}

return [
    'success' => true,
    'data' => $converted
];
```

---

## Example 6: Merge Multiple Data Sources

Combine data from different sources:

```php
<?php
$customer = $process_input['result']['customer'] ?? [];
$orders = $process_input['result']['orders'] ?? [];
$preferences = $process_input['result']['preferences'] ?? [];

// Merge into unified structure
$merged = [
    'customer_id' => $customer['id'] ?? null,
    'profile' => [
        'name' => $customer['name'] ?? '',
        'email' => $customer['email'] ?? '',
        'phone' => $customer['phone'] ?? ''
    ],
    'order_summary' => [
        'total_orders' => count($orders),
        'total_value' => array_sum(array_column($orders, 'total')),
        'last_order_date' => !empty($orders) 
            ? max(array_column($orders, 'created_at')) 
            : null
    ],
    'preferences' => $preferences,
    'merged_at' => date('Y-m-d H:i:s')
];

return [
    'success' => true,
    'data' => $merged
];
```

---

## Example 7: Conditional Field Mapping

Map fields based on conditions:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$sourceType = $process_input['result']['source_type'] ?? 'default';

// Different mappings for different source types
$mappings = [
    'shopify' => [
        'order_id' => 'id',
        'order_number' => 'name',
        'order_total' => 'total_price',
        'customer_email' => 'email'
    ],
    'woocommerce' => [
        'order_id' => 'id',
        'order_number' => 'number',
        'order_total' => 'total',
        'customer_email' => 'billing.email'
    ],
    'default' => [
        'order_id' => 'id',
        'order_number' => 'order_number',
        'order_total' => 'total',
        'customer_email' => 'email'
    ]
];

$fieldMap = $mappings[$sourceType] ?? $mappings['default'];

// Helper to get nested value
$getValue = function($data, $path) {
    $keys = explode('.', $path);
    $value = $data;
    foreach ($keys as $key) {
        if (!isset($value[$key])) return null;
        $value = $value[$key];
    }
    return $value;
};

$transformed = [];
foreach ($fieldMap as $target => $source) {
    $transformed[$target] = $getValue($data, $source);
}

return [
    'success' => true,
    'data' => [
        'source_type' => $sourceType,
        'transformed' => $transformed
    ]
];
```

---

## Example 8: Array Restructuring

Convert between different array structures:

```php
<?php
/**
 * Convert key-value pairs to object array and vice versa
 */

$input = $process_input['result'] ?? [];
$operation = $input['operation'] ?? 'to_objects';
$data = $input['data'] ?? [];

if ($operation === 'to_objects') {
    // Convert { "key1": "val1", "key2": "val2" } 
    // to [{ "key": "key1", "value": "val1" }, ...]
    $result = [];
    foreach ($data as $key => $value) {
        $result[] = ['key' => $key, 'value' => $value];
    }
} elseif ($operation === 'to_map') {
    // Convert [{ "key": "key1", "value": "val1" }, ...] 
    // to { "key1": "val1", ... }
    $result = [];
    foreach ($data as $item) {
        if (isset($item['key'])) {
            $result[$item['key']] = $item['value'] ?? null;
        }
    }
} elseif ($operation === 'group_by') {
    // Group array by a field
    $groupField = $input['group_by'] ?? 'category';
    $result = [];
    foreach ($data as $item) {
        $key = $item[$groupField] ?? 'unknown';
        if (!isset($result[$key])) {
            $result[$key] = [];
        }
        $result[$key][] = $item;
    }
} else {
    return ['success' => false, 'error' => 'Unknown operation'];
}

return [
    'success' => true,
    'data' => $result
];
```

---

## Example 9: Data Enrichment

Add calculated fields to existing data:

```php
<?php
$orders = $process_input['result']['orders'] ?? [];

$enriched = array_map(function($order) {
    // Calculate derived fields
    $subtotal = floatval($order['subtotal'] ?? 0);
    $tax = floatval($order['tax'] ?? 0);
    $shipping = floatval($order['shipping'] ?? 0);
    $discount = floatval($order['discount'] ?? 0);
    
    $total = $subtotal + $tax + $shipping - $discount;
    $marginPercent = $subtotal > 0 
        ? round(($subtotal - ($order['cost'] ?? 0)) / $subtotal * 100, 2) 
        : 0;
    
    // Add calculated fields
    $order['calculated_total'] = round($total, 2);
    $order['margin_percent'] = $marginPercent;
    $order['has_discount'] = $discount > 0;
    $order['is_free_shipping'] = $shipping == 0;
    $order['processed_at'] = date('Y-m-d H:i:s');
    
    return $order;
}, $orders);

return [
    'success' => true,
    'data' => [
        'orders' => $enriched,
        'summary' => [
            'count' => count($enriched),
            'total_value' => array_sum(array_column($enriched, 'calculated_total')),
            'avg_margin' => count($enriched) > 0 
                ? round(array_sum(array_column($enriched, 'margin_percent')) / count($enriched), 2)
                : 0
        ]
    ]
];
```

---

## Best Practices

### 1. Always Validate Input
```php
if (!isset($process_input['result']['data'])) {
    return ['success' => false, 'error' => 'Missing required input: data'];
}
```

### 2. Handle Missing Fields Gracefully
```php
$value = $data['field'] ?? 'default_value';
```

### 3. Log Important Transformations
```php
$log_message('[INFO] Transformed ' . count($records) . ' records');
```

### 4. Return Consistent Output Structure
```php
return [
    'success' => true,
    'data' => [...],
    'metadata' => [
        'input_count' => $inputCount,
        'output_count' => $outputCount,
        'transformed_at' => date('c')
    ]
];
```

### 5. Keep Transformations Pure
- Don't modify input data directly
- Don't make API calls or database queries
- Don't use file operations for pure transformations

---

## Common Pitfalls

| Issue | Solution |
|-------|----------|
| Modifying nested arrays | Use recursive functions or clone data first |
| Type mismatches | Explicitly convert types before operations |
| Missing array keys | Use null coalescing (`??`) operator |
| Performance with large arrays | Use generators or chunk processing |

---

## See Also

- [Array & Collection Operations](Array_Collection_Operations.md)
- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
- [JSON/XML/CSV Parsing](JSON_XML_CSV_Parsing.md)
