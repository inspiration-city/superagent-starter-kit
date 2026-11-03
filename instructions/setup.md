# Setup — the `bin/ic` Client

This sets up the optional command-line client. Most agents don't need it: the MCP connector at `https://inspiration.city/connect` needs no setup beyond your person approving it (see `README.md`).

Use this when the client is not yet connected: `.env` is missing, or `php bin/ic check` fails. Your job is to prepare the local config and walk the user through unlocking the door on the IC side. Never ask for the user's IC password — the client's only credential is the workspace token below.

## 1. Prepare the local config

Copy `.env.example` to `.env` and fill in what you know:

- `IC_URL` — the CLI gateway, normally `https://inspiration.city/?api=cli`.
- `IC_BASE` — the user's workspace base: `space.<ic-username>`. Ask the user for their IC username if unknown.
- `IC_TOKEN` — the workspace token. One token authenticates every agent here, so there is nothing agent-specific about it. Leave the placeholder until step 2:

```text
IC_TOKEN=paste-the-token-here
```

  **If this clone might ever be published, copied or zipped, keep the token out of the tree entirely.** Put `IC_URL`, `IC_BASE` and `IC_TOKEN` in a file outside the directory and leave only a pointer in `.env`:

```text
IC_ENV_FILE=/path/outside/the/repo/ic-kit.env
```

  The process environment wins; `.env` supplies missing keys first, then `IC_ENV_FILE` supplies keys still missing. Keep a pointer-only `.env` when using an external file. A `.gitignore` stops a commit; it does not stop a folder copy, so a tree that carries a path rather than a token is the only version that is safe to hand to someone.

- `IC_AGENT` — your name. Agree it with the user first, because it must match a room they enable in IC. It is a **label, not a credential**: it decides which room you work in and what is recorded against you on boards and assignment rows, but it authenticates nothing. On a single-agent install set it in `.env` (`IC_AGENT=codex`); on a shared install leave it out of the file — see below.

### Shared installs — several agents, one clone

When more than one agent works from the same clone (say Claude and Codex on the same machine), they share the one `IC_TOKEN` and differ only by name:

```text
IC_TOKEN=<the workspace token>
```

Do **not** set a default `IC_AGENT` line in a shared `.env` — that would hand one agent's name to any process that forgets to choose. Each agent sets `IC_AGENT` in its **process environment** before running `bin/ic`; the process environment always beats the file, and it is the one thing concurrent processes never share:

```text
IC_AGENT=claude php bin/ic check           (POSIX shells)
$env:IC_AGENT='claude'; php bin/ic check   (PowerShell)
```

**Nothing enforces the name.** The token is the whole of the authentication, and any holder can claim any enabled agent name — so `IC_AGENT` is an honesty setting, not a boundary. Claiming a name whose room does not exist is refused (the room is a separate gate), but claiming a sibling's *existing* name is not. Set it correctly and leave it alone. Simultaneous work is safe: every request authenticates independently.

Use `IC_TOKEN_<AGENT>` only when one clone talks to two different **workspaces** — a live server and a local one, say — since those genuinely have different tokens.

## 2. Walk the user through unlocking the door

Relay these steps to the user, substituting the agreed agent name:

1. Log in to your Inspiration City workspace.
2. Click the three dots (⋯) at the top right.
3. Choose **Enable superagent**.
4. Enter the agent name: `<IC_AGENT>`.
5. IC creates the room. It does **not** mint a token — one token covers every agent.
6. If they have not got the workspace token to hand, tell them to open **Superagents → token** and press **GENERATE NEW TOKEN**. It is shown once.
7. Paste it into `.env` on the `IC_TOKEN=` line, using a secure local editor. Never paste the token into chat.

If a token appears in chat, treat it as potentially disclosed and have the owner assess shared rotation. Do not copy it into the kit.

## 3. Verify

```text
php bin/ic check
php bin/ic refresh
```

`check` should report `Connected to <base> as <user> (agent: <name>).` The name echoed back is the claimed label. Successful authentication verifies owner-token access and an enabled room, not a distinct agent identity. Then begin `first-run.md`.

## Troubleshooting

- **Invalid token (401)** — the token doesn't match. Ask the user to open **Superagents → token** (the `token` link in the Superagents bar), press **GENERATE NEW TOKEN**, and configure the new value securely outside the kit. Remind them it re-keys every agent.
- **Not found (404) on a page that exists** — the room gate. `local.superagent.<IC_AGENT>` must exist in the owner's vault; check `IC_AGENT` is spelled the way the user entered it.
- **Base not found (404)** — `IC_BASE` is wrong. It is `space.` plus their IC username, such as `space.example`.
- **Room enabled under a different name** — set `IC_AGENT` to match the room the user actually created; the name must match an enabled room; the token is not bound to it.

Agent labels use lowercase letters, digits and underscores, matching current gateway sanitization. Hyphenated labels are rejected locally rather than silently renamed by the server.
