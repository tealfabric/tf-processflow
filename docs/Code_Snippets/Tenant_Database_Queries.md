# Tenant Database Queries

This guide covers database operations within ProcessFlow code snippets using the tenant-scoped database service.

## Overview

The `$tenantDb` service provides secure, tenant-isolated database access. All queries are automatically scoped to the current tenant, preventing cross-tenant data access.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│   $tenantDb      │────▶│  Tenant Data    │
│  Query          │     │   Service        │     │  (Isolated)     │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Services

| Variable | Type | Description |
|----------|------|-------------|
| `$tenantDb` | TenantDatabaseService | **Recommended** - Auto-scoped queries |
| `$db` | PDO | Raw database connection (use with caution) |
| `$tenant_id` | string | Current tenant ID |

## TenantDatabaseService Methods

| Method | Description |
|--------|-------------|
| `query($sql, $params)` | Execute query with tenant scoping |
| `select($table, $conditions, $options)` | SELECT with auto tenant_id |
| `insert($table, $data)` | INSERT with auto tenant_id |
| `update($table, $data, $conditions)` | UPDATE with auto tenant_id |
| `delete($table, $conditions)` | DELETE with auto tenant_id |
| `count($table, $conditions)` | COUNT with auto tenant_id |

---

## Example 1: Basic Select Query

Retrieve records from a table:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$conditions = $process_input['result']['conditions'] ?? [];
$options = $process_input['result']['options'] ?? [];

if (empty($tableName)) {
    return ['success' => false, 'error' => 'Table name required'];
}

