# LLM Summarizer

This guide covers using the LLM Summarizer process step to automatically generate concise summaries from any data structure using the platform's native LLM service.

## Overview

The LLM Summarizer analyzes input data from previous process steps and generates structured summaries with key insights, bullet points, and takeaways. It automatically handles different data formats and structures, making it ideal for summarizing API responses, database records, documents, or any complex data.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Previous Step │────▶│  LLM Summarizer  │────▶│  Summary Data   │
│  (Any Data)     │     │  (LLM Analysis)  │     │  (Structured)   │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

## Features

- **Automatic Data Handling**: Works with any data structure (arrays, objects, strings)
- **Intelligent Extraction**: LLM automatically identifies key facts and insights
- **Structured Output**: Returns consistent JSON format with summary, bullets, and takeaway
- **Size Management**: Automatically truncates large inputs and limits output length
- **Error Handling**: Graceful fallback if JSON parsing fails

## Input Data

The summarizer accepts data from the previous process step in multiple formats:

| Source | Description | Priority |
|--------|-------------|----------|
| `process_input['data']` | Output from previous step (recommended) | Highest |
| `process_input` | Direct process input | Fallback |
| Any structure | Arrays, objects, strings | All supported |

### Example Input Formats

**From Previous Step (Recommended):**
```php
// Previous step returns:
return [
    'success' => true,
    'data' => [
        'customer_name' => 'John Doe',
        'order_total' => 1250.00,
        'items' => ['Product A', 'Product B'],
        'status' => 'completed'
    ]
];

// LLM Summarizer receives process_input['data'] automatically
```

**Direct Input:**
```php
// If previous step returns data directly:
return [
    'customer_name' => 'John Doe',
    'order_total' => 1250.00
];

// LLM Summarizer uses process_input directly
```

## Output Structure

The summarizer returns a standardized structure:

```php
[
    'success' => true,
    'data' => [
        'summary' => 'A concise summary (approximately 200 words)',
        'bullets' => [
            'Key point 1',
            'Key point 2',
            'Key point 3'
        ],
        'takeaway' => 'One-line takeaway message',
        'raw_response' => 'Original LLM response text'
    ],
    'message' => 'Summary generated successfully'
]
```

### Output Fields

| Field | Type | Description | Limits |
|-------|------|-------------|--------|
| `summary` | string | Concise summary of the data | ~200 words (truncated if longer) |
| `bullets` | array | Up to 5 key bullet points | Maximum 5 items |
| `takeaway` | string | One-line key insight | 150 characters (truncated if longer) |
| `raw_response` | string | Original LLM response | Full response text |

## Code Snippet

Copy this code into your process step:

```php
<?php
/**
 * LLM Summary Process Step
 * Generates a summary using the platform's native LLM service.
 */

try {
    $input_data = $process_input['data'] ?? $process_input ?? [];
    if (empty($input_data)) {
        return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'No input data provided'], 'data' => null];
    }
    
    $yaml_data = yaml_emit(is_array($input_data) ? $input_data : ['content' => $input_data], YAML_UTF8_ENCODING);
    if (mb_strlen($yaml_data) > 5000) {
        $yaml_data = mb_substr($yaml_data, 0, 5000) . "\n... (truncated)";
    }
    
    $prompt = "Analyze the YAML data and create a concise summary. Extract key facts and insights. Ignore metadata/IDs/timestamps unless relevant.\n" .
        "Return ONLY valid JSON (no markdown): {\"summary\": \"~200 words\", \"bullets\": [\"point 1\", \"point 2\"], \"takeaway\": \"one-line\"}\n\n" .
        "YAML Data:\n" . $yaml_data;
    
    $llm_result = $llm->callLLM($prompt, ['max_tokens' => 1000]);
    if (!isset($llm_result['success']) || !$llm_result['success']) {
        throw new Exception($llm_result['error']['message'] ?? 'LLM call failed');
    }
    
    $response_text = $llm_result['response']['response'] ?? $llm_result['response'] ?? '';
    if (empty($response_text)) {
        throw new Exception('Empty LLM response');
    }
    
    $json_text = preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response_text, $m) ? $m[1] : (preg_match('/(\{.*\})/s', $response_text, $m) ? $m[1] : $response_text);
    $parsed = json_decode($json_text, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
        $summary = $parsed['summary'] ?? '';
        $bullets = array_slice(array_filter(array_map('trim', $parsed['bullets'] ?? [])), 0, 5);
        $takeaway = $parsed['takeaway'] ?? '';
    } else {
        $summary = $response_text;
        $bullets = [];
        $takeaway = '';
    }
    
    if (str_word_count($summary) > 250) {
        $summary = implode(' ', array_slice(explode(' ', $summary), 0, 200)) . '...';
    }
    if (mb_strlen($takeaway) > 150) {
        $takeaway = mb_substr($takeaway, 0, 147) . '...';
    }
    
    return ['success' => true, 'data' => ['summary' => $summary, 'bullets' => $bullets, 'takeaway' => $takeaway, 'raw_response' => $response_text], 'message' => 'Summary generated successfully'];
    
} catch (Exception $e) {
    return ['success' => false, 'error' => ['code' => 'LLM_SUMMARY_ERROR', 'message' => 'Failed to generate summary', 'details' => $e->getMessage()], 'data' => null];
}
```

