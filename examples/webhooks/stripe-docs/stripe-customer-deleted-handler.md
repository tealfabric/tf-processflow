# Stripe Customer Deleted Webhook Handler - Implementation Plan

**Created**: 2026-01-29  
**Status**: ✅ Implementation Complete  
**Location**: `code-snippets/stripe-customer-deleted-handler.php`

## Overview

System-level process step code snippet that handles Stripe `customer.deleted` webhook events. When a Stripe customer is deleted, this handler finds the associated tenant, marks existing subscriptions as inactive, creates a new free tier subscription, and clears all upcoming invoices from the billing metadata.

## Requirements

1. **Capture Stripe Event**: Handle `customer.deleted` event
2. **Extract Customer ID**: Get `data.object.id` (Stripe customer ID)
3. **Find Tenant**: Search for tenant_id from stripe_customer_id in Subscriptions/Billing/Tenants tables
4. **Mark Subscriptions Inactive**: Mark all active subscriptions for the tenant as inactive
5. **Create Free Tier Subscription**: Create a new free tier subscription for the tenant
6. **Cancel Upcoming Invoices**: Clear upcoming invoice data from Billing metadata
7. **Update Billing Table**: Remove Stripe customer references from Billing table

## Architecture Context

### Table Relationships

- **Subscriptions Table**: Multiple records per tenant (subscription history)
  - Stores subscription records with status tracking
  - Fields: `subscription_id`, `tenant_id`, `subscription_type`, `plan_id`, `status`, `stripe_customer_id`, etc.
  - Active subscriptions are marked as `inactive` when customer is deleted
  - New free tier subscription is created

- **Billing Table**: One record per tenant (unique constraint on `tenant_id`)
  - Stores current billing status and summary
  - Fields: `billing_id`, `tenant_id`, `subscription_id`, `stripe_customer_id`, `metadata`, etc.
  - Stripe customer references are cleared
  - Upcoming invoice data is removed from metadata

- **SubscriptionPlans Table**: Plan definitions
  - Contains free tier plan definition
  - Used to create new free subscription

- **Relationship**: `Subscriptions.tenant_id` → `Tenants.tenant_id` (FK)
- **Relationship**: `Billing.subscription_id` → `Subscriptions.subscription_id` (FK)

## Implementation Details

### Data Flow

```
Stripe Webhook Event (customer.deleted)
    ↓
Validate Event Type (customer.deleted)
    ↓
Extract: stripe_customer_id
    ↓
Find tenant_id from stripe_customer_id (Subscriptions → Billing → Tenants)
    ↓
If tenant not found: Return success (no action needed)
    ↓
Start Database Transaction
    ↓
Get free plan from SubscriptionPlans table
    ↓
Mark all active subscriptions as inactive
    ↓
Create new free tier subscription
    ↓
Update Billing table:
  - Clear stripe_customer_id
  - Clear stripe_payment_method_id
  - Clear next_billing_date
  - Remove upcoming_invoice from metadata
  - Update subscription_id to new free subscription
  - Set billing_method to 'manual'
  - Set status to 'inactive'
    ↓
Commit Transaction
    ↓
Return success with details
```

### Key Operations

1. **Tenant Lookup**:
   - Queries `Subscriptions` table for `stripe_customer_id` to get `tenant_id`
   - Falls back to `Billing` table if needed
   - Falls back to `Tenants` table if needed
   - Returns success (no action) if tenant not found (customer may not have been associated with a tenant)

2. **Free Plan Lookup**:
   - Queries `SubscriptionPlans` table for plan with `plan_code = 'free'` and `is_active = 1`
   - Retrieves plan features from `SubscriptionFeatures` table
   - Returns error if free plan not found

3. **Subscription Management**:
   - Marks all active subscriptions (`status IN ('active', 'change_initiated')`) as `inactive`
   - Creates new subscription with:
     - `subscription_type = 'free'`
     - `status = 'active'`
     - `method = 'trial'`
     - Features from free plan
     - Metadata about customer deletion

4. **Billing Table Updates**:
   - Clears `stripe_customer_id` (set to NULL)
   - Clears `stripe_payment_method_id` (set to NULL)
   - Clears `next_billing_date` (set to NULL)
   - Removes `upcoming_invoice` from metadata JSON
   - Updates `subscription_id` to new free subscription
   - Sets `billing_method = 'manual'`
   - Sets `status = 'inactive'`
   - Adds customer deletion info to metadata

5. **Upcoming Invoice Cancellation**:
   - Removes `upcoming_invoice` field from Billing metadata
   - This effectively cancels any pending upcoming invoices

### Database Operations

**Tables Used:**
- `Subscriptions` - Mark inactive, create new free subscription
- `Billing` - Clear Stripe references, update subscription_id, clear upcoming invoices
- `SubscriptionPlans` - Get free plan definition
- `SubscriptionFeatures` - Get free plan features
- `Tenants` - Fallback tenant lookup (optional)

**Subscriptions Fields (Update):**
- `status` - Set to `'inactive'` for all active subscriptions

**Subscriptions Fields (Insert - New Free Subscription):**
- `subscription_id` - UUID (auto-generated)
- `tenant_id` - From lookup
- `subscription_type` - `'free'`
- `plan_id` - From free plan lookup
- `status` - `'active'`
- `method` - `'trial'`
- `features` - JSON from free plan features
- `metadata` - JSON with customer deletion info
- `created_at` - `UTC_TIMESTAMP()`
- `updated_at` - `UTC_TIMESTAMP()`

