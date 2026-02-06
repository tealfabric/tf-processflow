# Array & Collection Operations

This guide covers array manipulation and collection processing within ProcessFlow code snippets.

## Overview

Array operations are fundamental for processing lists, filtering data, and aggregating results. These are **idempotent operations** that transform collections without side effects.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Input Array    │────▶│  Array Process   │────▶│  Output Array   │
│  Collection     │     │  Code Snippet    │     │  Collection     │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available PHP Array Functions

| Category | Functions |
|----------|-----------|
| **Transform** | `array_map`, `array_filter`, `array_reduce`, `array_walk` |
| **Combine** | `array_merge`, `array_combine`, `array_replace`, `array_merge_recursive` |
| **Search** | `in_array`, `array_search`, `array_key_exists`, `array_keys`, `array_values` |
| **Sort** | `sort`, `rsort`, `asort`, `arsort`, `ksort`, `krsort`, `usort`, `uasort`, `uksort` |
| **Slice** | `array_slice`, `array_splice`, `array_chunk`, `array_pad` |
| **Unique** | `array_unique`, `array_flip`, `array_count_values` |
| **Aggregate** | `array_sum`, `array_product`, `count`, `array_column` |
| **Compare** | `array_diff`, `array_intersect`, `array_diff_key`, `array_intersect_key` |

---

## Example 1: Filtering Arrays

Filter arrays by various criteria:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$filters = $process_input['result']['filters'] ?? [];

if (empty($items)) {
    return ['success' => true, 'data' => ['items' => [], 'count' => 0]];
}

$filtered = $items;

// Apply filters
foreach ($filters as $field => $condition) {
    if (is_array($condition)) {
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
    } else {
        $operator = 'equals';
        $value = $condition;
    }
    
    $filtered = array_filter($filtered, function($item) use ($field, $operator, $value) {
        $itemValue = $item[$field] ?? null;
        
        switch ($operator) {
            case 'equals':
                return $itemValue == $value;
            case 'not_equals':
                return $itemValue != $value;
            case 'greater_than':
                return $itemValue > $value;
            case 'less_than':
                return $itemValue < $value;
            case 'greater_or_equal':
                return $itemValue >= $value;
            case 'less_or_equal':
                return $itemValue <= $value;
            case 'contains':
                return stripos($itemValue, $value) !== false;
            case 'starts_with':
                return strpos($itemValue, $value) === 0;
            case 'ends_with':
                return substr($itemValue, -strlen($value)) === $value;
            case 'in':
                return in_array($itemValue, (array)$value);
            case 'not_in':
                return !in_array($itemValue, (array)$value);
            case 'is_null':
                return $itemValue === null;
            case 'is_not_null':
                return $itemValue !== null;
            case 'is_empty':
                return empty($itemValue);
            case 'is_not_empty':
                return !empty($itemValue);
            default:
                return true;
        }
    });
}

$filtered = array_values($filtered);

return [
    'success' => true,
    'data' => [
        'items' => $filtered,
        'original_count' => count($items),
        'filtered_count' => count($filtered),
        'filters_applied' => array_keys($filters)
    ]
];
```

---

## Example 2: Sorting Arrays

Sort arrays by multiple criteria:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$sortBy = $process_input['result']['sort_by'] ?? [];
$caseSensitive = $process_input['result']['case_sensitive'] ?? false;

if (empty($items)) {
    return ['success' => true, 'data' => ['items' => []]];
}

// Convert simple sort to array format
if (is_string($sortBy)) {
    $sortBy = [['field' => $sortBy, 'direction' => 'asc']];
}

// Multi-field sorting
usort($items, function($a, $b) use ($sortBy, $caseSensitive) {
    foreach ($sortBy as $sort) {
        $field = $sort['field'] ?? $sort;
        $direction = strtolower($sort['direction'] ?? 'asc');
        
        $valA = $a[$field] ?? null;
        $valB = $b[$field] ?? null;
        
        // Handle string comparison
        if (is_string($valA) && is_string($valB) && !$caseSensitive) {
            $valA = strtolower($valA);
            $valB = strtolower($valB);
        }
        
        // Handle null values
        if ($valA === null && $valB === null) continue;
        if ($valA === null) return $direction === 'asc' ? 1 : -1;
        if ($valB === null) return $direction === 'asc' ? -1 : 1;
        
        // Compare values
        if ($valA < $valB) {
            return $direction === 'asc' ? -1 : 1;
        } elseif ($valA > $valB) {
            return $direction === 'asc' ? 1 : -1;
        }
    }
    return 0;
});

return [
    'success' => true,
    'data' => [
        'items' => $items,
        'count' => count($items),
        'sorted_by' => $sortBy
    ]
];
```

