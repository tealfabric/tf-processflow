# Process Flow Code Snippets Repository

This repository contains all documentation, code samples, and examples needed to author **fully functional** ProcessFlow code snippets. It is separate from the core platform so that:

- **Core platform code** and **customer/tenant-specific snippet code** stay distinct
- **Licensing** can differ (e.g. proprietary or customer-specific for this repo)
- **Versioning** of the snippet interface is managed here for non-breaking evolution

## Structure

- **`docs/`** — Snippet authoring guides, quick reference, event handling, WebApp/async trigger docs, and sandbox/API restrictions (`docs/PROCESSFLOW_SANDBOX_AND_API_RESTRICTIONS.md`). Versioned interface contract under `docs/interface/v1/`.
- **`spec/`** — Machine-readable step contract (e.g. JSON) per interface version.
- **`examples/`** — Shared, non-tenant PHP snippets by category (notifications, integrations, webhooks, data-processing, llm, files).
- **`reference/`** — Single prototype file and instructions for agents (Cursor, etc.), tied to an interface version.
- **`tenants/`** — Tenant-specific roots. Each tenant has a subdirectory (e.g. `tenants/tenant-acme/snippets/`). Use `tenants/_template/` when adding a new tenant.

## Interface version

The current step contract version is in **`INTERFACE_VERSION`** (e.g. `1.0.0`). All snippet docs and examples in this repo target that version unless stated otherwise. The platform execution engine implements the same interface version; compatibility is documented in `docs/interface/CHANGELOG.md`.

## Adding a new tenant

1. Copy `tenants/_template/` to `tenants/tenant-<slug>/` (e.g. `tenant-acme`).
2. Add your snippets under `tenants/tenant-<slug>/snippets/`.
3. Do not import or reference other tenants’ code; use `examples/` or `reference/` for shared patterns.

## Platform integration

Snippet **content** lives in this repo; the **runtime** (ProcessStepExecutor, CodeSandbox, etc.) lives in the core platform repo. The platform can consume this repo by:

- **Deploy-time copy:** Copy `tenants/<tenant>/snippets/` and optionally `examples/` into a path the platform reads.
- **Git submodule/subtree:** Include this repo in the platform repo and read snippet files from the tree at deploy or runtime.

See the plan in the platform repo (`docs/PLANS/Backlog/PROCESS_FLOW_SNIPPETS_REPOSITORY_SPLIT_PLAN.md`) for full migration and integration options.

## License

See **LICENSE** in this repository. This repo may use a different license than the core platform.
