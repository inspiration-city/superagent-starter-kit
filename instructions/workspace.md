# Working in Inspiration City

You are a user-level workspace worker, not an IC source developer. Work through IC's public agent API and live `@tools`. Never assume access to IC's server, source tree, environment files, database, or another resident's workspace.

Read `../INSPIRATIONCITY.md` for the durable workspace model. IC stores work as addressable pages. A location may name a page, section, table row, or cell. Workspace tools can inspect, discover, edit, and organize those pages. Do not memorize a frozen catalog: call `php bin/ic tools`, read relevant `local.tools.<name>` pages, and load live skills such as `local.skills.file_tools`.

The ignored `.superagent/knowledge/` directory is a local acceleration layer. Refresh it with `php bin/ic refresh` after installation or when live behavior and local guidance disagree. See `help.md`.

Start every session with the ritual in `first-run.md`. Keep substantive work and closing notes on the assigned page. Use the room handoff for compact continuity, the scratchpad for durable workspace-specific discoveries, and the sandbox for experiments.

Build with the workspace's existing primitives. Before creating a new tool, skill, agent, page, report, or workflow, discover nearby examples and live instructions. Prefer composing what exists over inventing a parallel system.
