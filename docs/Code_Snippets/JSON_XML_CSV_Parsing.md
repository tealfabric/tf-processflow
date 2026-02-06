# JSON/XML/CSV Parsing

This guide covers parsing and generating JSON, XML, and CSV data within ProcessFlow code snippets.

## Overview

Data format parsing is essential for integrations, imports, and data exchange. These are **idempotent operations** that convert between formats.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  JSON/XML/CSV   │────▶│  Parse &         │────▶│  PHP Arrays /   │
│  Input          │     │  Transform       │     │  Output Format  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available Functions

| Format | Parse | Generate |
|--------|-------|----------|
| JSON | `json_decode` | `json_encode` |
| XML | `simplexml_load_string` | SimpleXML methods |
| CSV | `str_getcsv`, `fgetcsv` | `fputcsv`, manual |

---

## JSON Operations

### Example 1: JSON Parsing with Error Handling

```php
<?php
$jsonString = $process_input['result']['json'] ?? '';
$options = $process_input['result']['options'] ?? [];

$result = [
    'success' => false,
    'data' => null,
    'error' => null,
    'metadata' => []
];

if (empty($jsonString)) {
    $result['error'] = 'No JSON input provided';
    return ['success' => false, 'data' => $result];
}

// Decode options
$assoc = $options['as_array'] ?? true;
$depth = $options['max_depth'] ?? 512;
$flags = 0;

if ($options['big_int_as_string'] ?? false) {
    $flags |= JSON_BIGINT_AS_STRING;
}

// Attempt decode
$decoded = json_decode($jsonString, $assoc, $depth, $flags);
$error = json_last_error();

if ($error !== JSON_ERROR_NONE) {
    $errorMessages = [
        JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
        JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
        JSON_ERROR_CTRL_CHAR => 'Control character error',
        JSON_ERROR_SYNTAX => 'Syntax error',
        JSON_ERROR_UTF8 => 'Malformed UTF-8 characters'
    ];
    
    $result['error'] = $errorMessages[$error] ?? 'Unknown JSON error';
    $result['error_code'] = $error;
    
    // Try to identify error location
    if ($error === JSON_ERROR_SYNTAX) {
        // Find approximate error position
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $jsonString);
        json_decode($cleaned);
        if (json_last_error() === JSON_ERROR_NONE) {
            $result['error'] .= ' (likely control characters in input)';
        }
    }
    
    return ['success' => false, 'data' => $result];
}

$result['success'] = true;
$result['data'] = $decoded;
$result['metadata'] = [
    'type' => gettype($decoded),
    'count' => is_array($decoded) ? count($decoded) : null,
    'keys' => is_array($decoded) ? array_keys($decoded) : null,
    'size_bytes' => strlen($jsonString)
];

return ['success' => true, 'data' => $result];
```

### Example 2: JSON Generation with Options

```php
<?php
$data = $process_input['result']['data'] ?? [];
$options = $process_input['result']['options'] ?? [];

$flags = 0;

// Build flags based on options
if ($options['pretty_print'] ?? false) {
    $flags |= JSON_PRETTY_PRINT;
}
if ($options['escape_unicode'] ?? false) {
    $flags |= JSON_UNESCAPED_UNICODE;
}
if ($options['escape_slashes'] ?? true) {
    // Default behavior
} else {
    $flags |= JSON_UNESCAPED_SLASHES;
}
if ($options['preserve_zero_fraction'] ?? false) {
    $flags |= JSON_PRESERVE_ZERO_FRACTION;
}
if ($options['numeric_check'] ?? false) {
    $flags |= JSON_NUMERIC_CHECK;
}

$depth = $options['max_depth'] ?? 512;

$json = json_encode($data, $flags, $depth);

if ($json === false) {
    return [
        'success' => false,
        'error' => 'JSON encoding failed: ' . json_last_error_msg()
    ];
}

return [
    'success' => true,
    'data' => [
        'json' => $json,
        'size_bytes' => strlen($json),
        'flags_used' => $flags
    ]
];
```

### Example 3: JSON Path Query

