# Data Validation & Sanitization

This guide covers data validation and sanitization techniques within ProcessFlow code snippets.

## Overview

Validation ensures data meets required criteria before processing. Sanitization cleans data to prevent security issues. Both are **essential idempotent operations** for data quality.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Raw Input     │────▶│  Validate &      │────▶│  Clean, Valid   │
│   Data          │     │  Sanitize        │     │  Data           │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Available PHP Functions

| Category | Functions |
|----------|-----------|
| **Filter** | `filter_var`, `filter_input` |
| **String** | `htmlspecialchars`, `htmlentities`, `strip_tags`, `trim` |
| **Type Check** | `is_string`, `is_numeric`, `is_array`, `is_bool`, `is_int`, `is_float` |
| **Regex** | `preg_match`, `preg_replace` |
| **Encoding** | `mb_check_encoding`, `mb_convert_encoding` |

---

## Example 1: Comprehensive Input Validation

Validate input against schema:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$schema = $process_input['result']['schema'] ?? [];

$errors = [];
$validated = [];

foreach ($schema as $field => $rules) {
    $value = $data[$field] ?? null;
    $fieldErrors = [];
    
    // Required check
    if (($rules['required'] ?? false) && ($value === null || $value === '')) {
        $fieldErrors[] = "Field '$field' is required";
        $errors[$field] = $fieldErrors;
        continue;
    }
    
    // Skip further validation if empty and not required
    if ($value === null || $value === '') {
        $validated[$field] = $rules['default'] ?? null;
        continue;
    }
    
    // Type validation
    $type = $rules['type'] ?? 'string';
    switch ($type) {
        case 'string':
            if (!is_string($value)) {
                $fieldErrors[] = "Must be a string";
            }
            break;
        case 'integer':
        case 'int':
            if (!is_numeric($value) || (int)$value != $value) {
                $fieldErrors[] = "Must be an integer";
            } else {
                $value = (int)$value;
            }
            break;
        case 'float':
        case 'number':
            if (!is_numeric($value)) {
                $fieldErrors[] = "Must be a number";
            } else {
                $value = (float)$value;
            }
            break;
        case 'boolean':
        case 'bool':
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                $fieldErrors[] = "Must be a boolean";
            }
            break;
        case 'email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $fieldErrors[] = "Must be a valid email";
            }
            break;
        case 'url':
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                $fieldErrors[] = "Must be a valid URL";
            }
            break;
        case 'array':
            if (!is_array($value)) {
                $fieldErrors[] = "Must be an array";
            }
            break;
        case 'date':
            if (strtotime($value) === false) {
                $fieldErrors[] = "Must be a valid date";
            }
            break;
    }
    
    // String constraints
    if (is_string($value) && empty($fieldErrors)) {
        $minLen = $rules['min_length'] ?? null;
        $maxLen = $rules['max_length'] ?? null;
        
        if ($minLen !== null && mb_strlen($value) < $minLen) {
            $fieldErrors[] = "Must be at least $minLen characters";
        }
        if ($maxLen !== null && mb_strlen($value) > $maxLen) {
            $fieldErrors[] = "Must not exceed $maxLen characters";
        }
        
        // Pattern validation
        if (isset($rules['pattern'])) {
            if (!preg_match($rules['pattern'], $value)) {
                $fieldErrors[] = $rules['pattern_message'] ?? "Does not match required format";
            }
        }
    }
    
    // Numeric constraints
    if (is_numeric($value) && empty($fieldErrors)) {
        $min = $rules['min'] ?? null;
        $max = $rules['max'] ?? null;
        
        if ($min !== null && $value < $min) {
            $fieldErrors[] = "Must be at least $min";
        }
        if ($max !== null && $value > $max) {
            $fieldErrors[] = "Must not exceed $max";
        }
    }
    
    // Enum validation
    if (isset($rules['enum']) && !empty($value)) {
        if (!in_array($value, $rules['enum'], true)) {
            $allowed = implode(', ', $rules['enum']);
            $fieldErrors[] = "Must be one of: $allowed";
        }
    }
    
    if (!empty($fieldErrors)) {
        $errors[$field] = $fieldErrors;
    } else {
        $validated[$field] = $value;
    }
}

