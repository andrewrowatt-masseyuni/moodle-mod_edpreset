# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where this plugin sits

This directory is the `mod_edpreset` plugin and **its own git repository** (remote
`git@github.com:andrewrowatt-masseyuni/moodle-mod_edpreset.git`, branch `main`).

It is checked out at `mod/edpreset` inside a **Moodle 4.5 core checkout** (branch
`MOODLE_405_STABLE`, upstream Moodle) at `/home/arowatt/moodle405_mod_edpreset`, which is the
development host and the session's working directory. **Two nested repositories: paths and cwd in
this file are explicit about which one they mean.**

Consequences:

* Commit plugin work from inside this directory. The outer repo is upstream Moodle core — never
  commit plugin changes there, and never commit `mod/edpreset` into it (it shows there as an
  untracked directory, as do the other locally-installed plugins: `mod/ednote`, `mod/questionnaire`,
  `local/codechecker`, `local/moodlecheck`, `theme/snap`).
* Editing Moodle core files is almost never the answer. When core behaviour looks wrong, read the
  core source to understand it and work around it in the plugin.
* `mod/ednote` is a **soft, optional** companion (teacher notes). There is deliberately no
  `$plugin->dependencies` entry; `mod_edpreset` must install and work without it.

[README.md](README.md) is the plugin's design record — it documents not
just what the code does but *why each non-obvious decision was made*, with the failure mode that
motivated it. **Read the relevant section before changing that area, and update it in the same
change when behaviour changes.** The notes below are the operating context that README does not
cover; do not duplicate README content here.

## Development environment

Moodle runs in `moodle-docker` containers (compose project `moodle405_mod_edpreset`), with the
Moodle root bind-mounted at `/var/www/html` — so this plugin is `/var/www/html/mod/edpreset` inside
the container. Containers are normally already up.

Run anything Moodle-side inside the webserver container:

```bash
docker exec moodle405_mod_edpreset-webserver-1 <command>   # cwd is /var/www/html
```

The `moodle-docker-compose` wrapper (`/home/arowatt/moodle-docker/bin/moodle-docker-compose`) is what
[.vscode/tasks.json](.vscode/tasks.json) uses, but it needs `COMPOSE_PROJECT_NAME`,
`MOODLE_DOCKER_WWWROOT` and `MOODLE_DOCKER_DB` exported — these are **not** in the shell profile, so
prefer plain `docker exec` with the container name above.

`moodle-plugin-ci` runs on the **host** (PHP 8.3, vs PHP 8.1 in the container) from
`/home/arowatt/moodle-plugin-ci`, invoked from the Moodle root as `../moodle-plugin-ci/bin/…`.

## Commands

**Run these from the Moodle root (`/home/arowatt/moodle405_mod_edpreset`), not from this plugin
directory** — both the container paths and the `../moodle-plugin-ci` relative path are anchored
there. The grunt block below is the one exception.

```bash
# PHPUnit — whole plugin
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit --testsuite mod_edpreset_testsuite

# PHPUnit — one file / one test (fastest feedback loop; use this while iterating)
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit mod/edpreset/tests/lib_test.php
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit --filter test_supports mod/edpreset/tests/lib_test.php

# Behat — whole plugin (slow: the background runs the real bake pipeline)
docker exec -u www-data moodle405_mod_edpreset-webserver-1 \
  php admin/tool/behat/cli/run.php --tags=@mod_edpreset --format progress

# Core privacy provider test — this plugin implements privacy providers, so it must stay green
docker exec moodle405_mod_edpreset-webserver-1 vendor/bin/phpunit privacy/tests/privacy/provider_test.php

# Re-init test environments after schema/version changes (db/install.xml, version.php, generators)
docker exec moodle405_mod_edpreset-webserver-1 php admin/tool/phpunit/cli/init.php
docker exec moodle405_mod_edpreset-webserver-1 php admin/tool/behat/cli/init.php
```

Static analysis and style (host, from the Moodle root):