```php
<?php
/**
 * Simple JSON path query implementation
 * Supports: $.field, $.nested.field, $.array[0], $.array[*].field
 */

$data = $process_input['result']['data'] ?? [];
$path = $process_input['result']['path'] ?? '$';

if (is_string($data)) {
    $data = json_decode($data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON'];
    }
}

// Parse path
$path = ltrim($path, '$.');
if (empty($path)) {
    return ['success' => true, 'data' => ['result' => $data]];
}

$parts = preg_split('/\.(?![^\[]*\])/', $path);
$result = $data;

foreach ($parts as $part) {
    if ($result === null) break;
    
    // Handle array notation: field[0] or field[*]
    if (preg_match('/^(\w+)\[(\d+|\*)\]$/', $part, $matches)) {
        $field = $matches[1];
        $index = $matches[2];
        
        if (!isset($result[$field])) {
            $result = null;
            break;
        }
        
        if ($index === '*') {
            // Get all items
            $result = $result[$field];
        } else {
            // Get specific index
            $result = $result[$field][(int)$index] ?? null;
        }
    } else {
        // Simple field access
        $result = $result[$part] ?? null;
    }
}

return [
    'success' => true,
    'data' => [
        'path' => $path,
        'result' => $result,
        'found' => $result !== null
    ]
];
```

---

## XML Operations

### Example 4: XML Parsing

```php
<?php
$xmlString = $process_input['result']['xml'] ?? '';
$options = $process_input['result']['options'] ?? [];

if (empty($xmlString)) {
    return ['success' => false, 'error' => 'No XML input'];
}

// Suppress XML errors for custom handling
libxml_use_internal_errors(true);

$flags = LIBXML_NOCDATA;
if ($options['remove_whitespace'] ?? true) {
    $flags |= LIBXML_NOBLANKS;
}

$xml = simplexml_load_string($xmlString, 'SimpleXMLElement', $flags);

if ($xml === false) {
    $errors = libxml_get_errors();
    $errorMessages = array_map(function($e) {
        return trim($e->message) . " at line {$e->line}";
    }, $errors);
    libxml_clear_errors();
    
    return [
        'success' => false,
        'error' => 'XML parsing failed',
        'details' => $errorMessages
    ];
}

// Convert to array
$xmlToArray = function($xml) use (&$xmlToArray) {
    $result = [];
    
    // Get attributes
    $attributes = [];
    foreach ($xml->attributes() as $key => $value) {
        $attributes['@' . $key] = (string)$value;
    }
    if (!empty($attributes)) {
        $result = array_merge($result, $attributes);
    }
    
    // Get children
    $children = [];
    foreach ($xml->children() as $name => $child) {
        $childData = $xmlToArray($child);
        
        if (isset($children[$name])) {
            if (!is_array($children[$name]) || !isset($children[$name][0])) {
                $children[$name] = [$children[$name]];
            }
            $children[$name][] = $childData;
        } else {
            $children[$name] = $childData;
        }
    }
    
    if (!empty($children)) {
        $result = array_merge($result, $children);
    }
    
    // Get text content
    $text = trim((string)$xml);
    if (!empty($text)) {
        if (empty($result)) {
            return $text;
        }
        $result['#text'] = $text;
    }
    
    return empty($result) ? '' : $result;
};

$data = $xmlToArray($xml);

return [
    'success' => true,
    'data' => [
        'parsed' => $data,
        'root_element' => $xml->getName(),
        'namespaces' => $xml->getNamespaces(true)
    ]
];
```

### Example 5: XML Generation

```php
<?php
$data = $process_input['result']['data'] ?? [];
$rootElement = $process_input['result']['root'] ?? 'root';
$options = $process_input['result']['options'] ?? [];

$arrayToXml = function($data, &$xml) use (&$arrayToXml) {
    foreach ($data as $key => $value) {
        // Handle attributes
        if (strpos($key, '@') === 0) {
            $xml->addAttribute(substr($key, 1), $value);
            continue;
        }
        
        // Handle text content
        if ($key === '#text') {
            // Text content is handled at parent level
            continue;
        }
        
        // Handle numeric keys (repeated elements)
        if (is_numeric($key)) {
            $key = 'item';
        }
        
        // Sanitize element name
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        if (is_numeric($key[0])) {
            $key = '_' . $key;
        }
        
        if (is_array($value)) {
            // Check if it's a list of items
            if (isset($value[0])) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $child = $xml->addChild($key);
                        $arrayToXml($item, $child);
                    } else {
                        $xml->addChild($key, htmlspecialchars($item));
                    }
                }
            } else {
                $child = $xml->addChild($key, isset($value['#text']) ? htmlspecialchars($value['#text']) : null);
                $arrayToXml($value, $child);
            }
        } else {
            $xml->addChild($key, htmlspecialchars((string)$value));
        }
    }
};

// Create XML
$xmlDeclaration = $options['include_declaration'] ?? true 
    ? '<?xml version="1.0" encoding="UTF-8"?>' 
    : '';

$xml = new \SimpleXMLElement("<{$rootElement}/>");
$arrayToXml($data, $xml);

$xmlString = $xml->asXML();

// Format if requested
if ($options['pretty_print'] ?? false) {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xmlString);
    $xmlString = $dom->saveXML();
}

if (!$xmlDeclaration) {
    $xmlString = preg_replace('/<\?xml[^>]*\?>/', '', $xmlString);
}

return [
    'success' => true,
    'data' => [
        'xml' => trim($xmlString),
        'size_bytes' => strlen($xmlString)
    ]
];
```

