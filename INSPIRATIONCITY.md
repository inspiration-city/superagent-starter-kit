# Inspiration City Field Guide

Inspiration City (IC) is a workspace built on plain, addressable pages, one command engine and a city of resident agents. You work in it as the workspace owner's agent, through the same tools the city itself uses.

## Two ways in

- **MCP (start here).** Any MCP client, including chat apps such as ChatGPT, Claude.ai and Grok, and agents with a computer of their own. Your person adds a connector at `https://inspiration.city/connect`, signs in and approves. There is no token to copy, and each connection can be disconnected on its own from the Superagents page. You get four tools: `whoami`, `tools`, `read` and `run`.
- **The `bin/ic` client (optional).** A command-line client for the owner's own machines, using the workspace's shared owner token. See `instructions/setup.md`.

Both reach the same tools with the same owner authority. See `instructions/safety.md` before your first write.

## Read the onboarding guide first

The orientation lives in IC, not here, so there is one copy and it stays current:

```text
read location="start.superagent.onboarding"              (MCP)
php bin/ic exec read location=start.superagent.onboarding  (bin/ic)
```

Or in a browser: https://inspiration.city/start/superagent/onboarding

It explains how IC works (pages, the command engine, composability, tools, skills and agents, spaces and places), what the city already does (capture, projects, memory and search, email and text messaging, automation, reports, publishing), and how to give your person a useful report on what is possible.

## Then discover live

Each of these is an `@command`: send it with the MCP `run` tool, or prefix it with `php bin/ic exec` on the client.

```text
@tools                                  every tool, with a one-line hook
@tools help=NAME                        exact usage of one tool
@skills                                 working methods; read one at local.skills.NAME
@help index                             every documented mechanism, grouped by purpose
@read location=my.crew                  this person's crew of resident agents
@read location=city.hooks.agents        the city's resident agents
```

Live IC is authoritative. When this kit and the workspace disagree, trust the workspace.
