# Help and Local Knowledge

The kit uses a two-layer knowledge model:

1. `INSPIRATIONCITY.md` contains stable concepts and operating rules.
2. `.superagent/knowledge/` contains an ignored cache refreshed from the connected workspace.

After configuring `.env`, run:

```text
php bin/ic refresh
```

The refresh is read-only in IC. It caches the live tool catalog, skill catalog, help instructions, help-index source, and file-tools skill. Refresh after installation, when guidance appears stale, after an IC capability change, or when a tool behaves differently from the local documentation. Do not refresh on every command.

For live investigation:

```text
php bin/ic exec help
php bin/ic exec help <topic>
php bin/ic exec snapshot help
php bin/ic exec list folder=help
php bin/ic exec read location=local.tools.<tool>
php bin/ic exec read location=local.skills.<skill>
```

The live workspace is authoritative. Never edit files in the cache as a substitute for changing or reporting an IC documentation problem.

Cache manifest `.superagent/knowledge/manifest.json` selects one complete `generation-*` directory. Read documents from that directory, not legacy top-level files. Failed refresh leaves the prior manifest selected. Old complete generations may be removed manually when no reader uses them. Do not share cached workspace documentation.