```bash
../moodle-plugin-ci/bin/moodle-plugin-ci phplint  ./mod/edpreset
../moodle-plugin-ci/bin/moodle-plugin-ci phpcs    ./mod/edpreset     # CI runs with --max-warnings 0
../moodle-plugin-ci/bin/moodle-plugin-ci phpcbf   ./mod/edpreset     # auto-fix style
../moodle-plugin-ci/bin/moodle-plugin-ci phpmd    ./mod/edpreset     # advisory; CI does not fail on it
../moodle-plugin-ci/bin/moodle-plugin-ci mustache ./mod/edpreset
docker exec moodle405_mod_edpreset-webserver-1 \
  php local/moodlecheck/cli/moodlecheck.php -p=mod/edpreset -f=text  # PHPDoc; CI runs --max-warnings 0
```

`moodle-plugin-ci validate` and `savepoints` **cannot be run on the host** — they boot Moodle and die
on `$CFG->dataroot` (which only exists inside the container). Those two are CI-only; the PHPDoc check
is covered locally by the `moodlecheck` command above rather than `moodle-plugin-ci phpdoc`.

JS/CSS/Gherkin (host, **cwd is this plugin directory** — Moodle's grunt detects which plugin to
build from cwd, so running these from the Moodle root would process all of core instead):

```bash
grunt --max-lint-warnings=0 amd          # required after editing amd/src/*.js
grunt --max-lint-warnings=0 stylelint
grunt --max-lint-warnings=0 gherkinlint
```

`amd/build/*.min.js` and their source maps are **committed**. Editing `amd/src/*.js` without running
`grunt amd` ships a stale bundle, and CI's grunt step will fail on the diff.

Site maintenance:

```bash
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/upgrade.php --non-interactive   # install/upgrade after version.php bump
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/purge_caches.php                # after AMD, template, lang or capability changes
docker exec moodle405_mod_edpreset-webserver-1 php admin/cli/uninstall_plugins.php --plugins=mod_edpreset --run
```

CI ([.github/workflows/moodle-ci.yml](.github/workflows/moodle-ci.yml))
runs the full `moodle-plugin-ci` set on push/PR against Moodle 4.5 / PHP 8.1 / PostgreSQL 16:
phplint, phpmd, phpcs, phpdoc, validate, savepoints, mustache, grunt, PHPUnit, Behat.

The `moodle-review` skill reviews code against Moodle coding style, security and Core API
guidelines — use it for review passes on plugin code.

## Architecture

`mod_edpreset` lets a curator configure exemplar activities in a **template course**, and lets
teachers add fully-configured copies of them into their own courses from the activity chooser.

### The load-bearing oddity

This is an activity module that **can never be instantiated**. It exists only because core requires
every activity-chooser content item to come from a `mod_*` component. So `edpreset_add_instance()`,
`edpreset_update_instance()` and `mod_edpreset_mod_form::definition()` all throw by design, the
`edpreset` table is a never-written stub that exists only so core's unconditional
`count_records('{modulename}')` in `course/reset_form.php` does not fail site-wide, and
`mod/edpreset:addinstance` is not an add-instance capability but the per-course gate that decides
whether presets are offered at all. Do not "fix" any of these — README's *Plugin shape* section
explains each.

### Pipeline

```
rebuild → bake (backup) → scrub (clear dates) → validate (test restore in sandbox) → promote to live
```

Driven by [classes/local/baker.php](classes/local/baker.php) (rebuild/orchestration),
[backup_baker.php](classes/local/backup_baker.php),
[scrubber.php](classes/local/scrubber.php) +
[scrub/](classes/local/scrub/) rules,
[validator.php](classes/local/validator.php) and
[sandbox.php](classes/local/sandbox.php). Entered from
[classes/task/](classes/task/) (nightly `reconcile_presets`, adhoc `bake_preset` /
`validate_preset`) and from [classes/observer.php](classes/observer.php).

Two invariants worth holding in mind when touching this: chooser visibility is gated by
`preset::is_live()` (a live archive whose content hash matches the record), **not** by the `status`
column — so a preset is never offered without a proven backup, and a re-bake in flight never pulls a
working preset out of the chooser. And a failed re-bake never removes a preset that already works.

### Data model