return [
    'success' => empty($errors),
    'data' => [
        'valid' => empty($errors),
        'validated' => $validated,
        'errors' => $errors,
        'error_count' => array_sum(array_map('count', $errors))
    ]
];
```

---

## Example 2: Email Validation

Comprehensive email validation:

```php
<?php
$email = $process_input['result']['email'] ?? '';
$strictMode = $process_input['result']['strict'] ?? true;

$result = [
    'original' => $email,
    'valid' => false,
    'errors' => [],
    'sanitized' => null,
    'details' => []
];

// Basic cleanup
$email = trim(strtolower($email));

// Check for empty
if (empty($email)) {
    $result['errors'][] = 'Email is required';
    return ['success' => false, 'data' => $result];
}

// Basic format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $result['errors'][] = 'Invalid email format';
    return ['success' => false, 'data' => $result];
}

// Extract parts
$parts = explode('@', $email);
$localPart = $parts[0];
$domain = $parts[1];

$result['details'] = [
    'local_part' => $localPart,
    'domain' => $domain
];

// Strict validations
if ($strictMode) {
    // Check for disposable email domains
    $disposableDomains = ['tempmail.com', 'throwaway.email', '10minutemail.com', 'guerrillamail.com'];
    if (in_array($domain, $disposableDomains)) {
        $result['errors'][] = 'Disposable email addresses not allowed';
    }
    
    // Check for common typos in popular domains
    $typoFixes = [
        'gmial.com' => 'gmail.com',
        'gmal.com' => 'gmail.com',
        'gmali.com' => 'gmail.com',
        'hotmal.com' => 'hotmail.com',
        'yahooo.com' => 'yahoo.com',
        'outloook.com' => 'outlook.com'
    ];
    
    if (isset($typoFixes[$domain])) {
        $result['details']['possible_typo'] = true;
        $result['details']['suggested_domain'] = $typoFixes[$domain];
        $result['details']['suggested_email'] = $localPart . '@' . $typoFixes[$domain];
    }
    
    // Length checks
    if (strlen($localPart) > 64) {
        $result['errors'][] = 'Local part too long (max 64 characters)';
    }
    if (strlen($domain) > 255) {
        $result['errors'][] = 'Domain too long (max 255 characters)';
    }
}

// Identify email type
$freeEmailDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com'];
$result['details']['is_free_email'] = in_array($domain, $freeEmailDomains);
$result['details']['domain_parts'] = explode('.', $domain);
$result['details']['tld'] = end($result['details']['domain_parts']);

if (empty($result['errors'])) {
    $result['valid'] = true;
    $result['sanitized'] = $email;
}

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 3: Phone Number Validation

Validate and format phone numbers:

