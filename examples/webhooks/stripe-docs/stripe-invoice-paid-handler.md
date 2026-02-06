# Stripe Invoice Paid Webhook Handler - Implementation Plan

**Created**: 2026-01-20  
**Status**: ✅ Implementation Complete  
**Location**: `code-snippets/stripe-invoice-paid-handler.php`

## Overview

System-level process step code snippet that handles Stripe `invoice.paid` webhook events. The handler extracts invoice data, finds the associated tenant and billing records, and updates both the `BillingHistory` table (for invoice records) and the `Billing` table (for current billing status summary).

## Requirements

1. **Capture Stripe Event**: Handle `invoice.paid` event
2. **Extract Customer ID**: Get `data.object.customer` (Stripe customer ID)
3. **Extract Invoice ID**: Get `data.object.id` (Stripe invoice ID)
4. **Extract Invoice URL**: Get `data.object.hosted_invoice_url` (link to PDF invoice)
5. **Update BillingHistory Table**: Create or update invoice record based on `stripe_invoice_id`
6. **Update Billing Table**: Update summary fields (`last_payment_date`, `last_payment_amount`, `next_billing_date`)

## Architecture Context

### Table Relationships

- **Billing Table**: One record per tenant (unique constraint on `tenant_id`)
  - Stores current billing status and summary
  - Fields: `billing_id`, `tenant_id`, `subscription_id`, `last_payment_date`, `last_payment_amount`, `next_billing_date`, etc.
  - Updated with summary info when invoices are paid

- **BillingHistory Table**: Multiple records per tenant (invoice history)
  - Stores individual invoice records
  - Fields: `history_id`, `tenant_id`, `billing_id`, `subscription_id`, `stripe_invoice_id`, `invoice_number`, `amount`, `status`, `paid_date`, etc.
  - Has index on `stripe_invoice_id` for lookups

- **Relationship**: `BillingHistory.billing_id` → `Billing.billing_id` (FK)

## Implementation Details

### Data Flow

```
Stripe Webhook Event (invoice.paid)
    ↓
Validate Event Type (invoice.paid)
    ↓
Extract: stripe_customer_id, stripe_invoice_id, hosted_invoice_url, amount_paid, etc.
    ↓
Find tenant_id from stripe_customer_id
    ↓
Find billing_id and subscription_id from Billing table (by tenant_id)
    ↓
Check BillingHistory for existing stripe_invoice_id
    ↓
If found: Update existing BillingHistory record
If not found: Create new BillingHistory record
    ↓
Update Billing table with summary (last_payment_date, last_payment_amount, next_billing_date)
    ↓
Return success with invoice details
```

### Key Operations

1. **Tenant Lookup**:
   - Queries `Subscriptions` table for `stripe_customer_id` to get `tenant_id`
   - Falls back to `Billing` table if needed
   - Returns error if tenant not found

2. **Billing Record Lookup**:
   - Queries `Billing` table by `tenant_id` to get:
     - `billing_id` (needed for BillingHistory FK)
     - `subscription_id` (needed for BillingHistory FK)
   - Returns error if billing record not found

3. **Invoice Record Lookup**:
   - Queries `BillingHistory` table for `stripe_invoice_id`
   - If found: Updates existing record (idempotency)
   - If not found: Creates new record

4. **Status Mapping**:
   - Stripe invoice `status: "paid"` → BillingHistory `status: "paid"`
   - Other statuses: `pending`, `failed`, `refunded`, `cancelled` (map as-is)

5. **Timestamp Conversion**:
   - Converts Unix timestamps to MySQL format: `date('Y-m-d H:i:s', $unixTimestamp)`
   - Handles: `paid_at`, `period_start`, `period_end`, `due_date`

### Database Operations

**Tables Used:**
- `BillingHistory` - Invoice records (create/update)
- `Billing` - Current billing status (update summary fields)
- `Subscriptions` - Tenant lookup via `stripe_customer_id`
- `Tenants` - Fallback tenant lookup (optional)