**Billing Fields (Update):**
- `stripe_customer_id` - Set to NULL
- `stripe_payment_method_id` - Set to NULL
- `next_billing_date` - Set to NULL
- `metadata` - Remove `upcoming_invoice`, add `stripe_customer_deleted` info
- `subscription_id` - Update to new free subscription ID
- `billing_method` - Set to `'manual'`
- `status` - Set to `'inactive'`
- `updated_at` - `UTC_TIMESTAMP()`

### Field Mapping (Stripe → Database)

| Stripe Field | Database Field | Conversion |
|-------------|---------------|------------|
| `data.object.id` | Used for lookup, then cleared from Billing.stripe_customer_id | Direct |
| `data.object.email` | Stored in metadata (for reference) | Direct |
| `data.object.name` | Stored in metadata (for reference) | Direct |
| `id` (event) | `Subscriptions.metadata.stripe_event_id` | Direct |
| `created` (event) | `Subscriptions.metadata.stripe_created` | Direct |
| `livemode` (event) | `Subscriptions.metadata.stripe_livemode` | Direct |

### Error Handling

The code snippet handles the following error scenarios:

1. **Validation Errors**:
   - Invalid input structure
   - Missing event type
   - Missing customer data

2. **Data Extraction Errors**:
   - Missing customer ID

3. **Lookup Errors**:
   - Tenant not found (returns success with no action)
   - Free plan not found (returns error)

4. **Database Errors**:
   - PDO exceptions
   - Transaction failures
   - General exceptions

All errors return structured error responses with codes, messages, and details.

### Idempotency

- Safe to process the same event multiple times
- If tenant not found, returns success (no action needed)
- If subscriptions already inactive, still creates new free subscription
- Transaction ensures atomicity

### Transaction Safety

- Wraps all database operations in a single transaction
- Rolls back on any error to maintain data consistency
- Commits only after all updates succeed

## Usage

### Process Configuration

- **Tenant**: `/root` (system-level)
- **Process Type**: Webhook handler
- **Trigger**: Stripe webhook event `customer.deleted`
- **Step Type**: Action (code snippet)

### Input Format

```json
{
    "type": "customer.deleted",
    "data": {
        "object": {
            "id": "cus_TWGT5dSWiUMUU8",
            "object": "customer",
            "email": "jarlah@jl9034.net",
            "name": "Jarno Lähteenmäki",
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
        "stripe_customer_id": "cus_...",
        "new_subscription_id": "uuid",
        "new_subscription_type": "free",
        "inactivated_subscriptions_count": 1,
        "upcoming_invoices_cancelled": true,
        "action": "customer_deleted_and_downgraded"
    },
    "message": "Customer deletion processed successfully - tenant downgraded to free tier"
}
```

**Success (No Tenant Found):**
```json
{
    "success": true,
    "data": {
        "stripe_customer_id": "cus_...",
        "tenant_id": null,
        "action": "no_tenant_found",
        "message": "Customer deleted but no associated tenant found"
    },
    "message": "Customer deletion processed (no tenant found)"
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

1. **Customer Deletion with Active Subscription**:
   - Valid event with customer that has active subscription
   - Should mark subscription as inactive
   - Should create new free subscription
   - Should clear Stripe references from Billing table
   - Should remove upcoming invoices from metadata

2. **Customer Deletion with Multiple Subscriptions**:
   - Event for customer with multiple active subscriptions
   - Should mark all subscriptions as inactive
   - Should create single new free subscription

3. **Customer Deletion with Upcoming Invoice**:
   - Event for customer with upcoming invoice in metadata
   - Should remove upcoming_invoice from metadata
   - Should clear next_billing_date

4. **Customer Deletion without Tenant**:
   - Event for customer not associated with any tenant
   - Should return success with no action

5. **Customer Deletion with No Billing Record**:
   - Event for tenant without billing record
   - Should create new billing record for free subscription

6. **Invalid Event Type**:
   - Event with wrong type
   - Should return `INVALID_EVENT_TYPE` error

7. **Missing Required Fields**:
   - Event missing customer ID
   - Should return appropriate validation error

8. **Transaction Rollback**:
   - Simulate database error during update
   - Should rollback all changes

9. **Free Plan Not Found**:
   - Simulate missing free plan in database
   - Should return `DATABASE_ERROR` with details

## Dependencies

- Database tables: `Subscriptions`, `Billing`, `SubscriptionPlans`, `SubscriptionFeatures`, `Tenants`
- ProcessFlow execution environment with `$db` (PDO) access
- System-level tenant (`/root`) for cross-tenant operations
- Free tier plan must exist in `SubscriptionPlans` table with `plan_code = 'free'`

## Notes

- Uses `UTC_TIMESTAMP()` for database timestamps (UTC timezone)
- Uses MySQL `UUID()` function for `subscription_id` generation
- Stores customer deletion metadata in subscription and billing records
- Gracefully handles case where tenant is not found (returns success)
- Creates new free subscription even if previous subscriptions were already inactive
- Clears all Stripe payment references from Billing table
- Removes upcoming invoice data from metadata to cancel pending invoices

## Future Enhancements

1. **Email Notification**: Send notification to tenant admin about subscription downgrade
2. **Audit Logging**: Enhanced logging for compliance and audit trail
3. **Data Retention**: Option to archive or delete old subscription records
4. **Grace Period**: Option to implement grace period before downgrading
5. **Subscription History**: Better tracking of subscription changes
6. **Webhook Retry Handling**: Better idempotency for webhook retries
