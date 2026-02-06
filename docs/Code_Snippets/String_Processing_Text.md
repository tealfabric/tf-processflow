# String Processing & Text Manipulation

This guide covers string processing and text manipulation techniques within ProcessFlow code snippets.

## Overview

String processing is essential for data cleaning, formatting, parsing, and content generation. These are **idempotent operations** that transform text input into formatted output.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Raw Text      │────▶│  String Process  │────▶│  Formatted      │
│   Input         │     │  Code Snippet    │     │  Output         │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available PHP String Functions

| Category | Functions |
|----------|-----------|
| **Case** | `strtolower`, `strtoupper`, `ucfirst`, `ucwords`, `lcfirst` |
| **Trim** | `trim`, `ltrim`, `rtrim` |
| **Search** | `strpos`, `stripos`, `strrpos`, `strstr`, `stristr` |
| **Replace** | `str_replace`, `str_ireplace`, `substr_replace`, `preg_replace` |
| **Substring** | `substr`, `mb_substr` |
| **Length** | `strlen`, `mb_strlen` |
| **Split/Join** | `explode`, `implode`, `str_split`, `chunk_split` |
| **Format** | `sprintf`, `printf`, `number_format`, `money_format` |
| **Regex** | `preg_match`, `preg_match_all`, `preg_replace`, `preg_split` |
| **HTML** | `htmlspecialchars`, `htmlentities`, `strip_tags`, `nl2br` |
| **Encoding** | `utf8_encode`, `utf8_decode`, `mb_convert_encoding` |

---

## Example 1: Text Cleaning & Normalization

Clean and normalize user input:

```php
<?php
$input = $process_input['result']['text'] ?? '';

// Clean whitespace
$cleaned = trim($input);
$cleaned = preg_replace('/\s+/', ' ', $cleaned);  // Multiple spaces to single

// Remove special characters (keep alphanumeric, spaces, basic punctuation)
$cleaned = preg_replace('/[^\p{L}\p{N}\s.,!?@#\-_]/u', '', $cleaned);

// Normalize case
$normalized = ucfirst(strtolower($cleaned));

// Truncate if too long
$maxLength = 500;
if (mb_strlen($normalized) > $maxLength) {
    $normalized = mb_substr($normalized, 0, $maxLength - 3) . '...';
}

return [
    'success' => true,
    'data' => [
        'original' => $input,
        'cleaned' => $normalized,
        'original_length' => mb_strlen($input),
        'cleaned_length' => mb_strlen($normalized)
    ]
];
```

---

## Example 2: Name Parsing

Parse full name into components:

```php
<?php
$fullName = trim($process_input['result']['name'] ?? '');

if (empty($fullName)) {
    return ['success' => false, 'error' => 'No name provided'];
}

// Split by whitespace
$parts = preg_split('/\s+/', $fullName);
$partCount = count($parts);

$parsed = [
    'full_name' => $fullName,
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'prefix' => '',
    'suffix' => ''
];

// Common prefixes and suffixes
$prefixes = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof'];
$suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'phd', 'md', 'esq'];

// Check for prefix
if ($partCount > 0 && in_array(strtolower(rtrim($parts[0], '.')), $prefixes)) {
    $parsed['prefix'] = array_shift($parts);
    $partCount--;
}

// Check for suffix
if ($partCount > 0 && in_array(strtolower(rtrim(end($parts), '.')), $suffixes)) {
    $parsed['suffix'] = array_pop($parts);
    $partCount--;
}

// Assign remaining parts
if ($partCount === 1) {
    $parsed['first_name'] = $parts[0];
} elseif ($partCount === 2) {
    $parsed['first_name'] = $parts[0];
    $parsed['last_name'] = $parts[1];
} elseif ($partCount >= 3) {
    $parsed['first_name'] = $parts[0];
    $parsed['last_name'] = array_pop($parts);
    array_shift($parts);
    $parsed['middle_name'] = implode(' ', $parts);
}

// Normalize capitalization
foreach (['first_name', 'middle_name', 'last_name'] as $field) {
    if (!empty($parsed[$field])) {
        $parsed[$field] = ucwords(strtolower($parsed[$field]));
    }
}

return [
    'success' => true,
    'data' => $parsed
];
```

