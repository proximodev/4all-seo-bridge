# 4All SEO Bridge

Single-file WordPress plugin (`4all-seo-bridge.php`) exposing the `4all/v1`
REST namespace that 4All Digital's SEO tools push through. Public repo,
GPL-2.0. Self-updating from v0.2.0 via `Update URI` + the latest release's
`manifest.json`.

## Layout

```
4all-seo-bridge.php    the plugin; the Version: header is the source of truth
README.md              install, updates, endpoints (the public API contract), releasing
CHANGELOG.md
HANDOFF.md             status, per-site rollout, update-path check, local PHP
tests/run.php          stub tests (no WordPress); CI runs them on PHP 7.4 + 8.3
tests/live.mjs         live matrix against a local WordPress (wp-env); see tests/README.md
tests/wp-updater-probe.php
.github/workflows/     lint.yml (push/PR), release.yml (v* tags → zip + manifest)
```

## Rules

- Bump **both** the `Version:` header and `FOURALL_SEO_BRIDGE_VERSION`; CI
  fails otherwise. Tag must equal the header (`vX.Y.Z`).
- The release zip must unpack to `4all-seo-bridge/` — that is the installed
  folder name and what makes in-place updates work.
- Never run `wp plugin update 4all-seo-bridge` on the wp-env site while
  `.wp-env.json` maps this checkout in as the plugin (the upgrader deletes
  the folder — your working copy).
- No client hostnames, credentials, or sheet URLs in this repo. It is public.

## The other repository

The tools that call this plugin live in the private **4all-automations**
repo (`C:\Users\doug\WebstormProjects\4all-automations`,
https://github.com/proximodev/4all-automations): `wp-seo-push`, `wp-push`,
`seo-post`, the draft reader, and all system-level docs (pipeline spec,
operator guides, status page, test plan).

- **Docs live in one place.** Plugin facts here; everything system-wide
  there. Do not copy that repo's documents into this one — link or read
  them by path. `TESTING.md` here is a pointer for that reason.
- **Cross-repo work goes through a brief** in
  `4all-automations/documentation/briefs/` (convention in its README):
  self-contained, with a status banner the executing session updates. If
  a plugin change needs tool changes (new error code, changed response,
  new minimum version), write the brief there rather than editing the
  tools blind.
- **The tools pin a minimum plugin version** (`BRIDGE_MIN_VERSION` in
  `scripts/lib/wp-seo-core.js`) and warn when a site is behind. Raise it
  over there when a release adds something the tools rely on.
- **Live coordination:** another Claude Code session is usually open on the
  automations repo on this machine (`ListAgents` shows it). Send it a
  message when you tag a release or change a response shape.
