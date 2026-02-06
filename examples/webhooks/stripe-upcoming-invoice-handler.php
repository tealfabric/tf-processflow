<?php
/**
 * Stripe Upcoming Invoice Webhook Handler
 * 
 * System-level process step for handling Stripe invoice.upcoming events
 * 
 * This code snippet:
 * 1. Validates the Stripe event structure
 * 2. Extracts upcoming invoice data (customer ID, invoice ID, amount, dates, etc.)
 * 3. Finds tenant_id from stripe_customer_id
 * 4. Finds billing_id and subscription_id from Billing table
 * 5. Updates Billing table with upcoming invoice information in metadata field
 * 6. Updates next_billing_date field
 * 
 * Input: Stripe webhook event JSON (invoice.upcoming)
 * Output: Success/failure with upcoming invoice details
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
    log_message('error', 'Stripe Upcoming Invoice Webhook: Invalid input data', ['input' => $process_input]);
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
if ($eventType !== 'invoice.upcoming') {
    log_message('warning', 'Stripe Upcoming Invoice Webhook: Received unexpected event type', ['event_type' => $eventType]);
    return [
        'success' => false,
        'error' => [
            'code' => 'INVALID_EVENT_TYPE',
            'message' => 'Invalid event type',
            'details' => "Expected 'invoice.upcoming', got: " . $eventType
        ],
        'data' => null
    ];
}

// Extract Stripe invoice object
$stripeEventData = $process_input['data'] ?? null;
$stripeInvoice = $stripeEventData['object'] ?? null;

if (!$stripeInvoice) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Missing essential invoice data in event', ['event_data' => $stripeEventData]);
    return [
        'success' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing essential invoice data',
            'details' => 'Stripe event data.object is missing'
        ],
        'data' => null
    ];
}

// 2. Extract required fields from Stripe invoice
// Note: Upcoming invoices don't have an 'id' field until they're finalized
// Extract identifier from lines URL or generate one based on subscription + period
$stripeInvoiceId = $stripeInvoice['id'] ?? null;

// If no id (upcoming invoice), try to extract from lines URL
if (empty($stripeInvoiceId) && !empty($stripeInvoice['lines']['url'])) {
    // Extract upcoming invoice ID from URL like: "/v1/invoices/upcoming_in_1SsnkOR6GQL9fzKrFBc6MbuM/lines"
    if (preg_match('/\/invoices\/(upcoming_in_[^\/]+)\//', $stripeInvoice['lines']['url'], $matches)) {
        $stripeInvoiceId = $matches[1];
        log_message('info', 'Stripe Upcoming Invoice Webhook: Extracted invoice ID from lines URL', ['stripe_invoice_id' => $stripeInvoiceId]);
    }
}

// If still no id, generate one based on subscription and period
if (empty($stripeInvoiceId)) {
    $subscriptionId = $stripeInvoice['parent']['subscription_details']['subscription'] ?? null;
    $periodStart = $stripeInvoice['period_start'] ?? null;
    if ($subscriptionId && $periodStart) {
        $stripeInvoiceId = 'upcoming_' . $subscriptionId . '_' . $periodStart;
        log_message('info', 'Stripe Upcoming Invoice Webhook: Generated invoice ID from subscription and period', ['stripe_invoice_id' => $stripeInvoiceId]);
    }
}

// If we still don't have an identifier, use event ID as fallback
if (empty($stripeInvoiceId)) {
    $stripeInvoiceId = 'upcoming_' . ($process_input['id'] ?? uniqid('evt_', true));
    log_message('warning', 'Stripe Upcoming Invoice Webhook: Using event ID as invoice identifier', ['stripe_invoice_id' => $stripeInvoiceId]);
}
$stripeCustomerId = $stripeInvoice['customer'] ?? '';
$invoiceNumber = $stripeInvoice['number'] ?? null;
$invoiceStatus = $stripeInvoice['status'] ?? 'draft';
$amountDue = $stripeInvoice['amount_due'] ?? 0;
$amountRemaining = $stripeInvoice['amount_remaining'] ?? 0;
$currency = strtoupper($stripeInvoice['currency'] ?? 'USD');
$periodStart = $stripeInvoice['period_start'] ?? null;
$periodEnd = $stripeInvoice['period_end'] ?? null;
$dueDate = $stripeInvoice['due_date'] ?? null;
$nextPaymentAttempt = $stripeInvoice['next_payment_attempt'] ?? null;
$billingReason = $stripeInvoice['billing_reason'] ?? null;
$collectionMethod = $stripeInvoice['collection_method'] ?? null;
$description = $stripeInvoice['description'] ?? null;
$customerAddress = $stripeInvoice['customer_address'] ?? null;
$customerEmail = $stripeInvoice['customer_email'] ?? null;
$customerName = $stripeInvoice['customer_name'] ?? null;

// Extract line items
$lineItems = [];
if (!empty($stripeInvoice['lines']['data']) && is_array($stripeInvoice['lines']['data'])) {
    foreach ($stripeInvoice['lines']['data'] as $lineItem) {
        $lineItems[] = [
            'id' => $lineItem['id'] ?? null,
            'description' => $lineItem['description'] ?? null,
            'amount' => $lineItem['amount'] ?? 0,
            'currency' => $lineItem['currency'] ?? $currency,
            'quantity' => $lineItem['quantity'] ?? 1,
            'period' => $lineItem['period'] ?? null
        ];
    }
}

// Validate required fields
if (empty($stripeCustomerId)) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Missing customer ID', ['stripe_invoice_id' => $stripeInvoiceId]);
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

log_message('info', 'Stripe Upcoming Invoice Webhook: Extracted data', [
    'stripe_invoice_id' => $stripeInvoiceId,
    'stripe_customer_id' => $stripeCustomerId,
    'amount_due' => $amountDue,
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
        log_message('info', 'Stripe Upcoming Invoice Webhook: Found tenant_id from Subscriptions table', ['tenant_id' => $tenantId]);
    } else {
        // Fallback: Try to find tenant_id from Billing table
        $stmt = $db->prepare("SELECT tenant_id FROM Billing WHERE stripe_customer_id = ? LIMIT 1");
        $stmt->execute([$stripeCustomerId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $tenantId = $result['tenant_id'];
            log_message('info', 'Stripe Upcoming Invoice Webhook: Found tenant_id from Billing table', ['tenant_id' => $tenantId]);
        }
    }
} catch (PDOException $e) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Database error during tenant lookup', ['error' => $e->getMessage()]);
    // Continue, tenantId will be null and handled below
}

if (empty($tenantId)) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Tenant not found for Stripe customer ID', ['stripe_customer_id' => $stripeCustomerId]);
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
$existingMetadata = null;
try {
    $stmt = $db->prepare("SELECT billing_id, subscription_id, metadata FROM Billing WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $billingResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($billingResult) {
        $billingId = $billingResult['billing_id'];
        $subscriptionId = $billingResult['subscription_id'];
        $existingMetadata = $billingResult['metadata'];
        log_message('info', 'Stripe Upcoming Invoice Webhook: Found billing_id and subscription_id', [
            'billing_id' => $billingId,
            'subscription_id' => $subscriptionId
        ]);
    }
} catch (PDOException $e) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Database error during billing lookup', ['error' => $e->getMessage()]);
    // Continue, will be handled below
}

if (empty($billingId) || empty($subscriptionId)) {
    log_message('error', 'Stripe Upcoming Invoice Webhook: Billing record not found for tenant', ['tenant_id' => $tenantId]);
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
$amountDueDecimal = $amountDue / 100; // Convert cents to decimal
$amountRemainingDecimal = $amountRemaining / 100; // Convert cents to decimal
$mysqlPeriodStart = $periodStart ? date('Y-m-d H:i:s', $periodStart) : null;
$mysqlPeriodEnd = $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null;
$mysqlDueDate = $dueDate ? date('Y-m-d H:i:s', $dueDate) : null;
$mysqlNextPaymentAttempt = $nextPaymentAttempt ? date('Y-m-d H:i:s', $nextPaymentAttempt) : null;

// Use next_payment_attempt as next_billing_date if available, otherwise use period_end
$nextBillingDate = $mysqlNextPaymentAttempt ?? $mysqlPeriodEnd;

// 6. Build upcoming invoice metadata
$upcomingInvoiceData = [
    'stripe_invoice_id' => $stripeInvoiceId,
    'invoice_number' => $invoiceNumber,
    'status' => $invoiceStatus,
    'amount_due' => $amountDueDecimal,
    'amount_remaining' => $amountRemainingDecimal,
    'currency' => $currency,
    'period_start' => $mysqlPeriodStart,
    'period_end' => $mysqlPeriodEnd,
    'due_date' => $mysqlDueDate,
    'next_payment_attempt' => $mysqlNextPaymentAttempt,
    'billing_reason' => $billingReason,
    'collection_method' => $collectionMethod,
    'description' => $description,
    'customer_address' => $customerAddress,
    'customer_email' => $customerEmail,
    'customer_name' => $customerName,
    'line_items' => $lineItems,
    'stripe_event_id' => $process_input['id'] ?? null,
    'stripe_created' => $process_input['created'] ?? null,
    'stripe_livemode' => $process_input['livemode'] ?? false,
    'updated_at' => date('Y-m-d H:i:s')
];

// 7. Merge with existing metadata if present
$metadata = [];
if (!empty($existingMetadata)) {
    try {
        $metadata = json_decode($existingMetadata, true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
    } catch (Exception $e) {
        log_message('warning', 'Stripe Upcoming Invoice Webhook: Failed to parse existing metadata', ['error' => $e->getMessage()]);
        $metadata = [];
    }
}

// Store upcoming invoice data in metadata
$metadata['upcoming_invoice'] = $upcomingInvoiceData;
$metadataJson = json_encode($metadata);

// Start database transaction
$db->beginTransaction();
try {
    // 8. Update Billing table with upcoming invoice information
    $updateStmt = $db->prepare("
        UPDATE Billing 
        SET metadata = ?,
            next_billing_date = ?,
            currency = ?,
            updated_at = UTC_TIMESTAMP()
        WHERE billing_id = ?
    ");
    $updateStmt->execute([
        $metadataJson,
        $nextBillingDate,
        $currency,
        $billingId
    ]);
    
    log_message('info', 'Stripe Upcoming Invoice Webhook: Updated Billing table with upcoming invoice', [
        'billing_id' => $billingId,
        'next_billing_date' => $nextBillingDate,
        'amount_due' => $amountDueDecimal,
        'currency' => $currency
    ]);
    
    $db->commit();
    
    log_message('info', 'Stripe Upcoming Invoice Webhook: Upcoming invoice event processed successfully', [
        'stripe_invoice_id' => $stripeInvoiceId,
        'tenant_id' => $tenantId,
        'billing_id' => $billingId
    ]);
    
    // 9. Return success response
    return [
        'success' => true,
        'data' => [
            'tenant_id' => $tenantId,
            'billing_id' => $billingId,
            'subscription_id' => $subscriptionId,
            'stripe_invoice_id' => $stripeInvoiceId,
            'invoice_number' => $invoiceNumber,
            'amount_due' => $amountDueDecimal,
            'amount_remaining' => $amountRemainingDecimal,
            'currency' => $currency,
            'status' => $invoiceStatus,
            'next_billing_date' => $nextBillingDate,
            'period_start' => $mysqlPeriodStart,
            'period_end' => $mysqlPeriodEnd,
            'action' => 'updated'
        ],
        'message' => 'Upcoming invoice event processed successfully'
    ];
    
} catch (PDOException $e) {
    $db->rollBack();
    log_message('error', 'Stripe Upcoming Invoice Webhook: Database transaction failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'stripe_invoice_id' => $stripeInvoiceId
    ]);
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Failed to update upcoming invoice in database',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    $db->rollBack();
    log_message('error', 'Stripe Upcoming Invoice Webhook: Unexpected error during upcoming invoice processing', [
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