## Configuration Options

### Customizing the Prompt

You can modify the prompt to change how the LLM analyzes data:

**Default Prompt:**
```php
$prompt = "Analyze the YAML data and create a concise summary. Extract key facts and insights. Ignore metadata/IDs/timestamps unless relevant.\n" .
    "Return ONLY valid JSON (no markdown): {\"summary\": \"~200 words\", \"bullets\": [\"point 1\", \"point 2\"], \"takeaway\": \"one-line\"}\n\n" .
    "YAML Data:\n" . $yaml_data;
```

**Customization Examples:**

**Focus on Business Metrics:**
```php
$prompt = "Analyze the YAML data focusing on business metrics, revenue, and KPIs. Extract financial insights and performance indicators.\n" .
    "Return ONLY valid JSON (no markdown): {\"summary\": \"~200 words\", \"bullets\": [\"metric 1\", \"metric 2\"], \"takeaway\": \"one-line\"}\n\n" .
    "YAML Data:\n" . $yaml_data;
```

**Technical Summary:**
```php
$prompt = "Analyze the YAML data from a technical perspective. Focus on system status, errors, performance metrics, and technical details.\n" .
    "Return ONLY valid JSON (no markdown): {\"summary\": \"~200 words\", \"bullets\": [\"technical point 1\", \"technical point 2\"], \"takeaway\": \"one-line\"}\n\n" .
    "YAML Data:\n" . $yaml_data;
```

**Customer-Focused Summary:**
```php
$prompt = "Analyze the YAML data from a customer perspective. Focus on customer experience, satisfaction, issues, and feedback.\n" .
    "Return ONLY valid JSON (no markdown): {\"summary\": \"~200 words\", \"bullets\": [\"customer insight 1\", \"customer insight 2\"], \"takeaway\": \"one-line\"}\n\n" .
    "YAML Data:\n" . $yaml_data;
```

### Adjusting Token Limits

Modify the `max_tokens` parameter to control response length:

```php
// Shorter responses (faster, cheaper)
$llm_result = $llm->callLLM($prompt, ['max_tokens' => 500]);

// Longer responses (more detailed)
$llm_result = $llm->callLLM($prompt, ['max_tokens' => 2000]);
```

### Changing Input Size Limits

Adjust the YAML truncation limit:

```php
// Allow larger inputs (default: 5000 characters)
if (mb_strlen($yaml_data) > 10000) {
    $yaml_data = mb_substr($yaml_data, 0, 10000) . "\n... (truncated)";
}
```

### Modifying Output Limits

Change summary and takeaway length limits:

```php
// Longer summary (default: ~200 words)
if (str_word_count($summary) > 500) {
    $summary = implode(' ', array_slice(explode(' ', $summary), 0, 400)) . '...';
}

// Longer takeaway (default: 150 characters)
if (mb_strlen($takeaway) > 300) {
    $takeaway = mb_substr($takeaway, 0, 297) . '...';
}

// More bullet points (default: 5)
$bullets = array_slice(array_filter(array_map('trim', $parsed['bullets'] ?? [])), 0, 10);
```

## Use Cases

### 1. Summarizing API Responses

Summarize complex API responses for easier consumption:

```php
// Previous step: Fetch data from external API
// Returns: process_input['data'] with API response

// LLM Summarizer step: Generates summary
// Output: Structured summary of API data
```

### 2. Database Query Results

Summarize database query results:

