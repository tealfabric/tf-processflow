# ProcessFlow Sandbox Restrictions (End-User Reference)

This document lists the practical restrictions that apply to code snippets executed in ProcessFlow Sandbox.

It focuses on what your step code is allowed to do, what is blocked, and common rejection reasons.

## 1) Mandatory sandbox conditions

- Code runs only in sandbox context.
- A tenant context is required.
- Code is validated before execution.
- Validation checks code content (comments are ignored for security pattern checks).

## 2) Blocked functions (direct use in snippet code)

The following function groups are blocked by sandbox security checks.

### System command execution

- `exec`
- `system`
- `shell_exec`
- `passthru`
- `proc_open`
- `popen`

### Dynamic code execution

- `eval`
- `create_function`
- `call_user_func`
- `call_user_func_array`

### Direct network primitives

- `curl_init`
- `curl_exec`
- `fsockopen`
- `socket_create`
- `socket_connect`

### Direct DB driver calls

- `mysql_*`
- `mysqli_*`
- `pg_*`
- `sqlite_*`
- `oci_*`

### Process control

- `pcntl_fork`
- `pcntl_exec`
- `pcntl_signal`

### Runtime/config mutation

- `ini_set`
- `ini_get`
- `putenv`
- `getenv`
- `set_time_limit`
- `error_reporting`
- `register_shutdown_function`

### Upload helper blocked in snippets

- `move_uploaded_file` (use platform services instead)

## 3) Blocked classes and class usage patterns

The sandbox blocks usage of these classes/patterns:

- `PDO` (not allowed in sandbox user code)
- `mysqli`
- `ReflectionClass`
- `ReflectionFunction`
- `ReflectionMethod`
- `SimpleXMLElement`
- `DOMDocument`
- `SoapClient`
- `CurlHandle`

PDO note:

- `PDO` is not allowed in sandbox user code.
- Use the database context provided by the sandbox instead of creating your own connection.
- `PDOException` is allowed for exception handling.

## 4) Blocked pattern categories

Your code is rejected if it matches dangerous patterns such as:

- Direct superglobal reads: `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION`, `$_SERVER`, `$_ENV`
- Variable function execution not in sandbox whitelist
- Reflection usage
- Serialization calls (`serialize`, `unserialize`)

Variable-function note:

- Calls like `$someFunction(...)` are blocked by default.
- Only sandbox-whitelisted closure names are allowed as variable calls.
- The full allowed closure list is in section 6.

## 5) Allowed utility function set (core built-ins)

Common utility functions are allowed, including:

### Array/type checks

- `array_merge`, `array_keys`, `array_values`, `array_map`, `array_filter`, `array_reduce`
- `array_search`, `array_unique`, `array_slice`, `array_splice`
- `count`, `sizeof`, `empty`, `isset`
- `is_array`, `is_string`, `is_numeric`, `is_bool`, `is_null`, `gettype`

### String/encoding/hash

- `strlen`, `strpos`, `str_replace`, `substr`, `trim`, `ltrim`, `rtrim`
- `strtolower`, `strtoupper`, `ucfirst`, `ucwords`
- `explode`, `implode`
- `json_encode`, `json_decode`
- `base64_encode`, `base64_decode`
- `md5`, `sha1`, `hash`

### Math/date/validation

- `abs`, `ceil`, `floor`, `round`, `min`, `max`, `rand`, `mt_rand`
- `date`, `time`, `strtotime`, `mktime`
- `filter_var`, `filter_input`, `preg_match`, `preg_replace`
- `intval`, `floatval`, `strval`, `boolval`

## 6) Allowed sandbox-provided file operation closures

Sandbox provides tenant-scoped file operation closures you can use from snippet code:

- `$file_get_contents`, `$file_put_contents`
- `$fopen`, `$fclose`, `$fread`, `$fwrite`, `$fgets`
- `$unlink`, `$copy`, `$rename`, `$mkdir`, `$rmdir`, `$chmod`, `$chown`
- `$scandir`, `$opendir`, `$readdir`, `$closedir`, `$glob`
- `$file_exists`, `$is_dir`, `$is_file`, `$is_readable`, `$is_writable`
- `$filesize`, `$filemtime`, `$filectime`, `$fileatime`, `$fileperms`, `$filetype`

Important:

- These are sandbox closures (prefixed with `$`).
- They are tenant-scoped and validated by sandbox rules.

## 7) Allowed context variables in snippet runtime

Only approved context keys are exposed to snippet code, including:

- `process_input`, `raw_input`, `process_output`
- `tenant_id`, `user_id`
- `db`, `tenantDb`
- `connectors`, `integration`
- `llm`, `email`, `notification`, `api`, `jwt`, `keystore`, `datapool`
- Request metadata such as `http_headers`, `request_method`, `request_uri`, `remote_addr`
- Process metadata such as `process_id`, `webapp_tenant_id`, `app_url`

## 8) Step configuration limits

Sandbox/validator limits include:

- `execution_timeout`: `1..300` seconds
- `memory_limit`: max `256MB` (supports values like `64M`, `128M`, or `default`)
- `max_retries`: `0..10`

If these values are outside allowed bounds, validation fails.

## 9) Schema restrictions for step input/output

When schemas are provided:

- Field names must match identifier format (`[a-zA-Z_][a-zA-Z0-9_]*`)
- Allowed schema types: `string`, `integer`, `int`, `float`, `double`, `boolean`, `bool`, `array`, `object`, `null`, `mixed`
- Invalid schema structure or types cause validation errors.

## 10) Why a sandbox step gets rejected

Most rejections are caused by one or more of:

- Blocked function/class detected
- Dangerous pattern match (superglobals, dynamic execution, reflection, serialization)
- Invalid PHP syntax or unbalanced brackets
- Exceeding timeout/memory/retry limits
- Invalid schema definitions

## 11) Authoring checklist (sandbox-safe)

- Avoid system/network/raw DB low-level calls.
- Use sandbox-provided services and `$`-prefixed sandbox wrappers.
- Avoid direct superglobal access.
- Keep retry/time/memory values within allowed ranges.
- Validate schema names/types before saving.
