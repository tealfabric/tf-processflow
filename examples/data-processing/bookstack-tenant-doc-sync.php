<?php
/**
 * BookStack Tenant Doc Sync – Sync one tenant's documentation page
 *
 * Process step (system tenant /root): aggregate tenant data, generate content via LLM,
 * ensure BookStack book exists, create or update page (page name = tenant name), write mapping to datapool.
 *
 * Config (define in snippet header; secrets from keystore):
 *   BOOKSTACK_BASE_URL   - Base URL of BookStack (e.g. https://wiki.example.com). From keystore key "bookstack_base_url" or set in header.
 *   BOOK_NAME            - Book name for tenant pages (e.g. "Tenant Documentation"). Default: "Tenant Documentation".
 *   BOOKSTACK_DATAPOOL_SCHEMA - Datapool schema for tenant->page mapping. Default: "bookstack_tenant_pages".
 * Keystore keys (process_id = $process_id): bookstack_api_id, bookstack_api_secret (BookStack API token); optionally bookstack_base_url.
 *
 * Input: process_input must contain tenant_id (string).
 * Output: ['success' => true, 'data' => ['tenant_id', 'bookstack_page_id', 'book_id', 'created' => bool]]
 */

$BOOK_NAME = 'Tenant Documentation';
$BOOKSTACK_DATAPOOL_SCHEMA = 'bookstack_tenant_pages';

$input = $process_input ?? [];
$tenantId = $input['tenant_id'] ?? $input['data']['tenant_id'] ?? null;
if (empty($tenantId)) {
    return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'tenant_id is required'], 'data' => null];
}

