# From a video to a verified draft

The form includes MP4 upload, cover-image upload, EN/FR/DE/IT/ES WebVTT upload fields, title, description/content, category, source language and content date. Cover and caption choices refer to files already uploaded to the same workspace and video. Optional attachments permit simpler drafts; a requested full workflow verifies all six.

`video-add-content` coordinates three capabilities:

1. **Local preparation:** Python/faster-whisper transcribes actual speech; FFmpeg extracts a frame for the cover. The agent reviews the transcript, translates every cue into five languages, and writes a factual title and description. The helper validates the editorial JSON and exports five VTT files with unchanged timestamps. This runs on the agent's machine, without SaaS-side AI keys.
2. **Authenticated browser uploads:** The agent uses supported browser file-upload controls for the MP4, cover and five subtitle files. The server inspects the media and returns workspace-owned identifiers. A local path is never used as an identifier.
3. **Native WebMCP operations:** Read actual form state → prepare metadata and attachment IDs → save the current prepared revision → read the persisted draft. Open the returned detail link to inspect the cover, five subtitle tracks and file links.

The skill's generation instructions are in [media preparation](../skills/video-add-content/references/media-preparation.md). The exact tool contract is in [WebMCP workflow](../skills/video-add-content/references/webmcp-workflow.md). Unsupported native clients are reported explicitly; developer-inspector execution is labelled separately.

The cover is an extracted video frame. The title/description and translations are written by the agent from reviewed source content. The helper does not independently call a paid LLM API. Review generated content before publishing elsewhere. Silent or music-only media must not receive invented captions.

[Recorded verification](full-workflow-verification.json) includes an actual manual save and an actual native save/readback with six generated attachments. Both are unpublished drafts. Docker hosting instructions are in [deployment](deployment.md); public hosting is deferred.
