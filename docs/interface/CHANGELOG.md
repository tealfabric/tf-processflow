# Interface Changelog

ProcessFlow step contract changes by version. Non-breaking rules: patch = clarifications/optional additions; minor = new optional variables/keys; major = breaking changes with migration path.

## [1.0.0] — Initial

- **Variables:** `$process_input`, `$raw_input` (WebApp), `$tenant_id`, `$user_id`, `$tenantDb`, `$datapool`, `$integration`, `$api`, `$execution_auth_key`, `$llm`, `$email`, `$notification`, `$files` (when execution_id set), `$http_headers`, `$request_method`, `$request_uri`, `$remote_addr`, `$app_url`. `$db` (PDO) not available in standard tenant context.
- **Return format:** Array with required `success` (bool) and `data` (array or null). Optional `message` (string). On failure, `error` (array) with `code`, `message`, `details`.
- **Function:** `log_message(string $message)` — injected at runtime.
- **Compatibility:** Platform ProcessStepExecutor and CodeSandbox implementing this contract are compatible with snippets written for interface v1.0.x.
