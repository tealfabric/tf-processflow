# Using LLM in ProcessFlow Code Snippets

This guide explains how to integrate Large Language Models (LLM) into your ProcessFlow code snippets using the `$llm` service.

## Overview

The `$llm` service provides tenant-scoped access to LLM capabilities within ProcessFlow code snippets. It handles:

- **Authentication & Configuration**: Uses tenant-specific LLM settings
- **Rate Limiting**: Enforces per-tenant and per-user rate limits
- **Usage Tracking**: Logs all LLM calls with token usage and costs
- **Error Handling**: Provides structured error responses

## Available Methods

### `$llm->callLLM(string $prompt, array $options = []): array`

Makes a synchronous LLM call and returns the complete response.

**Parameters:**
- `$prompt` - The prompt/question to send to the LLM
- `$options` - Optional configuration overrides:
  - `model` - Model name (e.g., 'gpt-4o', 'gpt-5-mini')
  - `temperature` - Creativity level (0.0 - 1.0)
  - `max_tokens` - Maximum response length
  - `timeout` - Request timeout in seconds

**Returns:**
```php
[
    'success' => true|false,
    'call_id' => 'unique_call_identifier',
    'tenant_id' => 'your_tenant_id',
    'user_id' => 'executing_user_id',
    'prompt' => 'your_prompt',
    'response' => [
        'response' => 'The LLM response text...',
        'usage' => [
            'prompt_tokens' => 50,
            'completion_tokens' => 100,
            'total_tokens' => 150
        ]
    ],
    'execution_time' => 1.234,  // seconds
    'usage' => [...],
    'error' => null  // or error details if failed
]
```

### `$llm->callLLMStream(string $prompt, array $options = [], ?callable $callback = null): array`

Makes a streaming LLM call. Useful for long responses where you want incremental output.

---

## Example 1: Simple Text Generation

Generate a summary of input data:

```php
<?php
// Get input data
$data = $process_input['result']['data'] ?? [];

if (empty($data)) {
    return [
        'success' => false,
        'error' => 'No input data provided'
    ];
}

// Build prompt
$prompt = "Summarize the following data in 2-3 sentences:\n\n" . json_encode($data, JSON_PRETTY_PRINT);

// Call LLM
$result = $llm->callLLM($prompt, [
    'temperature' => 0.3,  // Lower temperature for more focused output
    'max_tokens' => 200
]);

if (!$result['success']) {
    return [
        'success' => false,
        'error' => $result['error']['message'] ?? 'LLM call failed'
    ];
}

return [
    'success' => true,
    'data' => [
        'summary' => $result['response']['response'],
        'tokens_used' => $result['response']['usage']['total_tokens'] ?? 0
    ]
];
```

---

## Example 2: Data Classification

Classify customer feedback into categories:

```php
<?php
$feedback = $process_input['result']['feedback'] ?? '';

if (empty($feedback)) {
    return ['success' => false, 'error' => 'No feedback provided'];
}

$prompt = <<<PROMPT
Classify the following customer feedback into one of these categories:
- positive
- negative
- neutral
- feature_request
- bug_report
- question

Respond with ONLY the category name, nothing else.

Feedback: "$feedback"
PROMPT;

$result = $llm->callLLM($prompt, [
    'temperature' => 0.1,  // Very low for consistent classification
    'max_tokens' => 20
]);

if (!$result['success']) {
    return ['success' => false, 'error' => 'Classification failed'];
}

$category = strtolower(trim($result['response']['response']));

// Validate category
$validCategories = ['positive', 'negative', 'neutral', 'feature_request', 'bug_report', 'question'];
if (!in_array($category, $validCategories)) {
    $category = 'unknown';
}

return [
    'success' => true,
    'data' => [
        'feedback' => $feedback,
        'category' => $category,
        'confidence' => 'high'  // Low temperature = high confidence
    ]
];
```

---

## Example 3: Structured Data Extraction

Extract structured information from unstructured text:

```php
<?php
$text = $process_input['result']['raw_text'] ?? '';

if (empty($text)) {
    return ['success' => false, 'error' => 'No text provided'];
}

$prompt = <<<PROMPT
Extract the following information from the text below and return as JSON:
- company_name: string
- contact_email: string or null
- phone_number: string or null
- address: string or null
- products_mentioned: array of strings

Text:
$text

Respond ONLY with valid JSON, no other text.
PROMPT;

$result = $llm->callLLM($prompt, [
    'temperature' => 0.2,
    'max_tokens' => 500
]);

if (!$result['success']) {
    return ['success' => false, 'error' => 'Extraction failed'];
}

// Parse JSON response
$extracted = json_decode($result['response']['response'], true);

if (json_last_error() !== JSON_ERROR_NONE) {
    // Try to extract JSON from response (sometimes model adds text around it)
    preg_match('/\{[^{}]*\}/s', $result['response']['response'], $matches);
    if (!empty($matches[0])) {
        $extracted = json_decode($matches[0], true);
    }
}

if (!$extracted) {
    return ['success' => false, 'error' => 'Failed to parse LLM response as JSON'];
}

return [
    'success' => true,
    'data' => [
        'extracted' => $extracted,
        'source_text' => substr($text, 0, 100) . '...'
    ]
];
```

