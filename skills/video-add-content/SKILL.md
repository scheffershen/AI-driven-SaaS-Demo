---
name: video-add-content
description: Prepare a local video's cover, English/French/German/Italian/Spanish WebVTT captions, title and description, then add and verify a Video Workspace draft through native browser WebMCP.
---

# Add video content

Use the user's Video Workspace URL and browser session. Read [the tool workflow](references/webmcp-workflow.md) before operating the page; it contains the exact fields and uncertainty rules.

For a local video and a request to generate its content, follow [media preparation](references/media-preparation.md). Use the bundled helper to transcribe speech locally and extract an actual cover frame. You, the agent, translate the timed transcript into EN/FR/DE/IT/ES and write the title and description from the verified content; the helper validates and exports all five VTT files. This does not require SaaS-side AI API keys. Do not invent captions for silent videos. Use supplied files instead when the user asks.

Open **Add video content** and discover the page's native WebMCP tools using the browser/client capability available to the agent. Read `get_video_content_form_state` for current options, assets, required fields, revision and submission phase. Native tools are scoped to the visited page. The built-in inspector is a developer test surface; using it must be described as inspector execution, not native agent discovery.

Choose returned category/language IDs and an accessible `asset_id`. The shared `sample` asset is an eight-second test pattern. For a local MP4, use the browser's supported file-upload capability and the normal upload form, or ask the user to select it; a local path is not an asset ID. Re-read state after the upload.

Upload the cover and five VTT files through their normal file inputs before preparing metadata. Choose the owned video in each upload form. Re-read state to obtain actual attachment IDs. Map `cover_id`, `subtitle_en`, `subtitle_fr`, `subtitle_de`, `subtitle_it`, and `subtitle_es` to the matching type, language and video. Missing optional files may be omitted for a basic draft, but a requested full workflow is incomplete until all six are verified.

Use facts supplied by the user or the verified transcript. Generate the title and description (`summary`) when requested. Ask for missing category, source language or date when they cannot be resolved from those facts. Do not derive substantive claims or identities from filenames. The app supplies video duration.

Call `prepare_video_content_draft` with the metadata and attachment IDs. Inspect the returned prepared state and visible form. If the user requested a save, call `save_video_content_draft` with the returned `revision` and `save_key`. An edit requires preparation again.

Verify with `get_video_content_draft` and report the actual draft ID, title, asset ID, cover and five subtitle IDs, clickable detail URL and draft status. Verify the detail page exposes the cover and all requested VTT links. Never claim publication: this app only creates unpublished drafts.

When a save times out, read by the same save key before any retry. Do not try multiple argument encodings for a mutation. If native discovery/execution is unavailable, explain the compatible browser/client requirement; offer a manual form path only when appropriate and label it accurately.
