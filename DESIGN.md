# Video Workspace design

Concept: an ordinary content editor gains a visible, inspectable agent workflow. Let the form and saved draft be the proof.

## Tokens

| Role | Value |
|---|---|
| Canvas | `#f4f7f6` |
| Panel | `#ffffff` |
| Ink | `#142d40` |
| Muted ink | `#496372` |
| Action | `#087e73` |
| Action hover | `#05655d` |
| Rule | `#c7d8d4` |
| Error | `#a62c35` |

Typography: locally bundled **Source Sans 3** for UI/body/headlines; locally bundled **Source Code Pro** for tool names/code. Fallback sans-serif/monospace while development assets are unavailable. Use actual font files in rendered chapters. No remote font fetch during render.

UI: 16px body, 14px labels, 40–64px page title, 8px spacing grid, 12px corners, restrained borders. Forms use real labels and a clear full-width primary action. Draft badges always spell out draft status.

Video: 1920×1080, 30fps; 72px headline, 36px teaching callout, 30px body; at least 80px safe margins. Browser recordings sit in a large central frame with a narrow teaching strip. Crop or zoom to the active form rather than shrinking the whole desktop. Code excerpts fit five short lines. Do not put essential content below 28px. Phone sheets must retain the main point.

Motion: entrance only, 0.4–0.6 seconds with power2.out, short stagger 0.08 seconds; one highlight per tool event. No repeated background loops. Cuts are deliberate between task stages. Keep final visuals visible until narration ends plus 0.5 seconds.

Persistent chapter signature: bottom right, “Video Workspace · Symfony + WebMCP”, 24px, ink on canvas; do not imitate Medflixs branding. Background uses a faint static workspace grid and top chapter progress rule. No unnecessary decorative animation while reading code or forms.

## Assets

The welcome sample is an original eight-second FFmpeg test-pattern video with a tone. It is labelled as a test pattern, not a recording of a person. Public tutorial captures only the fresh demo workspace.