---

## Example 3: Grouping Arrays

Group items by field values:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$groupBy = $process_input['result']['group_by'] ?? 'category';
$includeStats = $process_input['result']['include_stats'] ?? true;

if (empty($items)) {
    return ['success' => true, 'data' => ['groups' => []]];
}

$groups = [];

foreach ($items as $item) {
    // Support nested keys with dot notation
    $keys = explode('.', $groupBy);
    $key = $item;
    foreach ($keys as $k) {
        $key = $key[$k] ?? null;
    }
    
    $groupKey = $key ?? '_ungrouped';
    
    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            'key' => $groupKey,
            'items' => []
        ];
    }
    
    $groups[$groupKey]['items'][] = $item;
}

// Calculate stats for each group
if ($includeStats) {
    foreach ($groups as &$group) {
        $group['count'] = count($group['items']);
        
        // Find numeric fields and calculate sums
        $numericFields = [];
        if (!empty($group['items'])) {
            $firstItem = $group['items'][0];
            foreach ($firstItem as $field => $value) {
                if (is_numeric($value)) {
                    $numericFields[$field] = array_sum(array_column($group['items'], $field));
                }
            }
        }
        $group['sums'] = $numericFields;
    }
}

return [
    'success' => true,
    'data' => [
        'groups' => array_values($groups),
        'group_count' => count($groups),
        'total_items' => count($items),
        'grouped_by' => $groupBy
    ]
];
```

---

## Example 4: Array Aggregation

Aggregate array data with multiple operations:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$aggregations = $process_input['result']['aggregations'] ?? [];

if (empty($items)) {
    return [
        'success' => true,
        'data' => ['results' => [], 'count' => 0]
    ];
}

$results = [
    'count' => count($items)
];

// Default aggregations if none specified
if (empty($aggregations)) {
    $aggregations = [
        'count' => ['type' => 'count']
    ];
}

foreach ($aggregations as $name => $config) {
    $type = $config['type'] ?? 'count';
    $field = $config['field'] ?? null;
    
    switch ($type) {
        case 'count':
            $results[$name] = count($items);
            break;
            
        case 'sum':
            if ($field) {
                $values = array_column($items, $field);
                $results[$name] = array_sum(array_filter($values, 'is_numeric'));
            }
            break;
            
        case 'avg':
            if ($field) {
                $values = array_filter(array_column($items, $field), 'is_numeric');
                $results[$name] = count($values) > 0 
                    ? round(array_sum($values) / count($values), 4) 
                    : 0;
            }
            break;
            
        case 'min':
            if ($field) {
                $values = array_filter(array_column($items, $field), 'is_numeric');
                $results[$name] = !empty($values) ? min($values) : null;
            }
            break;
            
        case 'max':
            if ($field) {
                $values = array_filter(array_column($items, $field), 'is_numeric');
                $results[$name] = !empty($values) ? max($values) : null;
            }
            break;
            
        case 'distinct':
            if ($field) {
                $values = array_column($items, $field);
                $results[$name] = array_values(array_unique($values));
                $results[$name . '_count'] = count($results[$name]);
            }
            break;
            
        case 'first':
            $results[$name] = $field ? ($items[0][$field] ?? null) : $items[0];
            break;
            
        case 'last':
            $last = end($items);
            $results[$name] = $field ? ($last[$field] ?? null) : $last;
            break;
    }
}

return [
    'success' => true,
    'data' => [
        'results' => $results,
        'item_count' => count($items)
    ]
];
```

---

## Example 5: Array Comparison

Compare and find differences between arrays:

