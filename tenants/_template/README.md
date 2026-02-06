# Tenant template

Copy this directory to create a new tenant directory, e.g.:

```bash
cp -r _template ../tenant-acme
```

Then add your snippets under `snippets/` and optional step templates under `templates/`.

## Conventions

- **Directory name:** Use `tenant-<slug>` (e.g. `tenant-acme`, `tenant-globex`) or your agreed tenant-id format.
- **Isolation:** Do not import or reference other tenants' code. Use shared patterns from `examples/` or `reference/` in the repo root.
- **Templates:** Optional. Use `templates/` for step templates specific to this tenant if needed.