```php
<?php
$phone = $process_input['result']['phone'] ?? '';
$country = $process_input['result']['country'] ?? 'FI';

$result = [
    'original' => $phone,
    'valid' => false,
    'errors' => [],
    'sanitized' => null,
    'formatted' => null,
    'international' => null
];

// Strip all non-numeric except leading +
$cleaned = preg_replace('/[^\d+]/', '', $phone);
$hasPlus = (substr($cleaned, 0, 1) === '+');
$digits = preg_replace('/[^\d]/', '', $cleaned);

if (empty($digits)) {
    $result['errors'][] = 'Phone number required';
    return ['success' => false, 'data' => $result];
}

// Country-specific validation
$countryPatterns = [
    'FI' => [
        'prefix' => '358',
        'local_prefix' => '0',
        'lengths' => [9, 10, 11], // Finnish numbers
        'pattern' => '/^(?:\+358|0)[1-9]\d{6,9}$/',
        'format' => function($d) {
            // Format: +358 40 123 4567
            $d = ltrim($d, '0');
            return sprintf('+358 %s %s %s', 
                substr($d, 0, 2), 
                substr($d, 2, 3), 
                substr($d, 5)
            );
        }
    ],
    'US' => [
        'prefix' => '1',
        'local_prefix' => '',
        'lengths' => [10, 11],
        'pattern' => '/^(?:\+?1)?[2-9]\d{9}$/',
        'format' => function($d) {
            $d = ltrim($d, '1');
            return sprintf('+1 (%s) %s-%s',
                substr($d, 0, 3),
                substr($d, 3, 3),
                substr($d, 6, 4)
            );
        }
    ],
    'SE' => [
        'prefix' => '46',
        'local_prefix' => '0',
        'lengths' => [9, 10, 11],
        'pattern' => '/^(?:\+46|0)[1-9]\d{6,9}$/',
        'format' => function($d) {
            $d = ltrim($d, '0');
            return '+46 ' . $d;
        }
    ]
];

$countryConfig = $countryPatterns[$country] ?? null;

if (!$countryConfig) {
    // Generic validation
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        $result['errors'][] = 'Phone number must be 7-15 digits';
    } else {
        $result['valid'] = true;
        $result['sanitized'] = '+' . $digits;
        $result['international'] = '+' . $digits;
    }
} else {
    // Remove country prefix if present
    $localDigits = $digits;
    if (strpos($digits, $countryConfig['prefix']) === 0) {
        $localDigits = substr($digits, strlen($countryConfig['prefix']));
    }
    
    // Validate length
    if (!in_array(strlen($digits), $countryConfig['lengths']) && 
        !in_array(strlen($localDigits), $countryConfig['lengths'])) {
        $result['errors'][] = 'Invalid phone number length for ' . $country;
    }
    
    // Pattern validation
    $testNumber = $hasPlus ? '+' . $digits : $countryConfig['local_prefix'] . ltrim($localDigits, '0');
    if (!preg_match($countryConfig['pattern'], $testNumber)) {
        $result['errors'][] = 'Invalid phone number format for ' . $country;
    }
    
    if (empty($result['errors'])) {
        $result['valid'] = true;
        $result['sanitized'] = $localDigits;
        $result['formatted'] = $countryConfig['format']($localDigits);
        $result['international'] = '+' . $countryConfig['prefix'] . ltrim($localDigits, '0');
    }
}

$result['country'] = $country;
$result['digits_only'] = $digits;

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 4: Data Sanitization

Sanitize data for safe storage and display:

```php
<?php
$data = $process_input['result']['data'] ?? [];
$rules = $process_input['result']['rules'] ?? [];

$sanitized = [];

// Default sanitization rules per type
$defaultRules = [
    'string' => ['trim', 'strip_tags'],
    'html' => ['trim', 'htmlspecialchars'],
    'email' => ['trim', 'lowercase', 'email'],
    'url' => ['trim', 'url'],
    'integer' => ['integer'],
    'float' => ['float'],
    'boolean' => ['boolean'],
    'text' => ['trim', 'normalize_whitespace'],
    'name' => ['trim', 'strip_tags', 'ucwords'],
    'slug' => ['trim', 'lowercase', 'slug']
];

$sanitizeFunctions = [
    'trim' => function($v) { return is_string($v) ? trim($v) : $v; },
    'strip_tags' => function($v) { return is_string($v) ? strip_tags($v) : $v; },
    'htmlspecialchars' => function($v) { 
        return is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v; 
    },
    'lowercase' => function($v) { return is_string($v) ? mb_strtolower($v) : $v; },
    'uppercase' => function($v) { return is_string($v) ? mb_strtoupper($v) : $v; },
    'ucwords' => function($v) { return is_string($v) ? ucwords(mb_strtolower($v)) : $v; },
    'ucfirst' => function($v) { return is_string($v) ? ucfirst(mb_strtolower($v)) : $v; },
    'email' => function($v) { return filter_var($v, FILTER_SANITIZE_EMAIL); },
    'url' => function($v) { return filter_var($v, FILTER_SANITIZE_URL); },
    'integer' => function($v) { return (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT); },
    'float' => function($v) { return (float)filter_var($v, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION); },
    'boolean' => function($v) { return filter_var($v, FILTER_VALIDATE_BOOLEAN); },
    'alphanumeric' => function($v) { return preg_replace('/[^a-zA-Z0-9]/', '', $v); },
    'alpha' => function($v) { return preg_replace('/[^a-zA-Z]/', '', $v); },
    'numeric' => function($v) { return preg_replace('/[^0-9]/', '', $v); },
    'normalize_whitespace' => function($v) { 
        return is_string($v) ? preg_replace('/\s+/', ' ', $v) : $v; 
    },
    'slug' => function($v) { 
        $v = mb_strtolower($v);
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        return trim($v, '-');
    },
    'remove_null' => function($v) { return str_replace("\0", '', $v); }
];