---

## Example 3: Email Validation & Extraction

Extract and validate emails from text:

```php
<?php
$text = $process_input['result']['text'] ?? '';

// Email regex pattern
$emailPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

// Find all emails
preg_match_all($emailPattern, $text, $matches);
$foundEmails = array_unique($matches[0]);

// Validate and categorize
$validEmails = [];
$invalidEmails = [];

foreach ($foundEmails as $email) {
    $email = strtolower(trim($email));
    
    // Additional validation
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Extract domain
        $domain = substr($email, strpos($email, '@') + 1);
        
        $validEmails[] = [
            'email' => $email,
            'domain' => $domain,
            'is_free_email' => in_array($domain, [
                'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com'
            ])
        ];
    } else {
        $invalidEmails[] = $email;
    }
}

return [
    'success' => true,
    'data' => [
        'valid_emails' => $validEmails,
        'invalid_emails' => $invalidEmails,
        'total_found' => count($foundEmails),
        'valid_count' => count($validEmails)
    ]
];
```

---

## Example 4: Phone Number Formatting

Parse and format phone numbers:

```php
<?php
$phone = $process_input['result']['phone'] ?? '';
$countryCode = $process_input['result']['country_code'] ?? 'US';

// Remove all non-numeric characters
$digits = preg_replace('/[^0-9]/', '', $phone);

// Remove leading zeros
$digits = ltrim($digits, '0');

$formatted = [
    'original' => $phone,
    'digits_only' => $digits,
    'formatted' => '',
    'international' => '',
    'valid' => false
];

// Format based on country
switch ($countryCode) {
    case 'US':
    case 'CA':
        // North American format: (XXX) XXX-XXXX
        if (strlen($digits) === 10) {
            $formatted['formatted'] = sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
            $formatted['international'] = '+1' . $digits;
            $formatted['valid'] = true;
        } elseif (strlen($digits) === 11 && $digits[0] === '1') {
            $formatted['formatted'] = sprintf('(%s) %s-%s',
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7, 4)
            );
            $formatted['international'] = '+' . $digits;
            $formatted['valid'] = true;
        }
        break;
        
    case 'FI':
        // Finnish format: 0XX XXX XXXX or +358 XX XXX XXXX
        if (strlen($digits) >= 9 && strlen($digits) <= 10) {
            $formatted['formatted'] = sprintf('%s %s %s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6)
            );
            $formatted['international'] = '+358' . ltrim($digits, '0');
            $formatted['valid'] = true;
        }
        break;
        
    default:
        // Generic international format
        if (strlen($digits) >= 8 && strlen($digits) <= 15) {
            $formatted['formatted'] = $digits;
            $formatted['international'] = '+' . $digits;
            $formatted['valid'] = true;
        }
}

return [
    'success' => true,
    'data' => $formatted
];
```

---

## Example 5: URL Parsing & Manipulation

Parse and manipulate URLs:

```php
<?php
$url = $process_input['result']['url'] ?? '';

if (empty($url)) {
    return ['success' => false, 'error' => 'No URL provided'];
}

// Parse the URL
$parsed = parse_url($url);

if ($parsed === false) {
    return ['success' => false, 'error' => 'Invalid URL format'];
}

// Parse query string
$queryParams = [];
if (isset($parsed['query'])) {
    parse_str($parsed['query'], $queryParams);
}

// Extract path segments
$pathSegments = [];
if (isset($parsed['path'])) {
    $pathSegments = array_filter(explode('/', $parsed['path']));
}

// Build result
$result = [
    'original_url' => $url,
    'scheme' => $parsed['scheme'] ?? 'https',
    'host' => $parsed['host'] ?? '',
    'port' => $parsed['port'] ?? null,
    'path' => $parsed['path'] ?? '/',
    'path_segments' => array_values($pathSegments),
    'query_string' => $parsed['query'] ?? '',
    'query_params' => $queryParams,
    'fragment' => $parsed['fragment'] ?? '',
    'user' => $parsed['user'] ?? '',
    'is_secure' => ($parsed['scheme'] ?? '') === 'https',
    'domain_parts' => explode('.', $parsed['host'] ?? '')
];

// Reconstruct clean URL
$result['clean_url'] = sprintf('%s://%s%s',
    $result['scheme'],
    $result['host'],
    $result['path']
);

return [
    'success' => true,
    'data' => $result
];
```

