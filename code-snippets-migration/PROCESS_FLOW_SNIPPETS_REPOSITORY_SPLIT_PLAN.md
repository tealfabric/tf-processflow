# Plan: Process Flow Code Snippets — Separate Repository Split

**Status:** Backlog  
**Goal:** Create a dedicated repository for process flow code snippets so that core platform code and customer/tenant-specific snippet code are clearly separated and can use different licenses.

---

## 1. Overview and Goals

### 1.1 Purpose

- **Separation of concerns:** Keep the core platform (this repo) free of tenant- or customer-specific snippet code.
- **Licensing:** Allow the snippets repository to use a different license (e.g. proprietary, customer-specific) while the platform remains under its current license.
- **Clarity:** Make it obvious what is “platform” vs “snippet library” and simplify audits, onboarding, and reuse.

### 1.2 Requirements (from request)

1. The new repository must contain **all documentation, code samples, and examples** needed to author **fully functional** process flow snippets.
2. **Process flow documentation and interfaces must be versioned** so that changes can be made in a **non-breaking** way (compatibility guarantees per version).
3. **Folder structure is tenant-specific:** each tenant has its own top-level subdirectory in the repository.

---

## 2. Repository Scope

### 2.1 What Belongs in the Snippets Repository

| Category | Contents |
|----------|----------|
| **Documentation** | Snippet authoring guides, quick reference, API surface for steps, available variables, error contract, security and validation rules, event handling, WebApp/async trigger docs relevant to snippets. |
| **Interface specs** | Versioned definition of the step contract: input (`$process_input`, `$raw_input` when applicable), return format (`success`, `data`, `error`), available globals (`$tenantDb`, `$api`, `$llm`, `$integration`, `$files`, etc.), and `log_message()`. |
| **Code samples & examples** | Reference PHP snippets (notifications, integrations, webhooks, data processing, LLM, file ops, etc.) that are known to work against a given interface version. |
| **Tenant-specific code** | Per-tenant folders containing that tenant’s own snippets, templates, and small custom libraries. |
| **Prototype / Cursor reference** | Single reference file (or small set) that documents the full contract and “instructions for agents” (e.g. cursor-process-step-code-snippet prototype), tied to an interface version. |
| **Changelog / compatibility** | Per-version changelog and “supported platform versions” or “interface version” matrix. |

### 2.2 What Stays in the Core Platform Repository

| Category | Contents |
|----------|----------|
| **Runtime** | `ProcessStepExecutor`, `CodeSandbox`, `StepSecurityValidator`, execution pipeline, tenant context. |
| **Platform docs** | ProcessFlow API (REST), deployment, process design (UI), triggers, admin tool — except the “snippet authoring” subset, which can be duplicated or linked from the snippets repo. |
| **Library/import mechanism** | How the platform resolves and loads snippet code (e.g. from DB, from files, or from the snippets repo). The *mechanism* lives in the platform; the *content* lives in the snippets repo. |
| **Process editor UI** | Step editor, library browser (if any), process canvas — no snippet *bodies* stored in platform repo. |

Boundary: **snippet content and “how to write snippets” live in the new repo; execution engine and “how we run snippets” stay in the platform.**

---

## 3. Versioning Strategy (Non-Breaking Changes)

### 3.1 Interface Version

- **Interface version** = contract that snippet code expects: variables injected, return shape, and behavior guarantees.
- Format suggestion: **SemVer** (e.g. `1.0.0`) or **calendar-ish** (e.g. `2026.02`) for the step contract.
- Stored in:
  - A single **`INTERFACE_VERSION`** (or `snippet-api-version`) file or key in the snippets repo (e.g. `docs/interface/INTERFACE_VERSION`).
  - Optional: per-tenant or per-snippet override only if needed later.

### 3.2 Rules for Non-Breaking Changes

- **Patch (e.g. 1.0.x):** Clarifications, new optional variables, new optional return keys. No removals, no renames, no type changes of existing variables/keys.
- **Minor (e.g. 1.x.0):** New variables, new optional return keys, new services. Old snippets must still run unchanged.
- **Major (e.g. x.0.0):** Breaking changes (removals, renames, type changes). Document migration path and support a transition period.

### 3.3 Where Versions Are Documented

- **Snippets repo:** `docs/interface/` (or `spec/`) per version, e.g.:
  - `docs/interface/v1/` — variables, return format, error shape, deprecations.
  - `CHANGELOG.md` or `docs/interface/CHANGELOG.md` describing changes per version.