foreach ($data as $field => $value) {
    // Get rules for this field
    $fieldRules = $rules[$field] ?? null;
    
    if ($fieldRules === null) {
        // Auto-detect type and apply default
        if (is_string($value)) {
            $fieldRules = 'string';
        } elseif (is_numeric($value)) {
            $fieldRules = is_float($value) ? 'float' : 'integer';
        } elseif (is_bool($value)) {
            $fieldRules = 'boolean';
        }
    }
    
    // Get sanitization functions
    $functions = [];
    if (is_string($fieldRules) && isset($defaultRules[$fieldRules])) {
        $functions = $defaultRules[$fieldRules];
    } elseif (is_array($fieldRules)) {
        $functions = $fieldRules;
    }
    
    // Apply sanitization
    $sanitizedValue = $value;
    foreach ($functions as $func) {
        if (isset($sanitizeFunctions[$func])) {
            $sanitizedValue = $sanitizeFunctions[$func]($sanitizedValue);
        }
    }
    
    $sanitized[$field] = $sanitizedValue;
}

return [
    'success' => true,
    'data' => [
        'sanitized' => $sanitized,
        'field_count' => count($sanitized)
    ]
];
```

---

## Example 5: JSON Validation

Validate JSON structure:

```php
<?php
$jsonInput = $process_input['result']['json'] ?? '';
$schema = $process_input['result']['schema'] ?? null;

$result = [
    'valid' => false,
    'errors' => [],
    'data' => null
];

// Check if input is string
if (is_string($jsonInput)) {
    $decoded = json_decode($jsonInput, true);
    $jsonError = json_last_error();
    
    if ($jsonError !== JSON_ERROR_NONE) {
        $errorMessages = [
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Underflow or mode mismatch',
            JSON_ERROR_CTRL_CHAR => 'Unexpected control character',
            JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters'
        ];
        $result['errors'][] = $errorMessages[$jsonError] ?? 'Unknown JSON error';
        return ['success' => false, 'data' => $result];
    }
    
    $result['data'] = $decoded;
} elseif (is_array($jsonInput)) {
    $result['data'] = $jsonInput;
} else {
    $result['errors'][] = 'Input must be JSON string or array';
    return ['success' => false, 'data' => $result];
}

// Schema validation
if ($schema && !empty($result['data'])) {
    $validateStructure = function($data, $schema, $path = '') use (&$validateStructure, &$result) {
        foreach ($schema as $key => $rules) {
            $currentPath = $path ? "$path.$key" : $key;
            $value = $data[$key] ?? null;
            
            // Required check
            if (($rules['required'] ?? false) && !isset($data[$key])) {
                $result['errors'][] = "Missing required field: $currentPath";
                continue;
            }
            
            if ($value === null) continue;
            
            // Type check
            $expectedType = $rules['type'] ?? null;
            if ($expectedType) {
                $actualType = gettype($value);
                $typeMatch = match($expectedType) {
                    'string' => is_string($value),
                    'integer', 'int' => is_int($value),
                    'float', 'double', 'number' => is_numeric($value),
                    'boolean', 'bool' => is_bool($value),
                    'array' => is_array($value) && array_keys($value) === range(0, count($value) - 1),
                    'object' => is_array($value) && array_keys($value) !== range(0, count($value) - 1),
                    default => true
                };
                
                if (!$typeMatch) {
                    $result['errors'][] = "Field $currentPath: expected $expectedType, got $actualType";
                }
            }
            
            // Nested object validation
            if (isset($rules['properties']) && is_array($value)) {
                $validateStructure($value, $rules['properties'], $currentPath);
            }
            
            // Array item validation
            if (isset($rules['items']) && is_array($value)) {
                foreach ($value as $index => $item) {
                    if (is_array($item) && isset($rules['items']['properties'])) {
                        $validateStructure($item, $rules['items']['properties'], "$currentPath[$index]");
                    }
                }
            }
        }
    };
    
    $validateStructure($result['data'], $schema);
}

