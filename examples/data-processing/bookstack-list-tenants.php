<?php
/**
 * BookStack Tenant Doc Sync – List Tenants
 *
 * Process step (system tenant /root): returns tenant IDs to sync.
 * Use as Step 1 of "BookStack Tenant Doc Sync" process.
 *
 * Config (snippet header / process input):
 *   - tenant_id (optional): sync only this tenant; if omitted, sync all active tenants.
 *
 * Output: ['success' => true, 'data' => ['tenant_ids' => [...]]]
 */

$input = $process_input ?? [];
$singleTenantId = $input['tenant_id'] ?? null;

try {
    if (!empty($singleTenantId)) {
        $stmt = $db->prepare("SELECT tenant_id FROM Tenants WHERE tenant_id = ? LIMIT 1");
        $stmt->execute([$singleTenantId]);
    } else {
        $stmt = $db->query("SELECT tenant_id FROM Tenants WHERE tenant_id != '' ORDER BY name ASC");
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tenantIds = array_column($rows, 'tenant_id');

    return [
        'success' => true,
        'data' => [
            'tenant_ids' => $tenantIds,
            'count' => count($tenantIds)
        ],
        'message' => count($tenantIds) . ' tenant(s) to sync'
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'LIST_TENANTS_ERROR',
            'message' => 'Failed to list tenants',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