---

## Example 6: Template String Replacement

Replace placeholders in template strings:

```php
<?php
$template = $process_input['result']['template'] ?? '';
$variables = $process_input['result']['variables'] ?? [];

if (empty($template)) {
    return ['success' => false, 'error' => 'No template provided'];
}

// Support multiple placeholder formats
// {{variable}}, {variable}, %variable%, $variable$

$result = $template;

foreach ($variables as $key => $value) {
    // Handle different placeholder formats
    $patterns = [
        '{{' . $key . '}}',
        '{' . $key . '}',
        '%' . $key . '%',
        '$' . $key . '$',
        '[[' . $key . ']]'
    ];
    
    // Convert value to string
    $stringValue = is_array($value) ? json_encode($value) : strval($value);
    
    foreach ($patterns as $pattern) {
        $result = str_replace($pattern, $stringValue, $result);
    }
}

// Find any remaining unresolved placeholders
preg_match_all('/\{\{(\w+)\}\}|\{(\w+)\}|%(\w+)%/', $result, $matches);
$unresolvedVars = array_filter(array_merge(
    $matches[1] ?? [],
    $matches[2] ?? [],
    $matches[3] ?? []
));

return [
    'success' => true,
    'data' => [
        'rendered' => $result,
        'variables_used' => array_keys($variables),
        'unresolved_variables' => array_unique($unresolvedVars)
    ]
];
```

---

## Example 7: Slug Generation

Generate URL-friendly slugs:

```php
<?php
$text = $process_input['result']['text'] ?? '';
$maxLength = $process_input['result']['max_length'] ?? 100;

if (empty($text)) {
    return ['success' => false, 'error' => 'No text provided'];
}

// Convert to lowercase
$slug = mb_strtolower($text);

// Replace special characters with ASCII equivalents
$replacements = [
    'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
    'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    'ñ' => 'n', 'ç' => 'c', 'å' => 'a', 'ø' => 'o', 'æ' => 'ae'
];
$slug = str_replace(array_keys($replacements), array_values($replacements), $slug);

// Remove any remaining non-ASCII characters
$slug = preg_replace('/[^\x20-\x7E]/', '', $slug);

// Replace non-alphanumeric with hyphens
$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

// Remove leading/trailing hyphens
$slug = trim($slug, '-');

// Remove consecutive hyphens
$slug = preg_replace('/-+/', '-', $slug);

// Truncate at word boundary
if (strlen($slug) > $maxLength) {
    $slug = substr($slug, 0, $maxLength);
    $lastHyphen = strrpos($slug, '-');
    if ($lastHyphen > $maxLength * 0.7) {
        $slug = substr($slug, 0, $lastHyphen);
    }
}

return [
    'success' => true,
    'data' => [
        'original' => $text,
        'slug' => $slug,
        'length' => strlen($slug)
    ]
];
```

---

## Example 8: Text Excerpt Generation

Generate excerpts with word boundaries:

```php
<?php
$text = $process_input['result']['text'] ?? '';
$maxLength = $process_input['result']['max_length'] ?? 200;
$suffix = $process_input['result']['suffix'] ?? '...';

// Clean HTML tags if present
$cleanText = strip_tags($text);

// Normalize whitespace
$cleanText = preg_replace('/\s+/', ' ', trim($cleanText));

if (mb_strlen($cleanText) <= $maxLength) {
    return [
        'success' => true,
        'data' => [
            'excerpt' => $cleanText,
            'truncated' => false
        ]
    ];
}

// Truncate at word boundary
$excerpt = mb_substr($cleanText, 0, $maxLength);

// Find last space
$lastSpace = mb_strrpos($excerpt, ' ');
if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
    $excerpt = mb_substr($excerpt, 0, $lastSpace);
}

// Remove trailing punctuation (except periods for abbreviations)
$excerpt = rtrim($excerpt, ',;:!?');

// Add suffix
$excerpt .= $suffix;

return [
    'success' => true,
    'data' => [
        'excerpt' => $excerpt,
        'truncated' => true,
        'original_length' => mb_strlen($cleanText),
        'excerpt_length' => mb_strlen($excerpt)
    ]
];
```

