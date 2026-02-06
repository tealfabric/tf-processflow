<?php
/**
 * Stripe Checkout Session Completed Webhook Handler
 * 
 * System-level process step for handling Stripe checkout.session.completed events
 * 
 * This code snippet:
 * 1. Validates the Stripe event structure
 * 2. Extracts tenant_id from client_reference_id (most reliable identifier)
 * 3. Extracts stripe_customer_id, stripe_subscription_id, stripe_invoice_id from checkout session
 * 4. Updates Subscriptions table with checkout session data
 * 5. Updates or creates Billing table record with checkout session data
 * 
 * Input: Stripe webhook event JSON (checkout.session.completed)
 * Output: Success/failure with checkout session details
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
    log_message('error', 'Stripe Checkout Session Webhook: Invalid input data', ['input' => $process_input]);
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
if ($eventType !== 'checkout.session.completed') {
    log_message('warning', 'Stripe Checkout Session Webhook: Received unexpected event type', ['event_type' => $eventType]);
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EVENT_TYPE',
            'message' => 'Invalid event type',
            'details' => "Expected 'checkout.session.completed', got: " . $eventType
        ],
        'data' => null
    ];
}

// Extract Stripe checkout session object
$stripeEventData = $process_input['data'] ?? null;
$checkoutSession = $stripeEventData['object'] ?? null;

if (!$checkoutSession || empty($checkoutSession['id'])) {
    log_message('error', 'Stripe Checkout Session Webhook: Missing essential checkout session data', ['event_data' => $stripeEventData]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing essential checkout session data',
            'details' => 'Stripe event data.object is missing id'
        ],
        'data' => null
    ];
}

// 2. Extract required fields from Stripe checkout session
$checkoutSessionId = $checkoutSession['id'];
$tenantId = $checkoutSession['client_reference_id'] ?? null; // This is our tenant_id
$stripeCustomerId = $checkoutSession['customer'] ?? null;
$stripeSubscriptionId = $checkoutSession['subscription'] ?? null;
$stripeInvoiceId = $checkoutSession['invoice'] ?? null;
$paymentStatus = $checkoutSession['payment_status'] ?? '';
$checkoutStatus = $checkoutSession['status'] ?? '';
$mode = $checkoutSession['mode'] ?? '';
$amountTotal = $checkoutSession['amount_total'] ?? 0;
$currency = strtoupper($checkoutSession['currency'] ?? 'USD');
$customerDetails = $checkoutSession['customer_details'] ?? null;
$collectedInformation = $checkoutSession['collected_information'] ?? null;
$created = $checkoutSession['created'] ?? null;

// Validate required fields
if (empty($tenantId)) {
    log_message('error', 'Stripe Checkout Session Webhook: Missing tenant_id (client_reference_id)', ['checkout_session_id' => $checkoutSessionId]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing tenant_id',
            'details' => 'data.object.client_reference_id is required (should contain tenant_id)'
        ],
        'data' => null
    ];
}

if (empty($stripeCustomerId)) {
    log_message('error', 'Stripe Checkout Session Webhook: Missing Stripe customer ID', ['checkout_session_id' => $checkoutSessionId, 'tenant_id' => $tenantId]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing Stripe customer ID',
            'details' => 'data.object.customer is required'
        ],
        'data' => null
    ];
}

// Only process subscription mode checkout sessions
if ($mode !== 'subscription') {
    log_message('info', 'Stripe Checkout Session Webhook: Skipping non-subscription checkout', [
        'checkout_session_id' => $checkoutSessionId,
        'mode' => $mode
    ]);
    return [
        'success' => true,
        'data' => [
            'skipped' => true,
            'reason' => 'Not a subscription checkout',
            'mode' => $mode
        ],
        'message' => 'Checkout session is not a subscription, skipping'
    ];
}

log_message('info', 'Stripe Checkout Session Webhook: Extracted data', [
    'checkout_session_id' => $checkoutSessionId,
    'tenant_id' => $tenantId,
    'stripe_customer_id' => $stripeCustomerId,
    'stripe_subscription_id' => $stripeSubscriptionId,
    'stripe_invoice_id' => $stripeInvoiceId,
    'payment_status' => $paymentStatus,
    'checkout_status' => $checkoutStatus,
    'amount_total' => $amountTotal,
    'currency' => $currency
]);

// 3. Extract customer details for billing address
$billingAddress = null;
$customerEmail = null;
$customerName = null;
$customerBusinessName = null;

if ($customerDetails) {
    $customerEmail = $customerDetails['email'] ?? null;
    $customerName = $customerDetails['name'] ?? null;
    $customerBusinessName = $customerDetails['business_name'] ?? null;
    
    if (!empty($customerDetails['address'])) {
        $billingAddress = [
            'line1' => $customerDetails['address']['line1'] ?? null,
            'line2' => $customerDetails['address']['line2'] ?? null,
            'city' => $customerDetails['address']['city'] ?? null,
            'state' => $customerDetails['address']['state'] ?? null,
            'postal_code' => $customerDetails['address']['postal_code'] ?? null,
            'country' => $customerDetails['address']['country'] ?? null
        ];
    }
} elseif ($collectedInformation) {
    // Fallback to collected_information if customer_details not available
    $customerBusinessName = $collectedInformation['business_name'] ?? null;
}

// Convert amount from cents to decimal
$amountDecimal = $amountTotal / 100;

// Convert Unix timestamp to MySQL timestamp
$mysqlCreatedDate = $created ? date('Y-m-d H:i:s', $created) : null;

// Build metadata JSON
$metadata = [
    'checkout_session_id' => $checkoutSessionId,
    'checkout_status' => $checkoutStatus,
    'payment_status' => $paymentStatus,
    'mode' => $mode,
    'customer_email' => $customerEmail,
    'customer_name' => $customerName,
    'customer_business_name' => $customerBusinessName,
    'stripe_event_id' => $process_input['id'] ?? null,
    'stripe_created' => $process_input['created'] ?? null,
    'stripe_livemode' => $process_input['livemode'] ?? false,
    'checkout_session_object' => $checkoutSession
];
$metadataJson = json_encode($metadata);
$billingAddressJson = $billingAddress ? json_encode($billingAddress) : null;

// Start database transaction
$db->beginTransaction();
try {
    // 4. Update Subscriptions table if stripe_subscription_id exists
    if (!empty($stripeSubscriptionId)) {
        // Check if subscription with this stripe_subscription_id already exists
        $checkSubStmt = $db->prepare("
            SELECT subscription_id, tenant_id, status
            FROM Subscriptions 
            WHERE stripe_subscription_id = ?
            LIMIT 1
        ");
        $checkSubStmt->execute([$stripeSubscriptionId]);
        $existingSubscription = $checkSubStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingSubscription) {
            // Update existing subscription
            $updateSubStmt = $db->prepare("
                UPDATE Subscriptions 
                SET tenant_id = ?,
                    stripe_customer_id = ?,
                    status = CASE 
                        WHEN ? = 'paid' THEN 'active'
                        ELSE status
                    END,
                    updated_at = UTC_TIMESTAMP()
                WHERE subscription_id = ?
            ");
            $newStatus = ($paymentStatus === 'paid') ? 'active' : $existingSubscription['status'];
            $updateSubStmt->execute([
                $tenantId,
                $stripeCustomerId,
                $paymentStatus,
                $existingSubscription['subscription_id']
            ]);
            
            log_message('info', 'Stripe Checkout Session Webhook: Updated existing subscription', [
                'subscription_id' => $existingSubscription['subscription_id'],
                'stripe_subscription_id' => $stripeSubscriptionId,
                'payment_status' => $paymentStatus
            ]);
        } else {
            // Subscription doesn't exist yet (might be created by subscription.created event later)
            // Just log this - the subscription.created handler will create it
            log_message('info', 'Stripe Checkout Session Webhook: Subscription not found, will be created by subscription.created event', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'tenant_id' => $tenantId
            ]);
        }
    }
    
    // 5. Update or create Billing table record
    // Check if billing record exists for this tenant
    $checkBillingStmt = $db->prepare("
        SELECT billing_id, subscription_id
        FROM Billing 
        WHERE tenant_id = ?
        LIMIT 1
    ");
    $checkBillingStmt->execute([$tenantId]);
    $existingBilling = $checkBillingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingBilling) {
        // Update existing billing record
        $updateBillingStmt = $db->prepare("
            UPDATE Billing 
            SET stripe_customer_id = ?,
                billing_address = ?,
                currency = ?,
                status = CASE 
                    WHEN ? = 'paid' THEN 'active'
                    ELSE status
                END,
                last_payment_date = CASE 
                    WHEN ? = 'paid' THEN UTC_TIMESTAMP()
                    ELSE last_payment_date
                END,
                last_payment_amount = CASE 
                    WHEN ? = 'paid' THEN ?
                    ELSE last_payment_amount
                END,
                updated_at = UTC_TIMESTAMP()
            WHERE billing_id = ?
        ");
        $updateBillingStmt->execute([
            $stripeCustomerId,
            $billingAddressJson,
            $currency,
            $paymentStatus,
            $paymentStatus,
            $paymentStatus,
            $amountDecimal,
            $existingBilling['billing_id']
        ]);
        
        log_message('info', 'Stripe Checkout Session Webhook: Updated existing billing record', [
            'billing_id' => $existingBilling['billing_id'],
            'tenant_id' => $tenantId,
            'payment_status' => $paymentStatus
        ]);
        
        $billingId = $existingBilling['billing_id'];
        $subscriptionId = $existingBilling['subscription_id'];
    } else {
        // Create new billing record
        // First, try to find an existing subscription for this tenant
        $findSubStmt = $db->prepare("
            SELECT subscription_id 
            FROM Subscriptions 
            WHERE tenant_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $findSubStmt->execute([$tenantId]);
        $subResult = $findSubStmt->fetch(PDO::FETCH_ASSOC);
        $subscriptionId = $subResult['subscription_id'] ?? null;
        
        if (!$subscriptionId) {
            // No subscription found - this shouldn't happen, but create billing anyway
            log_message('warning', 'Stripe Checkout Session Webhook: No subscription found for tenant, creating billing without subscription_id', [
                'tenant_id' => $tenantId
            ]);
        }
        
        $billingId = null;
        $insertBillingStmt = $db->prepare("
            INSERT INTO Billing (
                billing_id, tenant_id, subscription_id,
                billing_method, status, stripe_customer_id,
                billing_address, currency,
                last_payment_date, last_payment_amount,
                metadata, created_at, updated_at
            ) VALUES (
                UUID(), ?, ?,
                'stripe', 
                CASE WHEN ? = 'paid' THEN 'active' ELSE 'active' END,
                ?,
                ?, ?,
                CASE WHEN ? = 'paid' THEN UTC_TIMESTAMP() ELSE NULL END,
                CASE WHEN ? = 'paid' THEN ? ELSE NULL END,
                ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");
        $insertBillingStmt->execute([
            $tenantId,
            $subscriptionId,
            $paymentStatus,
            $stripeCustomerId,
            $billingAddressJson,
            $currency,
            $paymentStatus,
            $paymentStatus,
            $amountDecimal,
            $metadataJson
        ]);
        
        // Get the newly created billing_id
        $billingIdStmt = $db->prepare("
            SELECT billing_id 
            FROM Billing 
            WHERE tenant_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $billingIdStmt->execute([$tenantId]);
        $billingResult = $billingIdStmt->fetch(PDO::FETCH_ASSOC);
        $billingId = $billingResult['billing_id'] ?? null;
        
        log_message('info', 'Stripe Checkout Session Webhook: Created new billing record', [
            'billing_id' => $billingId,
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId
        ]);
    }
    
    $db->commit();
    
    log_message('info', 'Stripe Checkout Session Webhook: Checkout session completed event processed successfully', [
        'checkout_session_id' => $checkoutSessionId,
        'tenant_id' => $tenantId,
        'stripe_customer_id' => $stripeCustomerId,
        'payment_status' => $paymentStatus
    ]);
    
    // 6. Return success response
    return [
        'success' => true,
        'data' => [
            'checkout_session_id' => $checkoutSessionId,
            'tenant_id' => $tenantId,
            'billing_id' => $billingId,
            'subscription_id' => $subscriptionId,
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_invoice_id' => $stripeInvoiceId,
            'payment_status' => $paymentStatus,
            'checkout_status' => $checkoutStatus,
            'amount' => $amountDecimal,
            'currency' => $currency
        ],
        'message' => 'Checkout session completed event processed successfully'
    ];
    
} catch (PDOException $e) {
    $db->rollBack();
    log_message('error', 'Stripe Checkout Session Webhook: Database transaction failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'checkout_session_id' => $checkoutSessionId,
        'tenant_id' => $tenantId
    ]);
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Failed to update checkout session data in database',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    $db->rollBack();
    log_message('error', 'Stripe Checkout Session Webhook: Unexpected error during checkout session processing', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'checkout_session_id' => $checkoutSessionId,
        'tenant_id' => $tenantId
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