```php
<?php
$array1 = $process_input['result']['array1'] ?? [];
$array2 = $process_input['result']['array2'] ?? [];
$keyField = $process_input['result']['key_field'] ?? null;

// Index arrays by key field if provided
if ($keyField) {
    $indexed1 = [];
    $indexed2 = [];
    
    foreach ($array1 as $item) {
        $key = $item[$keyField] ?? null;
        if ($key !== null) {
            $indexed1[$key] = $item;
        }
    }
    
    foreach ($array2 as $item) {
        $key = $item[$keyField] ?? null;
        if ($key !== null) {
            $indexed2[$key] = $item;
        }
    }
    
    // Find differences
    $onlyIn1 = array_diff_key($indexed1, $indexed2);
    $onlyIn2 = array_diff_key($indexed2, $indexed1);
    $inBoth = array_intersect_key($indexed1, $indexed2);
    
    // Find modified items
    $modified = [];
    foreach ($inBoth as $key => $item1) {
        $item2 = $indexed2[$key];
        if ($item1 != $item2) {
            $modified[$key] = [
                'key' => $key,
                'old' => $item1,
                'new' => $item2,
                'changes' => array_diff_assoc($item2, $item1)
            ];
        }
    }
    
    return [
        'success' => true,
        'data' => [
            'only_in_first' => array_values($onlyIn1),
            'only_in_second' => array_values($onlyIn2),
            'in_both' => count($inBoth),
            'modified' => array_values($modified),
            'summary' => [
                'added' => count($onlyIn2),
                'removed' => count($onlyIn1),
                'modified' => count($modified),
                'unchanged' => count($inBoth) - count($modified)
            ]
        ]
    ];
}

// Simple array comparison
return [
    'success' => true,
    'data' => [
        'only_in_first' => array_values(array_diff($array1, $array2)),
        'only_in_second' => array_values(array_diff($array2, $array1)),
        'in_both' => array_values(array_intersect($array1, $array2))
    ]
];
```

---

## Example 6: Pagination

Paginate array data:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$page = max(1, (int)($process_input['result']['page'] ?? 1));
$perPage = max(1, min(100, (int)($process_input['result']['per_page'] ?? 25)));

$total = count($items);
$totalPages = ceil($total / $perPage);
$page = min($page, max(1, $totalPages));

$offset = ($page - 1) * $perPage;
$pageItems = array_slice($items, $offset, $perPage);

return [
    'success' => true,
    'data' => [
        'items' => $pageItems,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total_items' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $totalPages ? $page + 1 : null,
            'first_item' => $total > 0 ? $offset + 1 : 0,
            'last_item' => min($offset + $perPage, $total)
        ]
    ]
];
```

---

## Example 7: Array Deduplication

Remove duplicates with various strategies:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$strategy = $process_input['result']['strategy'] ?? 'first';
$keyFields = $process_input['result']['key_fields'] ?? null;

if (empty($items)) {
    return ['success' => true, 'data' => ['items' => [], 'removed' => 0]];
}

$originalCount = count($items);

// Simple deduplication for scalar arrays
if (!is_array($items[0])) {
    $unique = array_values(array_unique($items));
    return [
        'success' => true,
        'data' => [
            'items' => $unique,
            'original_count' => $originalCount,
            'unique_count' => count($unique),
            'duplicates_removed' => $originalCount - count($unique)
        ]
    ];
}

// Object deduplication
$seen = [];
$unique = [];
$duplicates = [];

foreach ($items as $index => $item) {
    // Generate key based on key fields
    if ($keyFields) {
        $keyParts = [];
        foreach ((array)$keyFields as $field) {
            $keyParts[] = $item[$field] ?? '';
        }
        $key = implode('|', $keyParts);
    } else {
        // Use full item as key
        $key = md5(json_encode($item));
    }
    
    if (isset($seen[$key])) {
        $duplicates[] = [
            'item' => $item,
            'duplicate_of_index' => $seen[$key]
        ];
        
        // Merge strategy - combine with existing
        if ($strategy === 'merge') {
            foreach ($unique as &$existingItem) {
                if ($existingItem['_dedup_key'] === $key) {
                    $existingItem = array_merge($existingItem, $item);
                    break;
                }
            }
        }
        // Last strategy - replace
        elseif ($strategy === 'last') {
            foreach ($unique as $i => &$existingItem) {
                if ($existingItem['_dedup_key'] === $key) {
                    $unique[$i] = array_merge($item, ['_dedup_key' => $key]);
                    break;
                }
            }
        }
        // First strategy (default) - keep first occurrence
    } else {
        $seen[$key] = $index;
        $unique[] = array_merge($item, ['_dedup_key' => $key]);
    }
}

// Remove internal key
$unique = array_map(function($item) {
    unset($item['_dedup_key']);
    return $item;
}, $unique);

return [
    'success' => true,
    'data' => [
        'items' => array_values($unique),
        'original_count' => $originalCount,
        'unique_count' => count($unique),
        'duplicates_removed' => count($duplicates),
        'strategy_used' => $strategy,
        'key_fields' => $keyFields
    ]
];
```

