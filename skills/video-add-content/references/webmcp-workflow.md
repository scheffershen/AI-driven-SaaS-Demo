# Browser tool contract

The user's app URL supplies the origin. Start a demo workspace if none exists, then visit `/content/new`. Do not switch to another workspace during an operation.

| Tool | Input | Output |
|---|---|---|
| `get_video_content_form_state` | `{}` | `values`, `choices`, `assets`, `attachments`, `missing_fields`, `revision`, `prepared_revision`, `save_key`, `phase`, `draft` |
| `prepare_video_content_draft` | Any supported metadata fields | Complete state after server validation and atomic visible preparation |
| `save_video_content_draft` | `{revision: <integer>, save_key: <returned string>}` | `{draft: {id, title, asset_id, status, published, url, ...}}` |
| `get_video_content_draft` | `{id: <draft ID>}` OR `{save_key: <returned key>}` | `{draft: <owned record or null>}` |

Supported metadata fields: `title` (1–120 characters), `summary` (1–4000), `category`, `language`, `content_date` (`YYYY-MM-DD`, a real calendar date), `asset_id`. All six are required to save. Get category/language choices from state rather than assuming labels are IDs. Media duration comes from inspected server metadata.

Optional attachment fields are `cover_id`, `subtitle_en`, `subtitle_fr`, `subtitle_de`, `subtitle_it`, `subtitle_es`. Use uploaded IDs from `attachments`, matching the selected `asset_id`, `kind` and `language`. An empty string removes an optional attachment during preparation. The visible description/content field maps to `summary`. Local paths and arbitrary URLs are rejected as IDs. The skill performs generation locally before uploading; WebMCP performs validated preparation and saving.

Example after confirming the returned options:

```json
{
  "title": "Welcome to Video Workspace",
  "summary": "A short introduction to adding video content.",
  "category": "getting-started",
  "language": "en",
  "content_date": "2026-10-03",
  "asset_id": "sample"
}
```

The date is example metadata, not a default. Use the date the user requests.

Preparation does not save. Saving uses the visible Symfony form, its current CSRF token, and the browser session. The app enforces ownership, validation, quotas and draft-only status. The save key is scoped to a workspace and bound to the payload; an identical retry returns the same draft, and different metadata with that key conflicts.

## Uncertain outcomes

After transport failure, use `get_video_content_draft` with the original key. A persisted record is proof of success even if the POST response was lost. A null response is meaningful only after an authenticated, successful read; a failed read leaves the outcome uncertain. Retry with the same key/payload only after reconciliation. Do not create a fresh key to work around uncertainty. Reusing a saved key for edited metadata is a conflict, not an update operation.

## Capability boundaries

Native browser WebMCP is a proposed standard with browser/client prerequisites. A skill supplies instructions; it does not install native browser support, authenticate the user, or expose a remote MCP endpoint. If only the application's inspector is available, disclose that distinction. Return page-relative URLs resolved against the actual visited origin; do not invent a public deployment URL.
