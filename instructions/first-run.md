# First Run

Run these steps in order. The first pass uses stored-source reads. Snapshot/view evaluate markup; use them only after inspecting known composed pages and confirming evaluation is safe.

1. Read `INSPIRATIONCITY.md`, then this kit's `rooms.md`, `help.md`, and `safety.md`.
2. `php bin/ic check`
3. `php bin/ic tools`
4. Read the room index at `local.superagent.<IC_AGENT>`.
5. Read `local.superagent.<IC_AGENT>.handoff` if it exists. On a fresh room a missing handoff means no previous session, not failed authentication; do not create it during the read-only pass.
6. Read `local.superagents.activity`.
7. If accessible, read `my.superagent` and locate assignments. Assignment management is admin-only; no assignments or an unavailable admin index is a normal resident path. Follow the owner's explicit brief instead.
8. Read `local.skills.file_tools`.
9. `php bin/ic context "<the assignment's subject>" page=<the page you were pointed at>` — the resident's routes and what each area holds, matching memories, that page's links, tags and backlinks, what is in progress, and both searches. It is the fastest way to stop guessing where things live.
10. If assigned work exists, read its source and snapshot its rendered view when applicable.
11. Summarize the workspace, assignment, intended files/pages, and risks before writing.
12. Before the first content write, create your own entry in `local.superagents.activity`, even for a report-only assignment.

The five canonical room pages are the room index itself, handoff, sandbox, scratchpad, and console. An assignment page is not one of the five.

On a new installation, run `php bin/ic refresh` after the connection check. Thereafter refresh when the cache is missing, IC capabilities have changed, or a live result conflicts with local guidance—not automatically on every session.

`refresh` and `context` are opposites and both are deliberate. `refresh` caches documentation that changes rarely, so you are not re-reading the tool catalog every session. `context` caches nothing: bookmarks, memory, links, state and search are live or they are wrong, and it is safe to run as often as you like. Never treat a context bundle as a record of anything — every line names its source page, and the page is the record.

## Required session close

Before finishing any session in which you wrote content:

1. Verify the deliverable by reading its source and snapshotting its rendered view when applicable.
2. Put the outcome or blocker on the assigned page.
3. Mark the assignment done or blocked only after that note exists.
4. Update `local.superagent.<IC_AGENT>.handoff` with what happened, concrete next steps, and open questions.
5. Remove your own `local.superagents.activity` row last with `php bin/ic exec trash location=local.superagents.activity:<IC_AGENT>`. Do not use `replace out=""`; the live replace tool requires a non-empty replacement.

Do not report the session complete if the handoff or activity cleanup is unfinished.

Example commands, replacing `codex` with the configured agent name:

```text
php bin/ic exec read location=local.superagent.codex
php bin/ic exec read location=local.superagent.codex.handoff
php bin/ic exec read location=local.superagents.activity
php bin/ic exec read location=my.superagent
php bin/ic exec read location=local.skills.file_tools
php bin/ic exec snapshot local.superagent.codex.assignment
php bin/ic exec superagent codex claim file=local.superagent.codex.assignment
```

Rooms and tokens are separate: the owner enables a room label and supplies the shared owner token out of band. If `php bin/ic check` fails, or the room named by `IC_AGENT` is missing, stop and follow `setup.md` — it has the exact steps to relay to the user. Never ask for their IC password.