---

## Example 8: Array Chunking and Batching

Split arrays into chunks for batch processing:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$chunkSize = max(1, (int)($process_input['result']['chunk_size'] ?? 10));
$preserveKeys = $process_input['result']['preserve_keys'] ?? false;
$includeMetadata = $process_input['result']['include_metadata'] ?? true;

if (empty($items)) {
    return ['success' => true, 'data' => ['chunks' => [], 'total_chunks' => 0]];
}

$chunks = array_chunk($items, $chunkSize, $preserveKeys);

$result = [];
foreach ($chunks as $index => $chunk) {
    $chunkData = ['items' => $chunk];
    
    if ($includeMetadata) {
        $chunkData['metadata'] = [
            'chunk_number' => $index + 1,
            'chunk_size' => count($chunk),
            'start_index' => $index * $chunkSize,
            'end_index' => ($index * $chunkSize) + count($chunk) - 1,
            'is_first' => $index === 0,
            'is_last' => $index === count($chunks) - 1
        ];
    }
    
    $result[] = $chunkData;
}

return [
    'success' => true,
    'data' => [
        'chunks' => $result,
        'total_items' => count($items),
        'total_chunks' => count($chunks),
        'chunk_size' => $chunkSize
    ]
];
```

---

## Example 9: Array Flattening and Plucking

Extract specific fields or flatten nested arrays:

```php
<?php
$items = $process_input['result']['items'] ?? [];
$operation = $process_input['result']['operation'] ?? 'pluck';
$field = $process_input['result']['field'] ?? null;
$depth = (int)($process_input['result']['depth'] ?? 1);

if (empty($items)) {
    return ['success' => true, 'data' => ['result' => []]];
}

switch ($operation) {
    case 'pluck':
        // Extract single field values
        if (!$field) {
            return ['success' => false, 'error' => 'Field required for pluck'];
        }
        
        // Support dot notation
        $result = array_map(function($item) use ($field) {
            $keys = explode('.', $field);
            $value = $item;
            foreach ($keys as $key) {
                $value = $value[$key] ?? null;
                if ($value === null) break;
            }
            return $value;
        }, $items);
        
        return [
            'success' => true,
            'data' => [
                'result' => array_values(array_filter($result, function($v) { return $v !== null; })),
                'field' => $field,
                'count' => count($result)
            ]
        ];
        
    case 'flatten':
        // Flatten nested arrays
        $flatten = function($array, $currentDepth = 0) use (&$flatten, $depth) {
            $result = [];
            foreach ($array as $item) {
                if (is_array($item) && ($depth === -1 || $currentDepth < $depth)) {
                    $result = array_merge($result, $flatten($item, $currentDepth + 1));
                } else {
                    $result[] = $item;
                }
            }
            return $result;
        };
        
        return [
            'success' => true,
            'data' => [
                'result' => $flatten($items),
                'depth' => $depth
            ]
        ];
        
    case 'key_by':
        // Index array by a field
        if (!$field) {
            return ['success' => false, 'error' => 'Field required for key_by'];
        }
        
        $keyed = [];
        foreach ($items as $item) {
            $key = $item[$field] ?? null;
            if ($key !== null) {
                $keyed[$key] = $item;
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'result' => $keyed,
                'field' => $field,
                'count' => count($keyed)
            ]
        ];
        
    case 'select':
        // Select only specific fields
        $fields = (array)$field;
        $result = array_map(function($item) use ($fields) {
            $selected = [];
            foreach ($fields as $f) {
                if (isset($item[$f])) {
                    $selected[$f] = $item[$f];
                }
            }
            return $selected;
        }, $items);
        
        return [
            'success' => true,
            'data' => [
                'result' => $result,
                'fields' => $fields,
                'count' => count($result)
            ]
        ];
        
    default:
        return ['success' => false, 'error' => 'Unknown operation: ' . $operation];
}
```

---

## Example 10: Array Merging and Joining

Merge arrays with various strategies:

```php
<?php
$arrays = $process_input['result']['arrays'] ?? [];
$strategy = $process_input['result']['strategy'] ?? 'append';
$joinField = $process_input['result']['join_field'] ?? null;

