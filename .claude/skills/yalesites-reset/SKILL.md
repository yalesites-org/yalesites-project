---
name: yalesites-reset
description: Reset the YaleSites local development environment to a clean, verified baseline — syncs all four repos (yalesites-project, atomic, component-library-twig, tokens) to their latest default branches, pulls the Pantheon dev database and files, rebuilds Drupal config, and verifies the result end to end. Use this whenever starting work on a new YaleSites ticket, and any time the local site is stale, broken, on the wrong branches, or "needs a fresh start" — including when the user mentions resetting or rebuilding their yalesites/lando environment, a YALB ticket, a failed `lando pull` or `npm run db:get`, atomic vs component-library branch confusion, or a local yalesites site that won't load. Prefer this over running `npm run build-with-assets` directly, which fails silently in several ways this skill detects and works around.
---

# YaleSites local reset

Bring the local YaleSites platform back to a known-good baseline: every repo
on its latest default branch, a fresh Pantheon dev database and files,
Drupal config imported, and the whole thing verified.

Scope is deliberately *reset only*. Creating ticket branches is left to the
developer, so this stays safe to re-run at any point.

## Why not just `npm run build-with-assets`

That command is the documented path, but it has quiet failure modes: it
skips the Pantheon pull entirely when there's no TTY (while exiting 0),
composer sometimes dies before dumping the autoloader, and composer leaves
the atomic theme on a detached tag rather than `develop`. Each one leaves a
site that *looks* rebuilt but isn't. This skill runs the same underlying
tooling in an order that avoids those traps, and then proves the result.

Full explanations of every quirk live in `references/gotchas.md`. Read the
relevant section whenever a step misbehaves rather than guessing — these are
all non-obvious and were expensive to work out.

## Before starting

Run the preflight check. It is read-only and changes nothing:

```bash
.claude/skills/yalesites-reset/scripts/doctor.sh
```

If it exits non-zero, **stop and report what it found**. The most important
thing it catches is uncommitted work in any of the four repos — the reset
switches branches and lets composer re-checkout atomic, so tracked changes
would be lost. Untracked files are safe and are reported as information
only.