---

## CSV Operations

### Example 6: CSV Parsing

```php
<?php
$csvString = $process_input['result']['csv'] ?? '';
$options = $process_input['result']['options'] ?? [];

$delimiter = $options['delimiter'] ?? ',';
$enclosure = $options['enclosure'] ?? '"';
$escape = $options['escape'] ?? '\\';
$hasHeaders = $options['has_headers'] ?? true;
$skipEmpty = $options['skip_empty_lines'] ?? true;

if (empty($csvString)) {
    return ['success' => false, 'error' => 'No CSV input'];
}

// Split into lines
$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $csvString));

$data = [];
$headers = [];
$rowCount = 0;
$errors = [];

foreach ($lines as $lineNum => $line) {
    // Skip empty lines
    if ($skipEmpty && trim($line) === '') {
        continue;
    }
    
    // Parse CSV line
    $row = str_getcsv($line, $delimiter, $enclosure, $escape);
    
    // Handle headers
    if ($hasHeaders && empty($headers)) {
        $headers = array_map('trim', $row);
        continue;
    }
    
    // Map to headers or use numeric keys
    if (!empty($headers)) {
        if (count($row) !== count($headers)) {
            $errors[] = "Line " . ($lineNum + 1) . ": Column count mismatch";
            // Pad or trim to match
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), null);
            } else {
                $row = array_slice($row, 0, count($headers));
            }
        }
        $row = array_combine($headers, $row);
    }
    
    $data[] = $row;
    $rowCount++;
}

return [
    'success' => true,
    'data' => [
        'records' => $data,
        'headers' => $headers,
        'row_count' => $rowCount,
        'column_count' => count($headers) ?: (isset($data[0]) ? count($data[0]) : 0),
        'errors' => $errors
    ]
];
```

### Example 7: CSV Generation

```php
<?php
$records = $process_input['result']['records'] ?? [];
$options = $process_input['result']['options'] ?? [];

$delimiter = $options['delimiter'] ?? ',';
$enclosure = $options['enclosure'] ?? '"';
$includeHeaders = $options['include_headers'] ?? true;
$headers = $options['headers'] ?? null;

if (empty($records)) {
    return [
        'success' => true,
        'data' => [
            'csv' => '',
            'row_count' => 0
        ]
    ];
}

// Auto-detect headers from first record
if ($headers === null && $includeHeaders) {
    $firstRecord = reset($records);
    if (is_array($firstRecord)) {
        $headers = array_keys($firstRecord);
    }
}

$lines = [];

// Add headers
if ($includeHeaders && $headers) {
    $lines[] = implode($delimiter, array_map(function($h) use ($enclosure) {
        return $enclosure . str_replace($enclosure, $enclosure . $enclosure, $h) . $enclosure;
    }, $headers));
}

// Add data rows
foreach ($records as $record) {
    if ($headers) {
        // Ensure consistent column order
        $row = [];
        foreach ($headers as $header) {
            $row[] = $record[$header] ?? '';
        }
    } else {
        $row = array_values($record);
    }
    
    // Escape and quote values
    $escapedRow = array_map(function($value) use ($enclosure, $delimiter) {
        $value = (string)$value;
        
        // Check if quoting needed
        $needsQuotes = strpos($value, $delimiter) !== false ||
                       strpos($value, $enclosure) !== false ||
                       strpos($value, "\n") !== false ||
                       strpos($value, "\r") !== false;
        
        if ($needsQuotes) {
            $value = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $value) . $enclosure;
        }
        
        return $value;
    }, $row);
    
    $lines[] = implode($delimiter, $escapedRow);
}

$csv = implode("\n", $lines);

return [
    'success' => true,
    'data' => [
        'csv' => $csv,
        'row_count' => count($records),
        'column_count' => count($headers ?? []),
        'size_bytes' => strlen($csv)
    ]
];
```

