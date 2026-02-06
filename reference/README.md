# Reference — ProcessFlow Step Prototype and Examples

This directory holds the **single reference file** for ProcessFlow step code snippets, including the full contract and instructions for agents (e.g. Cursor). The snippet runtime is implemented in the core platform repository; this file documents what is available at execution time.

## File

- **cursor-process-step-code-snippet_prototype_and_examples.php** — Prototype and examples. It documents:
  - Interface handling (`$process_input`, return array with `success`, `data`, `error`)
  - Database access (`$tenantDb` only; `$db` not available in standard context)
  - File operations, variable handling, error handling, security, performance limits
  - Available variables and services
  - DataPool and integration usage
  - Example patterns (validation, DB, API, async process spawning, files, etc.)

The file is intended for copy-paste and as a reference when writing new snippets. It is tied to **interface v1**; see `docs/interface/v1/` for the versioned contract.

## Stub for linter

The file includes a stub for `log_message()` so that static analysis does not complain; at runtime the platform injects the real function.