$result['valid'] = empty($result['errors']);

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 6: Credit Card Validation

Validate credit card numbers (Luhn algorithm):

```php
<?php
$cardNumber = $process_input['result']['card_number'] ?? '';
$expiryMonth = $process_input['result']['expiry_month'] ?? '';
$expiryYear = $process_input['result']['expiry_year'] ?? '';
$cvv = $process_input['result']['cvv'] ?? '';

$result = [
    'valid' => false,
    'errors' => [],
    'card_type' => null,
    'masked' => null
];

// Clean card number
$cleanCard = preg_replace('/[^0-9]/', '', $cardNumber);

if (empty($cleanCard)) {
    $result['errors'][] = 'Card number is required';
    return ['success' => false, 'data' => $result];
}

// Check length
if (strlen($cleanCard) < 13 || strlen($cleanCard) > 19) {
    $result['errors'][] = 'Invalid card number length';
}

// Detect card type by prefix
$cardTypes = [
    'visa' => '/^4/',
    'mastercard' => '/^5[1-5]/',
    'amex' => '/^3[47]/',
    'discover' => '/^6(?:011|5)/',
    'diners' => '/^3(?:0[0-5]|[68])/',
    'jcb' => '/^(?:2131|1800|35)/'
];

foreach ($cardTypes as $type => $pattern) {
    if (preg_match($pattern, $cleanCard)) {
        $result['card_type'] = $type;
        break;
    }
}

// Luhn algorithm validation
$sum = 0;
$length = strlen($cleanCard);
$parity = $length % 2;

for ($i = 0; $i < $length; $i++) {
    $digit = (int)$cleanCard[$i];
    
    if ($i % 2 === $parity) {
        $digit *= 2;
        if ($digit > 9) {
            $digit -= 9;
        }
    }
    
    $sum += $digit;
}

if ($sum % 10 !== 0) {
    $result['errors'][] = 'Invalid card number (checksum failed)';
}

// Validate expiry
if (!empty($expiryMonth) && !empty($expiryYear)) {
    $month = (int)$expiryMonth;
    $year = (int)$expiryYear;
    
    // Handle 2-digit year
    if ($year < 100) {
        $year += 2000;
    }
    
    if ($month < 1 || $month > 12) {
        $result['errors'][] = 'Invalid expiry month';
    }
    
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    
    if ($year < $currentYear || ($year === $currentYear && $month < $currentMonth)) {
        $result['errors'][] = 'Card has expired';
    }
    
    if ($year > $currentYear + 20) {
        $result['errors'][] = 'Invalid expiry year';
    }
}

// Validate CVV
if (!empty($cvv)) {
    $cvvLength = strlen(preg_replace('/[^0-9]/', '', $cvv));
    $expectedLength = ($result['card_type'] === 'amex') ? 4 : 3;
    
    if ($cvvLength !== $expectedLength) {
        $result['errors'][] = "CVV must be $expectedLength digits";
    }
}

// Create masked version
$result['masked'] = str_repeat('*', strlen($cleanCard) - 4) . substr($cleanCard, -4);

$result['valid'] = empty($result['errors']);

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 7: URL Validation

Comprehensive URL validation:

```php
<?php
$url = $process_input['result']['url'] ?? '';
$options = $process_input['result']['options'] ?? [];

$result = [
    'original' => $url,
    'valid' => false,
    'errors' => [],
    'parsed' => null,
    'sanitized' => null
];

$url = trim($url);

if (empty($url)) {
    $result['errors'][] = 'URL is required';
    return ['success' => false, 'data' => $result];
}

// Add scheme if missing
if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'https://' . $url;
}

// Basic filter validation
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    $result['errors'][] = 'Invalid URL format';
    return ['success' => false, 'data' => $result];
}

// Parse URL
$parsed = parse_url($url);

if ($parsed === false) {
    $result['errors'][] = 'Could not parse URL';
    return ['success' => false, 'data' => $result];
}

$result['parsed'] = [
    'scheme' => $parsed['scheme'] ?? null,
    'host' => $parsed['host'] ?? null,
    'port' => $parsed['port'] ?? null,
    'path' => $parsed['path'] ?? '/',
    'query' => $parsed['query'] ?? null,
    'fragment' => $parsed['fragment'] ?? null
];