if (empty($arrays) || count($arrays) < 2) {
    return [
        'success' => false, 
        'error' => 'Need at least 2 arrays to merge'
    ];
}

switch ($strategy) {
    case 'append':
        // Simple concatenation
        $result = [];
        foreach ($arrays as $arr) {
            $result = array_merge($result, (array)$arr);
        }
        break;
        
    case 'merge_recursive':
        // Deep merge
        $result = $arrays[0];
        for ($i = 1; $i < count($arrays); $i++) {
            $result = array_merge_recursive($result, $arrays[$i]);
        }
        break;
        
    case 'replace':
        // Later values replace earlier
        $result = $arrays[0];
        for ($i = 1; $i < count($arrays); $i++) {
            $result = array_replace($result, $arrays[$i]);
        }
        break;
        
    case 'left_join':
        // Join arrays by field (like SQL LEFT JOIN)
        if (!$joinField) {
            return ['success' => false, 'error' => 'join_field required'];
        }
        
        $result = [];
        $left = $arrays[0];
        $right = [];
        
        // Index right arrays by join field
        for ($i = 1; $i < count($arrays); $i++) {
            foreach ($arrays[$i] as $item) {
                $key = $item[$joinField] ?? null;
                if ($key !== null) {
                    $right[$key] = array_merge($right[$key] ?? [], $item);
                }
            }
        }
        
        // Join
        foreach ($left as $leftItem) {
            $key = $leftItem[$joinField] ?? null;
            if ($key !== null && isset($right[$key])) {
                $result[] = array_merge($leftItem, $right[$key]);
            } else {
                $result[] = $leftItem;
            }
        }
        break;
        
    case 'intersect':
        // Only items present in all arrays
        $result = $arrays[0];
        for ($i = 1; $i < count($arrays); $i++) {
            $result = array_intersect($result, $arrays[$i]);
        }
        $result = array_values($result);
        break;
        
    case 'union':
        // Unique items from all arrays
        $result = [];
        foreach ($arrays as $arr) {
            $result = array_merge($result, (array)$arr);
        }
        $result = array_values(array_unique($result));
        break;
        
    default:
        return ['success' => false, 'error' => 'Unknown strategy: ' . $strategy];
}

return [
    'success' => true,
    'data' => [
        'result' => $result,
        'count' => count($result),
        'strategy' => $strategy,
        'input_arrays' => count($arrays)
    ]
];
```

---

## Best Practices

### 1. Always Return Array Values
```php
// Reset array keys after filtering
$filtered = array_values(array_filter($items, $callback));
```

### 2. Handle Empty Arrays
```php
if (empty($items)) {
    return ['success' => true, 'data' => ['items' => []]];
}
```

### 3. Use Type-Safe Comparisons
```php
// Use === for exact matching
$exists = in_array($value, $array, true);
```

### 4. Avoid Modifying During Iteration
```php
// Bad: modifying while iterating
foreach ($items as &$item) { ... }

// Good: create new array
$result = array_map(function($item) { ... }, $items);
```

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
- [Mathematical & Statistical Calculations](Math_Statistics.md)
