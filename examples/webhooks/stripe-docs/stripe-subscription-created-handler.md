# Stripe Subscription Created Webhook Handler - Implementation Plan

**Created**: 2026-01-20  
**Status**: ✅ Implementation Complete  
**Location**: `code-snippets/stripe-subscription-created-handler.php`

## Overview

System-level process step code snippet that handles Stripe `customer.subscription.created` webhook events. The handler extracts subscription data, finds the associated tenant and plan, and updates the `Subscriptions` table to match the new Stripe subscription.

## Requirements

1. **Capture Stripe Event**: Handle `customer.subscription.created` event
2. **Extract Customer ID**: Get `data.object.customer` (Stripe customer ID)
3. **Extract Price ID**: Get `data.object.items.data[0].plan.id` (maps to `SubscriptionPlans.stripe_price_id`)
4. **Update Subscriptions Table**: Create new subscription record and mark old ones as inactive

## Implementation Details

### Data Flow

```
Stripe Webhook Event
    ↓
Validate Event Type (customer.subscription.created)
    ↓
Extract: stripe_customer_id, stripe_subscription_id, price_id
    ↓
Find tenant_id from stripe_customer_id
    ↓
Find plan_id from price_id (stripe_price_id)
    ↓
Mark existing active subscriptions as 'inactive'
    ↓
Insert new subscription record OR update existing
    ↓
Return success with subscription details
```

### Key Operations

1. **Tenant Lookup**:
   - First checks `Subscriptions` table for `stripe_customer_id`
   - Falls back to `Tenants` table (if `stripe_customer_id` column exists)
   - Returns error if tenant not found

2. **Plan Lookup**:
   - Queries `SubscriptionPlans` table where `stripe_price_id = price_id`
   - Gets `plan_id` and `plan_code` (maps to `subscription_type` enum)
   - Returns error if plan not found

3. **Status Mapping**:
   - Stripe `active` → System `active`
   - Stripe `trialing` → System `active`
   - Stripe `past_due` → System `suspended`
   - Stripe `canceled` → System `cancelled`
   - Stripe `unpaid` → System `suspended`
   - Stripe `incomplete` → System `inactive`
   - Stripe `incomplete_expired` → System `expired`

4. **History Tracking**:
   - Updates all existing active subscriptions for the tenant to `inactive` status
   - Inserts new subscription record with current Stripe data
   - If subscription with same `stripe_subscription_id` exists, updates it instead

5. **Timestamp Conversion**:
   - Converts Unix timestamps to MySQL format: `date('Y-m-d H:i:s', $unixTimestamp)`
   - Handles: `current_period_start`, `current_period_end`, `trial_start`, `trial_end`

### Database Operations

**Tables Used:**
- `Subscriptions` - Main subscription records
- `SubscriptionPlans` - Plan lookup by `stripe_price_id`
- `SubscriptionFeatures` - Plan features (stored as JSON in subscription)
- `Tenants` - Fallback tenant lookup (optional)

**Fields Updated/Created:**
- `subscription_id` (UUID, auto-generated)
- `tenant_id` (from lookup)
- `plan_id` (from lookup)
- `subscription_type` (from plan_code)
- `status` (mapped from Stripe status)
- `method` = `'stripe'`
- `stripe_customer_id`
- `stripe_subscription_id`
- `price_id`
- `current_period_start`, `current_period_end`
- `trial_start`, `trial_end` (if applicable)
- `features` (JSON from SubscriptionFeatures)
- `metadata` (JSON with Stripe event details)

### Error Handling

The code snippet handles the following error scenarios:

1. **Validation Errors**:
   - Invalid input structure
   - Missing event type
   - Missing subscription data

2. **Data Extraction Errors**:
   - Missing customer ID
   - Missing price ID

3. **Lookup Errors**:
   - Tenant not found for customer
   - Plan not found for price_id

4. **Database Errors**:
   - PDO exceptions
   - General exceptions

All errors return structured error responses with codes, messages, and details.

### Idempotency

- Checks if subscription with `stripe_subscription_id` already exists
- If exists: Updates the existing record
- If not exists: Inserts new record
- Prevents duplicate subscriptions from webhook retries

### History Tracking

- Before inserting new subscription, marks all existing active subscriptions for the tenant as `inactive`
- This preserves subscription history while ensuring only one active subscription per tenant
- Uses `status IN ('active', 'change_initiated')` to catch all active states

## Usage

### Process Configuration

- **Tenant**: `/root` (system-level)
- **Process Type**: Webhook handler
- **Trigger**: Stripe webhook event `customer.subscription.created`
- **Step Type**: Action (code snippet)

### Input Format

```json
{
    "type": "customer.subscription.created",
    "data": {
        "object": {
            "id": "sub_...",
            "customer": "cus_...",
            "status": "active",
            "items": {
                "data": [{
                    "plan": {
                        "id": "price_..."
                    }
                }]
            },
            "current_period_start": 1768821538,
            "current_period_end": 1771499938,
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
        "subscription_id": "uuid",
        "tenant_id": "uuid",
        "plan_id": "uuid",
        "subscription_type": "premium",
        "status": "active",
        "stripe_customer_id": "cus_...",
        "stripe_subscription_id": "sub_...",
        "price_id": "price_...",
        "action": "created",
        "previous_subscriptions_updated": 1
    },
    "message": "Subscription created successfully"
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

1. **New Subscription Creation**:
   - Valid event with new customer
   - Should create new subscription record
   - Should mark existing subscriptions as inactive

2. **Existing Subscription Update**:
   - Event with existing `stripe_subscription_id`
   - Should update existing record instead of creating duplicate

3. **Tenant Not Found**:
   - Event with unknown `stripe_customer_id`
   - Should return `TENANT_NOT_FOUND` error

4. **Plan Not Found**:
   - Event with unknown `price_id`
   - Should return `PLAN_NOT_FOUND` error

5. **Invalid Event Type**:
   - Event with wrong type
   - Should return `INVALID_EVENT_TYPE` error

6. **Missing Required Fields**:
   - Event missing customer or price_id
   - Should return appropriate validation error

## Dependencies

- Database tables: `Subscriptions`, `SubscriptionPlans`, `SubscriptionFeatures`, `Tenants`
- ProcessFlow execution environment with `$db` (PDO) access
- System-level tenant (`/root`) for cross-tenant operations

## Notes

- Uses `UTC_TIMESTAMP()` for database timestamps (UTC timezone)
- Uses MySQL `UUID()` function for subscription_id generation
- Stores full Stripe subscription object in `metadata` JSON field for reference
- Handles optional trial period dates
- Preserves subscription history by marking old subscriptions as inactive

## Future Enhancements

1. **Billing Record Creation**: Automatically create/update `Billing` table record
2. **Notification**: Send notification to tenant admin about new subscription
3. **Feature Activation**: Automatically activate plan features for tenant
4. **Webhook Retry Handling**: Better idempotency for webhook retries
5. **Logging**: Enhanced logging for audit trail
