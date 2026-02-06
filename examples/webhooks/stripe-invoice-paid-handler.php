<?php
/**
 * Stripe Invoice Paid Webhook Handler
 * 
 * System-level process step for handling Stripe invoice.paid events
 * 
 * This code snippet:
 * 1. Validates the Stripe event structure
 * 2. Extracts invoice data (customer ID, invoice ID, amount, URLs, etc.)
 * 3. Finds tenant_id from stripe_customer_id
 * 4. Finds billing_id and subscription_id from Billing table
 * 5. Creates or updates BillingHistory record for the invoice
 * 6. Updates Billing table with summary fields (last_payment_date, last_payment_amount, next_billing_date)
 * 
 * Input: Stripe webhook event JSON (invoice.paid)
 * Output: Success/failure with invoice details
 */

 // Delay execution with 10 seconds to ensure that checkout completed event is processed first
 sleep(10);

// Ensure Logger is available for system-level logging
if (!function_exists('log_message')) {
    // Fallback logger for local testing outside ProcessFlow runtime
    function log_message($level, $message, $context = []) {
        error_log(strtoupper($level) . ": " . $message . " " . json_encode($context));
    }
}

// 1. Validate input structure
if (empty($process_input) || !is_array($process_input)) {
    log_message('error', 'Stripe Invoice Webhook: Invalid input data', ['input' => $process_input]);
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
if ($eventType !== 'invoice.paid') {
    log_message('warning', 'Stripe Invoice Webhook: Received unexpected event type', ['event_type' => $eventType]);
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EVENT_TYPE',
            'message' => 'Invalid event type',
            'details' => "Expected 'invoice.paid', got: " . $eventType
        ],
        'data' => null
    ];
}

// Extract Stripe invoice object
$stripeEventData = $process_input['data'] ?? null;
$stripeInvoice = $stripeEventData['object'] ?? null;

if (!$stripeInvoice || empty($stripeInvoice['id'])) {
    log_message('error', 'Stripe Invoice Webhook: Missing essential invoice data in event', ['event_data' => $stripeEventData]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing essential invoice data',
            'details' => 'Stripe event data.object is missing id'
        ],
        'data' => null
    ];
}

// 2. Extract required fields from Stripe invoice
$stripeInvoiceId = $stripeInvoice['id'];
$stripeCustomerId = $stripeInvoice['customer'] ?? '';
$invoiceNumber = $stripeInvoice['number'] ?? null;
$invoiceStatus = $stripeInvoice['status'] ?? '';
$amountPaid = $stripeInvoice['amount_paid'] ?? 0;
$currency = strtoupper($stripeInvoice['currency'] ?? 'USD');
$hostedInvoiceUrl = $stripeInvoice['hosted_invoice_url'] ?? null;
$invoicePdf = $stripeInvoice['invoice_pdf'] ?? null;
$collectionMethod = $stripeInvoice['collection_method'] ?? null;
$periodStart = $stripeInvoice['period_start'] ?? null;
$periodEnd = $stripeInvoice['period_end'] ?? null;
$dueDate = $stripeInvoice['due_date'] ?? null;
$paidAt = $stripeInvoice['status_transitions']['paid_at'] ?? null;
$description = $stripeInvoice['description'] ?? null;
$customerAddress = $stripeInvoice['customer_address'] ?? null;
$customerEmail = $stripeInvoice['customer_email'] ?? null;
$customerName = $stripeInvoice['customer_name'] ?? null;

// Validate required fields
if (empty($stripeCustomerId)) {
    log_message('error', 'Stripe Invoice Webhook: Missing customer ID', ['stripe_invoice_id' => $stripeInvoiceId]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing customer ID',
            'details' => 'data.object.customer is required'
        ],
        'data' => null
    ];
}

if (empty($stripeInvoiceId)) {
    log_message('error', 'Stripe Invoice Webhook: Missing invoice ID');
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing invoice ID',
            'details' => 'data.object.id is required'
        ],
        'data' => null
    ];
}

