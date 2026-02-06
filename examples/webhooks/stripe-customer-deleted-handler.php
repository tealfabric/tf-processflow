<?php
/**
 * Stripe Customer Deleted Webhook Handler
 * 
 * System-level process step for handling Stripe customer.deleted events
 * 
 * This code snippet:
 * 1. Validates the Stripe event structure
 * 2. Extracts deleted customer information
 * 3. Finds tenant_id from stripe_customer_id in Subscriptions/Billing/Tenants tables
 * 4. Marks existing subscriptions as inactive
 * 5. Creates a new free tier subscription for the tenant
 * 6. Clears upcoming invoices from Billing metadata
 * 7. Updates Billing table to remove Stripe customer references
 * 
 * Input: Stripe webhook event JSON (customer.deleted)
 * Output: Success/failure with customer deletion details
 */

// Ensure Logger is available for system-level logging
if (!function_exists('log_message')) {
    // Fallback logger for local testing outside ProcessFlow runtime
    function log_message($level, $message, $context = []) {
        error_log(strtoupper($level) . ": " . $message . " " . json_encode($context));
    }
}

// 1. Validate input structure
if (empty($process_input) || !is_array($process_input)) {
    log_message('error', 'Stripe Customer Deleted Webhook: Invalid input data', ['input' => $process_input]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid input data',
            'details' => 'Input must be a valid Stripe event object'
        ],
        'data' => null
    ];
}

// Validate event type
$eventType = $process_input['type'] ?? '';
if ($eventType !== 'customer.deleted') {
    log_message('warning', 'Stripe Customer Deleted Webhook: Received unexpected event type', ['event_type' => $eventType]);
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EVENT_TYPE',
            'message' => 'Invalid event type',
            'details' => "Expected 'customer.deleted', got: " . $eventType
        ],
        'data' => null
    ];
}

// Extract Stripe customer object
$stripeEventData = $process_input['data'] ?? null;
$stripeCustomer = $stripeEventData['object'] ?? null;

if (!$stripeCustomer || empty($stripeCustomer['id'])) {
    log_message('error', 'Stripe Customer Deleted Webhook: Missing essential customer data in event', ['event_data' => $stripeEventData]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing essential customer data',
            'details' => 'Stripe event data.object is missing id'
        ],
        'data' => null
    ];
}

// 2. Extract required fields from Stripe customer
$stripeCustomerId = $stripeCustomer['id'];
$customerEmail = $stripeCustomer['email'] ?? null;
$customerName = $stripeCustomer['name'] ?? $stripeCustomer['individual_name'] ?? null;

log_message('info', 'Stripe Customer Deleted Webhook: Processing customer deletion', [
    'stripe_customer_id' => $stripeCustomerId,
    'customer_email' => $customerEmail,
    'customer_name' => $customerName
]);