### Example 8: CSV to JSON Conversion

```php
<?php
$csvString = $process_input['result']['csv'] ?? '';
$options = $process_input['result']['options'] ?? [];

$delimiter = $options['delimiter'] ?? ',';
$hasHeaders = $options['has_headers'] ?? true;
$typeCast = $options['type_cast'] ?? true;

// Parse CSV
$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $csvString));
$lines = array_filter($lines, function($l) { return trim($l) !== ''; });

if (empty($lines)) {
    return ['success' => true, 'data' => ['json' => '[]', 'records' => []]];
}

$headers = [];
$records = [];

foreach ($lines as $index => $line) {
    $row = str_getcsv($line, $delimiter);
    
    if ($hasHeaders && $index === 0) {
        $headers = array_map('trim', $row);
        continue;
    }
    
    // Type casting
    if ($typeCast) {
        $row = array_map(function($value) {
            $trimmed = trim($value);
            
            // Check for boolean
            if (strtolower($trimmed) === 'true') return true;
            if (strtolower($trimmed) === 'false') return false;
            
            // Check for null
            if ($trimmed === '' || strtolower($trimmed) === 'null') return null;
            
            // Check for integer
            if (preg_match('/^-?\d+$/', $trimmed)) {
                return (int)$trimmed;
            }
            
            // Check for float
            if (preg_match('/^-?\d+\.\d+$/', $trimmed)) {
                return (float)$trimmed;
            }
            
            return $trimmed;
        }, $row);
    }
    
    // Map to headers
    if (!empty($headers)) {
        $record = [];
        foreach ($headers as $i => $header) {
            $record[$header] = $row[$i] ?? null;
        }
        $records[] = $record;
    } else {
        $records[] = $row;
    }
}

$json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

return [
    'success' => true,
    'data' => [
        'json' => $json,
        'records' => $records,
        'record_count' => count($records),
        'headers' => $headers
    ]
];
```

---

## Format Detection and Conversion

### Example 9: Auto-Detect Format

```php
<?php
$input = $process_input['result']['input'] ?? '';

$result = [
    'detected_format' => null,
    'confidence' => 0,
    'parsed' => null
];

$input = trim($input);

if (empty($input)) {
    return ['success' => false, 'error' => 'No input provided'];
}

// Try JSON
$jsonData = json_decode($input, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $result['detected_format'] = 'json';
    $result['confidence'] = 100;
    $result['parsed'] = $jsonData;
    return ['success' => true, 'data' => $result];
}

// Try XML
libxml_use_internal_errors(true);
$xml = simplexml_load_string($input);
if ($xml !== false) {
    // Convert to array (simplified)
    $result['detected_format'] = 'xml';
    $result['confidence'] = 100;
    $result['parsed'] = json_decode(json_encode($xml), true);
    libxml_clear_errors();
    return ['success' => true, 'data' => $result];
}
libxml_clear_errors();

// Try CSV
$lines = explode("\n", str_replace("\r\n", "\n", $input));
$lines = array_filter($lines, function($l) { return trim($l) !== ''; });

if (count($lines) > 0) {
    // Check for consistent column count
    $delimiters = [',', ';', "\t", '|'];
    $bestDelimiter = ',';
    $bestScore = 0;
    
    foreach ($delimiters as $delimiter) {
        $firstRow = str_getcsv($lines[0], $delimiter);
        $columnCount = count($firstRow);
        
        if ($columnCount < 2) continue;
        
        $consistentRows = 0;
        foreach ($lines as $line) {
            $row = str_getcsv($line, $delimiter);
            if (count($row) === $columnCount) {
                $consistentRows++;
            }
        }
        
        $score = ($consistentRows / count($lines)) * 100;
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestDelimiter = $delimiter;
        }
    }
    
    if ($bestScore > 70) {
        $result['detected_format'] = 'csv';
        $result['confidence'] = round($bestScore);
        $result['delimiter'] = $bestDelimiter;
        
        // Parse CSV
        $headers = str_getcsv($lines[0], $bestDelimiter);
        $records = [];
        for ($i = 1; $i < count($lines); $i++) {
            $row = str_getcsv($lines[$i], $bestDelimiter);
            $records[] = array_combine($headers, array_pad($row, count($headers), null));
        }
        $result['parsed'] = $records;
        
        return ['success' => true, 'data' => $result];
    }
}

// Unknown format
$result['detected_format'] = 'unknown';
$result['confidence'] = 0;
$result['raw'] = $input;

return ['success' => true, 'data' => $result];
```