It also warns when Lando has no SSH keys in its containers. That's expected
on many setups and does not block anything: this skill deliberately avoids
SSH (see gotcha #2).

Because step 2 **drops the local database**, confirm with the developer
before starting unless they've already asked for a full reset.

## The sequence

Order matters here, and the reason is worth internalizing: composer installs
Yale packages from source and checks the atomic theme out at its *released
tag*, which detaches it from `develop`. So composer has to run **before** the
frontend repos are put on their branches and linked — otherwise it undoes
that work. Everything below follows from that constraint.

### 1. Sync the root repo

Fetch and fast-forward `yalesites-project` first, so composer resolves
against current `composer.json`.

```bash
git fetch origin --prune
git checkout develop && git pull --ff-only origin develop
npm install
```

Don't assume `develop`; ask the remote what it considers default
(`git ls-remote --symref origin HEAD`). `lib.sh` exposes `ys_default_branch`
for this. See gotcha #7 — the in-repo script's defaults are out of date.

### 2. Pull the Pantheon dev database and files

```bash
.claude/skills/yalesites-reset/scripts/pantheon-sync.sh --yes
```

This replaces `npm run db:get` / `npm run files:get`, which depend on
`lando pull` and fail (or silently no-op) on most local setups. It downloads
through `terminus backup:get` over HTTPS instead, imports the database, and
rsyncs files into `web/sites/default/files`.

Pass `--db` or `--files` alone to do just one. Drop `--yes` to be prompted
before the database is dropped.

If terminus isn't authenticated the script says so and stops — that needs a
machine token and is the developer's to fix.

### 3. Composer, then the autoloader

```bash
lando composer update
lando composer dump-autoload
```

The explicit `dump-autoload` is not redundant. `composer update` here often
dies at the very end with a `FilesystemRepository.php` /
`InstalledVersions.php` error *after* installing packages but *before*
regenerating the autoloader. When that happens `yethee/tiktoken` is on disk
but unregistered, and the next step fatals with `Class "Yethee\Tiktoken\
EncoderProvider" not found` partway through importing config — leaving
config half-applied. Running it unconditionally costs seconds. Gotcha #3.

### 4. Deploy config

```bash
lando drush deploy
```

This runs database updates, imports config, and rebuilds cache. Read the
output rather than trusting the exit code: look for `Error:` or `terminated
abnormally`. A partial config import is the most likely real failure, and
step 3 is usually the cause.

If `drush config:status` still shows differences afterward, a second
`lando drush config:import` settles items like `search_api.index.ys_beacon`
that `config_ignore` touches. One item never settles and that's fine —
gotcha #8.

### 5. Put the frontend repos on their branches and link them

Composer has just detached atomic, so restore it and re-establish the npm
links:

```bash
git -C web/themes/contrib/atomic checkout -- package-lock.json
git -C web/themes/contrib/atomic checkout develop
npm run local:git-checkout -- -m develop -a develop -c develop -t main
```

Discarding `package-lock.json` first matters: `npm install` inside atomic
rewrites it, and the checkout refuses while it's modified.

`local:git-checkout` re-links tokens and component-library-twig and rebuilds
both. Note it only *switches* branches — if a repo is already on the target
it will not pull, so fetch and fast-forward each one explicitly if you need
genuinely current code.

### 6. Fix the tokens symlink

`local-git-checkout.sh` has a typo (`@yalesitesorg` instead of
`@yalesites-org`) that leaves atomic resolving tokens to a stale registry
copy, plus a dangling nested symlink. It reports success either way, so this
has to be corrected by hand:

```bash
cd web/themes/contrib/atomic/node_modules/@yalesites-org
rm -rf tokens && ln -s ../../_yale-packages/tokens tokens
```

Gotcha #4 has the full mechanism and the permanent fix.

### 7. Clear cache and verify

```bash
lando drush cr
.claude/skills/yalesites-reset/scripts/verify.sh
```

## Verification

`verify.sh` is the point of the whole exercise — it checks the things that
fail *quietly*:

- all four repos on their remote-default branch, not detached, not behind
- atomic's tokens and component-library links resolve to the local repos,
  with the version it actually resolved to
- component-library `dist/` and tokens `build/` exist
- Drupal bootstraps; profile and theme are right
- the composer autoloader can resolve tiktoken
- no pending database updates; config in sync (known-benign diff annotated)
- the site returns 200 **on the URL Lando actually reports**, and the
  component-library CSS is really being served

Report its output to the developer, including the site URL and login
command it prints. Don't paraphrase a pass — if something is yellow or red,
say so and point at the matching gotcha.

## Reporting back

Finish with: the four repos and their branches/commits, the site URL, a
working `drush uli` command, and anything that needed a workaround. If a
step failed and you worked around it, say which — a silent workaround today
is a mystery next month.

## Lighter variants

Not every situation needs the full reset. Match the work to the ask:

| Situation | Do this |
|---|---|
| Config changed; keep local content | Steps 3–4 and 7 (skip the Pantheon pull) |
| Just fell behind on branches | Steps 1, 5, 6, 7 |
| "Is my environment OK?" | `doctor.sh` and `verify.sh` only — both read-only |
| Site won't load at all | `verify.sh` first; it usually names the cause |

## A note on the URL

The site is often **not** on port 443. If something else holds 80/443 —
OrbStack's proxy typically does — Lando falls back to the ports declared in
`.lando.local.yml`, commonly `:444`. A bare `https://<site>.lndo.site/` then
returns a plain-text `404 page not found` from Lando's proxy, which looks
exactly like a broken Drupal install but isn't.

Always take the URL from `lando info` (`ys_site_url` in `lib.sh`), and pass
`--uri` to `drush uli`, whose configured URI omits the port:

```bash
lando drush uli --uri="$(lando info --format=json | python3 -c 'import json,sys;print(next(u for s in json.load(sys.stdin) for u in (s.get("urls") or []) if u.startswith("https") and "lndo.site" in u).rstrip("/"))')"
```

## Environment notes

All three scripts locate the project root themselves by walking up for
`.lando.upstream.yml`, so they can be run from anywhere inside the checkout
— including from within `atomic` or `component-library-twig`, which are
separate git repos nested inside this one. They refuse to run outside it.

Scripts source `lib.sh`, which handles the two things that bite every
non-interactive run: Node (the project needs 20.x; `.nvmrc` may pin a patch
version that isn't installed, so it falls back to any installed 20) and
`YALESITES_BUILD_TOKEN`, which `~/.zshrc` doesn't provide to non-login
shells and without which every `@yalesites-org` npm install 401s.
