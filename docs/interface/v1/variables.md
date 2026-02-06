# Interface v1 — Available Variables

Variables injected into the step execution context by the platform. All snippet code receives these (when applicable).

## Input

| Variable | Type | Description |
|----------|------|-------------|
| `$process_input` | array | Data from the previous step. Primary input for step logic. |
| `$raw_input` | string | Raw, unmodified POST/JSON body. Available when the process is triggered by a WebApp; useful for signature verification (e.g. webhooks). |

## Identity & context

| Variable | Type | Description |
|----------|------|-------------|
| `$tenant_id` | string | Current tenant ID. Use from context only; never from user input. |
| `$user_id` | string | Current user ID. |

## Database & data

| Variable | Type | Description |
|----------|------|-------------|
| `$tenantDb` | object | **Tenant-scoped database service (RECOMMENDED).** Use for all tenant-scoped queries; enforces tenant isolation. |
| `$db` | PDO | Not available in standard tenant context (blocked by security guardrails). Do not use; use `$tenantDb` or `$api` for system data. |
| `$datapool` | object | DataPool service for schema-based storage and querying. |

## Integrations & API

| Variable | Type | Description |
|----------|------|-------------|
| `$integration` | object | Integration execution service. Prefer over deprecated `$connectors`. |
| `$api` | object | API service for HTTP requests (`get`, `post`, `put`, `delete`). Automatically includes execution authorization key. |
| `$execution_auth_key` | string\|null | Execution authorization key (HMAC-SHA256) for internal API calls. |

## Services

| Variable | Type | Description |
|----------|------|-------------|
| `$llm` | object | LLM service for AI operations. |
| `$email` | object | Email service. |
| `$notification` | object | Notification service. |
| `$files` | object\|null | File service for uploaded files. Only set when `execution_id` is set (e.g. WebApp file upload). |

## Request context (WebApp-triggered)

| Variable | Type | Description |
|----------|------|-------------|
| `$http_headers` | array | HTTP headers from the request. |
| `$request_method` | string | HTTP method (e.g. GET, POST). |
| `$request_uri` | string | Request URI path. |
| `$remote_addr` | string | Client IP address. |
| `$app_url` | string | Application base URL from `APP_URL` (e.g. `https://dev.tealfabric.io`). |

## Injected function

| Function | Signature | Description |
|----------|-----------|-------------|
| `log_message` | `log_message(string $message): void` | Logs to execution log file and database. Use instead of echo/print/var_dump. |
