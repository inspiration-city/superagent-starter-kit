# Releasing the Kit

For maintainers. Before tagging a release:

```
php bin/ic version
php tests/offline.php
python3 tests/awaken_test.py
php bin/package /path/to/new/empty/output
```

All four run without network access or credentials. `bin/package` copies exactly the files listed in `RELEASE_FILES.txt` to a new directory outside the kit, refuses links and token-shaped text, and writes a SHA-256 manifest. It never publishes. Its scan is a hygiene check, not a full secret audit.

Then, with credentials kept outside the kit, run the live read-only smoke test against a workspace you own:

```
IC_ENV_FILE=/secure/ic-kit.env php tests/smoke.php --agent <your-agent>
```

Bump the version in `bin/ic` and `README.md` together.
