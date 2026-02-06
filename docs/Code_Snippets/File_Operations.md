# File Upload & Download

This guide covers file operations within ProcessFlow code snippets using tenant-scoped file functions. **This document is the authoritative reference for tenant file operations in this repository.** The platform injects closure variables (e.g. `$file_get_contents`, `$file_put_contents`, `$mkdir`)—use the `$` prefix; native PHP names are not available in the snippet sandbox.

## Overview

ProcessFlow provides tenant-scoped file operations through closure variables. These functions automatically handle path validation and ensure files are stored within the tenant's directory.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Code Snippet   │────▶│  Tenant-Scoped   │────▶│  Tenant Files   │
│  (File Ops)     │     │  File Functions  │     │  Directory      │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## ⚠️ Important: Function Prefix Syntax

File operations in ProcessFlow use **closure variables** with `$` prefix:

| ✅ Correct Syntax | ❌ Incorrect Syntax |
|------------------|---------------------|
| `$file_put_contents(...)` | `file_put_contents(...)` |
| `$mkdir(...)` | `mkdir(...)` |
| `$is_dir(...)` | `is_dir(...)` |

## Available File Functions

| Function | Description |
|----------|-------------|
| `$file_put_contents($path, $content)` | Write content to file |
| `$file_get_contents($path)` | Read file content |
| `$mkdir($path, $mode, $recursive)` | Create directory |
| `$file_exists($path)` | Check if file exists |
| `$is_dir($path)` | Check if path is directory |
| `$is_file($path)` | Check if path is file |
| `$unlink($path)` | Delete file |
| `$copy($source, $dest)` | Copy file |
| `$rename($old, $new)` | Rename/move file |
| `$scandir($path)` | List directory contents |
| `$chmod($path, $mode)` | Change permissions |
| `$filesize($path)` | Get file size |
| `$filemtime($path)` | Get modification time |

### Native PHP Functions (No Prefix)

These work without the `$` prefix:

| Function | Description |
|----------|-------------|
| `pathinfo($path)` | Get path information |
| `basename($path)` | Get filename |
| `dirname($path)` | Get directory name |
| `json_encode($data)` | Encode JSON |
| `json_decode($string)` | Decode JSON |

---

## Example 1: Write File

Write content to a tenant file:

```php
<?php
$filename = $process_input['result']['filename'] ?? '';
$content = $process_input['result']['content'] ?? '';
$directory = $process_input['result']['directory'] ?? 'uploads';

if (empty($filename) || $content === '') {
    return ['success' => false, 'error' => 'Filename and content required'];
}

// Sanitize filename
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

// Build path (relative to tenant directory)
$path = $directory . '/' . $filename;

try {
    // Ensure directory exists
    if (!$is_dir($directory)) {
        $mkdir($directory, 0755, true);
    }
    
    // Write file
    $bytesWritten = $file_put_contents($path, $content);
    
    if ($bytesWritten === false) {
        return [
            'success' => false,
            'error' => 'Failed to write file'
        ];
    }
    
    $log_message("[INFO] File written: $path ($bytesWritten bytes)");
    
    return [
        'success' => true,
        'data' => [
            'path' => $path,
            'filename' => $filename,
            'bytes_written' => $bytesWritten,
            'directory' => $directory
        ]
    ];
} catch (\Exception $e) {
    $log_message("[ERROR] File write failed: " . $e->getMessage());
    
    return [
        'success' => false,
        'error' => 'File write error: ' . $e->getMessage()
    ];
}
```

---

## Example 2: Read File

Read content from a tenant file:

