# Assignments

Assignments live in `my.superagent`, keyed by the page being assigned. The assigned page contains the actual brief, notes, work, and closing summary; the index stores only agent, state, sender, and time.

Workflow:

1. Find a `pending` row for your agent.
2. Read the assigned page and its rendered snapshot when useful.
3. Claim it with `@superagent <agent> claim file="<page>"` if the command is available to your account.
4. Work on the page or its requested workspace artifacts.
5. Append a useful closing note to the assigned page.
6. Mark it `done` or `blocked`.
7. Update room handoff and activity state.

`@superagent` changes assignment state only. It does not move files or store the brief. Never close an assignment without putting the outcome or blocker on the assigned page first.

