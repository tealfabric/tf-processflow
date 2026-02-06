# Interface v1 — Return Format

Every step **must** return an array. The platform uses it to decide success/failure and to pass data to the next step.

## Required keys

| Key | Type | Description |
|-----|------|-------------|
| `success` | bool | `true` if the step succeeded, `false` on error. |
| `data` | array \| null | Payload for the next step. On success, any array; on failure, typically `null`. |

## Optional keys

| Key | Type | Description |
|-----|------|-------------|
| `message` | string | Human-readable status (e.g. "Operation completed successfully"). |
| `error` | array | Required when `success === false`. See [error-format.md](error-format.md). |

## Success example

```php
return [
    'success' => true,
    'data' => [
        'customer_id' => $customerId,
        'status' => 'processed'
    ],
    'message' => 'Processing completed successfully'
];
```

## Failure example

```php
return [
    'success' => false,
    'error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => 'Email is required',
        'details' => 'Missing required field: email'
    ],
    'data' => null
];
```

## Behavior

- **success = true:** Platform takes `data` and passes it as `$process_input` to the next step.
- **success = false:** Platform stops the process, logs the error, and does not run subsequent steps. The `error` structure is used for reporting.

Never return a non-array (e.g. string or object). Never use `echo`, `print`, or `print_r` for step output; use `log_message()` for logging.
