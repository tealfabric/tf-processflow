# Process Flow Snippets — Documentation

This directory contains all documentation needed to author ProcessFlow code snippets. The **snippet runtime** (ProcessStepExecutor, CodeSandbox, etc.) lives in the core platform repository; only the authoring guides and the versioned **interface contract** are maintained here.

## Master skeleton policy

This `tf-processflow` repository is the **master skeleton** for ProcessFlow snippet structure and generation.

- Do **not** store end-user/customer-specific production snippet code in this master repository.
- For tenant/application implementations, clone or fork this repository and keep end-user changes in that separate repository.
- Keep actual tenant content under `tenants/` in your tenant/app repository so master skeleton patches/upgrades can be applied later with lower risk.

## Contents

- **PROCESSFLOW_CODE_SNIPPETS_GUIDE.md** — Full guide: structure, input/output, variables, error handling, examples, best practices.
- **PROCESSFLOW_QUICK_REFERENCE.md** — Quick reference and common patterns.
- **PROCESSFLOW_EVENT_HANDLING_GUIDE.md** — Event Broker pattern and event-driven process orchestration.
- **WEBAPP_ASYNC_PROCESS_TRIGGER_GUIDE.md** — Async process trigger for WebApp/Webhook (stub process pattern).
- **PROCESSFLOW_SANDBOX_AND_API_RESTRICTIONS.md** — Sandbox runtime restrictions, blocked APIs/patterns, allowed wrappers, and validation limits.
- **Code_Snippets/** — Category-specific snippet examples (data validation, DB, connectors, notifications, etc.).
- **interface/** — Versioned step contract (variables, return format, error format, services). See `interface/v1/` for current contract and `interface/CHANGELOG.md` for changes.

## Interface version

The current interface version is in the repository root: **`INTERFACE_VERSION`**. Docs in this folder target that version unless a doc explicitly states otherwise.
