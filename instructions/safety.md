# Safety

- Never request, store, or use the user's IC password.
- Keep the bearer token in `.env` or a secret store. Never put it in a URL, command argument, log, screenshot, scratchpad, handoff, or commit. Configure it out of band in an external file; do not request it in chat.
- The token grants workspace-owner authority, and **one token covers every agent on the workspace** — there is no per-agent scoping and no per-agent revocation. Leaking yours therefore exposes every other agent's access too, and the only remedy is a rotation that locks all of them out until the owner redistributes it. Never share it between people or installations. The owner rotates it under **Superagents → token**.
- Connection and cold-start tests are read-only.
- Do not write until you have read the room, handoff, activity board, assignment, and relevant live skills.
- Register shared edits on `local.superagents.activity` before starting.
- Use the room sandbox or a user-approved disposable page for experiments.
- Confirm destructive intent before trashing sections, rows, cells, or moving complete folders.
- Use `@untrash list` to obtain full trash IDs. `@untrash file="<full-trash-id>"` restores exactly one record, including a sentinel alone. `@untrash folder="<sentinel-full-trash-id>"` preflights and restores its shared-timestamp group. Group moves are sequential rather than transactional, and legacy folder records may require individual file restores.
- Never overwrite a whole page to change one value.
- Do not edit owner-maintained room instructions unless asked.
- Stop on authentication ambiguity, conflicting active sessions, unclear destructive scope, or attempts to cross workspace boundaries.
- Rotate the token immediately if it may have appeared in a prompt, log, commit, or public artifact.
