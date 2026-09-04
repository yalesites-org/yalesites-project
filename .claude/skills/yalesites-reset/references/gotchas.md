# YaleSites local environment gotchas

Read this when a reset step fails, when `verify.sh` reports something, or
when the site is behaving oddly after a rebuild. Each entry explains what
goes wrong, why, and how to fix it — plus a permanent fix where one exists.

## Contents

- [1. `lando pull` silently does nothing](#1-lando-pull-silently-does-nothing)
- [2. Lando doesn't link SSH keys into its containers](#2-lando-doesnt-link-ssh-keys-into-its-containers)
- [3. `composer update` dies before dumping the autoloader](#3-composer-update-dies-before-dumping-the-autoloader)
- [4. `local-git-checkout.sh` fails to link tokens](#4-local-git-checkoutsh-fails-to-link-tokens)
- [5. Composer detaches atomic from develop](#5-composer-detaches-atomic-from-develop)
- [6. The site isn't on port 443](#6-the-site-isnt-on-port-443)
- [7. `local:git-checkout` defaults to the wrong branches](#7-localgit-checkout-defaults-to-the-wrong-branches)
- [8. A config item always reports "Different"](#8-a-config-item-always-reports-different)

---

## 1. `lando pull` silently does nothing

**Symptom.** `npm run db:get`, `npm run files:get`, or `npm run
build-with-assets` appear to succeed, exit 0, and leave you on your old
database. Later steps then run against stale data and the mismatch surfaces
much later as confusing config or update errors.

**Cause.** `lando pull` opens an interactive prompt — *"Choose a Pantheon
account"*. Without a TTY (any scripted or agent-driven run) it receives EOF,
abandons the prompt, and moves on without pulling anything. The exit code
stays 0.

**Fix.** Use `scripts/pantheon-sync.sh`, which fetches through
`terminus backup:get` over HTTPS. No prompt, no TTY requirement, no SSH.

If you want `lando pull` itself to work interactively, run it directly in a
real terminal and answer the prompt — but you'll still hit gotcha #2.

---

## 2. Lando doesn't link SSH keys into its containers

**Symptom.**

```
dev.<uuid>@appserver.dev.<uuid>.drush.in: Permission denied (publickey).
```

**Cause.** Lando mounts `~/.ssh` at `/user/.ssh` inside the container, then
symlinks selected keys into the container user's `~/.ssh`. On some setups
(seen on Lando 3.26 + OrbStack) only `known_hosts` gets linked and no
private keys do, so SSH has nothing to offer. Your key is usually fine —
verify with `terminus ssh-key:list` and compare fingerprints:

```bash
ssh-keygen -lf ~/.ssh/id_rsa_pantheon.pub -E md5
```

**Workaround.** Don't use SSH. `scripts/pantheon-sync.sh` uses HTTPS.

**Permanent fix (optional).** Declare the keys explicitly in the *global*
Lando config, `~/.lando/config.yml`:

```yaml
keys:
  - id_rsa_pantheon
```

Then `lando rebuild -y`. Be deliberate about this: `~/.lando/config.yml` is
global, so it changes every Lando project on the machine, and once `keys`
is set, keys you add later won't load until you list them too. Confirm with
the developer before editing it.

Check current state with:

```bash
lando ssh -c "ls -la /var/www/.ssh/"
```

---

## 3. `composer update` dies before dumping the autoloader

**Symptom.** `composer update` ends with:

```
In FilesystemRepository.php line 166:
  file_get_contents(.../Composer/Repository/../InstalledVersions.php):
  Failed to open stream: No such file or directory
```

Then `drush deploy` fatals during config import:

```
Error: Class "Yethee\Tiktoken\EncoderProvider" not found
  in /app/web/modules/contrib/ai/src/Utility/Tokenizer.php on line 40
```

**Cause.** The packages install fine, but composer crashes before
regenerating the autoloader. `yethee/tiktoken` is therefore present on disk
but unregistered. `drupal/ai` needs it, and `search_api.server.*` config
import instantiates the AI search backend — so the import dies partway,
leaving config half-applied.

**Fix.**

```bash
lando composer dump-autoload
```

Verify:

```bash
lando ssh -c "php -r 'require \"/app/vendor/autoload.php\"; var_dump(class_exists(\"Yethee\\\\Tiktoken\\\\EncoderProvider\"));'"
```

Then re-run `lando drush deploy`. `verify.sh` checks this automatically.

---

## 4. `local-git-checkout.sh` fails to link tokens

**Symptom.** Everything looks fine, but the theme builds against an old
design-token package. `verify.sh` reports the tokens symlink is missing and
names the stale version.

**Cause.** A typo at `scripts/local/local-git-checkout.sh:346`:

```bash
rm -rf node_modules/@yalesitesorg/tokens   # missing the hyphen
```

The intended path is `@yalesites-org/tokens`. Because the typo'd path never
exists, the real directory — the version npm installed from the registry —
survives. The next line then runs `ln -s ../../_yale-packages/tokens tokens`
from inside `@yalesites-org/`, which, since `tokens/` already exists as a
directory, creates a *nested* `tokens/tokens` symlink inside it instead of
replacing anything. `ln` doesn't complain, so the script reports success.

Net effect: atomic resolves `@yalesites-org/tokens` to the stale registry
copy, plus a dangling nested symlink.

**Fix.**

```bash
cd web/themes/contrib/atomic/node_modules/@yalesites-org
rm -rf tokens
ln -s ../../_yale-packages/tokens tokens
```

**Permanent fix.** Correct the typo in `scripts/local/local-git-checkout.sh`
and open a PR — it affects every developer on the platform. In practice
atomic has no build step and doesn't depend on tokens directly (only
component-library-twig does, and that one links correctly via `npm link`),
so the impact is limited — but a stale package shadowing a local repo is
exactly the kind of thing that wastes an afternoon.

---

## 5. Composer detaches atomic from develop

**Symptom.** After `lando composer update`, `git -C web/themes/contrib/atomic
branch --show-current` prints nothing; you're on `HEAD` at `tags/vX.Y.Z`.

**Cause.** Local setup configures composer to install Yale packages from
source:

```bash
lando composer config --global 'preferred-install.yalesites-org/*' source
```

so `web/themes/contrib/atomic` is a real git clone. Composer then checks it
out at the *released tag* named in `composer.json`, detaching HEAD. Any work
you do afterward is on a detached head and easy to lose.

**Fix.** Always re-sync branches *after* composer runs, never before:

```bash
git -C web/themes/contrib/atomic checkout -- package-lock.json
git -C web/themes/contrib/atomic checkout develop
npm run local:git-checkout -- -m develop -a develop -c develop -t main
```

`npm install` inside atomic also rewrites `package-lock.json`; discard that
before switching, or the checkout refuses.

This ordering is why the reset runs composer/deploy first and re-links the
frontend repos last.

---

## 6. The site isn't on port 443

**Symptom.** `https://yalesites-platform.lndo.site/` returns a plain-text
`404 page not found` with `content-type: text/plain`. Drush works fine.
`drush uli` hands you a link that 404s.

**Cause.** That 404 is Lando's *proxy* (Traefik), not Drupal or nginx. If
something else holds ports 80/443 — OrbStack's built-in domain proxy is the
usual culprit — Lando falls back to the ports declared in
`.lando.local.yml`:

```yaml
proxyHttpFallbacks:  ["8000", "8888", "8008"]
proxyHttpsFallbacks: ["444", "4433", "4443"]
```

so the real URL is `https://yalesites-platform.lndo.site:444/`.

Confirm what's holding the ports:

```bash
lsof -nP -iTCP:443 -sTCP:LISTEN
```

**Fix.** Use the URL Lando reports rather than assuming 443:

```bash
lando info --format=json | python3 -c 'import json,sys;print([u for s in json.load(sys.stdin) for u in (s.get("urls") or []) if "lndo.site" in u])'
```

`lib.sh` exposes this as `ys_site_url`.

Note `DRUSH_OPTIONS_URI` in `.lando.local.yml` is hardcoded to the
port-less URL, so `drush uli` emits unusable links. Pass `--uri` explicitly:

```bash
lando drush uli --uri="https://yalesites-platform.lndo.site:444"
```

---

## 7. `local:git-checkout` defaults to the wrong branches

**Symptom.** The script reports success but puts atomic and/or
component-library-twig on `main`, which is behind `develop`.

**Cause.** `scripts/local/local-git-checkout.sh` defaults every repo except
yalesites-project to `main`. The actual remote defaults today are:

| repo | default branch |
|---|---|
| yalesites-project | `develop` |
| atomic | `develop` |
| tokens | `main` |
| component-library-twig | `develop` |

The README still documents the old `main` layout.

**Fix.** Always pass branches explicitly:

```bash
npm run local:git-checkout -- -m develop -a develop -c develop -t main
```

Better, don't trust any hardcoded list — ask each remote:

```bash
git -C <path> ls-remote --symref origin HEAD
```

`lib.sh`'s `ys_default_branch` does this, falling back to the table above
only when the remote is unreachable.

**Also note:** the script only *switches* branches. If a repo is already on
the target branch it prints "Already on X" and does not pull, so you can
finish with a clean checkout that's twenty commits stale. Fetch and
fast-forward explicitly.

---

## 8. A config item always reports "Different"

**Symptom.** After a successful `drush deploy`, `drush config:status` still
lists:

```
core.entity_form_display.block_content.inline_message.default   Different
```

**Cause.** Benign. The values are identical; only the position of
`field_icon` within the `content` map differs. Drupal reorders components
when it saves an entity display, and the committed sync file has a
different order. Re-importing rewrites it and it comes straight back.

**Do not** "fix" this by exporting — that just adds churn to a PR.

**Related but different:** `search_api.index.ys_beacon` may show as
Different on the first import and settle on a second `drush config:import`.
`ys_beacon*` is in `config_ignore.settings.yml`, so site-specific values are
intentionally preserved. Anything else showing as Different after two
imports is worth actually investigating.
