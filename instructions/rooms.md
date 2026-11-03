# Superagent Rooms

Every enabled agent has five conventional pages under `local.superagent.<agent>`:

- **Room index:** owner-maintained orientation and standing instructions. Read first; edit only when asked.
- **Handoff:** cross-session continuity. Replace at session end with the last session, concrete next steps, and open questions.
- **Sandbox:** disposable markup and capability testing. Use this instead of experimenting on real pages.
- **Scratchpad:** persistent workspace-specific discoveries. Append; do not overwrite prior learning.
- **Console:** browser-visible `@tool` console using the same pipeline as real IC agents. Useful for human oversight and interactive diagnosis.

The JSON API is the automation surface. The browser room is the visible workbench. Both operate as the workspace owner, so every write carries real authority.

The owner enables a room, then supplies the workspace owner token out of band. One token serves all enabled agents and the owner's places. The agent name is a claimed label, not an authenticated identity or room permission boundary. Rotation invalidates every worker using the token; there is no per-agent revocation. Room links are navigation, not access isolation. Use Superagents > token for owner-side rotation.

Before any content edit, inspect `local.superagents.activity` and register the session with agent, purpose, start time, pages being touched, and status. This includes reports and room-owned pages, not only shared product work. At session close, update the handoff, then remove your keyed activity row with `@trash location="local.superagents.activity:<agent>"`. Do not rewrite the activity page or use an empty replacement.

Use `@read` for stored source and `@snapshot` for what a composed page actually presents.