- **Platform:** Execution engine knows which interface version it implements (e.g. config or constant); snippets repo docs state “compatible with platform interface v1.x”.

### 3.4 Documentation Versioning

- **Stable docs:** Tagged or branch-named by interface version (e.g. `v1`, `v1.0`) so that “docs for v1” never change in a breaking way.
- **Living docs:** `main` (or `latest`) can document the current/latest interface; older versions remain under versioned paths or tags.

---

## 4. Folder Structure (Tenant-Specific)

Each tenant has a dedicated top-level directory. Shared content (docs, shared examples) lives outside tenant dirs.

### 4.1 Proposed Layout

```text
process-flow-snippets/                    # Repository root
├── README.md
├── LICENSE
├── CHANGELOG.md                         # Repository/content changelog
├── INTERFACE_VERSION                    # e.g. 1.0.0 or 2026.02
│
├── docs/                                # Shared documentation
│   ├── README.md
│   ├── PROCESSFLOW_CODE_SNIPPETS_GUIDE.md
│   ├── PROCESSFLOW_QUICK_REFERENCE.md
│   ├── PROCESSFLOW_EVENT_HANDLING_GUIDE.md
│   ├── WEBAPP_ASYNC_PROCESS_TRIGGER_GUIDE.md   # Snippet-relevant parts
│   └── interface/                       # Versioned contract
│       ├── CHANGELOG.md
│       ├── v1/
│       │   ├── variables.md
│       │   ├── return-format.md
│       │   ├── error-format.md
│       │   └── services.md
│       └── v2/                          # Future
│           └── ...
│
├── spec/                                # Optional: machine-readable spec (e.g. JSON Schema)
│   └── v1/
│       └── step-contract.json
│
├── examples/                            # Shared, non-tenant code samples
│   ├── README.md
│   ├── notifications/
│   ├── integrations/
│   ├── webhooks/
│   ├── data-processing/
│   ├── llm/
│   └── files/
│
├── reference/                           # Single “prototype + instructions” file(s)
│   ├── cursor-process-step-code-snippet_prototype_and_examples.php
│   └── README.md
│
├── tenants/                             # Tenant-specific roots
│   ├── tenant-acme/
│   │   ├── README.md                    # Optional: tenant notes
│   │   ├── snippets/
│   │   │   ├── notify-custom.php
│   │   │   └── sync-to-crm.php
│   │   └── templates/                  # Optional: step templates
│   │
│   ├── tenant-globex/
│   │   ├── snippets/
│   │   └── ...
│   │
│   └── _template/                      # Optional: template for new tenant
│       ├── README.md
│       └── snippets/
│           └── .gitkeep
│
└── code-snippets-migration/             # One-time: scripts to move content from platform repo
    └── ...
```

### 4.2 Tenant Directory Conventions

- **Naming:** `tenants/<tenant-id>/` or `tenants/tenant-<slug>/` (e.g. `tenant-acme`). Decide one convention and document in repo README.
- **Isolation:** No cross-tenant imports; shared code lives under `examples/` or `reference/`.
- **Permissions:** Access control can be enforced via Git (e.g. per-tenant branches or submodules later) or at deploy time; document the chosen approach.

---

## 5. Content Inventory (What to Move or Create)

### 5.1 From Current Platform Repo → Snippets Repo

| Source (platform repo) | Destination (snippets repo) |
|------------------------|-----------------------------|
| `docs/PROCESSFLOW_CODE_SNIPPETS_GUIDE.md` | `docs/PROCESSFLOW_CODE_SNIPPETS_GUIDE.md` |
| `docs/PROCESSFLOW_QUICK_REFERENCE.md` | `docs/PROCESSFLOW_QUICK_REFERENCE.md` |
| `docs/public/PROCESSFLOW_*.md` (snippet-relevant) | `docs/` (flatten or keep structure) |
| `docs/public/Code_Snippets/*.md` | `docs/Code_Snippets/` or merged into `examples/` with READMEs |
| `scripts/mcp-tools/cursor-process-step-code-snippet_prototype_and_examples.php` | `reference/cursor-process-step-code-snippet_prototype_and_examples.php` |
| `code-snippets/*.php` | `examples/<category>/` (categorized) or `tenants/<tenant>/snippets/` where tenant-specific |
| Process flow integration guide (snippet sections) | `docs/` (extract snippet-relevant parts) |

### 5.2 To Create in Snippets Repo