```php
<?php
$path = $process_input['result']['path'] ?? '';

if (empty($path)) {
    return ['success' => false, 'error' => 'File path required'];
}

try {
    // Check file exists
    if (!$file_exists($path)) {
        return [
            'success' => false,
            'error' => 'File not found: ' . $path
        ];
    }
    
    // Check it's a file (not directory)
    if (!$is_file($path)) {
        return [
            'success' => false,
            'error' => 'Path is not a file: ' . $path
        ];
    }
    
    // Read content
    $content = $file_get_contents($path);
    
    if ($content === false) {
        return [
            'success' => false,
            'error' => 'Failed to read file'
        ];
    }
    
    // Get file info
    $info = pathinfo($path);
    
    return [
        'success' => true,
        'data' => [
            'path' => $path,
            'filename' => $info['basename'],
            'extension' => $info['extension'] ?? '',
            'content' => $content,
            'size' => strlen($content),
            'modified_at' => date('Y-m-d H:i:s', $filemtime($path))
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'File read error: ' . $e->getMessage()
    ];
}
```

---

## Example 3: JSON File Operations

Read and write JSON files:

```php
<?php
$operation = $process_input['result']['operation'] ?? 'read'; // read, write, update
$path = $process_input['result']['path'] ?? '';
$data = $process_input['result']['data'] ?? null;

if (empty($path)) {
    return ['success' => false, 'error' => 'File path required'];
}

// Ensure .json extension
if (!str_ends_with($path, '.json')) {
    $path .= '.json';
}

try {
    switch ($operation) {
        case 'read':
            if (!$file_exists($path)) {
                return ['success' => false, 'error' => 'File not found'];
            }
            
            $content = $file_get_contents($path);
            $decoded = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'Invalid JSON: ' . json_last_error_msg()
                ];
            }
            
            return [
                'success' => true,
                'data' => ['content' => $decoded, 'path' => $path]
            ];
            
        case 'write':
            if ($data === null) {
                return ['success' => false, 'error' => 'Data required for write'];
            }
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $directory = dirname($path);
            
            if ($directory !== '.' && !$is_dir($directory)) {
                $mkdir($directory, 0755, true);
            }
            
            $bytes = $file_put_contents($path, $json);
            
            return [
                'success' => $bytes !== false,
                'data' => ['path' => $path, 'bytes_written' => $bytes]
            ];
            
        case 'update':
            // Read existing, merge, write back
            $existing = [];
            if ($file_exists($path)) {
                $content = $file_get_contents($path);
                $existing = json_decode($content, true) ?? [];
            }
            
            $merged = array_merge($existing, $data ?? []);
            $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            $directory = dirname($path);
            if ($directory !== '.' && !$is_dir($directory)) {
                $mkdir($directory, 0755, true);
            }
            
            $bytes = $file_put_contents($path, $json);
            
            return [
                'success' => $bytes !== false,
                'data' => [
                    'path' => $path,
                    'merged_content' => $merged,
                    'bytes_written' => $bytes
                ]
            ];
            
        default:
            return ['success' => false, 'error' => 'Unknown operation'];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'JSON file operation failed: ' . $e->getMessage()
    ];
}
```

---

## Example 4: Directory Operations

Create and list directories:

```php
<?php
$operation = $process_input['result']['operation'] ?? 'list'; // list, create, delete
$path = $process_input['result']['path'] ?? '';
$recursive = $process_input['result']['recursive'] ?? false;

if (empty($path)) {
    return ['success' => false, 'error' => 'Path required'];
}

try {
    switch ($operation) {
        case 'list':
            if (!$is_dir($path)) {
                return ['success' => false, 'error' => 'Directory not found'];
            }
            
            $items = $scandir($path);
            $files = [];
            $directories = [];
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $itemPath = $path . '/' . $item;
                
                if ($is_dir($itemPath)) {
                    $directories[] = [
                        'name' => $item,
                        'path' => $itemPath,
                        'type' => 'directory'
                    ];
                } else {
                    $files[] = [
                        'name' => $item,
                        'path' => $itemPath,
                        'type' => 'file',
                        'size' => $filesize($itemPath),
                        'modified' => date('Y-m-d H:i:s', $filemtime($itemPath))
                    ];
                }
            }
            
            return [
                'success' => true,
                'data' => [
                    'path' => $path,
                    'directories' => $directories,
                    'files' => $files,
                    'total_items' => count($directories) + count($files)
                ]
            ];
            
        case 'create':
            if ($is_dir($path)) {
                return [
                    'success' => true,
                    'data' => ['path' => $path, 'already_exists' => true]
                ];
            }
            
            $result = $mkdir($path, 0755, $recursive);
            
            return [
                'success' => $result,
                'data' => ['path' => $path, 'created' => $result]
            ];
            
        case 'delete':
            // Only delete empty directories for safety
            if (!$is_dir($path)) {
                return ['success' => false, 'error' => 'Directory not found'];
            }
            
            $items = $scandir($path);
            $items = array_filter($items, fn($i) => $i !== '.' && $i !== '..');
            
            if (!empty($items)) {
                return [
                    'success' => false,
                    'error' => 'Directory not empty',
                    'data' => ['item_count' => count($items)]
                ];
            }
            
            // Note: rmdir may need to be a closure variable if available
            // For now, indicate manual deletion needed
            return [
                'success' => false,
                'error' => 'Directory deletion requires manual action'
            ];
            
        default:
            return ['success' => false, 'error' => 'Unknown operation'];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'Directory operation failed: ' . $e->getMessage()
    ];
}
```

