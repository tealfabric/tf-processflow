<?php
/**
 * BookStack Tenant Doc Sync – Queue one async execution per tenant (low priority)
 *
 * Process step (system tenant /root): loops through tenant_ids from the previous step,
 * enqueues one async execution of the BookStack sync process per tenant in low-priority mode.
 *
 * Config (snippet header or process_input):
 *   SYNC_PROCESS_ID - Process ID of the process that runs bookstack-tenant-doc-sync (single-tenant).
 *     Pass in process_input.sync_process_id or set in snippet header.
 *
 * Input: tenant_ids from previous step (list-tenants).
 *   - process_input.data.tenant_ids (array) or process_input.tenant_ids (array).
 *
 * Output: { success, data: { queued, queue_ids, tenant_ids }, message }
 */

$input = $process_input ?? [];
$tenantIds = $input['data']['tenant_ids'] ?? $input['tenant_ids'] ?? [];
if (!is_array($tenantIds)) {
    $tenantIds = [];
}
$syncProcessId = $input['sync_process_id'] ?? $input['data']['sync_process_id'] ?? null;

if (empty($syncProcessId)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'CONFIG_ERROR',
            'message' => 'sync_process_id is required',
            'details' => 'Set process_input.sync_process_id to the process ID that runs bookstack-tenant-doc-sync (one tenant per run).'
        ],
        'data' => null
    ];
}

if (empty($tenantIds)) {
    return [
        'success' => true,
        'data' => [
            'queued' => 0,
            'queue_ids' => [],
            'tenant_ids' => []
        ],
        'message' => 'No tenants to queue'
    ];
}

$baseUrl = rtrim($app_url ?? '', '/');
if (empty($baseUrl)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'CONFIG_ERROR',
            'message' => 'app_url not available',
            'details' => 'Ensure process runs in a context where app_url is set.'
        ],
        'data' => null
    ];
}

$queueIds = [];
$errors = [];

foreach ($tenantIds as $tid) {
    if (empty($tid)) {
        continue;
    }
    try {
        $resp = $api->post($baseUrl . '/api/v1/processflow?action=execute-process', [
            'process_id' => $syncProcessId,
            'input_data' => ['tenant_id' => $tid],
            'options' => ['async' => true, 'priority' => 'low']
        ]);
        $ok = isset($resp['success']) && $resp['success'];
        if ($ok && isset($resp['data']['queue_id'])) {
            $queueIds[] = $resp['data']['queue_id'];
        } elseif (!$ok) {
            $errors[] = $tid . ': ' . ($resp['error'] ?? $resp['data']['error'] ?? json_encode($resp));
        }
    } catch (Exception $e) {
    }
}

$queued = count($queueIds);
return [
    'success' => true,
    'data' => [
        'queued' => $queued,
        'queue_ids' => $queueIds,
        'tenant_ids' => $tenantIds,
        'errors' => $errors
    ],
    'message' => $queued . ' tenant(s) queued for async BookStack sync (low priority)'
];