**BillingHistory Fields (Create/Update):**
- `history_id` (UUID, auto-generated if new)
- `tenant_id` (from lookup)
- `billing_id` (from Billing table lookup)
- `subscription_id` (from Billing table lookup)
- `stripe_invoice_id` (from `data.object.id`)
- `invoice_number` (from `data.object.number`)
- `amount` (from `data.object.amount_paid`, converted from cents to decimal)
- `currency` (from `data.object.currency`)
- `status` (mapped from `data.object.status`)
- `payment_method` (from `data.object.collection_method` or `default_payment_method`)
- `billing_period_start` (from `data.object.period_start`)
- `billing_period_end` (from `data.object.period_end`)
- `due_date` (from `data.object.due_date`, if present)
- `paid_date` (from `data.object.status_transitions.paid_at`)
- `description` (from `data.object.description` or line items summary)
- `metadata` (JSON with: `hosted_invoice_url`, `invoice_pdf`, `customer_address`, `customer_email`, `customer_name`, full Stripe invoice object)

**Billing Fields (Update Summary):**
- `last_payment_date` = invoice `status_transitions.paid_at`
- `last_payment_amount` = invoice `amount_paid` (converted from cents)
- `next_billing_date` = invoice `period_end` (if subscription is active)
- `updated_at` = `UTC_TIMESTAMP()`

### Field Mapping (Stripe → Database)

| Stripe Field | Database Field | Conversion |
|-------------|---------------|------------|
| `data.object.id` | `BillingHistory.stripe_invoice_id` | Direct |
| `data.object.number` | `BillingHistory.invoice_number` | Direct |
| `data.object.amount_paid` | `BillingHistory.amount` | Divide by 100 (cents → decimal) |
| `data.object.currency` | `BillingHistory.currency` | Direct (uppercase) |
| `data.object.status` | `BillingHistory.status` | Map: `paid` → `paid` |
| `data.object.collection_method` | `BillingHistory.payment_method` | Direct |
| `data.object.period_start` | `BillingHistory.billing_period_start` | Unix → MySQL timestamp |
| `data.object.period_end` | `BillingHistory.billing_period_end` | Unix → MySQL timestamp |
| `data.object.due_date` | `BillingHistory.due_date` | Unix → MySQL timestamp (if present) |
| `data.object.status_transitions.paid_at` | `BillingHistory.paid_date` | Unix → MySQL timestamp |
| `data.object.status_transitions.paid_at` | `Billing.last_payment_date` | Unix → MySQL timestamp |
| `data.object.amount_paid` | `Billing.last_payment_amount` | Divide by 100 (cents → decimal) |
| `data.object.period_end` | `Billing.next_billing_date` | Unix → MySQL timestamp |
| `data.object.hosted_invoice_url` | `BillingHistory.metadata` | Store in JSON |
| `data.object.invoice_pdf` | `BillingHistory.metadata` | Store in JSON |
| `data.object.customer_address` | `BillingHistory.metadata` | Store in JSON |
| `data.object.customer_email` | `BillingHistory.metadata` | Store in JSON |
| `data.object.customer_name` | `BillingHistory.metadata` | Store in JSON |

### Error Handling

The code snippet handles the following error scenarios:

1. **Validation Errors**:
   - Invalid input structure
   - Missing event type
   - Missing invoice data

2. **Data Extraction Errors**:
   - Missing customer ID
   - Missing invoice ID
   - Missing required invoice fields

3. **Lookup Errors**:
   - Tenant not found for customer
   - Billing record not found for tenant
   - Subscription not found

4. **Database Errors**:
   - PDO exceptions
   - Transaction failures
   - General exceptions

All errors return structured error responses with codes, messages, and details.

### Idempotency

- Checks if `BillingHistory` record with `stripe_invoice_id` already exists
- If exists: Updates the existing record (prevents duplicates from webhook retries)
- If not exists: Creates new record
- Uses `ON DUPLICATE KEY UPDATE` or separate SELECT + UPDATE/INSERT logic

### Transaction Safety