---

## Example 9: CSV Line Parsing

Parse CSV formatted strings:

```php
<?php
$csvLine = $process_input['result']['csv_line'] ?? '';
$delimiter = $process_input['result']['delimiter'] ?? ',';
$enclosure = $process_input['result']['enclosure'] ?? '"';
$headers = $process_input['result']['headers'] ?? [];

if (empty($csvLine)) {
    return ['success' => false, 'error' => 'No CSV line provided'];
}

// Parse CSV line
$values = str_getcsv($csvLine, $delimiter, $enclosure);

// Map to headers if provided
$result = [];
if (!empty($headers) && count($headers) === count($values)) {
    $result = array_combine($headers, $values);
} else {
    $result = $values;
}

// Clean values
$cleaned = array_map(function($value) {
    return trim($value);
}, $result);

return [
    'success' => true,
    'data' => [
        'parsed' => $cleaned,
        'column_count' => count($values),
        'has_headers' => !empty($headers)
    ]
];
```

---

## Example 10: Regex Data Extraction

Extract structured data using patterns:

```php
<?php
$text = $process_input['result']['text'] ?? '';

$extracted = [
    'emails' => [],
    'phones' => [],
    'urls' => [],
    'dates' => [],
    'amounts' => [],
    'hashtags' => [],
    'mentions' => []
];

// Email addresses
preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches);
$extracted['emails'] = array_unique($matches[0]);

// Phone numbers (various formats)
preg_match_all('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/', $text, $matches);
$extracted['phones'] = array_unique($matches[0]);

// URLs
preg_match_all('/https?:\/\/[^\s<>"\']+/', $text, $matches);
$extracted['urls'] = array_unique($matches[0]);

// Dates (various formats)
preg_match_all('/\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}|\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2}/', $text, $matches);
$extracted['dates'] = array_unique($matches[0]);

// Currency amounts
preg_match_all('/[\$€£¥]\s?\d+(?:[,.\s]\d{3})*(?:[.,]\d{2})?|\d+(?:[,.\s]\d{3})*(?:[.,]\d{2})?\s?(?:USD|EUR|GBP|JPY)/', $text, $matches);
$extracted['amounts'] = array_unique($matches[0]);

// Hashtags
preg_match_all('/#[a-zA-Z0-9_]+/', $text, $matches);
$extracted['hashtags'] = array_unique($matches[0]);

// Mentions
preg_match_all('/@[a-zA-Z0-9_]+/', $text, $matches);
$extracted['mentions'] = array_unique($matches[0]);

return [
    'success' => true,
    'data' => [
        'extracted' => $extracted,
        'summary' => [
            'email_count' => count($extracted['emails']),
            'phone_count' => count($extracted['phones']),
            'url_count' => count($extracted['urls']),
            'date_count' => count($extracted['dates']),
            'amount_count' => count($extracted['amounts'])
        ]
    ]
];
```

---

## Best Practices

### 1. Use Multibyte Functions for Unicode
```php
// Instead of strlen()
mb_strlen($text);

// Instead of substr()
mb_substr($text, 0, 100);

// Instead of strtolower()
mb_strtolower($text);
```

### 2. Validate Before Processing
```php
if (!is_string($input) || empty(trim($input))) {
    return ['success' => false, 'error' => 'Invalid input'];
}
```

### 3. Escape Output Appropriately
```php
// For HTML output
$safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

// For URLs
$safe = urlencode($text);
```

### 4. Use Specific Regex Modifiers
```php
// u = UTF-8 mode
// i = case-insensitive
// s = dotall mode (. matches newlines)
preg_match('/pattern/ui', $text);
```

---

## Security Considerations

| ⚠️ Warning | Solution |
|------------|----------|
| Don't use `eval()` on strings | Use explicit parsing instead |
| Escape HTML before display | Use `htmlspecialchars()` |
| Validate regex patterns | Test patterns before use |
| Be careful with `preg_replace` callbacks | Callbacks are blocked by security validator |

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [Data Validation & Sanitization](Data_Validation_Sanitization.md)
