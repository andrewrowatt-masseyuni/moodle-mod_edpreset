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
  untracked directory, as do the other locally-installed plugins: `local/edguidance`,
  `filter/edguidance`, `lib/editor/tiny/plugins/edguidance`, `mod/ednote` (retired),
  `mod/questionnaire`, `local/codechecker`, `local/moodlecheck`, `theme/snap`).
* Editing Moodle core files is almost never the answer. When core behaviour looks wrong, read the
  core source to understand it and work around it in the plugin.
* Teacher guidance belongs to `local_edguidance` and is embedded in the exemplar's own text; it
  travels into copies inside the activity backup, so this plugin has no guidance code at all. Do not
  add any back - see README *Related plugins*.

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

# Behat — whole plugin
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

### Scan and copy

Nothing is stored between copies. There are two moving parts:

* **Scan** — [classes/local/baker.php](classes/local/baker.php) `rebuild()` rewrites the
  `edpreset_item` records from the template course. Entered from the manage page's Rescan (inline),
  the adhoc `rebuild_presets` the [observers](classes/observer.php) queue, and the nightly
  `reconcile_presets`. It backs nothing up.
* **Copy** — [classes/local/activity_copier.php](classes/local/activity_copier.php)
  `copy_activity()`: an import-mode backup of the exemplar **as the site admin**, straight into an
  import-mode restore as the teacher, then placement and tidying. The
  [scrubber](classes/local/scrubber.php) and its [rules](classes/local/scrub/) (date clearing) run on
  the *restored copy*, before its calendar is refreshed. A restore that fails part way has its debris removed; the failure is
  recorded on the preset for the manage page.

Two invariants worth holding in mind when touching this: what is offered is decided by
`preset::is_offered()` from the curator's **release status** alone (released → everyone; review →
holders of `mod/edpreset:reviewpresets` in the target course; draft/archived → nobody), and every
entry point - both choosers, `copy.php`, `get_template_items` - asks it. And a copy is always of the
exemplar as it is now, so the release status is the only thing between a curator's work in progress
and teachers: README *Release status* explains the duplicate-then-swap workflow it relies on.

### Data model

| Table | Role |
| --- | --- |
| `edpreset_meta` ([classes/meta.php](classes/meta.php)) | Curator input from the exemplar's own settings form. **Source of truth** — no row means not a preset. Raw text as typed, plus the release status. |
| `edpreset_item` ([classes/preset.php](classes/preset.php), a `core\persistent`) | Derived; rewritten by every rebuild. Cleaned HTML, chooser metadata, the release status (also written straight through by the settings form's post actions), and the last copy failure. **Upserted on `templatecmid`**, never delete+reinsert — favourites key on the preset id. |
| `edpreset` | Stub. Never written to. |

Section templates have **no table**: a template is a view over the `edpreset_item` rows sharing a
`sectionnum` ([section_template.php](classes/local/section_template.php)), flagged by a
non-empty `templatename`.

The plugin stores no files and implements **no `pluginfile` callback**; the curator's description
editor relies on that (`maxfiles => 0`). Keep it that way.

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
  — admin list of presets with their release status, the dates their copies clear and the last copy
  failure; Rescan runs the scan inline.
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
* Curator rich text fields are `PARAM_RAW` on the form and are rendered and cleaned **exactly once**,
  when the preset is scanned, with `format_text(…, ['noclean' => false])`. Persistent properties
  holding that already-cleaned HTML (`description`, `sectionsummary`) are `PARAM_RAW` and must never
  be re-cleaned or escaped downstream.
* Every form element added by `mod_edpreset_coursemodule_standard_elements()` must keep its
  `edpreset_` prefix — HTML_QuickForm silently drops an element clashing with the `name`/`intro`/
  `tags` that `standard_coursemodule_elements()` already added.
* `chooser.php` and `copy.php` both `require_sesskey()`; links are minted server-side per user.
* Observers must stay cheap and non-throwing (they fire on activity edits site-wide) and are declared
  `internal => false` so adhoc tasks are queued after the transaction commits. `course_module_created`
  also recognises core's Duplicate and gives the copy a draft copy of the preset details.
* Anything written to `edpreset_item` from a teacher's request (the copy failure) goes through
  `$DB` directly, not the persistent: `update()` would stamp the teacher into `usermodified`, which
  the privacy provider declares as curator authorship.
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