---

## Example 5: CSV File Export

Export data to CSV file:

```php
<?php
$records = $process_input['result']['records'] ?? [];
$filename = $process_input['result']['filename'] ?? 'export.csv';
$directory = $process_input['result']['directory'] ?? 'exports';
$headers = $process_input['result']['headers'] ?? null;

if (empty($records)) {
    return ['success' => false, 'error' => 'No records to export'];
}

// Sanitize filename
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
if (!str_ends_with($filename, '.csv')) {
    $filename .= '.csv';
}

// Auto-detect headers from first record
if ($headers === null && !empty($records)) {
    $headers = array_keys($records[0]);
}

try {
    // Ensure directory exists
    if (!$is_dir($directory)) {
        $mkdir($directory, 0755, true);
    }
    
    $path = $directory . '/' . $filename;
    
    // Build CSV content
    $lines = [];
    
    // Add headers
    $lines[] = implode(',', array_map(function($h) {
        return '"' . str_replace('"', '""', $h) . '"';
    }, $headers));
    
    // Add data rows
    foreach ($records as $record) {
        $row = [];
        foreach ($headers as $header) {
            $value = $record[$header] ?? '';
            // Escape and quote
            $row[] = '"' . str_replace('"', '""', $value) . '"';
        }
        $lines[] = implode(',', $row);
    }
    
    $csvContent = implode("\n", $lines);
    $bytes = $file_put_contents($path, $csvContent);
    
    $log_message("[INFO] CSV exported: $path (" . count($records) . " records)");
    
    return [
        'success' => $bytes !== false,
        'data' => [
            'path' => $path,
            'filename' => $filename,
            'record_count' => count($records),
            'bytes_written' => $bytes
        ]
    ];
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'CSV export failed: ' . $e->getMessage()
    ];
}
```

---

## Example 6: File Copy and Move

Copy or move files:

```php
<?php
$operation = $process_input['result']['operation'] ?? 'copy'; // copy, move
$sourcePath = $process_input['result']['source'] ?? '';
$destPath = $process_input['result']['destination'] ?? '';
$overwrite = $process_input['result']['overwrite'] ?? false;

if (empty($sourcePath) || empty($destPath)) {
    return ['success' => false, 'error' => 'Source and destination required'];
}

try {
    // Verify source exists
    if (!$file_exists($sourcePath)) {
        return ['success' => false, 'error' => 'Source file not found'];
    }
    
    // Check destination
    if ($file_exists($destPath) && !$overwrite) {
        return [
            'success' => false,
            'error' => 'Destination already exists. Set overwrite=true to replace.'
        ];
    }
    
    // Ensure destination directory exists
    $destDir = dirname($destPath);
    if ($destDir !== '.' && !$is_dir($destDir)) {
        $mkdir($destDir, 0755, true);
    }
    
    // Perform operation
    if ($operation === 'copy') {
        $result = $copy($sourcePath, $destPath);
        $action = 'copied';
    } else { // move
        $result = $rename($sourcePath, $destPath);
        $action = 'moved';
    }
    
    if ($result) {
        $log_message("[INFO] File $action: $sourcePath -> $destPath");
        
        return [
            'success' => true,
            'data' => [
                'operation' => $operation,
                'source' => $sourcePath,
                'destination' => $destPath,
                'size' => $filesize($destPath)
            ]
        ];
    } else {
        return [
            'success' => false,
            'error' => "Failed to $operation file"
        ];
    }
} catch (\Exception $e) {
    return [
        'success' => false,
        'error' => 'File operation failed: ' . $e->getMessage()
    ];
}
```