### Example 10: Universal Format Converter

```php
<?php
$input = $process_input['result']['input'] ?? '';
$sourceFormat = $process_input['result']['source_format'] ?? 'auto';
$targetFormat = $process_input['result']['target_format'] ?? 'json';
$options = $process_input['result']['options'] ?? [];

// Parse input based on source format
$data = null;
$parseError = null;

switch ($sourceFormat) {
    case 'json':
        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parseError = 'JSON parse error: ' . json_last_error_msg();
        }
        break;
        
    case 'xml':
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($input);
        if ($xml !== false) {
            $data = json_decode(json_encode($xml), true);
        } else {
            $parseError = 'XML parse error';
        }
        libxml_clear_errors();
        break;
        
    case 'csv':
        $delimiter = $options['csv_delimiter'] ?? ',';
        $lines = explode("\n", str_replace("\r\n", "\n", trim($input)));
        $headers = str_getcsv(array_shift($lines), $delimiter);
        $data = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line, $delimiter);
            $data[] = array_combine($headers, array_pad($row, count($headers), null));
        }
        break;
        
    case 'auto':
    default:
        // Try each format
        $data = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            break;
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($input);
        if ($xml !== false) {
            $data = json_decode(json_encode($xml), true);
            libxml_clear_errors();
            break;
        }
        libxml_clear_errors();
        
        // Assume CSV
        $delimiter = ',';
        $lines = explode("\n", str_replace("\r\n", "\n", trim($input)));
        if (count($lines) > 1) {
            $headers = str_getcsv(array_shift($lines), $delimiter);
            $data = [];
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $row = str_getcsv($line, $delimiter);
                $data[] = array_combine($headers, array_pad($row, count($headers), null));
            }
        }
        break;
}

if ($parseError || $data === null) {
    return ['success' => false, 'error' => $parseError ?? 'Could not parse input'];
}

// Generate output
$output = null;

switch ($targetFormat) {
    case 'json':
        $flags = JSON_UNESCAPED_UNICODE;
        if ($options['pretty'] ?? false) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $output = json_encode($data, $flags);
        break;
        
    case 'xml':
        $root = $options['xml_root'] ?? 'root';
        $itemName = $options['xml_item'] ?? 'item';
        
        $xml = new \SimpleXMLElement("<{$root}/>");
        $addToXml = function($xml, $data, $itemName) use (&$addToXml) {
            foreach ($data as $key => $value) {
                if (is_numeric($key)) $key = $itemName;
                $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
                
                if (is_array($value)) {
                    $child = $xml->addChild($key);
                    $addToXml($child, $value, $itemName);
                } else {
                    $xml->addChild($key, htmlspecialchars((string)$value));
                }
            }
        };
        $addToXml($xml, $data, $itemName);
        $output = $xml->asXML();
        break;
        
    case 'csv':
        $delimiter = $options['csv_delimiter'] ?? ',';
        $records = is_array($data) && isset($data[0]) ? $data : [$data];
        
        $headers = array_keys($records[0] ?? []);
        $lines = [implode($delimiter, $headers)];
        
        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $h) {
                $val = (string)($record[$h] ?? '');
                if (strpos($val, $delimiter) !== false || strpos($val, '"') !== false) {
                    $val = '"' . str_replace('"', '""', $val) . '"';
                }
                $row[] = $val;
            }
            $lines[] = implode($delimiter, $row);
        }
        $output = implode("\n", $lines);
        break;
        
    default:
        return ['success' => false, 'error' => 'Unknown target format: ' . $targetFormat];
}

return [
    'success' => true,
    'data' => [
        'output' => $output,
        'target_format' => $targetFormat,
        'record_count' => is_array($data) ? count($data) : 1
    ]
];
```

---

## Best Practices

### 1. Always Handle Parse Errors
```php
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    return ['success' => false, 'error' => json_last_error_msg()];
}
```

### 2. Use Appropriate Encoding
```php
// JSON with proper unicode handling
json_encode($data, JSON_UNESCAPED_UNICODE);

// XML with proper escaping
htmlspecialchars($value, ENT_XML1, 'UTF-8');
```

### 3. Validate Structure After Parsing
```php
if (!isset($data['required_field'])) {
    return ['success' => false, 'error' => 'Missing required field'];
}
```

### 4. Handle Large Files in Chunks
```php
// For large CSV, process line by line if possible
// rather than loading entire file into memory
```

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
- [String Processing & Text](String_Processing_Text.md)
