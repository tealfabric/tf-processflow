# Process Flow Code Snippets Repository

This repository contains all documentation, code samples, and examples needed to author **fully functional** ProcessFlow code snippets. It is separate from the core platform so that:

- **Core platform code** and **customer/tenant-specific snippet code** stay distinct
- **Licensing** can differ (e.g. proprietary or customer-specific for this repo)
- **Versioning** of the snippet interface is managed here for non-breaking evolution

## Repository role (master skeleton)

This repository is the **master skeleton** for ProcessFlow snippet generation and structure.

- Use this repo as a base/template to create your own tenant- or application-specific snippet repository.
- Do **not** use this master `tf-processflow` repo to store end-user/customer-specific production code.
- All end-user related changes must be maintained in a **separate repository** owned by that tenant/application.

This approach keeps the skeleton clean and makes it safer to patch or upgrade from the master repository over time.

## Recommended usage for tenants/apps

1. Clone/fork this `tf-processflow` repository for your tenant or application.
2. Keep your actual user content in the `tenants/` directory in your own repository.
3. Keep master skeleton updates and end-user code separate, so future upgrades can be merged with lower risk.

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

- **Manual deployment: ** Copy `tenants/<tenant>/snippets/` file content into the process it beongs to in the platform UI (tenant-admin level user account needed).
- **Git CI/CD deployment: ** Include a deployment script in the case of successful tests to push updated code snippets to the process through API (API key needed).
- **MCP Server: ** Ask your code generating Agent to push the code snippets to the processes through the Tealfabric MCP server. Cursor, Claude Code, and Lovable supported (API key needed).

## License

See **LICENSE** in this repository. This repo may use a different license than the core platform.