// Scheme validation
$allowedSchemes = $options['allowed_schemes'] ?? ['http', 'https'];
if (!in_array($parsed['scheme'], $allowedSchemes)) {
    $result['errors'][] = 'Scheme must be: ' . implode(', ', $allowedSchemes);
}

// Host validation
if (empty($parsed['host'])) {
    $result['errors'][] = 'Host is required';
} else {
    // Check for IP addresses if not allowed
    if (($options['allow_ip'] ?? true) === false) {
        if (filter_var($parsed['host'], FILTER_VALIDATE_IP)) {
            $result['errors'][] = 'IP addresses not allowed';
        }
    }
    
    // Check for localhost
    if (($options['allow_localhost'] ?? true) === false) {
        if (in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'])) {
            $result['errors'][] = 'Localhost not allowed';
        }
    }
    
    // Domain blacklist
    $blacklist = $options['blacklist'] ?? [];
    if (in_array($parsed['host'], $blacklist)) {
        $result['errors'][] = 'Domain is blacklisted';
    }
    
    // Domain whitelist
    $whitelist = $options['whitelist'] ?? null;
    if ($whitelist && !in_array($parsed['host'], $whitelist)) {
        $result['errors'][] = 'Domain not in whitelist';
    }
}

// Path validation
if (isset($options['required_path_prefix'])) {
    $path = $parsed['path'] ?? '/';
    if (strpos($path, $options['required_path_prefix']) !== 0) {
        $result['errors'][] = 'Path must start with: ' . $options['required_path_prefix'];
    }
}

if (empty($result['errors'])) {
    $result['valid'] = true;
    $result['sanitized'] = filter_var($url, FILTER_SANITIZE_URL);
}

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 8: Password Strength Validation

Check password strength:

```php
<?php
$password = $process_input['result']['password'] ?? '';
$requirements = $process_input['result']['requirements'] ?? [];

// Default requirements
$config = array_merge([
    'min_length' => 8,
    'max_length' => 128,
    'require_uppercase' => true,
    'require_lowercase' => true,
    'require_numbers' => true,
    'require_special' => true,
    'min_unique_chars' => 4,
    'forbid_common' => true
], $requirements);

$result = [
    'valid' => false,
    'score' => 0,
    'strength' => 'weak',
    'errors' => [],
    'suggestions' => []
];

// Length check
$length = mb_strlen($password);
if ($length < $config['min_length']) {
    $result['errors'][] = "Password must be at least {$config['min_length']} characters";
}
if ($length > $config['max_length']) {
    $result['errors'][] = "Password must not exceed {$config['max_length']} characters";
}

// Character type checks
$hasUpper = preg_match('/[A-Z]/', $password);
$hasLower = preg_match('/[a-z]/', $password);
$hasNumber = preg_match('/[0-9]/', $password);
$hasSpecial = preg_match('/[!@#$%^&*(),.?":{}|<>_\-+=\[\]\\\\\/~`]/', $password);

if ($config['require_uppercase'] && !$hasUpper) {
    $result['errors'][] = 'Password must contain at least one uppercase letter';
}
if ($config['require_lowercase'] && !$hasLower) {
    $result['errors'][] = 'Password must contain at least one lowercase letter';
}
if ($config['require_numbers'] && !$hasNumber) {
    $result['errors'][] = 'Password must contain at least one number';
}
if ($config['require_special'] && !$hasSpecial) {
    $result['errors'][] = 'Password must contain at least one special character';
}

// Unique characters check
$uniqueChars = count(array_unique(str_split($password)));
if ($uniqueChars < $config['min_unique_chars']) {
    $result['errors'][] = "Password must contain at least {$config['min_unique_chars']} unique characters";
}

// Common passwords check
if ($config['forbid_common']) {
    $common = ['password', '123456', 'qwerty', 'abc123', 'password1', 'letmein', 'welcome', 'admin'];
    if (in_array(strtolower($password), $common)) {
        $result['errors'][] = 'This password is too common';
    }
}