try {
    $processId = $process_id ?? null;
    if (empty($processId)) {
        return ['success' => false, 'error' => ['code' => 'CONFIG_ERROR', 'message' => 'process_id not available'], 'data' => null];
    }

    $baseUrl = rtrim($keystore->get('bookstack_base_url', $processId) ?? '', '/');
    if (empty($baseUrl)) {
        return ['success' => false, 'error' => ['code' => 'CONFIG_ERROR', 'message' => 'bookstack_base_url not in keystore'], 'data' => null];
    }
    $apiId = $keystore->get('bookstack_api_id', $processId);
    $apiSecret = $keystore->get('bookstack_api_secret', $processId);
    if (empty($apiId) || empty($apiSecret)) {
        return ['success' => false, 'error' => ['code' => 'CONFIG_ERROR', 'message' => 'bookstack_api_id and bookstack_api_secret required in keystore'], 'data' => null];
    }
    $authHeader = 'Token ' . $apiId . ':' . $apiSecret;
    $headers = ['Authorization' => $authHeader];

    $tenantRow = $db->prepare("SELECT * FROM Tenants WHERE tenant_id = ?");
    $tenantRow->execute([$tenantId]);
    $tenant = $tenantRow->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        return ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Tenant not found'], 'data' => null];
    }

    $processCount = $db->prepare("SELECT COUNT(*) FROM Processes WHERE tenant_id = ?");
    $processCount->execute([$tenantId]);
    $processCount = (int) $processCount->fetchColumn();
    $webappCount = $db->prepare("SELECT COUNT(*) FROM WebApps WHERE tenant_id = ?");
    $webappCount->execute([$tenantId]);
    $webappCount = (int) $webappCount->fetchColumn();
    $integrationCount = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM IntegrationConnectorConfiguration WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $integrationCount = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
    }
    $apiKeyCount = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM ApiKeys WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $apiKeyCount = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
    }

    $setupStatus = [];
    if (!empty($tenant['setup_status'])) {
        $setupStatus = json_decode($tenant['setup_status'], true) ?: [];
    }

    $payload = [
        'tenant_id' => $tenantId,
        'name' => $tenant['name'] ?? '',
        'legal_name' => $tenant['legal_name'] ?? null,
        'industry' => $tenant['industry'] ?? null,
        'website' => $tenant['website'] ?? null,
        'tenant_type' => $tenant['tenant_type'] ?? null,
        'country' => $tenant['country'] ?? null,
        'created_at' => $tenant['created_at'] ?? null,
        'processes_count' => $processCount,
        'webapps_count' => $webappCount,
        'integrations_count' => $integrationCount,
        'api_keys_count' => $apiKeyCount,
        'setup_status' => $setupStatus
    ];

    $systemPrompt = 'You are writing an internal wiki page for a tenant. Write a concise "Basic information" section and a "Current status" section that summarizes subscription, usage (processes, webapps, integrations, API keys), and setup progress. Use clear headings and bullet points. Output valid HTML or Markdown only. Do not invent data.';
    $userMessage = "Tenant data (JSON):\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $llmResult = $llm->callLLM($systemPrompt . "\n\n" . $userMessage, ['max_tokens' => 2000]);
    if (empty($llmResult['success']) || !$llmResult['success']) {
        return ['success' => false, 'error' => ['code' => 'LLM_ERROR', 'message' => $llmResult['error']['message'] ?? 'LLM call failed'], 'data' => null];
    }
    $content = $llmResult['response'] ?? '';
    if (empty($content)) {
        return ['success' => false, 'error' => ['code' => 'LLM_ERROR', 'message' => 'Empty LLM response'], 'data' => null];
    }
    if (strpos($content, '<') !== 0 && strpos($content, '#') === 0) {
        $content = '<pre>' . htmlspecialchars($content) . '</pre>';
    }

    $tenantName = $tenant['name'] ?? ('Tenant ' . $tenantId);
    $pageName = $tenantName;

    $booksResp = $api->get($baseUrl . '/api/books', $headers);
    $booksSuccess = isset($booksResp['success']) && $booksResp['success'] && isset($booksResp['body']);
    $booksData = $booksSuccess ? json_decode($booksResp['body'], true) : null;
    $bookId = null;
    if ($booksData && !empty($booksData['data'])) {
        foreach ($booksData['data'] as $b) {
            if (isset($b['name']) && (string)$b['name'] === $BOOK_NAME) {
                $bookId = (int) $b['id'];
                break;
            }
        }
    }
    if ($bookId === null) {
        $createBookResp = $api->post($baseUrl . '/api/books', ['name' => $BOOK_NAME], $headers);
        if (empty($createBookResp['success']) || !$createBookResp['success'] || empty($createBookResp['body'])) {
            return ['success' => false, 'error' => ['code' => 'BOOKSTACK_ERROR', 'message' => 'Failed to create book', 'details' => $createBookResp['body'] ?? ''], 'data' => null];
        }
        $createData = json_decode($createBookResp['body'], true);
        $bookId = isset($createData['id']) ? (int) $createData['id'] : null;
        if ($bookId === null) {
            return ['success' => false, 'error' => ['code' => 'BOOKSTACK_ERROR', 'message' => 'Book creation response missing id'], 'data' => null];
        }
    }

    $existingPageId = null;
    $existingDataId = null;
    try {
        $q = $datapool->query("SELECT * FROM " . $BOOKSTACK_DATAPOOL_SCHEMA . " WHERE tenant_id = " . $db->quote($tenantId));
        if (!empty($q)) {
            $row = $q[0];
            $existingDataId = $row['_id'] ?? $row['data_id'] ?? null;
            $contentDecoded = isset($row['bookstack_page_id']) ? $row : (is_string($row['data_content'] ?? null) ? json_decode($row['data_content'], true) : ($row['data_content'] ?? []));
            if (is_array($contentDecoded) && isset($contentDecoded['bookstack_page_id'])) {
                $existingPageId = (int) $contentDecoded['bookstack_page_id'];
            }
        }
    } catch (Exception $e) {
    }

    $created = false;
    if ($existingPageId) {
        $updateResp = $api->put($baseUrl . '/api/pages/' . $existingPageId, ['name' => $pageName, 'html' => $content], $headers);
        if (empty($updateResp['success']) || !$updateResp['success']) {
            return ['success' => false, 'error' => ['code' => 'BOOKSTACK_ERROR', 'message' => 'Failed to update page', 'details' => $updateResp['body'] ?? ''], 'data' => null];
        }
        $pageId = $existingPageId;
    } else {
        $createPageResp = $api->post($baseUrl . '/api/pages', ['book_id' => $bookId, 'name' => $pageName, 'html' => $content], $headers);
        if (empty($createPageResp['success']) || !$createPageResp['success'] || empty($createPageResp['body'])) {
            return ['success' => false, 'error' => ['code' => 'BOOKSTACK_ERROR', 'message' => 'Failed to create page', 'details' => $createPageResp['body'] ?? ''], 'data' => null];
        }
        $pageData = json_decode($createPageResp['body'], true);
        $pageId = isset($pageData['id']) ? (int) $pageData['id'] : null;
        if ($pageId === null) {
            return ['success' => false, 'error' => ['code' => 'BOOKSTACK_ERROR', 'message' => 'Page creation response missing id'], 'data' => null];
        }
        $created = true;
    }

    $mappingData = [
        'tenant_id' => $tenantId,
        'bookstack_page_id' => $pageId,
        'bookstack_book_id' => $bookId,
        'last_synced_at' => date('c')
    ];
    try {
        if ($existingDataId) {
            $datapool->update($BOOKSTACK_DATAPOOL_SCHEMA, $existingDataId, $mappingData);
        } else {
            $datapool->insert($BOOKSTACK_DATAPOOL_SCHEMA, $mappingData, ['auto_create_schema' => true]);
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => ['code' => 'DATAPOOL_ERROR', 'message' => 'Failed to write mapping', 'details' => $e->getMessage()], 'data' => ['tenant_id' => $tenantId, 'bookstack_page_id' => $pageId]];
    }

    return [
        'success' => true,
        'data' => [
            'tenant_id' => $tenantId,
            'bookstack_page_id' => $pageId,
            'book_id' => $bookId,
            'created' => $created
        ],
        'message' => $created ? 'Page created' : 'Page updated'
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => ['code' => 'SYNC_ERROR', 'message' => $e->getMessage(), 'details' => $e->getFile() . ':' . $e->getLine()],
        'data' => null
    ];
}