log_message('info', 'Stripe Invoice Webhook: Extracted data', [
    'stripe_invoice_id' => $stripeInvoiceId,
    'stripe_customer_id' => $stripeCustomerId,
    'amount_paid' => $amountPaid,
    'currency' => $currency,
    'status' => $invoiceStatus
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
        log_message('info', 'Stripe Invoice Webhook: Found tenant_id from Subscriptions table', ['tenant_id' => $tenantId]);
    } else {
        // Fallback: Try to find tenant_id from Billing table
        $stmt = $db->prepare("SELECT tenant_id FROM Billing WHERE stripe_customer_id = ? LIMIT 1");
        $stmt->execute([$stripeCustomerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $tenantId = $result['tenant_id'];
            log_message('info', 'Stripe Invoice Webhook: Found tenant_id from Billing table', ['tenant_id' => $tenantId]);
        }
    }
} catch (PDOException $e) {
    log_message('error', 'Stripe Invoice Webhook: Database error during tenant lookup', ['error' => $e->getMessage()]);
    // Continue, tenantId will be null and handled below
}

if (empty($tenantId)) {
    log_message('error', 'Stripe Invoice Webhook: Tenant not found for Stripe customer ID', ['stripe_customer_id' => $stripeCustomerId]);
    return [
        'success' => false,
        'error' => [
            'code' => 'TENANT_NOT_FOUND',
            'message' => 'Tenant not found for the given Stripe customer ID',
            'details' => 'No matching tenant found in Subscriptions or Billing table for ' . $stripeCustomerId
        ],
        'data' => null
    ];
}

// 4. Find billing_id and subscription_id from Billing table
$billingId = null;
$subscriptionId = null;
try {
    $stmt = $db->prepare("SELECT billing_id, subscription_id FROM Billing WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $billingResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($billingResult) {
        $billingId = $billingResult['billing_id'];
        $subscriptionId = $billingResult['subscription_id'];
        log_message('info', 'Stripe Invoice Webhook: Found billing_id and subscription_id', [
            'billing_id' => $billingId,
            'subscription_id' => $subscriptionId
        ]);
    }
} catch (PDOException $e) {
    log_message('error', 'Stripe Invoice Webhook: Database error during billing lookup', ['error' => $e->getMessage()]);
    // Continue, will be handled below
}

if (empty($billingId) || empty($subscriptionId)) {
    log_message('error', 'Stripe Invoice Webhook: Billing record not found for tenant', ['tenant_id' => $tenantId]);
    return [
        'success' => false,
        'error' => [
            'code' => 'BILLING_NOT_FOUND',
            'message' => 'Billing record not found for tenant',
            'details' => 'No billing record found for tenant_id: ' . $tenantId
        ],
        'data' => null
    ];
}

// 5. Convert amounts and timestamps
$amountDecimal = $amountPaid / 100; // Convert cents to decimal
$mysqlPeriodStart = $periodStart ? date('Y-m-d H:i:s', $periodStart) : null;
$mysqlPeriodEnd = $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null;
$mysqlDueDate = $dueDate ? date('Y-m-d H:i:s', $dueDate) : null;
$mysqlPaidDate = $paidAt ? date('Y-m-d H:i:s', $paidAt) : null;

// 6. Build metadata JSON
$metadata = [
    'hosted_invoice_url' => $hostedInvoiceUrl,
    'invoice_pdf' => $invoicePdf,
    'customer_address' => $customerAddress,
    'customer_email' => $customerEmail,
    'customer_name' => $customerName,
    'stripe_event_id' => $process_input['id'] ?? null,
    'stripe_created' => $process_input['created'] ?? null,
    'stripe_livemode' => $process_input['livemode'] ?? false,
    'stripe_invoice_object' => $stripeInvoice
];
$metadataJson = json_encode($metadata);

// 7. Generate description if not provided
if (empty($description) && !empty($stripeInvoice['lines']['data'])) {
    // Try to build description from line items
    $lineItems = [];
    foreach ($stripeInvoice['lines']['data'] as $lineItem) {
        $lineDescription = $lineItem['description'] ?? 'Subscription payment';
        $lineItems[] = $lineDescription;
    }
    $description = implode(', ', array_unique($lineItems));
}
if (empty($description)) {
    $description = 'Subscription payment';
}

// Start database transaction
$db->beginTransaction();
try {
    // 8. Check if BillingHistory record with this stripe_invoice_id already exists
    $checkStmt = $db->prepare("
        SELECT history_id 
        FROM BillingHistory 
        WHERE stripe_invoice_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$stripeInvoiceId]);
    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $action = 'created';
    $historyId = null;
    
    if ($existingRecord) {
        // Update existing record (idempotency)
        $historyId = $existingRecord['history_id'];
        $updateStmt = $db->prepare("
            UPDATE BillingHistory 
            SET tenant_id = ?,
                billing_id = ?,
                subscription_id = ?,
                invoice_number = ?,
                amount = ?,
                currency = ?,
                status = ?,
                payment_method = ?,
                billing_period_start = ?,
                billing_period_end = ?,
                due_date = ?,
                paid_date = ?,
                description = ?,
                metadata = ?,
                updated_at = UTC_TIMESTAMP()
            WHERE history_id = ?
        ");
        $updateStmt->execute([
            $tenantId,
            $billingId,
            $subscriptionId,
            $invoiceNumber,
            $amountDecimal,
            $currency,
            $invoiceStatus, // Map status: 'paid' → 'paid' (already correct)
            $collectionMethod,
            $mysqlPeriodStart,
            $mysqlPeriodEnd,
            $mysqlDueDate,
            $mysqlPaidDate,
            $description,
            $metadataJson,
            $historyId
        ]);
        $action = 'updated';
        log_message('info', 'Stripe Invoice Webhook: Updated existing BillingHistory record', [
            'history_id' => $historyId,
            'stripe_invoice_id' => $stripeInvoiceId
        ]);
    } else {
        // Insert new BillingHistory record
        $insertStmt = $db->prepare("
            INSERT INTO BillingHistory (
                history_id, tenant_id, billing_id, subscription_id,
                invoice_number, stripe_invoice_id, amount, currency, status,
                payment_method, billing_period_start, billing_period_end,
                due_date, paid_date, description, metadata,
                created_at, updated_at
            ) VALUES (
                UUID(), ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");
        $insertStmt->execute([
            $tenantId,
            $billingId,
            $subscriptionId,
            $invoiceNumber,
            $stripeInvoiceId,
            $amountDecimal,
            $currency,
            $invoiceStatus,
            $collectionMethod,
            $mysqlPeriodStart,
            $mysqlPeriodEnd,
            $mysqlDueDate,
            $mysqlPaidDate,
            $description,
            $metadataJson
        ]);
        
        // Get the newly created history_id
        $historyIdStmt = $db->prepare("
            SELECT history_id 
            FROM BillingHistory 
            WHERE stripe_invoice_id = ?
            LIMIT 1
        ");
        $historyIdStmt->execute([$stripeInvoiceId]);
        $historyResult = $historyIdStmt->fetch(PDO::FETCH_ASSOC);
        $historyId = $historyResult['history_id'] ?? null;
        
        log_message('info', 'Stripe Invoice Webhook: Created new BillingHistory record', [
            'history_id' => $historyId,
            'stripe_invoice_id' => $stripeInvoiceId
        ]);
    }
    
    if (!$historyId) {
        throw new Exception('Failed to create or retrieve BillingHistory record');
    }
    
    // 9. Update Billing table with summary fields
    $updateBillingStmt = $db->prepare("
        UPDATE Billing 
        SET last_payment_date = ?,
            last_payment_amount = ?,
            next_billing_date = ?,
            updated_at = UTC_TIMESTAMP()
        WHERE billing_id = ?
    ");
    $updateBillingStmt->execute([
        $mysqlPaidDate,
        $amountDecimal,
        $mysqlPeriodEnd, // Set next billing date to period_end
        $billingId
    ]);
    
    log_message('info', 'Stripe Invoice Webhook: Updated Billing table summary', [
        'billing_id' => $billingId,
        'last_payment_date' => $mysqlPaidDate,
        'last_payment_amount' => $amountDecimal
    ]);
    
    $db->commit();
    
    log_message('info', 'Stripe Invoice Webhook: Invoice paid event processed successfully', [
        'stripe_invoice_id' => $stripeInvoiceId,
        'tenant_id' => $tenantId,
        'action' => $action
    ]);
    
    // 10. Return success response
    return [
        'success' => true,
        'data' => [
            'history_id' => $historyId,
            'tenant_id' => $tenantId,
            'billing_id' => $billingId,
            'subscription_id' => $subscriptionId,
            'stripe_invoice_id' => $stripeInvoiceId,
            'invoice_number' => $invoiceNumber,
            'amount' => $amountDecimal,
            'currency' => $currency,
            'status' => $invoiceStatus,
            'paid_date' => $mysqlPaidDate,
            'action' => $action
        ],
        'message' => 'Invoice paid event processed successfully'
    ];
    
} catch (PDOException $e) {
    $db->rollBack();
    log_message('error', 'Stripe Invoice Webhook: Database transaction failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'stripe_invoice_id' => $stripeInvoiceId
    ]);
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Failed to update invoice in database',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    $db->rollBack();
    log_message('error', 'Stripe Invoice Webhook: Unexpected error during invoice processing', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'stripe_invoice_id' => $stripeInvoiceId
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