- **Versioned interface docs:** `docs/interface/v1/` (variables, return format, error format, services) derived from current `ProcessStepExecutor` and prototype file.
- **`INTERFACE_VERSION`** and `docs/interface/CHANGELOG.md`.
- **`tenants/_template/`** for new tenants.
- **Repository README:** Purpose, structure, how to add a tenant, how versioning works, link to platform “supported interface version”.
- **Optional:** CI that validates example snippets (e.g. syntax, or contract compliance) and that tenant dirs follow naming.

---

## 6. Migration and Split Steps

1. **Create the new repository** (e.g. `process-flow-snippets` or `tealfabric-process-snippets`) with README, LICENSE, and folder structure above.
2. **Define interface v1** from current behavior: document variables, return format, error format, and services in `docs/interface/v1/`; add `INTERFACE_VERSION` and first CHANGELOG entry.
3. **Copy documentation** from platform repo into `docs/` and trim platform-only sections; add a note “snippet runtime lives in platform repo”.
4. **Move or copy reference prototype** into `reference/` and ensure it references `docs/interface/v1`.
5. **Categorize and move `code-snippets/*.php`:** generic ones → `examples/<category>/`; tenant-specific → `tenants/<tenant>/snippets/`.
6. **Add tenant directories** for existing tenants (e.g. from DB or config); create `tenants/_template/`.
7. **Update platform repo:** Remove or archive moved snippet files; add pointer (README or config) to “snippet content lives in `<snippets-repo>`”; ensure platform can still load snippets (from DB, file path, or copy at deploy).
8. **Document in platform** how it resolves snippet code (e.g. “snippets are loaded from process-flow-snippets repo at path `tenants/<id>/snippets/` or from DB”).

---

## 7. Licensing

- **Snippets repository:** Choose and add LICENSE file (e.g. proprietary, or per-tenant agreements). Document in README that this repo is for snippet content and may be under a different license than the platform.
- **Platform repository:** No change to its license; it only references or loads snippet content. Avoid copying snippet code into the platform repo so the boundary stays clear.

---

## 8. Platform Integration (How the Platform Uses the Repo)

- **Option A — Deploy-time copy:** Build/deploy copies relevant `tenants/<tenant>/snippets/` and `examples/` into a path the platform reads (e.g. `storage/snippets/<tenant>/` or existing code-snippets path). Platform does not clone the repo at runtime.
- **Option B — Git submodule or subtree:** Platform includes the snippets repo as submodule/subtree; at runtime or at deploy, code is read from that tree.
- **Option C — API or package:** Snippets repo is published (e.g. as artifact or private package); platform fetches by tenant and version. More infra; use if needed for multi-environment or version pinning.

Recommendation: Start with **Option A** (deploy-time copy) and document the exact paths and interface version so that the platform’s executor remains compatible. Option B is viable if the platform repo and snippets repo are always deployed together.

---

## 9. Success Criteria

- [ ] New repository exists with README, LICENSE, and tenant-based folder structure.
- [ ] Interface is versioned; v1 documented under `docs/interface/v1/` with changelog.
- [ ] All snippet-authoring docs and examples needed for fully functional snippets live in the snippets repo.
- [ ] Each tenant has a dedicated directory under `tenants/`; shared examples under `examples/`.
- [ ] Platform repo no longer contains tenant-specific snippet bodies (or they are clearly deprecated in favor of the new repo).
- [ ] Process flow documentation and interfaces follow the versioning rules above for non-breaking evolution.

---

## 10. Dependencies and References

- **Process Code Library plan:** `docs/PLANS/Sprint-2026-04/PROCESS_CODE_LIBRARY_PLAN.md` — library concepts (categories, metadata, versioning) can apply to how snippets are organized and referenced; the *content* of the library can live in this new repo.
- **Current snippet docs:** `docs/PROCESSFLOW_CODE_SNIPPETS_GUIDE.md`, `docs/public/Code_Snippets/*.md`, `scripts/mcp-tools/cursor-process-step-code-snippet_prototype_and_examples.php`.
- **Runtime contract:** `src/Services/ProcessFlow/ProcessStepExecutor.php` (return array `success`, `data`, `error`; variables injected by `CodeSandbox`).

---

## 11. Open Points

- **Tenant naming:** Use `tenant_id` (UUID) or human-readable slug (e.g. `tenant-acme`) for directory names; decide and document.
- **Access control:** Whether to enforce per-tenant visibility via Git (e.g. separate branches or repos per tenant) or only at deploy/application level.
- **Library feature:** When the Process Code Library is implemented, whether “library” step definitions and metadata live in the platform DB but *code bodies* are loaded from this repo (by path or version).