// 3. Find tenant_id from stripe_customer_id
$tenantId = null;
try {
    // Try to find tenant_id from Subscriptions table first (most reliable)
    $stmt = $db->prepare("SELECT DISTINCT tenant_id FROM Subscriptions WHERE stripe_customer_id = ? LIMIT 1");
    $stmt->execute([$stripeCustomerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $tenantId = $result['tenant_id'];
        log_message('info', 'Stripe Customer Deleted Webhook: Found tenant_id from Subscriptions table', ['tenant_id' => $tenantId]);
    } else {
        // Fallback: Try to find tenant_id from Billing table
        $stmt = $db->prepare("SELECT tenant_id FROM Billing WHERE stripe_customer_id = ? LIMIT 1");
        $stmt->execute([$stripeCustomerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $tenantId = $result['tenant_id'];
            log_message('info', 'Stripe Customer Deleted Webhook: Found tenant_id from Billing table', ['tenant_id' => $tenantId]);
        }
    }
    
    // If still not found, check Tenants table (if stripe_customer_id column exists)
    if (!$tenantId) {
        try {
            $stmt = $db->prepare("SELECT tenant_id FROM Tenants WHERE stripe_customer_id = ? LIMIT 1");
            $stmt->execute([$stripeCustomerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $tenantId = $result['tenant_id'];
                log_message('info', 'Stripe Customer Deleted Webhook: Found tenant_id from Tenants table', ['tenant_id' => $tenantId]);
            }
        } catch (PDOException $e) {
            // Column might not exist, continue
            log_message('debug', 'Stripe Customer Deleted Webhook: Tenants.stripe_customer_id column may not exist', ['error' => $e->getMessage()]);
        }
    }
} catch (PDOException $e) {
    log_message('error', 'Stripe Customer Deleted Webhook: Database error during tenant lookup', ['error' => $e->getMessage()]);
    // Continue, tenantId will be null and handled below
}

if (empty($tenantId)) {
    log_message('warning', 'Stripe Customer Deleted Webhook: Tenant not found for Stripe customer ID', ['stripe_customer_id' => $stripeCustomerId]);
    // Return success but note that no tenant was found
    return [
        'success' => true,
        'data' => [
            'stripe_customer_id' => $stripeCustomerId,
            'tenant_id' => null,
            'action' => 'no_tenant_found',
            'message' => 'Customer deleted but no associated tenant found'
        ],
        'message' => 'Customer deletion processed (no tenant found)'
    ];
}

// Start database transaction
$db->beginTransaction();
try {
    // 4. Get free plan from SubscriptionPlans table
    $planStmt = $db->prepare("
        SELECT plan_id, plan_code 
        FROM SubscriptionPlans 
        WHERE plan_code = 'free' AND is_active = 1
        LIMIT 1
    ");
    $planStmt->execute();
    $planResult = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$planResult || empty($planResult['plan_id'])) {
        throw new Exception('Free subscription plan not found in database');
    }
    
    $freePlanId = $planResult['plan_id'];
    
    // Get free plan features
    $featuresStmt = $db->prepare("
        SELECT sf.feature_code, sf.feature_value
        FROM SubscriptionFeatures sf
        WHERE sf.plan_id = ?
    ");
    $featuresStmt->execute([$freePlanId]);
    $features = [];
    while ($feature = $featuresStmt->fetch(PDO::FETCH_ASSOC)) {
        $features[$feature['feature_code']] = $feature['feature_value'];
    }
    $featuresJson = !empty($features) ? json_encode($features) : null;
    
    // 5. Mark all existing subscriptions for this tenant as inactive
    $updateSubscriptionsStmt = $db->prepare("
        UPDATE Subscriptions 
        SET status = 'inactive',
            updated_at = UTC_TIMESTAMP()
        WHERE tenant_id = ? 
        AND status IN ('active', 'change_initiated')
    ");
    $updateSubscriptionsStmt->execute([$tenantId]);
    $inactivatedCount = $updateSubscriptionsStmt->rowCount();
    
    log_message('info', 'Stripe Customer Deleted Webhook: Marked subscriptions as inactive', [
        'tenant_id' => $tenantId,
        'inactivated_count' => $inactivatedCount
    ]);
    
    // 6. Create new free tier subscription
    $newSubscriptionId = null;
    $subscriptionIdStmt = $db->prepare("SELECT UUID() as uuid");
    $subscriptionIdStmt->execute();
    $uuidResult = $subscriptionIdStmt->fetch(PDO::FETCH_ASSOC);
    $newSubscriptionId = $uuidResult['uuid'] ?? null;
    
    if (!$newSubscriptionId) {
        throw new Exception('Failed to generate UUID for new subscription');
    }
    
    // Store metadata about the customer deletion
    $metadata = [
        'stripe_event_id' => $process_input['id'] ?? null,
        'stripe_created' => $process_input['created'] ?? null,
        'stripe_livemode' => $process_input['livemode'] ?? false,
        'deleted_stripe_customer_id' => $stripeCustomerId,
        'deleted_at' => date('Y-m-d H:i:s'),
        'previous_subscriptions_inactivated' => $inactivatedCount
    ];
    $metadataJson = json_encode($metadata);
    
    $insertSubscriptionStmt = $db->prepare("
        INSERT INTO Subscriptions (
            subscription_id, tenant_id, subscription_type, plan_id,
            status, method, features, metadata,
            created_at, updated_at
        ) VALUES (
            ?, ?, 'free', ?,
            'active', 'trial', ?, ?,
            UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )
    ");
    $insertSubscriptionStmt->execute([
        $newSubscriptionId,
        $tenantId,
        $freePlanId,
        $featuresJson,
        $metadataJson
    ]);
    
    log_message('info', 'Stripe Customer Deleted Webhook: Created new free tier subscription', [
        'tenant_id' => $tenantId,
        'subscription_id' => $newSubscriptionId,
        'plan_id' => $freePlanId
    ]);
    
    // 7. Update Billing table: clear Stripe references and cancel upcoming invoices
    $billingStmt = $db->prepare("
        SELECT billing_id, metadata 
        FROM Billing 
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $billingStmt->execute([$tenantId]);
    $billingResult = $billingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($billingResult) {
        $billingId = $billingResult['billing_id'];
        $existingMetadata = $billingResult['metadata'];
        
        // Parse existing metadata and remove upcoming_invoice
        $metadata = [];
        if (!empty($existingMetadata)) {
            try {
                $metadata = json_decode($existingMetadata, true);
                if (!is_array($metadata)) {
                    $metadata = [];
                }
            } catch (Exception $e) {
                log_message('warning', 'Stripe Customer Deleted Webhook: Failed to parse existing billing metadata', ['error' => $e->getMessage()]);
                $metadata = [];
            }
        }
        
        // Remove upcoming_invoice from metadata
        unset($metadata['upcoming_invoice']);
        
        // Add customer deletion info to metadata
        $metadata['stripe_customer_deleted'] = [
            'stripe_customer_id' => $stripeCustomerId,
            'deleted_at' => date('Y-m-d H:i:s'),
            'stripe_event_id' => $process_input['id'] ?? null
        ];
        
        $updatedMetadataJson = json_encode($metadata);
        
        // Update Billing table: clear Stripe customer ID, payment method, and upcoming invoice
        $updateBillingStmt = $db->prepare("
            UPDATE Billing 
            SET stripe_customer_id = NULL,
                stripe_payment_method_id = NULL,
                next_billing_date = NULL,
                metadata = ?,
                subscription_id = ?,
                billing_method = 'manual',
                status = 'inactive',
                updated_at = UTC_TIMESTAMP()
            WHERE billing_id = ?
        ");
        $updateBillingStmt->execute([
            $updatedMetadataJson,
            $newSubscriptionId,
            $billingId
        ]);
        
        log_message('info', 'Stripe Customer Deleted Webhook: Updated Billing table', [
            'billing_id' => $billingId,
            'cleared_stripe_customer_id' => true,
            'cleared_upcoming_invoice' => true
        ]);
    } else {
        // No billing record found, create one for the new free subscription
        $billingIdStmt = $db->prepare("SELECT UUID() as uuid");
        $billingIdStmt->execute();
        $billingUuidResult = $billingIdStmt->fetch(PDO::FETCH_ASSOC);
        $newBillingId = $billingUuidResult['uuid'] ?? null;
        
        if ($newBillingId) {
            $metadata = [
                'stripe_customer_deleted' => [
                    'stripe_customer_id' => $stripeCustomerId,
                    'deleted_at' => date('Y-m-d H:i:s'),
                    'stripe_event_id' => $process_input['id'] ?? null
                ]
            ];
            $metadataJson = json_encode($metadata);
            
            $insertBillingStmt = $db->prepare("
                INSERT INTO Billing (
                    billing_id, tenant_id, subscription_id,
                    billing_method, status, metadata,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?,
                    'manual', 'inactive', ?,
                    UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )
            ");
            $insertBillingStmt->execute([
                $newBillingId,
                $tenantId,
                $newSubscriptionId,
                $metadataJson
            ]);
            
            log_message('info', 'Stripe Customer Deleted Webhook: Created new Billing record', [
                'billing_id' => $newBillingId,
                'tenant_id' => $tenantId
            ]);
        }
    }
    
    $db->commit();
    
    log_message('info', 'Stripe Customer Deleted Webhook: Customer deletion processed successfully', [
        'stripe_customer_id' => $stripeCustomerId,
        'tenant_id' => $tenantId,
        'new_subscription_id' => $newSubscriptionId,
        'inactivated_subscriptions' => $inactivatedCount
    ]);
    
    // 8. Return success response
    return [
        'success' => true,
        'data' => [
            'tenant_id' => $tenantId,
            'stripe_customer_id' => $stripeCustomerId,
            'new_subscription_id' => $newSubscriptionId,
            'new_subscription_type' => 'free',
            'inactivated_subscriptions_count' => $inactivatedCount,
            'upcoming_invoices_cancelled' => true,
            'action' => 'customer_deleted_and_downgraded'
        ],
        'message' => 'Customer deletion processed successfully - tenant downgraded to free tier'
    ];
    
} catch (PDOException $e) {
    $db->rollBack();
    log_message('error', 'Stripe Customer Deleted Webhook: Database transaction failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'stripe_customer_id' => $stripeCustomerId,
        'tenant_id' => $tenantId ?? null
    ]);
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Failed to process customer deletion in database',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    $db->rollBack();
    log_message('error', 'Stripe Customer Deleted Webhook: Unexpected error during customer deletion processing', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'stripe_customer_id' => $stripeCustomerId,
        'tenant_id' => $tenantId ?? null
    ]);
    return [
        'success' => false,
        'error' => [
            'code' => 'UNEXPECTED_ERROR',
            'message' => 'An unexpected error occurred',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
