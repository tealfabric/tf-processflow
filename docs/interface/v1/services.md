# Interface v1 — Services Summary

Short reference for services injected into the step context. For full variable list see [variables.md](variables.md).

## TenantDatabaseService (`$tenantDb`)

Use for all tenant-scoped database operations. Enforces tenant isolation; do not pass `tenant_id` manually in queries when using its helpers.

- **getOne(sql, params)** — Single row (returns associative array or null).
- **query(sql, params)** — Multiple rows (returns array of associative arrays).
- **insert(table, data)** — Insert row; returns insert id.
- **update(table, data, where)** — Update rows; returns affected count.
- **Prepared statements** — Prefer over raw SQL with concatenation.

## DataPool (`$datapool`)

Schema-based storage and querying: `createSchema`, `getSchema`, `listSchemas`, `insert`, `update`, `delete`, `query`, `table()` (query builder), `bulkInsert`.

## Integration (`$integration`)

- **executeSync(integrationId, data, options)** — Run integration and wait for result.
- **execute(integrationId, data, options)** — Execute (sync/async per config).
- **executeAsync(integrationId, data, options)** — Queue and return execution id.
- **getStatus(executionId)** — Check async execution status.

## API (`$api`)

HTTP calls to internal or external URLs. Automatically adds execution auth and tenant/user context.

- **get(url, headers)** — GET request.
- **post(url, data, headers)** — POST request.
- **put(url, data, headers)** — PUT request.
- **delete(url, headers)** — DELETE request.

Response shape: `['success' => bool, 'status_code' => int, 'data' => ...]`.

## LLM (`$llm`)

AI/LLM operations (model-specific methods as provided by the platform).

## Email (`$email`)

Send email (template-based or raw) as defined by the platform email service.

## Notification (`$notification`)

Create in-app notifications; e.g. `createAdminNotification([...])` for admin alerts.

## Files (`$files`)

Only set when execution has uploaded files (e.g. WebApp). Methods: `getFiles()`, `getFile(name)`, `readFile(name)`, `getFileInfo(name)`, `uploadToExternal(name, url, options)`.

---

All services are provided by the platform at runtime. Do not instantiate database or HTTP clients yourself; use these variables only.