try {
    // Using select method - tenant_id is auto-injected
    $result = $tenantDb->select($tableName, $conditions, [
        'order_by' => $options['order_by'] ?? null,
        'limit' => $options['limit'] ?? 100,
        'offset' => $options['offset'] ?? 0,
        'columns' => $options['columns'] ?? ['*']
    ]);
    
    return [
        'success' => true,
        'data' => [
            'records' => $result,
            'count' => count($result),
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Database query failed: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Custom SQL Query

Execute custom SQL with parameters:

```php
<?php
$customQuery = $process_input['result']['query'] ?? '';
$params = $process_input['result']['params'] ?? [];

if (empty($customQuery)) {
    return ['success' => false, 'error' => 'Query required'];
}

try {
    // The tenantDb->query() method automatically injects tenant_id
    // into WHERE clauses for tenant-scoped tables
    $result = $tenantDb->query($customQuery, $params);
    
    return [
        'success' => true,
        'data' => [
            'records' => $result,
            'count' => count($result)
        ]
    ];
} catch (\Exception $e) {
    $log_message('[ERROR] Query failed: ' . $e->getMessage());
    return [
        'success' => false,
        'error' => 'Query execution failed: ' . $e->getMessage()
    ];
}
```

---

## Example 3: Insert Record

Insert a new record:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$data = $process_input['result']['data'] ?? [];

if (empty($tableName) || empty($data)) {
    return ['success' => false, 'error' => 'Table and data required'];
}

try {
    // Insert automatically adds tenant_id
    $insertId = $tenantDb->insert($tableName, $data);
    
    $log_message("[INFO] Inserted record into $tableName with ID: $insertId");
    
    return [
        'success' => true,
        'data' => [
            'insert_id' => $insertId,
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    $log_message('[ERROR] Insert failed: ' . $e->getMessage());
    return [
        'success' => false,
        'error' => 'Insert failed: ' . $e->getMessage()
    ];
}
```

---

## Example 4: Update Records

Update existing records:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$data = $process_input['result']['data'] ?? [];
$conditions = $process_input['result']['conditions'] ?? [];

if (empty($tableName) || empty($data) || empty($conditions)) {
    return ['success' => false, 'error' => 'Table, data, and conditions required'];
}

try {
    // Update with tenant scoping - tenant_id auto-added to conditions
    $affectedRows = $tenantDb->update($tableName, $data, $conditions);
    
    $log_message("[INFO] Updated $affectedRows rows in $tableName");
    
    return [
        'success' => true,
        'data' => [
            'affected_rows' => $affectedRows,
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    $log_message('[ERROR] Update failed: ' . $e->getMessage());
    return [
        'success' => false,
        'error' => 'Update failed: ' . $e->getMessage()
    ];
}
```

---

## Example 5: Delete Records

Delete records (with safety checks):

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$conditions = $process_input['result']['conditions'] ?? [];
$confirmDelete = $process_input['result']['confirm_delete'] ?? false;

if (empty($tableName) || empty($conditions)) {
    return ['success' => false, 'error' => 'Table and conditions required'];
}

// Safety: require explicit confirmation for deletions
if (!$confirmDelete) {
    // First, count records that would be deleted
    $count = $tenantDb->count($tableName, $conditions);
    
    return [
        'success' => false,
        'route' => 'confirm_delete',
        'data' => [
            'records_to_delete' => $count,
            'table' => $tableName,
            'conditions' => $conditions,
            'message' => 'Set confirm_delete=true to proceed'
        ]
    ];
}

try {
    $deletedRows = $tenantDb->delete($tableName, $conditions);
    
    $log_message("[INFO] Deleted $deletedRows rows from $tableName");
    
    return [
        'success' => true,
        'data' => [
            'deleted_rows' => $deletedRows,
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    $log_message('[ERROR] Delete failed: ' . $e->getMessage());
    return [
        'success' => false,
        'error' => 'Delete failed: ' . $e->getMessage()
    ];
}
```

---

## Example 6: Aggregation Queries

Perform aggregation operations:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$aggregation = $process_input['result']['aggregation'] ?? 'count';
$field = $process_input['result']['field'] ?? '*';
$conditions = $process_input['result']['conditions'] ?? [];
$groupBy = $process_input['result']['group_by'] ?? null;

if (empty($tableName)) {
    return ['success' => false, 'error' => 'Table name required'];
}

try {
    $aggFunctions = ['count', 'sum', 'avg', 'min', 'max'];
    
    if (!in_array($aggregation, $aggFunctions)) {
        return ['success' => false, 'error' => 'Invalid aggregation function'];
    }
    
    // Build aggregation query
    $aggField = $field === '*' ? '*' : "`$field`";
    $selectClause = strtoupper($aggregation) . "($aggField) as result";
    
    if ($groupBy) {
        $selectClause = "`$groupBy`, " . $selectClause;
    }
    
    // Build WHERE clause
    $whereClause = '';
    $params = [];
    if (!empty($conditions)) {
        $whereParts = [];
        foreach ($conditions as $col => $val) {
            $whereParts[] = "`$col` = ?";
            $params[] = $val;
        }
        $whereClause = ' WHERE ' . implode(' AND ', $whereParts);
    }
    
    $query = "SELECT $selectClause FROM `$tableName`$whereClause";
    
    if ($groupBy) {
        $query .= " GROUP BY `$groupBy`";
    }
    
    $result = $tenantDb->query($query, $params);
    
    if ($groupBy) {
        return [
            'success' => true,
            'data' => [
                'results' => $result,
                'aggregation' => $aggregation,
                'grouped_by' => $groupBy
            ]
        ];
    }
    
    return [
        'success' => true,
        'data' => [
            'result' => $result[0]['result'] ?? 0,
            'aggregation' => $aggregation,
            'field' => $field,
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Aggregation failed: ' . $e->getMessage()
    ];
}
```

---

## Example 7: Join Queries

Perform JOIN operations:

```php
<?php
$mainTable = $process_input['result']['main_table'] ?? '';
$joins = $process_input['result']['joins'] ?? [];
$conditions = $process_input['result']['conditions'] ?? [];
$columns = $process_input['result']['columns'] ?? ['*'];

if (empty($mainTable) || empty($joins)) {
    return ['success' => false, 'error' => 'Main table and joins required'];
}

try {
    // Build SELECT clause
    $selectClause = implode(', ', $columns);
    
    // Build JOIN clauses
    $joinClauses = [];
    foreach ($joins as $join) {
        $type = strtoupper($join['type'] ?? 'LEFT') . ' JOIN';
        $table = $join['table'];
        $on = $join['on']; // e.g., "main.id = joined.main_id"
        $joinClauses[] = "$type `$table` ON $on";
    }
    $joinClause = implode(' ', $joinClauses);
    
    // Build WHERE clause
    $whereClause = '';
    $params = [];
    if (!empty($conditions)) {
        $whereParts = [];
        foreach ($conditions as $col => $val) {
            $whereParts[] = "$col = ?";
            $params[] = $val;
        }
        $whereClause = ' WHERE ' . implode(' AND ', $whereParts);
    }
    
    $query = "SELECT $selectClause FROM `$mainTable` $joinClause$whereClause";
    
    $log_message('[DEBUG] Executing join query: ' . $query);
    
    $result = $tenantDb->query($query, $params);
    
    return [
        'success' => true,
        'data' => [
            'records' => $result,
            'count' => count($result)
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Join query failed: ' . $e->getMessage()
    ];
}
```

---

## Example 8: Batch Insert

Insert multiple records efficiently:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$records = $process_input['result']['records'] ?? [];

if (empty($tableName) || empty($records)) {
    return ['success' => false, 'error' => 'Table and records required'];
}

$insertedCount = 0;
$errors = [];

try {
    foreach ($records as $index => $record) {
        try {
            $tenantDb->insert($tableName, $record);
            $insertedCount++;
        } catch (\Exception $e) {
            $errors[] = [
                'index' => $index,
                'error' => $e->getMessage()
            ];
        }
    }
    
    $log_message("[INFO] Batch insert: $insertedCount/" . count($records) . " records inserted into $tableName");
    
    return [
        'success' => count($errors) === 0,
        'data' => [
            'inserted_count' => $insertedCount,
            'total_records' => count($records),
            'errors' => $errors,
            'table' => $tableName
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Batch insert failed: ' . $e->getMessage()
    ];
}
```

---

## Example 9: Search with Pagination

Search with filtering and pagination:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$searchFields = $process_input['result']['search_fields'] ?? [];
$searchTerm = $process_input['result']['search_term'] ?? '';
$filters = $process_input['result']['filters'] ?? [];
$page = max(1, (int)($process_input['result']['page'] ?? 1));
$perPage = min(100, max(1, (int)($process_input['result']['per_page'] ?? 25)));
$orderBy = $process_input['result']['order_by'] ?? 'created_at';
$orderDir = strtoupper($process_input['result']['order_dir'] ?? 'DESC');

if (empty($tableName)) {
    return ['success' => false, 'error' => 'Table name required'];
}

try {
    $offset = ($page - 1) * $perPage;
    
    // Build WHERE clause
    $whereParts = [];
    $params = [];
    
    // Search term across multiple fields
    if (!empty($searchTerm) && !empty($searchFields)) {
        $searchParts = [];
        foreach ($searchFields as $field) {
            $searchParts[] = "`$field` LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }
        $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
    }
    
    // Exact filters
    foreach ($filters as $field => $value) {
        if ($value === null) {
            $whereParts[] = "`$field` IS NULL";
        } elseif (is_array($value)) {
            $placeholders = implode(',', array_fill(0, count($value), '?'));
            $whereParts[] = "`$field` IN ($placeholders)";
            $params = array_merge($params, $value);
        } else {
            $whereParts[] = "`$field` = ?";
            $params[] = $value;
        }
    }
    
    $whereClause = !empty($whereParts) ? ' WHERE ' . implode(' AND ', $whereParts) : '';
    
    // Count total
    $countQuery = "SELECT COUNT(*) as total FROM `$tableName`$whereClause";
    $countResult = $tenantDb->query($countQuery, $params);
    $total = $countResult[0]['total'] ?? 0;
    
    // Fetch page
    $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';
    $query = "SELECT * FROM `$tableName`$whereClause ORDER BY `$orderBy` $orderDir LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    $records = $tenantDb->query($query, $params);
    
    $totalPages = ceil($total / $perPage);
    
    return [
        'success' => true,
        'data' => [
            'records' => $records,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_previous' => $page > 1
            ]
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Search failed: ' . $e->getMessage()
    ];
}
```

---

## Example 10: Upsert (Insert or Update)

Insert or update based on unique key:

```php
<?php
$tableName = $process_input['result']['table'] ?? '';
$data = $process_input['result']['data'] ?? [];
$uniqueKeys = $process_input['result']['unique_keys'] ?? [];

if (empty($tableName) || empty($data) || empty($uniqueKeys)) {
    return ['success' => false, 'error' => 'Table, data, and unique_keys required'];
}

try {
    // Build conditions from unique keys
    $conditions = [];
    foreach ($uniqueKeys as $key) {
        if (!isset($data[$key])) {
            return ['success' => false, 'error' => "Missing unique key value: $key"];
        }
        $conditions[$key] = $data[$key];
    }
    
    // Check if record exists
    $existing = $tenantDb->select($tableName, $conditions, ['limit' => 1]);
    
    if (!empty($existing)) {
        // Update existing record
        $affectedRows = $tenantDb->update($tableName, $data, $conditions);
        
        $log_message("[INFO] Updated existing record in $tableName");
        
        return [
            'success' => true,
            'data' => [
                'action' => 'update',
                'affected_rows' => $affectedRows,
                'table' => $tableName
            ]
        ];
    } else {
        // Insert new record
        $insertId = $tenantDb->insert($tableName, $data);
        
        $log_message("[INFO] Inserted new record into $tableName with ID: $insertId");
        
        return [
            'success' => true,
            'data' => [
                'action' => 'insert',
                'insert_id' => $insertId,
                'table' => $tableName
            ]
        ];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Upsert failed: ' . $e->getMessage()
    ];
}
```

---

## Security Considerations

### ⚠️ Important Security Notes

1. **Always use $tenantDb** - It automatically adds tenant_id to all queries
2. **Use parameterized queries** - Never concatenate user input into SQL
3. **Validate table names** - Whitelist allowed tables if accepting dynamic input
4. **Limit query scope** - Always use conditions to limit affected rows

```php
// ✅ GOOD - Parameterized
$tenantDb->query("SELECT * FROM users WHERE email = ?", [$email]);

// ❌ BAD - SQL Injection risk
$tenantDb->query("SELECT * FROM users WHERE email = '$email'");
```

### Allowed Tables

The tenant database service only allows queries on tenant-scoped tables. System tables like `Tenants`, `SubscriptionPlans` are protected.

---

## Best Practices

### 1. Handle Errors Gracefully
```php
try {
    $result = $tenantDb->query($sql, $params);
} catch (\Exception $e) {
    $log_message('[ERROR] ' . $e->getMessage());
    return ['success' => false, 'error' => $e->getMessage()];
}
```

### 2. Log Important Operations
```php
$log_message("[INFO] Inserted record ID: $insertId into $tableName");
```

### 3. Use Transactions for Multi-Step Operations
```php
// Note: Transaction support via tenantDb may be limited
// For complex transactions, coordinate via process steps
```

### 4. Validate Input Before Queries
```php
if (empty($tableName) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
    return ['success' => false, 'error' => 'Invalid table name'];
}
```

---

## See Also

- [Integration Connector Usage](Integration_Connectors.md)
- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
