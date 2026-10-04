# Your SaaS through an AI agent

The visible content form and its browser tools reach the same Symfony backend. The skill teaches an agent how to use those tools; it does not authenticate the user or replace server validation.

## Try the manual path

Start the Docker package, visit `http://localhost:8094`, and create a temporary workspace. Open Add video content, choose the test-pattern sample, enter title/summary/category/language/date, and save. The detail page shows the persisted draft ID and its unpublished status.

## Install and use the skill

Install `skills/video-add-content` in a compatible agent's skills directory. Follow the browser setup in the README and open the content form in the same session. Ask the agent to select the sample, supply all metadata, save a draft and return its link.

The agent reads state to obtain actual choices, prepares fields after validation, saves with the returned revision/key, then reads the stored draft. Open the returned detail URL. Missing native support is a prerequisite issue; an inspector test must be labelled as such.

For the complete workflow, give the skill an actual local MP4. Its helper transcribes speech locally and extracts a cover frame. The agent reviews the transcript, translates every timed cue into EN/FR/DE/IT/ES and writes title/description. The helper checks completeness and exports VTT files. Upload the video first, then cover and the five VTT files through their separate upload fields. Read state to discover owned IDs, attach the six files to that video, prepare, save and read back. See [full execution evidence](full-workflow-verification.json).

## Expose one existing operation

The tool definitions in `public/webmcp.js` include a name, description, JSON schema and execute function. They submit the same `VideoFormType` as manual use. Symfony checks the CSRF token and data; the service scopes assets and drafts to the active workspace and creates the record transactionally.

Keep the backend authoritative. Return an ID and detail URL after persistence. A prepared form is not a saved record. If a response is lost, reconcile with the original save key before retrying.

## Reproduce the result

The project includes Docker, tests, original sample media generation, and the installable skill. Local native execution was verified on Chrome 154.0.8037.93 with the Codex agent through Playwright. See `docs/native-verification.json` for the actual IDs and tested encoding. Other agent clients need their own compatibility check.

The deployment guide prepares public operation with HTTPS and a persistent volume. No public app URL has been configured for this package.
