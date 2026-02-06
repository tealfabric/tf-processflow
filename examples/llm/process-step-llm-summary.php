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