---

## Example 4: Analyzing a File with LLM

Read a file and have the LLM analyze its contents:

```php
<?php
// File path is relative to tenant storage: storage/tenantdata/<tenant_id>/
$filePath = $process_input['result']['file_path'] ?? 'reports/latest.txt';

// Read the file using tenant-scoped file operations
$content = $file_get_contents($filePath);

if ($content === false) {
    return [
        'success' => false,
        'error' => "Could not read file: $filePath"
    ];
}

// Limit content size to avoid token limits
$maxChars = 10000;
if (strlen($content) > $maxChars) {
    $content = substr($content, 0, $maxChars) . "\n\n[Content truncated...]";
}

$prompt = <<<PROMPT
Analyze the following document and provide:
1. A brief summary (2-3 sentences)
2. Key points (bullet list)
3. Any action items or recommendations

Document content:
---
$content
---
PROMPT;

$result = $llm->callLLM($prompt, [
    'temperature' => 0.5,
    'max_tokens' => 1000
]);

if (!$result['success']) {
    return [
        'success' => false,
        'error' => $result['error']['message'] ?? 'Analysis failed'
    ];
}

return [
    'success' => true,
    'data' => [
        'file_analyzed' => $filePath,
        'file_size' => strlen($content),
        'analysis' => $result['response']['response'],
        'tokens_used' => $result['response']['usage']['total_tokens'] ?? 0
    ]
];
```

---

## Example 5: LLM Generates Content and Saves to File

Have the LLM generate content and save it to a file:

```php
<?php
$topic = $process_input['result']['topic'] ?? 'Introduction to AI';
$outputDir = 'generated/articles';

// Build the prompt
$prompt = <<<PROMPT
Write a professional article about: "$topic"

The article should:
- Be approximately 500 words
- Have a clear introduction, body, and conclusion
- Include relevant examples
- Be suitable for a business audience
PROMPT;

// Call LLM
$result = $llm->callLLM($prompt, [
    'temperature' => 0.7,
    'max_tokens' => 1500
]);

if (!$result['success']) {
    return [
        'success' => false,
        'error' => 'Content generation failed: ' . ($result['error']['message'] ?? 'Unknown error')
    ];
}

$articleContent = $result['response']['response'];

// Create output directory if it doesn't exist
if (!$is_dir($outputDir)) {
    $mkdir($outputDir, 0700, true);
}

// Generate filename from topic
$safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($topic));
$safeFilename = substr($safeFilename, 0, 50);
$timestamp = date('Y-m-d_His');
$filename = "{$outputDir}/{$safeFilename}_{$timestamp}.md";

// Add metadata header
$fileContent = "# {$topic}\n\n";
$fileContent .= "_Generated: " . date('Y-m-d H:i:s') . "_\n\n";
$fileContent .= "---\n\n";
$fileContent .= $articleContent;

// Save to file
$bytes = $file_put_contents($filename, $fileContent);

if ($bytes === false) {
    return [
        'success' => false,
        'error' => 'Failed to save generated content'
    ];
}

$chmod($filename, 0600);

return [
    'success' => true,
    'data' => [
        'topic' => $topic,
        'file_path' => $filename,
        'file_size' => $bytes,
        'word_count' => str_word_count($articleContent),
        'tokens_used' => $result['response']['usage']['total_tokens'] ?? 0
    ],
    'message' => "Article generated and saved to: $filename"
];
```

---

## Example 6: Multi-Step Analysis with File Input/Output

Process a CSV file, analyze with LLM, and generate a report:

```php
<?php
$inputFile = $process_input['result']['csv_file'] ?? 'data/sales.csv';
$outputDir = 'reports';

// Read CSV file
$csvContent = $file_get_contents($inputFile);
if ($csvContent === false) {
    return ['success' => false, 'error' => "Cannot read file: $inputFile"];
}

// Parse CSV (simple parsing - first 50 rows for analysis)
$lines = explode("\n", $csvContent);
$header = str_getcsv(array_shift($lines));
$rows = [];
$count = 0;

foreach ($lines as $line) {
    if (empty(trim($line))) continue;
    $rows[] = array_combine($header, str_getcsv($line));
    $count++;
    if ($count >= 50) break;  // Limit for LLM context
}

if (empty($rows)) {
    return ['success' => false, 'error' => 'No data rows found in CSV'];
}

// Convert to readable format for LLM
$dataDescription = "Columns: " . implode(', ', $header) . "\n\n";
$dataDescription .= "Sample data (first " . count($rows) . " rows):\n";
$dataDescription .= json_encode($rows, JSON_PRETTY_PRINT);

// Step 1: Get data insights
$insightPrompt = <<<PROMPT
Analyze this dataset and provide insights:

$dataDescription

Please provide:
1. Data quality assessment
2. Key patterns or trends
3. Notable outliers or anomalies
4. Recommendations for further analysis
PROMPT;

$insightResult = $llm->callLLM($insightPrompt, [
    'temperature' => 0.5,
    'max_tokens' => 1000
]);

if (!$insightResult['success']) {
    return ['success' => false, 'error' => 'Insight analysis failed'];
}

$insights = $insightResult['response']['response'];

// Step 2: Generate executive summary
$summaryPrompt = <<<PROMPT
Based on this analysis, write a brief executive summary (3-4 sentences) suitable for business stakeholders:

$insights
PROMPT;

$summaryResult = $llm->callLLM($summaryPrompt, [
    'temperature' => 0.3,
    'max_tokens' => 200
]);

$summary = $summaryResult['success'] ? $summaryResult['response']['response'] : 'Summary generation failed.';

// Create report directory
if (!$is_dir($outputDir)) {
    $mkdir($outputDir, 0700, true);
}

// Generate report file
$reportFilename = $outputDir . '/analysis_report_' . date('Y-m-d_His') . '.md';
$report = "# Data Analysis Report\n\n";
$report .= "**Generated:** " . date('Y-m-d H:i:s') . "\n";
$report .= "**Source File:** $inputFile\n";
$report .= "**Rows Analyzed:** " . count($rows) . "\n\n";
$report .= "---\n\n";
$report .= "## Executive Summary\n\n$summary\n\n";
$report .= "---\n\n";
$report .= "## Detailed Analysis\n\n$insights\n\n";
$report .= "---\n\n";
$report .= "## Data Preview\n\n";
$report .= "```json\n" . json_encode(array_slice($rows, 0, 5), JSON_PRETTY_PRINT) . "\n```\n";

$bytes = $file_put_contents($reportFilename, $report);
$chmod($reportFilename, 0600);

// Calculate total tokens
$totalTokens = ($insightResult['response']['usage']['total_tokens'] ?? 0) + 
               ($summaryResult['response']['usage']['total_tokens'] ?? 0);

return [
    'success' => true,
    'data' => [
        'source_file' => $inputFile,
        'rows_analyzed' => count($rows),
        'report_file' => $reportFilename,
        'report_size' => $bytes,
        'executive_summary' => $summary,
        'total_tokens_used' => $totalTokens
    ],
    'message' => "Analysis complete. Report saved to: $reportFilename"
];
```

---

## Best Practices

### 1. Handle Errors Gracefully
Always check `$result['success']` before using the response:

```php
$result = $llm->callLLM($prompt);
if (!$result['success']) {
    $log_message('[ERROR] LLM call failed: ' . ($result['error']['message'] ?? 'Unknown'));
    return ['success' => false, 'error' => 'AI processing failed'];
}
```

### 2. Use Appropriate Temperature
- **Low (0.1-0.3)**: Classification, extraction, factual responses
- **Medium (0.4-0.6)**: Balanced creativity and accuracy
- **High (0.7-0.9)**: Creative writing, brainstorming

### 3. Limit Input Size
LLMs have token limits. Truncate or summarize large inputs:

```php
$maxChars = 8000;
if (strlen($content) > $maxChars) {
    $content = substr($content, 0, $maxChars) . "\n[Truncated...]";
}
```

### 4. Validate LLM Output
When expecting structured output (JSON, categories), always validate:

```php
$json = json_decode($result['response']['response'], true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Handle invalid JSON
}
```

### 5. Log Token Usage
Monitor costs by logging token usage:

```php
$log_message('[INFO] LLM tokens used: ' . ($result['response']['usage']['total_tokens'] ?? 0));
```

---

## Rate Limits

The LLM service enforces rate limits:

| Limit Type | Default |
|------------|---------|
| Tenant per hour | 1,000 calls |
| User per hour | 100 calls |
| Tenant per day | 10,000 calls |
| User per day | 1,000 calls |

If rate limits are exceeded, the `$llm->callLLM()` method will return an error with the rate limit information.

---

## File Operations Reference

When working with files in LLM code snippets, use these tenant-scoped functions:

| Function | Description |
|----------|-------------|
| `$file_get_contents($path)` | Read file contents |
| `$file_put_contents($path, $data)` | Write content to file |
| `$mkdir($path, $mode, $recursive)` | Create directory |
| `$is_dir($path)` | Check if directory exists |
| `$file_exists($path)` | Check if file exists |
| `$chmod($path, $mode)` | Set file permissions |

All paths are relative to: `storage/tenantdata/<tenant_id>/`

---

## See Also

- [ProcessFlow Code Snippets Guide](PROCESSFLOW_CODE_SNIPPETS_GUIDE.md)
- [Email MIME Parsing](Email_MIME_Parsing.md)
- [ProcessFlow Quick Reference](PROCESSFLOW_QUICK_REFERENCE.md)
