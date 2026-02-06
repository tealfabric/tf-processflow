# Stripe Upcoming Invoice Webhook Handler - Implementation Plan

**Created**: 2026-01-27  
**Status**: ✅ Implementation Complete  
**Location**: `code-snippets/stripe-upcoming-invoice-handler.php`

## Overview

System-level process step code snippet that handles Stripe `invoice.upcoming` webhook events. The handler extracts upcoming invoice data, finds the associated tenant and billing records, and updates the `Billing` table with upcoming invoice information stored in the `metadata` field and updates the `next_billing_date` field.

## Requirements

1. **Capture Stripe Event**: Handle `invoice.upcoming` event
2. **Extract Customer ID**: Get `data.object.customer` (Stripe customer ID)
3. **Extract Invoice Identifier**: Extract or generate invoice identifier (upcoming invoices don't have an `id` field until finalized)
4. **Extract Invoice Details**: Get amount, dates, line items, customer info, etc.
5. **Update Billing Table**: Store upcoming invoice information in `metadata` field and update `next_billing_date`

## Architecture Context

### Table Relationships

- **Billing Table**: One record per tenant (unique constraint on `tenant_id`)
  - Stores current billing status and summary
  - Fields: `billing_id`, `tenant_id`, `subscription_id`, `next_billing_date`, `metadata`, etc.
  - Updated with upcoming invoice info when `invoice.upcoming` events are received
  - Upcoming invoice data is stored in `metadata.upcoming_invoice` JSON field

- **Relationship**: `Billing.tenant_id` → `Tenants.tenant_id` (FK)

## Implementation Details

### Data Flow

```
Stripe Webhook Event (invoice.upcoming)
    ↓
Validate Event Type (invoice.upcoming)
    ↓
Extract: stripe_customer_id, stripe_invoice_id, amount_due, period_start, period_end, etc.
    ↓
Find tenant_id from stripe_customer_id
    ↓
Find billing_id and subscription_id from Billing table (by tenant_id)
    ↓
Extract existing metadata from Billing table
    ↓
Merge upcoming invoice data into metadata.upcoming_invoice
    ↓
Update Billing table with:
  - metadata (with upcoming invoice info)
  - next_billing_date (from next_payment_attempt or period_end)
  - currency
    ↓
Return success with upcoming invoice details
```

### Key Operations

1. **Invoice Identifier Extraction**:
   - Upcoming invoices don't have an `id` field until they're finalized
   - First attempts to extract identifier from `lines.url` (pattern: `/invoices/upcoming_in_XXX/lines`)
   - If not found, generates identifier from `subscription_id` + `period_start`
   - Falls back to using event ID if neither is available

2. **Tenant Lookup**:
   - Queries `Subscriptions` table for `stripe_customer_id` to get `tenant_id`
   - Falls back to `Billing` table if needed
   - Returns error if tenant not found

3. **Billing Record Lookup**:
   - Queries `Billing` table by `tenant_id` to get:
     - `billing_id` (needed for update)
     - `subscription_id` (for reference)
     - `metadata` (existing metadata to merge with)
   - Returns error if billing record not found

4. **Metadata Management**:
   - Extracts existing `metadata` JSON from Billing table
   - Merges upcoming invoice data into `metadata.upcoming_invoice`
   - Preserves other metadata fields
   - Stores complete upcoming invoice information

5. **Date Handling**:
   - Uses `next_payment_attempt` as `next_billing_date` if available
   - Falls back to `period_end` if `next_payment_attempt` is not available
   - Converts Unix timestamps to MySQL format

### Database Operations

**Tables Used:**
- `Billing` - Current billing status (update metadata and next_billing_date)
- `Subscriptions` - Tenant lookup via `stripe_customer_id`
- `Tenants` - Fallback tenant lookup (optional)

**Billing Fields (Update):**
- `metadata` - JSON field containing `upcoming_invoice` object with:
  - `stripe_invoice_id`
  - `invoice_number`
  - `status`
  - `amount_due` (decimal)
  - `amount_remaining` (decimal)
  - `currency`
  - `period_start` (MySQL timestamp)
  - `period_end` (MySQL timestamp)
  - `due_date` (MySQL timestamp)
  - `next_payment_attempt` (MySQL timestamp)
  - `billing_reason`
  - `collection_method`
  - `description`
  - `customer_address`
  - `customer_email`
  - `customer_name`
  - `line_items` (array)
  - `stripe_event_id`
  - `stripe_created`
  - `stripe_livemode`
  - `updated_at`
- `next_billing_date` - Set to `next_payment_attempt` or `period_end`
- `currency` - Updated from invoice currency
- `updated_at` - Set to `UTC_TIMESTAMP()`

### Field Mapping (Stripe → Database)

| Stripe Field | Database Field | Conversion |
|-------------|---------------|------------|
| `data.object.id` (if present) or extracted from `lines.url` or generated | `Billing.metadata.upcoming_invoice.stripe_invoice_id` | Extract from lines URL pattern `/invoices/(upcoming_in_[^\/]+)/` or generate from subscription + period |
| `data.object.number` | `Billing.metadata.upcoming_invoice.invoice_number` | Direct |
| `data.object.status` | `Billing.metadata.upcoming_invoice.status` | Direct |
| `data.object.amount_due` | `Billing.metadata.upcoming_invoice.amount_due` | Divide by 100 (cents → decimal) |
| `data.object.amount_remaining` | `Billing.metadata.upcoming_invoice.amount_remaining` | Divide by 100 (cents → decimal) |
| `data.object.currency` | `Billing.metadata.upcoming_invoice.currency` | Direct (uppercase) |
| `data.object.currency` | `Billing.currency` | Direct (uppercase) |
| `data.object.period_start` | `Billing.metadata.upcoming_invoice.period_start` | Unix → MySQL timestamp |
| `data.object.period_end` | `Billing.metadata.upcoming_invoice.period_end` | Unix → MySQL timestamp |
| `data.object.due_date` | `Billing.metadata.upcoming_invoice.due_date` | Unix → MySQL timestamp (if present) |
| `data.object.next_payment_attempt` | `Billing.metadata.upcoming_invoice.next_payment_attempt` | Unix → MySQL timestamp |
| `data.object.next_payment_attempt` | `Billing.next_billing_date` | Unix → MySQL timestamp (or period_end) |
| `data.object.billing_reason` | `Billing.metadata.upcoming_invoice.billing_reason` | Direct |
| `data.object.collection_method` | `Billing.metadata.upcoming_invoice.collection_method` | Direct |
| `data.object.description` | `Billing.metadata.upcoming_invoice.description` | Direct |
| `data.object.customer_address` | `Billing.metadata.upcoming_invoice.customer_address` | Direct (JSON) |
| `data.object.customer_email` | `Billing.metadata.upcoming_invoice.customer_email` | Direct |
| `data.object.customer_name` | `Billing.metadata.upcoming_invoice.customer_name` | Direct |
| `data.object.lines.data` | `Billing.metadata.upcoming_invoice.line_items` | Array of line items |
| `id` (event, from `process_input['id']`) | `Billing.metadata.upcoming_invoice.stripe_event_id` | Direct |
| `created` (event, from `process_input['created']`) | `Billing.metadata.upcoming_invoice.stripe_created` | Direct |
| `livemode` (event, from `process_input['livemode']`) | `Billing.metadata.upcoming_invoice.stripe_livemode` | Direct |

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

4. **Database Errors**:
   - PDO exceptions
   - Transaction failures
   - General exceptions

All errors return structured error responses with codes, messages, and details.

### Idempotency

- Always updates the existing Billing record for the tenant
- Merges upcoming invoice data into existing metadata (preserves other metadata fields)
- Overwrites previous `upcoming_invoice` data in metadata
- Safe to process the same event multiple times (idempotent)

### Transaction Safety

- Wraps Billing table update in a database transaction
- Rolls back on any error to maintain data consistency
- Commits only after update succeeds

## Usage

### Process Configuration

- **Tenant**: `/root` (system-level)
- **Process Type**: Webhook handler
- **Trigger**: Stripe webhook event `invoice.upcoming`
- **Step Type**: Action (code snippet)

### Input Format

```json
{
    "type": "invoice.upcoming",
    "data": {
        "object": {
            "id": "in_...",
            "customer": "cus_...",
            "number": null,
            "status": "draft",
            "amount_due": 5000,
            "amount_remaining": 5000,
            "currency": "eur",
            "period_start": 1769792929,
            "period_end": 1772298529,
            "due_date": null,
            "next_payment_attempt": 1769796529,
            "billing_reason": "upcoming",
            "collection_method": "charge_automatically",
            "description": null,
            "customer_address": {
                "city": null,
                "country": "FI",
                "line1": null,
                "postal_code": null
            },
            "customer_email": "jarlah@tealfabric.com",
            "customer_name": "Jarno Lähteenmäki",
            "lines": {
                "data": [
                    {
                        "id": "il_tmp_...",
                        "description": "1 seats × Tealfabric Platform Builder (at €50.00 / month)",
                        "amount": 5000,
                        "currency": "eur",
                        "quantity": 1
                    }
                ]
            },
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
        "tenant_id": "uuid",
        "billing_id": "uuid",
        "subscription_id": "uuid",
        "stripe_invoice_id": "in_...",
        "invoice_number": null,
        "amount_due": 50.00,
        "amount_remaining": 50.00,
        "currency": "EUR",
        "status": "draft",
        "next_billing_date": "2026-01-27 10:30:00",
        "period_start": "2026-01-27 10:00:00",
        "period_end": "2026-02-27 10:00:00",
        "action": "updated"
    },
    "message": "Upcoming invoice event processed successfully"
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

1. **New Upcoming Invoice**:
   - Valid event with upcoming invoice
   - Should update `Billing` table metadata with upcoming invoice info
   - Should update `next_billing_date` field

2. **Updated Upcoming Invoice**:
   - Event with updated upcoming invoice (same or different invoice ID)
   - Should overwrite previous `upcoming_invoice` in metadata
   - Should update `next_billing_date` field

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
   - Event missing customer, invoice_id, or amount_due
   - Should return appropriate validation error

7. **Transaction Rollback**:
   - Simulate database error during update
   - Should rollback Billing table changes

8. **Metadata Preservation**:
   - Event when Billing table already has other metadata
   - Should preserve existing metadata fields
   - Should only update `upcoming_invoice` field

## Dependencies

- Database tables: `Billing`, `Subscriptions`, `Tenants`
- ProcessFlow execution environment with `$db` (PDO) access
- System-level tenant (`/root`) for cross-tenant operations

## Notes

- Uses `UTC_TIMESTAMP()` for database timestamps (UTC timezone)
- Stores full upcoming invoice object in `metadata.upcoming_invoice` JSON field for reference
- Handles optional fields (due_date, description, customer_address, invoice_number)
- Converts amounts from cents (Stripe) to decimal (database)
- Merges with existing metadata to preserve other metadata fields
- Uses `next_payment_attempt` as `next_billing_date` if available, otherwise uses `period_end`
- Always updates existing Billing record (one per tenant)

## Future Enhancements

1. **Email Notification**: Send upcoming invoice notification to customer
2. **BillingHistory Pre-creation**: Create pending BillingHistory record for upcoming invoice
3. **Payment Method Validation**: Check if payment method is available before storing
4. **Subscription Status Check**: Verify subscription is active before processing
5. **Line Items Analysis**: Extract and analyze subscription items from line items
6. **Webhook Retry Handling**: Better idempotency for webhook retries
7. **Logging**: Enhanced logging for audit trail