- Wraps both `BillingHistory` and `Billing` updates in a database transaction
- Rolls back on any error to maintain data consistency
- Commits only after both updates succeed

## Usage

### Process Configuration

- **Tenant**: `/root` (system-level)
- **Process Type**: Webhook handler
- **Trigger**: Stripe webhook event `invoice.paid`
- **Step Type**: Action (code snippet)

### Input Format

```json
{
    "type": "invoice.paid",
    "data": {
        "object": {
            "id": "in_...",
            "customer": "cus_...",
            "number": "3Q3KA3CS-0011",
            "status": "paid",
            "amount_paid": 50000,
            "currency": "eur",
            "hosted_invoice_url": "https://invoice.stripe.com/...",
            "invoice_pdf": "https://pay.stripe.com/invoice/.../pdf",
            "period_start": 1768821538,
            "period_end": 1771499938,
            "due_date": null,
            "status_transitions": {
                "paid_at": 1768821539
            },
            "collection_method": "charge_automatically",
            "customer_address": {
                "city": "Madrid",
                "country": "FI",
                "line1": "Street 1",
                "postal_code": "00002"
            },
            "customer_email": "customer@example.com",
            "customer_name": "Customer Name",
            "description": "Subscription payment",
            ...
        }
    }
}
```

### Output Format

**Success:**
```json
{
    "success": true,
    "data": {
        "history_id": "uuid",
        "tenant_id": "uuid",
        "billing_id": "uuid",
        "subscription_id": "uuid",
        "stripe_invoice_id": "in_...",
        "invoice_number": "3Q3KA3CS-0011",
        "amount": 500.00,
        "currency": "EUR",
        "status": "paid",
        "paid_date": "2026-01-20 10:30:00",
        "action": "created" // or "updated"
    },
    "message": "Invoice paid event processed successfully"
}
```

**Error:**
```json
{
    "success": false,
    "error": {
        "code": "ERROR_CODE",
        "message": "Human readable message",
        "details": "Additional details"
    },
    "data": null
}
```

## Testing

### Test Cases

1. **New Invoice Creation**:
   - Valid event with new invoice
   - Should create new `BillingHistory` record
   - Should update `Billing` table summary fields

2. **Existing Invoice Update**:
   - Event with existing `stripe_invoice_id`
   - Should update existing `BillingHistory` record
   - Should update `Billing` table summary fields

3. **Tenant Not Found**:
   - Event with unknown `stripe_customer_id`
   - Should return `TENANT_NOT_FOUND` error

4. **Billing Record Not Found**:
   - Event for tenant without billing record
   - Should return `BILLING_NOT_FOUND` error

5. **Invalid Event Type**:
   - Event with wrong type
   - Should return `INVALID_EVENT_TYPE` error

6. **Missing Required Fields**:
   - Event missing customer, invoice_id, or amount_paid
   - Should return appropriate validation error

7. **Transaction Rollback**:
   - Simulate database error during update
   - Should rollback both `BillingHistory` and `Billing` changes

## Dependencies

- Database tables: `BillingHistory`, `Billing`, `Subscriptions`, `Tenants`
- ProcessFlow execution environment with `$db` (PDO) access
- System-level tenant (`/root`) for cross-tenant operations

## Notes

- Uses `UTC_TIMESTAMP()` for database timestamps (UTC timezone)
- Uses MySQL `UUID()` function for `history_id` generation
- Stores full Stripe invoice object in `metadata` JSON field for reference
- Handles optional fields (due_date, description, customer_address)
- Converts amounts from cents (Stripe) to decimal (database)
- Updates both invoice history and current billing status

## Future Enhancements

1. **Email Notification**: Send invoice receipt email to customer
2. **PDF Storage**: Download and store invoice PDF locally
3. **Refund Handling**: Handle `invoice.refunded` events
4. **Failed Payment Handling**: Handle `invoice.payment_failed` events
5. **Webhook Retry Handling**: Better idempotency for webhook retries
6. **Logging**: Enhanced logging for audit trail
7. **BillingHistory Cleanup**: Archive old invoice records