// Calculate strength score (0-100)
$score = 0;
$score += min($length * 4, 40); // Length contribution (max 40)
$score += $hasUpper ? 10 : 0;
$score += $hasLower ? 10 : 0;
$score += $hasNumber ? 10 : 0;
$score += $hasSpecial ? 15 : 0;
$score += min($uniqueChars * 2, 15); // Unique chars (max 15)

$result['score'] = min(100, $score);
$result['strength'] = match(true) {
    $score >= 80 => 'very_strong',
    $score >= 60 => 'strong',
    $score >= 40 => 'medium',
    $score >= 20 => 'weak',
    default => 'very_weak'
};

// Generate suggestions
if ($length < 12) {
    $result['suggestions'][] = 'Consider using a longer password';
}
if (!$hasSpecial) {
    $result['suggestions'][] = 'Add special characters for more security';
}
if ($uniqueChars < 8) {
    $result['suggestions'][] = 'Use more varied characters';
}

$result['valid'] = empty($result['errors']);

return [
    'success' => $result['valid'],
    'data' => $result
];
```

---

## Example 9: Batch Validation

Validate multiple records:

```php
<?php
$records = $process_input['result']['records'] ?? [];
$schema = $process_input['result']['schema'] ?? [];
$stopOnFirstError = $process_input['result']['stop_on_first_error'] ?? false;

$results = [
    'valid_count' => 0,
    'invalid_count' => 0,
    'total' => count($records),
    'valid_records' => [],
    'invalid_records' => [],
    'errors_by_record' => []
];

foreach ($records as $index => $record) {
    $recordErrors = [];
    $isValid = true;
    
    foreach ($schema as $field => $rules) {
        $value = $record[$field] ?? null;
        
        // Required
        if (($rules['required'] ?? false) && ($value === null || $value === '')) {
            $recordErrors[$field][] = 'Required';
            $isValid = false;
            continue;
        }
        
        if ($value === null || $value === '') continue;
        
        // Type validation
        $type = $rules['type'] ?? 'string';
        $typeValid = match($type) {
            'string' => is_string($value),
            'integer', 'int' => is_numeric($value) && (int)$value == $value,
            'float', 'number' => is_numeric($value),
            'boolean', 'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => true
        };
        
        if (!$typeValid) {
            $recordErrors[$field][] = "Invalid type, expected $type";
            $isValid = false;
        }
        
        // Additional rules
        if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
            $recordErrors[$field][] = "Must be >= {$rules['min']}";
            $isValid = false;
        }
        if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
            $recordErrors[$field][] = "Must be <= {$rules['max']}";
            $isValid = false;
        }
        if (isset($rules['enum']) && !in_array($value, $rules['enum'])) {
            $recordErrors[$field][] = "Must be one of: " . implode(', ', $rules['enum']);
            $isValid = false;
        }
    }
    
    if ($isValid) {
        $results['valid_count']++;
        $results['valid_records'][] = [
            'index' => $index,
            'data' => $record
        ];
    } else {
        $results['invalid_count']++;
        $results['invalid_records'][] = [
            'index' => $index,
            'data' => $record,
            'errors' => $recordErrors
        ];
        $results['errors_by_record'][$index] = $recordErrors;
        
        if ($stopOnFirstError) {
            break;
        }
    }
}

$results['all_valid'] = $results['invalid_count'] === 0;

return [
    'success' => $results['all_valid'],
    'data' => $results
];
```

---

## Best Practices

### 1. Validate Early
```php
// Validate at the start of processing
if (empty($data['required_field'])) {
    return ['success' => false, 'error' => 'Missing required field'];
}
```

### 2. Use Type-Specific Validation
```php
// Use appropriate filter for type
$email = filter_var($input, FILTER_VALIDATE_EMAIL);
$url = filter_var($input, FILTER_VALIDATE_URL);
```

### 3. Sanitize Before Storage
```php
$clean = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
```

### 4. Return Detailed Errors
```php
return [
    'success' => false,
    'errors' => [
        'field1' => ['Error message 1'],
        'field2' => ['Error message 2']
    ]
];
```

---

## See Also

- [Data Transformation & Mapping](Data_Transformation_Mapping.md)
- [String Processing & Text](String_Processing_Text.md)
- [JSON/XML/CSV Parsing](JSON_XML_CSV_Parsing.md)