| Table | Role |
| --- | --- |
| `edpreset_meta` ([classes/meta.php](classes/meta.php)) | Curator input from the exemplar's own settings form. **Source of truth** — no row means not a preset. Raw markdown as typed. |
| `edpreset_item` ([classes/preset.php](classes/preset.php), a `core\persistent`) | Derived; rewritten by every rebuild. Cleaned HTML, chooser metadata, pipeline status, archive fingerprints. **Upserted on `templatecmid`**, never delete+reinsert — favourites key on the preset id. |
| `edpreset` | Stub. Never written to. |

Section templates have **no table**: a template is a view over the `edpreset_item` rows sharing a
`sectionnum` ([section_template.php](classes/local/section_template.php)), flagged by a
non-empty `templatename`.

Archives live in three system-context file areas keyed by preset id (`presetunscrubbed`,
`presetstaging`, `presetbackup`). The plugin implements **no `pluginfile` callback** — there is no
URL that reaches an archive. Keep it that way.

### Request flow

* [lib.php](lib.php) — core callbacks: the two `*_content_items()` functions that feed
  the chooser, the three `*_coursemodule_*` callbacks that splice the **Preset details** group into
  *other* modules' settings forms inside the template course, and `mod_edpreset_user_preferences()`.
* [chooser.php](chooser.php) → [classes/output/chooser_page.php](classes/output/chooser_page.php)
  — the standalone preset chooser page (filtering, starring, multi-select, section templates).
* [copy.php](copy.php) → [classes/local/activity_copier.php](classes/local/activity_copier.php)
  — the single copy handler for both entry points. Takes a preset list **or** a template, plus an
  optional explicit order. Deliberately does not end on the new activity's settings form.
* [manage.php](manage.php) → [classes/output/manage_page.php](classes/output/manage_page.php)
  — admin view of the pipeline: rescan, re-bake one, clear cached archives.
* [classes/local/access.php](classes/local/access.php) — `require_can_copy_into()` is
  the **single** access gate shared by the chooser page, the copy handler and the tests. Route new
  entry points through it rather than re-checking capabilities inline.
* [classes/external/](classes/external/) — `set_favourite` and `get_template_items` are
  AJAX-only helpers for the chooser page's JS, not a public API. Copying is a form post, not a web
  service, on purpose.

## Conventions and traps

* **`$plugin->supported` is pinned to `[405, 405]`** because the plugin depends on the legacy
  `get_course_content_items` callback that core is migrating to the hook API. Do not widen it
  without verifying that callback still exists and is still dispatched.
* Curator markdown fields are `PARAM_RAW` on the form and are rendered and cleaned **exactly once**,
  at bake time, with `format_text(…, ['noclean' => false])`. Persistent properties holding that
  already-cleaned HTML (`description`, `teacherguidance`, `templatesummary`) are `PARAM_RAW` and must
  never be re-cleaned or escaped downstream.
* Every form element added by `mod_edpreset_coursemodule_standard_elements()` must keep its
  `edpreset_` prefix — HTML_QuickForm silently drops an element clashing with the `name`/`intro`/
  `tags` that `standard_coursemodule_elements()` already added.
* `chooser.php` and `copy.php` both `require_sesskey()`; links are minted server-side per user.
* Observers must stay cheap and non-throwing (they fire on activity edits site-wide) and are declared
  `internal => false` so adhoc tasks are queued after the transaction commits.
* No `backup/` directory and `FEATURE_BACKUP_MOODLE2 => false` — the plugin *uses* backup/restore,
  it does not implement it for itself.
* Bump `$plugin->version` in [version.php](version.php) with every `db/` change, and
  pair it with an `upgrade_mod_savepoint()` step in
  [db/upgrade.php](db/upgrade.php) — CI's `savepoints` check enforces this.
* All user-facing text goes through `get_string()` into
  [lang/en/edpreset.php](lang/en/edpreset.php), whose keys are kept in alphabetical
  order.
* Behat fixtures come from [tests/generator/behat_mod_edpreset_generator.php](tests/generator/behat_mod_edpreset_generator.php),
  which supplies three entities core cannot: `mod_edpreset > sections` (4.5 has no section
  generator), `preset details`, and `template courses`. The drag gesture itself is deliberately not
  automated — see README's *Testing* section before adding Behat coverage of reordering.
