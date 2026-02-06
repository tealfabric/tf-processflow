# Examples — Shared ProcessFlow Code Snippets

Shared, non-tenant PHP snippets organized by category. These are reference implementations that work against **interface v1** (see `INTERFACE_VERSION` and `docs/interface/v1/`). Tenant-specific code belongs under `tenants/<tenant-id>/snippets/`.

## Categories

| Directory | Description | Files |
|-----------|-------------|--------|
| **notifications/** | Notify via Mattermost, email, etc. | process-step-notify-mattermost.php, process-step-notify-user-email.php |
| **integrations/** | GitLab, LinkedIn, marketing contacts, etc. | process-step-fetch-gitlab-issue.php, process-step-analyze-gitlab-issue.php, tf-pc-*.php |
| **webhooks/** | Stripe webhooks, WebApp API router stub | stripe-*-handler.php, webapp-api-router-process-template.php |
| **data-processing/** | Errors collection, IMAP parsing, Bookstack sync | collect-process-and-integration-errors.php, parse_attachments_*.php, bookstack-*.php |
| **llm/** | LLM/summary steps | process-step-llm-summary.php |
| **workflow/** | Async process trigger (stub for WebApp/webhook) | process-step-trigger-async-process.php |
| **files/** | File operations (see also docs/Code_Snippets/File_Operations.md) | — (add file-based examples here if needed) |

## Usage

Copy or adapt these snippets into your process steps or into `tenants/<tenant>/snippets/`. Ensure your platform runtime implements the same interface version as documented in this repo.