```php
// Previous step: Query database
return [
    'success' => true,
    'data' => [
        'records' => [
            ['customer' => 'John', 'order' => 1250, 'status' => 'completed'],
            ['customer' => 'Jane', 'order' => 890, 'status' => 'pending']
        ],
        'total' => 2140,
        'count' => 2
    ]
];

// LLM Summarizer: Creates summary of query results
```

### 3. Document Analysis

Summarize document content or extracted text:

```php
// Previous step: Extract text from document
return [
    'success' => true,
    'data' => [
        'document_text' => 'Long document content...',
        'metadata' => ['title' => 'Report', 'pages' => 10]
    ]
];

// LLM Summarizer: Creates executive summary
```

### 4. Integration Data Summaries

Summarize data from integrations:

```php
// Previous step: Fetch integration data
return [
    'success' => true,
    'data' => [
        'sales_data' => [...],
        'customer_data' => [...],
        'product_data' => [...]
    ]
];

// LLM Summarizer: Creates business insights summary
```

## Error Handling

The summarizer handles errors gracefully:

### Validation Error

```php
// If no input data provided:
[
    'success' => false,
    'error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => 'No input data provided'
    ],
    'data' => null
]
```

### LLM Service Error

```php
// If LLM call fails:
[
    'success' => false,
    'error' => [
        'code' => 'LLM_SUMMARY_ERROR',
        'message' => 'Failed to generate summary',
        'details' => 'LLM service call failed: Connection timeout'
    ],
    'data' => null
]
```

### Fallback Behavior

If JSON parsing fails, the summarizer uses the raw LLM response as the summary:

```php
// Fallback output:
[
    'success' => true,
    'data' => [
        'summary' => 'Raw LLM response text',
        'bullets' => [],
        'takeaway' => '',
        'raw_response' => 'Raw LLM response text'
    ]
]
```

## Best Practices

### 1. Use `process_input['data']` Structure

Always return data in the standard format from previous steps:

```php
// ✅ Good
return [
    'success' => true,
    'data' => $your_data
];

// ❌ Avoid
return $your_data; // Works but less explicit
```

### 2. Filter Sensitive Data

Remove sensitive information before summarizing:

```php
// Before LLM Summarizer step, filter sensitive data:
$filtered_data = array_filter($process_input['data'], function($key) {
    return !in_array($key, ['password', 'api_key', 'ssn', 'credit_card']);
}, ARRAY_FILTER_USE_KEY);

// Pass filtered_data to LLM Summarizer
```

### 3. Limit Input Size

For very large datasets, consider pre-filtering:

```php
// In previous step, select only relevant fields:
return [
    'success' => true,
    'data' => [
        'key_metrics' => $data['metrics'],
        'recent_events' => array_slice($data['events'], 0, 10)
    ]
];
```

### 4. Customize for Your Domain

Modify the prompt to match your business domain:

```php
// E-commerce domain
$prompt = "Analyze e-commerce order data. Focus on customer behavior, product trends, and sales patterns...";

// Healthcare domain
$prompt = "Analyze patient data. Focus on health metrics, treatment outcomes, and care patterns...";

// Finance domain
$prompt = "Analyze financial data. Focus on transactions, balances, and financial health indicators...";
```

## Troubleshooting

### Issue: "No input data provided"

**Cause:** Previous step didn't return data in expected format.

**Solution:** Ensure previous step returns:
```php
return [
    'success' => true,
    'data' => $your_data
];
```

### Issue: Summary is too generic

**Cause:** Prompt doesn't specify domain or focus area.

**Solution:** Customize the prompt to include domain-specific instructions:
```php
$prompt = "Analyze [YOUR DOMAIN] data. Focus on [SPECIFIC ASPECTS]...";
```

### Issue: Summary is truncated

**Cause:** Input data exceeds 5000 character limit.

**Solution:** Increase truncation limit or pre-filter data in previous step.

### Issue: LLM call fails

**Cause:** LLM service unavailable or misconfigured.

**Solution:** Check LLM service configuration in platform settings.

## Related Documentation

- [LLM Integration Guide](./LLM_Integration.md) - General LLM usage patterns
- [Process Communication](./Process_Communication.md) - Passing data between steps
- [Data Transformation](./Data_Transformation_Mapping.md) - Transforming data structures

## Summary

The LLM Summarizer is a powerful tool for automatically generating structured summaries from any data source. It handles complex data structures, provides consistent output, and can be customized for your specific needs. Use it to create executive summaries, extract key insights, and make complex data more accessible.

