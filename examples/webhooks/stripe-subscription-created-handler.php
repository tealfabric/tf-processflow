<?php
/**
 * Stripe Subscription Created Webhook Handler
 * 
 * System-level process step for handling Stripe customer.subscription.created events
 * 
 * This code snippet:
 * 1. Validates the Stripe event structure
 * 2. Extracts customer ID and price ID from the event
 * 3. Finds tenant_id from stripe_customer_id
 * 4. Finds plan_id from price_id (stripe_price_id)
 * 5. Marks existing active subscriptions as inactive (history tracking)
 * 6. Inserts new subscription record with Stripe data
 * 
 * Input: Stripe webhook event JSON (customer.subscription.created)
 * Output: Success/failure with subscription details
 */

  // Delay execution with 10 seconds to ensure that checkout completed event is processed first
  sleep(10);


// Validate input structure
if (empty($process_input) || !is_array($process_input)) {
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
if ($eventType !== 'customer.subscription.created') {
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EVENT_TYPE',
            'message' => 'Invalid event type',
            'details' => "Expected 'customer.subscription.created', got: " . $eventType
        ],
        'data' => null
    ];
}

// Extract Stripe subscription object
$stripeSubscription = $process_input['data']['object'] ?? null;
if (!$stripeSubscription || empty($stripeSubscription['id'])) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_SUBSCRIPTION_DATA',
            'message' => 'Missing subscription data in event',
            'details' => 'Event data.object is missing or invalid'
        ],
        'data' => null
    ];
}

// Extract required fields from Stripe subscription
$stripeCustomerId = $stripeSubscription['customer'] ?? '';
$stripeSubscriptionId = $stripeSubscription['id'] ?? '';
$stripeStatus = $stripeSubscription['status'] ?? '';

// Extract price_id from first subscription item
$priceId = null;
if (!empty($stripeSubscription['items']['data']) && is_array($stripeSubscription['items']['data'])) {
    $firstItem = $stripeSubscription['items']['data'][0] ?? null;
    if ($firstItem && !empty($firstItem['plan']['id'])) {
        $priceId = $firstItem['plan']['id'];
    }
}

// Validate required fields
if (empty($stripeCustomerId)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_CUSTOMER_ID',
            'message' => 'Missing Stripe customer ID',
            'details' => 'data.object.customer is required'
        ],
        'data' => null
    ];
}

if (empty($priceId)) {
    return [
        'success' => false,
        'error' => [
            'code' => 'MISSING_PRICE_ID',
            'message' => 'Missing Stripe price ID',
            'details' => 'data.object.items.data[0].plan.id is required'
        ],
        'data' => null
    ];
}