---

## Example 7: Process Attachments

Save attachments from process input:

```php
<?php
$attachments = $process_input['result']['attachments'] ?? [];
$directory = $process_input['result']['directory'] ?? 'attachments/' . date('Y/m/d');

if (empty($attachments)) {
    return [
        'success' => true,
        'data' => ['saved' => [], 'message' => 'No attachments to process']
    ];
}

// Ensure directory exists
if (!$is_dir($directory)) {
    $mkdir($directory, 0755, true);
}

$saved = [];
$failed = [];

foreach ($attachments as $index => $attachment) {
    $filename = $attachment['filename'] ?? "attachment_$index";
    $content = $attachment['content'] ?? '';
    $isBase64 = $attachment['is_base64'] ?? false;
    
    // Sanitize filename
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    
    // Decode base64 if needed
    if ($isBase64) {
        $content = base64_decode($content);
        if ($content === false) {
            $failed[] = [
                'filename' => $filename,
                'error' => 'Invalid base64 content'
            ];
            continue;
        }
    }
    
    $path = $directory . '/' . $filename;
    
    // Handle duplicate filenames
    $counter = 1;
    $originalPath = $path;
    while ($file_exists($path)) {
        $info = pathinfo($originalPath);
        $path = $info['dirname'] . '/' . $info['filename'] . "_$counter";
        if (isset($info['extension'])) {
            $path .= '.' . $info['extension'];
        }
        $counter++;
    }
    
    try {
        $bytes = $file_put_contents($path, $content);
        
        if ($bytes !== false) {
            $saved[] = [
                'original_filename' => $attachment['filename'] ?? "attachment_$index",
                'saved_path' => $path,
                'size' => $bytes
            ];
        } else {
            $failed[] = [
                'filename' => $filename,
                'error' => 'Write failed'
            ];
        }
    } catch (\Exception $e) {
        $failed[] = [
            'filename' => $filename,
            'error' => $e->getMessage()
        ];
    }
}

$log_message(sprintf(
    "[INFO] Attachments processed: %d saved, %d failed",
    count($saved),
    count($failed)
));

return [
    'success' => count($failed) === 0,
    'data' => [
        'saved' => $saved,
        'failed' => $failed,
        'total' => count($attachments),
        'directory' => $directory
    ]
];
```

---

## Best Practices

### 1. Always Check File Existence
```php
if (!$file_exists($path)) {
    return ['success' => false, 'error' => 'File not found'];
}
```

### 2. Sanitize Filenames
```php
$filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
```

### 3. Create Directories Before Writing
```php
if (!$is_dir($directory)) {
    $mkdir($directory, 0755, true);
}
```

### 4. Log File Operations
```php
$log_message("[INFO] File written: $path ($bytes bytes)");
```

---

## Security Considerations

| ⚠️ Warning | Solution |
|------------|----------|
| Path traversal attacks | Paths are automatically validated by sandbox |
| Sensitive file access | Only tenant directory accessible |
| File size limits | Check size before operations |
| Filename injection | Sanitize user-provided filenames |

---

## See Also

- [Email MIME Parsing](Email_MIME_Parsing.md)
- [Batch Data Import/Export](Batch_Import_Export.md)
- [Integration Connectors](Integration_Connectors.md)
