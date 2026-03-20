# Cursor MCP Server for Tealfabric IO — Manifest

This manifest describes how to use the **Cursor MCP Server for Tealfabric IO** from Cursor to read and update ProcessFlow data on the Tealfabric platform.

Download/source repository:
- [tealfabric/cursor-mcp-tealfabric](https://github.com/tealfabric/cursor-mcp-tealfabric)

## Purpose

The Tealfabric MCP server connects Cursor to Tealfabric platform APIs so you can:
- list and inspect ProcessFlow processes and steps
- fetch step content for analysis and editing
- update process step definitions/content through MCP tools
- execute processes for validation
- work with WebApps and Documents from the same toolset

## Prerequisites

- Node.js 18+
- Tealfabric API key (`TEALFABRIC_API_KEY`)
- Tealfabric base URL (`TEALFABRIC_API_URL`, default `https://tealfabric.io`)
- Built MCP server (`dist/index.js`) from the repo

## Cursor MCP Configuration

Add server in Cursor UI or `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "tealfabric": {
      "command": "node",
      "args": ["/ABSOLUTE/PATH/TO/cursor-mcp-tealfabric/dist/index.js"],
      "env": {
        "TEALFABRIC_API_KEY": "YOUR_API_KEY_HERE",
        "TEALFABRIC_API_URL": "https://tealfabric.io"
      }
    }
  }
}
```

Restart Cursor after configuration changes.

## Tool Inventory (high-level)

Common MCP tools exposed by this server:
- `tealfabric_list_processes`
- `tealfabric_get_process`
- `tealfabric_list_process_steps`
- `tealfabric_get_process_step`
- `tealfabric_execute_process`
- `tealfabric_list_webapps`
- `tealfabric_get_webapp`
- `tealfabric_create_webapp`
- `tealfabric_update_webapp`
- `tealfabric_publish_webapp`
- document tools (`list/get metadata/upload/move/delete`)

## ProcessFlow: Read/Fetch Workflow

Recommended sequence to fetch process data safely:

1. **Find target process**
   - Use `tealfabric_list_processes` with optional search.
2. **Inspect process metadata**
   - Use `tealfabric_get_process` with `process_id`.
3. **List all steps**
   - Use `tealfabric_list_process_steps` for the process.
4. **Read step details/content**
   - Use `tealfabric_get_process_step` for each target `step_id`.

Notes:
- Keep IDs (`process_id`, `step_id`) as the source of truth.
- Prefer fetching current step content immediately before updates to avoid stale edits.

## ProcessFlow: Update Workflow

Recommended sequence for updates:

1. Fetch latest process and step state (`get_process`, `get_process_step`).
2. Prepare minimal change set (only fields that must change).
3. Call the relevant update tool (for step/webapp based on your object).
4. Re-fetch the updated object to verify persistence.
5. Optionally run `tealfabric_execute_process` with controlled input for validation.

Good practice:
- Update one step at a time.
- Keep a local backup/snapshot of original content.
- Validate return structure and error handling after updates.

## Example Operational Playbook

- **Audit a process**
  - list processes -> get process -> list steps -> get each step
- **Patch one code step**
  - get step -> edit code locally -> update step -> get step again -> execute process test
- **Regression check**
  - run process with known input payload and compare output shape

## Safety and Security Notes

- Do not commit real API keys to git.
- Prefer local-only MCP config or `.cursor/mcp.json.example` patterns.
- Use least-privilege API keys if scope controls are available.
- Treat process step code as production logic: review diffs before update calls.

## Troubleshooting

- **Server not visible in Cursor**
  - Verify path to `dist/index.js`, restart Cursor.
- **Auth failures**
  - Check `TEALFABRIC_API_KEY` validity and tenant/user permissions.
- **Unexpected process/step data**
  - Re-run fetch sequence (`list` -> `get` -> `list steps` -> `get step`) to confirm current state.

## Reference

- MCP server repo: [https://github.com/tealfabric/cursor-mcp-tealfabric](https://github.com/tealfabric/cursor-mcp-tealfabric)
- Tealfabric docs: [https://tealfabric.io/docs](https://tealfabric.io/docs)