try {
    // Step 1: Find tenant_id from stripe_customer_id
    // Check Subscriptions table first (most reliable if subscription already exists)
    $tenantStmt = $db->prepare("
        SELECT DISTINCT tenant_id 
        FROM Subscriptions 
        WHERE stripe_customer_id = ? 
        LIMIT 1
    ");
    $tenantStmt->execute([$stripeCustomerId]);
    $tenantResult = $tenantStmt->fetch(PDO::FETCH_ASSOC);
    $tenantId = $tenantResult['tenant_id'] ?? null;
    
    // If not found in Subscriptions, check Billing table
    // (checkout.session.completed stores stripe_customer_id here before subscription.created arrives)
    if (!$tenantId) {
        try {
            $billingStmt = $db->prepare("
                SELECT tenant_id 
                FROM Billing 
                WHERE stripe_customer_id = ? 
                LIMIT 1
            ");
            $billingStmt->execute([$stripeCustomerId]);
            $billingResult = $billingStmt->fetch(PDO::FETCH_ASSOC);
            $tenantId = $billingResult['tenant_id'] ?? null;
        } catch (PDOException $e) {
            // Continue if error
        }
    }
    
    // If still not found, check Tenants table (if stripe_customer_id column exists)
    if (!$tenantId) {
        try {
            $tenantTableStmt = $db->prepare("
                SELECT tenant_id 
                FROM Tenants 
                WHERE stripe_customer_id = ? 
                LIMIT 1
            ");
            $tenantTableStmt->execute([$stripeCustomerId]);
            $tenantTableResult = $tenantTableStmt->fetch(PDO::FETCH_ASSOC);
            $tenantId = $tenantTableResult['tenant_id'] ?? null;
        } catch (PDOException $e) {
            // Column might not exist, continue
        }
    }
    
    if (!$tenantId) {
        return [
            'success' => false,
            'error' => [
                'code' => 'TENANT_NOT_FOUND',
                'message' => 'Tenant not found for Stripe customer',
                'details' => "No tenant found with stripe_customer_id: {$stripeCustomerId}"
            ],
            'data' => null
        ];
    }
    
    // Step 2: Find plan_id from price_id (stripe_price_id)
    $planStmt = $db->prepare("
        SELECT plan_id, plan_code 
        FROM SubscriptionPlans 
        WHERE stripe_price_id = ? AND is_active = 1
        LIMIT 1
    ");
    $planStmt->execute([$priceId]);
    $planResult = $planStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$planResult || empty($planResult['plan_id'])) {
        // Add planResult to log
        log_message('error', 'Stripe Subscription Created Handler: Plan not found', [
            'plan_result' => $planResult
        ]);
        return [
            'success' => false,
            'error' => [
                'code' => 'PLAN_NOT_FOUND',
                'message' => 'Subscription plan not found',
                'details' => "No active plan found with stripe_price_id: {$priceId}"
            ],
            'data' => null
        ];
    }
    
    $planId = $planResult['plan_id'];
    $subscriptionType = $planResult['plan_code']; // Maps to subscription_type enum
    
    // Step 3: Map Stripe status to system status
    $statusMap = [
        'active' => 'active',
        'trialing' => 'active',
        'past_due' => 'suspended',
        'canceled' => 'cancelled',
        'unpaid' => 'suspended',
        'incomplete' => 'inactive',
        'incomplete_expired' => 'expired'
    ];
    $systemStatus = $statusMap[$stripeStatus] ?? 'inactive';
    
    // Step 4: Convert Unix timestamps to MySQL timestamps
    $currentPeriodStart = null;
    $currentPeriodEnd = null;
    $trialStart = null;
    $trialEnd = null;
    
    // Get first subscription item for period dates
    $firstItem = $stripeSubscription['items']['data'][0] ?? null;
    
    if ($firstItem && !empty($firstItem['current_period_start'])) {
        $currentPeriodStart = date('Y-m-d H:i:s', $firstItem['current_period_start']);
    }
    if ($firstItem && !empty($firstItem['current_period_end'])) {
        $currentPeriodEnd = date('Y-m-d H:i:s', $firstItem['current_period_end']);
    }
    if (!empty($stripeSubscription['trial_start'])) {
        $trialStart = date('Y-m-d H:i:s', $stripeSubscription['trial_start']);
    }
    if (!empty($stripeSubscription['trial_end'])) {
        $trialEnd = date('Y-m-d H:i:s', $stripeSubscription['trial_end']);
    }
    
    // Step 5: Get plan features for the subscription
    $featuresStmt = $db->prepare("
        SELECT sf.feature_code, sf.feature_value
        FROM SubscriptionFeatures sf
        WHERE sf.plan_id = ?
    ");
    $featuresStmt->execute([$planId]);
    $features = [];
    while ($feature = $featuresStmt->fetch(PDO::FETCH_ASSOC)) {
        $features[$feature['feature_code']] = $feature['feature_value'];
    }
    $featuresJson = !empty($features) ? json_encode($features) : null;
    
    // Step 6: Store additional Stripe metadata
    $metadata = [
        'stripe_event_id' => $process_input['id'] ?? null,
        'stripe_created' => $process_input['created'] ?? null,
        'stripe_livemode' => $process_input['livemode'] ?? false,
        'stripe_subscription_object' => $stripeSubscription
    ];
    $metadataJson = json_encode($metadata);
    
    // Step 7: Mark existing active subscriptions as inactive (history tracking)
    $updateStmt = $db->prepare("
        UPDATE Subscriptions 
        SET status = 'inactive',
            updated_at = UTC_TIMESTAMP()
        WHERE tenant_id = ? 
        AND status IN ('active', 'change_initiated')
        AND stripe_subscription_id != ?
    ");
    $updateStmt->execute([$tenantId, $stripeSubscriptionId]);
    $updatedCount = $updateStmt->rowCount();
    
    // Step 8: Check if subscription with this stripe_subscription_id already exists
    $checkStmt = $db->prepare("
        SELECT subscription_id 
        FROM Subscriptions 
        WHERE stripe_subscription_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$stripeSubscriptionId]);
    $existingSubscription = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingSubscription) {
        // Update existing subscription instead of creating duplicate
        $updateSubStmt = $db->prepare("
            UPDATE Subscriptions 
            SET tenant_id = ?,
                plan_id = ?,
                subscription_type = ?,
                status = ?,
                method = 'stripe',
                stripe_customer_id = ?,
                price_id = ?,
                current_period_start = ?,
                current_period_end = ?,
                trial_start = ?,
                trial_end = ?,
                features = ?,
                metadata = ?,
                updated_at = UTC_TIMESTAMP()
            WHERE subscription_id = ?
        ");
        $updateSubStmt->execute([
            $tenantId,
            $planId,
            $subscriptionType,
            $systemStatus,
            $stripeCustomerId,
            $priceId,
            $currentPeriodStart,
            $currentPeriodEnd,
            $trialStart,
            $trialEnd,
            $featuresJson,
            $metadataJson,
            $existingSubscription['subscription_id']
        ]);
        
        $subscriptionId = $existingSubscription['subscription_id'];
        $action = 'updated';
    } else {
        // Insert new subscription record
        $insertStmt = $db->prepare("
            INSERT INTO Subscriptions (
                subscription_id, tenant_id, plan_id, subscription_type, status, method,
                stripe_customer_id, stripe_subscription_id, price_id,
                current_period_start, current_period_end, trial_start, trial_end,
                features, metadata, created_at, updated_at
            ) VALUES (
                UUID(), ?, ?, ?, ?, 'stripe',
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");
        $insertStmt->execute([
            $tenantId,
            $planId,
            $subscriptionType,
            $systemStatus,
            $stripeCustomerId,
            $stripeSubscriptionId,
            $priceId,
            $currentPeriodStart,
            $currentPeriodEnd,
            $trialStart,
            $trialEnd,
            $featuresJson,
            $metadataJson
        ]);
        
        // Get the newly created subscription_id
        $subscriptionIdStmt = $db->prepare("
            SELECT subscription_id 
            FROM Subscriptions 
            WHERE stripe_subscription_id = ?
            LIMIT 1
        ");
        $subscriptionIdStmt->execute([$stripeSubscriptionId]);
        $subscriptionResult = $subscriptionIdStmt->fetch(PDO::FETCH_ASSOC);
        $subscriptionId = $subscriptionResult['subscription_id'] ?? null;
        $action = 'created';
    }
    
    if (!$subscriptionId) {
        return [
            'success' => false,
            'error' => [
                'code' => 'DATABASE_ERROR',
                'message' => 'Failed to create or retrieve subscription',
                'details' => 'Subscription record operation completed but subscription_id not found'
            ],
            'data' => null
        ];
    }
    
    // Return success response
    return [
        'success' => true,
        'data' => [
            'subscription_id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'subscription_type' => $subscriptionType,
            'status' => $systemStatus,
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'price_id' => $priceId,
            'action' => $action,
            'previous_subscriptions_updated' => $updatedCount
        ],
        'message' => "Subscription {$action} successfully"
    ];
    
} catch (PDOException $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Database operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'UNKNOWN_ERROR',
            'message' => 'Unexpected error occurred',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
