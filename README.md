# Activity preset provider (mod_edpreset)

[![Moodle Plugin CI](https://github.com/andrewrowatt-masseyuni/moodle-mod_edpreset/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/andrewrowatt-masseyuni/moodle-mod_edpreset/actions/workflows/moodle-ci.yml)

<!-- TODO: Replace the placeholder below with the plugin description. -->

Enables teachers to add curated activities with preset configuration including settings, instructions and teacher guidance.
Activities can be individually presented in the standard Moodle activity chooser. A standalone chooser is also provided with a rich user-interface to discover and select preconfigured activities and section templates.

## Requirements

| | |
| --- | --- |
| Moodle | 4.5 (build 2024100700). `$plugin->supported` is pinned to `[405, 405]`. |
| PHP | 8.1 or later (the version CI runs against). |
| Database | Any Moodle-supported database. CI runs PostgreSQL 16. |

The version pin is deliberate: the plugin depends on the legacy `get_course_content_items` callback,
which Moodle is migrating to the hook API. Do not widen `$plugin->supported` without re-checking that
the callback still exists and is still dispatched.

### Related plugins

`mod_ednote` is an optional companion. When it is installed, copying a preset that carries teacher
guidance also drops a teacher note above the new activity. There is deliberately **no**
`$plugin->dependencies` entry — the link is one-way and soft, and either plugin installs and works on
its own.

## Installation

1. Copy the plugin into `mod/edpreset` in your Moodle installation:

   ```
   git clone git@github.com:andrewrowatt-masseyuni/moodle-mod_edpreset.git mod/edpreset
   ```

   Alternatively, download the ZIP and extract it so that `version.php` sits at `mod/edpreset/version.php`.

2. Visit **Site administration → Notifications**, or run:

   ```
   php admin/cli/upgrade.php
   ```

3. Purge caches after the upgrade completes.

## Setup

The plugin does nothing until it is enabled and pointed at a template course.

1. Create (or choose) a course to hold the exemplar activities. Section 0 is never scanned, so it can
   be used for instructions to whoever curates the course.
2. Go to **Site administration → Plugins → Activity modules → Activity preset provider**.
3. Tick **Enable preset activities** and set **Template course ID** to the course from step 1. The
   setting echoes back the resolved course name so the ID can be sanity-checked.
4. Review the remaining settings:

   | Setting | Default | Purpose |
   | --- | --- | --- |
   | `enabled` | off | Master switch. When off, no presets are offered and no backups are taken. |
   | `templatecourseid` | *(empty)* | The course holding the exemplar activities. |
   | `maxpresets` | 100 | Cap on how many presets are offered at once. |
   | `maxbackupsize` | 104857600 (100 MB) | Exemplars with a larger backup are not offered. `0` disables the limit. |
   | `datefields` | *(empty)* | Extra date fields for activity types the built-in scrub map does not cover, one `modname: field, field` pair per line. |
   | `sandboxshortname` | `edpreset_restore_test` | Short name of the hidden course used for test restores. |
   | `sandboxcategoryid` | site default | Category the test-restore course is created in. |

5. Open **Site administration → Plugins → Activity modules → Manage preset activities** to watch the
   pipeline, force a rescan, re-bake a single preset, or clear every cached archive.

## Usage

### Curating a preset

Inside the template course, add and configure an activity as you want teachers to receive it, then
fill in the **Preset details** group at the top of its settings form:

| Field | Required | Notes |
| --- | --- | --- |
| Preset name | yes | What teachers see. Independent of the activity's own name. |
| Description | yes | Markdown. Shown on the preset card and in the activity chooser's info panel. |
| Teacher guidance | no | Markdown. Becomes a teacher note above the copied activity (needs `mod_ednote`). |
| Tags | no | Comma separated. Also prefixed onto the chooser description so tag search works there. |
| Default activity name | no | The name the copied activity is given. |

An activity with no preset details is not a preset and is never scanned, baked or offered. Section
names in the template course become the preset categories.

### Adding a preset to a course

* Presets in **section 1** of the template course appear directly in the standard activity chooser.
* Everything in **sections 2 and above** is reached through the **Preset activities** item in the
  chooser, which opens a filterable page with starring, tag filters and multi-select.

Either route ends at the same handler, and the teacher is returned to the section they started from.
A preset arrives already configured, so — unlike every other chooser item — the flow deliberately
does **not** end on the new activity's settings form.

## Technical details

### Plugin shape

This is an activity module that is never instantiated. It exists only to contribute content items to
the activity chooser, and core requires every chooser content item to originate from a `mod_*`
component (see `core_course\local\service\content_item_service::get_content_items_for_user_in_course`),
which rules out a `local` plugin.

Consequences of that shape, all of them load-bearing:

* `edpreset_add_instance()`, `edpreset_update_instance()` and `mod_edpreset_mod_form::definition()`
  all throw. The form refuses first, because `course/modedit.php` builds the form before it would
  ever reach the library function.
* `edpreset_supports()` returns `MOD_ARCHETYPE_SYSTEM`, which keeps the module out of the admin
  module pickers and suppresses the missing-capability notice in `course_allowed_module()`. It also
  returns `FEATURE_NO_VIEW_LINK` and `FEATURE_BACKUP_MOODLE2 => false`.
* Implementing `mod_edpreset_get_course_content_items()` makes core discard this module's own default
  chooser item, so "Activity preset provider" never appears in the chooser itself.
* The stub `edpreset` table exists because core queries `{modulename}` unconditionally —
  `course/reset_form.php` runs an uncaught `count_records()` for every installed module, so without
  the table `/course/reset.php` would fail site-wide.
* `mod/edpreset:addinstance` is never used to add an instance. It is the per-course gate that
  `course_allowed_module()` checks, and therefore what decides whether presets are offered at all.

### Database

| Table | Role |
| --- | --- |
| `edpreset_meta` | Curator input, entered on the exemplar's own settings form. **Source of truth**: an activity without a row here is not a preset. Holds raw markdown as typed. |
| `edpreset_item` | Derived record, one per exemplar, rewritten wholesale by every rebuild. Holds cleaned HTML, chooser metadata, pipeline status and archive fingerprints. |
| `edpreset` | Stub instance table (see above). Never written to. |

`edpreset_item` rows are **upserted on `templatecmid`**, never deleted and reinserted, because user
favourites and recommendations key on the preset id. Reinserting on each rebuild would silently
re-point everyone's stars.

`edpreset_item.title` is copied from `edpreset_meta.presetname`, not from the exemplar's activity
name: an inline rename on the course page fires `course_module_updated` without running the settings
form's post actions, so the two must stay independent.

### Chooser integration

`mod_edpreset_get_course_content_items()` supplies the per-course items;
`mod_edpreset_get_all_content_items()` supplies the context-free list. The second is not optional:
`content_item_service::add_to_user_favourites()` resolves a starred id with `array_search()` over
that list and, on failure, silently indexes `$items[0]` — an unrelated module.

Each preset becomes a `content_item` with:

* a unique internal name (`edpreset_<id>`), used by `activitychooser.js` as a de-duplication key;
* a link to `copy.php` that already contains a `?`, because the chooser JS string-appends
  `&section=…&beforemod=…` to it;
* the exemplar's archetype and purpose, which decide the chooser tab and the icon colour.

The item that opens the preset chooser page uses id `-1`. That is not an arbitrary sentinel:
`course_content_item_exporter` sets `legacyitem => (id == -1)` and the item template wraps its
favourite star in `{{^legacyitem}}`, so `-1` is the one value that renders an item with no star.

Chooser visibility is gated by `preset::is_live()` — a live archive exists **and** its content hash
matches the recorded one — not by the `status` column. Two things follow: a preset can never be
offered without a proven backup behind it, and a re-bake in flight does not pull a working preset out
of the chooser.

### Curator form extension

Three core callbacks extend other modules' settings forms inside the template course:

* `mod_edpreset_coursemodule_standard_elements()` splices the **Preset details** group in above the
  activity name with `insertElementBefore()`. Every element is `edpreset_` prefixed because
  `standard_coursemodule_elements()` has already added `name`, `intro` and `tags`, and HTML_QuickForm
  silently drops an incompatible duplicate.
* `mod_edpreset_coursemodule_validation()` re-checks the fields server-side, because a module can be
  created by web service or restore without the form ever being submitted.
* `mod_edpreset_coursemodule_edit_post_actions()` writes the `edpreset_meta` row.

Markdown fields are typed `PARAM_RAW` on the form (PARAM_TEXT would mangle the syntax) and are
rendered and cleaned exactly once, at bake time, via `format_text(…, ['noclean' => false])` — the
point at which the text crosses out of the template course and becomes readable by everyone who can
add an activity.

### The bake pipeline

```
rebuild → bake (backup) → scrub → validate (test restore) → promote to live
```

**1. Rebuild** (`local\baker::rebuild()`) walks the template course's `modinfo`, skips section 0,
hidden and delegated sections, skips subsections and modules without backup support, and upserts a
preset row for every activity that has curator details. Presets whose exemplar has gone are deleted,
along with their file areas and any core/plugin favourite rows pointing at them.

**2. Backup** (`local\backup_baker::bake()`) runs a `backup_controller` with
`TYPE_1ACTIVITY / FORMAT_MOODLE / INTERACTIVE_NO / MODE_GENERAL`. `MODE_GENERAL` rather than
`MODE_IMPORT` because `backup_helper::store_backup_file()` returns null for import mode, leaving no
file to keep. Every user-data and site-specific setting (`users`, `role_assignments`, `logs`,
`grade_histories`, `groups`, `comments`, `badges`, `calendarevents`, `contentbankcontent`,
`legacyfiles`, …) is switched off, skipping any the site has locked. The result is size-checked
against `maxbackupsize`, copied into the `presetunscrubbed` area, and the backup left in the
exemplar's own module context is deleted so curators do not find stray archives on their activities.

**3. Scrub** (`local\scrubber`) extracts the `.mbz`, applies each `local\scrub\rule`, and repacks into
`presetstaging`. The archive is rewritten rather than the exemplar: nulling the exemplar's own
columns around the backup call would briefly corrupt a live course, and would leave it corrupted if
cron died mid-run.

The only rule shipping today is `clear_dates`, which zeroes date fields so a copied activity does not
arrive with last year's due date. Which fields count as dates comes from a **curated per-module map**,
not from matching column names — a name heuristic was tried and rejected because it zeroed `assign`'s
`sendnotifications` (a boolean) and `quiz`'s `timelimit` (a duration) while still missing `wiki`'s
`editbegin`. Unknown modules get no date clearing at all; the admin can extend coverage through the
`datefields` setting, and the manage page lists date-looking columns the map does not cover as
advisory suggestions. Fields are set to `0`, not emptied, because module restore steps pass them
straight to `apply_date_offset()` and expect an integer.

Scrubbing is **fail-soft**: a rule that throws is caught individually, and any failure falls back to
staging the untouched original. A preset with stale dates is useful; a preset that does not exist is
not.

**4. Validate** (`local\validator`) is what makes best-effort scrubbing safe. The staged archive is
test-restored into a hidden sandbox course through *exactly the same code path a teacher's click
takes*, rather than a parallel one. If the restore fails and the archive was scrubbed, the untouched
original is tried instead; if that works it is published and the reason is recorded against the
preset. So the correct behaviour per module is discovered automatically and no curator has to know
which modules tolerate which rules.

The sandbox (`local\sandbox`) is resolved **by shortname, not by a stored course id** — admins delete
it from time to time because it looks like clutter, and a stored id would then point at nothing (or
at whatever course later reused that id). It is wiped *before* each validation rather than after, so
that debris from a crashed or killed run cannot contaminate the next one. The wipe also removes
restore-created sections and calls `question_delete_course()`, without which a sandbox validating quiz
presets nightly would accumulate question banks indefinitely.

**5. Promote.** A proven archive is copied into `presetbackup`, and its content hash, size and
validation time are recorded. A failed re-bake never removes a preset that already works: the
previously validated archive stays in place and keeps serving.

### Copying into a course

`local\activity_copier::restore_into()` is the cross-course equivalent of core's `duplicate_module()`,
which cannot be reused — its backup/restore core would work, but everything after the restore is
hardcoded to the *source* course, so it silently fails when the target is a different one.

The restore runs as the requesting user in `MODE_IMPORT` with `TARGET_CURRENT_ADDING`, which needs
`moodle/restore:restoretargetimport` in the target course (editing teachers hold it by default). Note
that the *backup* side has the opposite requirement, `moodle/backup:backuptargetimport` in the source
course, which teachers do **not** hold for the template course — that is why archives are baked ahead
of time by cron rather than produced on demand.

After `execute_plan()` the copier:

* finds the new `cmid` from the plan's sole `restore_activity_task`, before `dispose()` empties the
  task list;
* places the activity explicitly with `moveto_module()`, creating the section if needed — the restore
  otherwise places it by matching the *exemplar's* section number;
* clears `idnumber` (must be unique per course), `availability` (references template-course ids) and
  `completionexpected` (never the date the teacher wants);
* rebuilds the course cache and calls `course_module_update_calendar_events()`;
* fires `course_module_created` by hand, because the restore subsystem does not — without it,
  completion, competencies and third-party observers never learn the activity exists;
* renames the activity to the preset's default name *before* reading modinfo, since
  `set_coursemodule_name()` purges and rebuilds the course cache;
* emits a `mod_ednote` teacher note above the activity when the preset has guidance and
  `course_allowed_module()` permits it. The note carries the *preset id*, so later edits in the
  template course reach every course that already added it, with the guidance text stored as a
  fallback.

`copy_many()` copies a batch sequentially. Nothing wraps a restore in a transaction — core does not
either — so a preset that fails does not take the rest down with it; the caller gets both an `added`
and a `failed` list. All copies share the same `beforemod`, which is what keeps a multi-select in
selection order.

`restore_controller` teardown is handled explicitly in `dispose()`: `backup_ids_temp` and
`backup_files_temp` are per-connection temp tables, so a restore that threw part way through leaves
them behind for the next restore in the same request to adopt, and `destroy()` is what releases the
plan and logger (its own docblock warns that a script performing several operations without it runs
out of memory — exactly this case).

### Tasks and events

| Task | Type | Schedule |
| --- | --- | --- |
| `reconcile_presets` | scheduled | 03:00 daily |
| `bake_preset` | adhoc | queued per exemplar, de-duplicated |
| `validate_preset` | adhoc | queued by a successful bake |

The nightly reconcile is not redundant with the observers: editing quiz questions, book chapters or
lesson pages fires module-specific events, not `course_module_updated`, so those changes would
otherwise never reach a preset. A blanket nightly re-bake is simpler and more reliably correct than
enumerating every content-changing event across every module, and it is cheap — Moodle's file storage
deduplicates by content hash, so an unchanged exemplar produces no new stored file.

Observers (`db/events.php`) cover `course_module_created`, `course_module_updated`,
`course_module_deleted`, `course_section_updated`, `grading_definition_created`,
`grading_definition_updated` and `course_deleted`. All are declared `internal => false` so they run
after the transaction commits — they queue adhoc tasks, which must not be queued from inside a
transaction that might roll back. Every handler is cheap and non-throwing: they fire on activity edits
site-wide, so the first thing each one does is compare a config value and give up. The grading
observers exist because editing a rubric or marking guide does not fire `course_module_updated`.

`edpreset_meta` and `edpreset_item` rows are swept by the delete observers, because XMLDB foreign keys
are not enforced by the database and nothing cascades them away.

### Concurrency and limits

* **Pipeline lock** (`mod_edpreset` / `pipeline`, 120 s): serialises bakes and validations. They share
  the sandbox course, and two concurrent validations would delete each other's activity mid-restore.
* **Per-user copy lock** (`copy_<userid>`, no wait): taken once around a whole batch, so a reload or
  double-click cannot start a second restore run.
* `access::MAX_PRESETS` (20) caps one request, so a hand-edited URL cannot occupy a PHP worker for an
  hour. `copy.php` also raises the time limit and memory limit.
* `maxpresets` caps how many items the chooser is handed; `maxbackupsize` caps a single archive.

### Access control

`local\access::require_can_copy_into()` is the single gate, shared by the chooser page, the copy
handler and the tests. It requires `moodle/course:manageactivities` and
`moodle/restore:restoretargetimport` on the target course, checks `course_allowed_module()`, and
range-checks the section number against the format's maximum (the same guard core applies in
`modedit.php`, MDL-69431).

Both `copy.php` and `chooser.php` call `require_sesskey()`. The chooser links are minted server-side
per user, so carrying a sesskey costs nothing and closes CSRF on what is otherwise a state-changing
GET.

### Files

Archives live in three system-context file areas, keyed by preset id:

| Area | Contents |
| --- | --- |
| `presetunscrubbed` | The untouched backup, kept as a fallback if a scrub rule breaks the restore. |
| `presetstaging` | The candidate awaiting its test restore. |
| `presetbackup` | The validated, live archive the chooser offers. |

The plugin implements **no `pluginfile` callback**, so there is no URL that reaches any archive.

### Progress reporting

`local\copy_progress` extends `core\progress\display_if_slow` and defers the page header to the first
`start_html()` call. Printing the header up front would move `moodle_page` to `STATE_IN_BODY`, after
which `redirect()` can no longer send a `Location` header and falls back to a scripted redirect with a
"You should really redirect before you start page output" notice — a hard failure on a site with
developer debugging turned into exceptions. So a fast copy stays completely silent and ends on a real
303; a slow one prints the header, the standard restore progress bar, and finishes with a scripted
redirect plus a continue button.

### Web services and user preferences

`mod_edpreset_set_favourite` is AJAX-only and not part of any service — it exists for the preset
chooser page's JavaScript, not as a public API. Copying is deliberately a form post rather than a web
service, so that one restore or ten happen in a single request.

Stars are recorded against this plugin's own `core_favourites` component/item type
(`mod_edpreset` / `preset`) in the user's context, rather than `core_course`'s, because they must
cover the presets the standard chooser never shows. Both `create_favourite()` and `delete_favourite()`
are guarded by an existence check, since core's are unguarded and a double-click would otherwise
produce a 500.

The collapsed-group state is a user preference, `mod_edpreset_collapsed`, declared through
`mod_edpreset_user_preferences()` — without which `core_user::get_preference_definition()` throws and
the AJAX write is rejected. Groups are keyed by an 8-character hash of the section name because the
whole set has to fit in `user_preferences.value` (`char(1333)`).

### Plugin backup and restore

The plugin declares `FEATURE_BACKUP_MOODLE2 => false` and ships no `backup/` directory. There is
nothing to back up: no instance of this module can exist, and everything it owns is either derived
from the template course (and rebuilt by cron) or is per-user favourite/preference data. Course backup
and restore are used *by* the plugin, as described above, not implemented *for* it.

Presets copied into a course are ordinary activities of their own module type from the moment they
land, so they back up, restore, duplicate and import exactly like any other activity, with no
reference back to this plugin.

### Privacy

`privacy\provider` implements the metadata, plugin, userlist and user-preference providers. The
presets themselves hold nothing personal — they describe exemplar activities, and the stored backups
are taken with user data excluded. What is covered is the starred presets (via the `core_favourites`
subsystem link, always in the user's own context) and the collapsed-groups preference, which is
exported as a count rather than as its raw list of hashes.

## Testing

The plugin ships PHPUnit coverage for the access rules, the copier, the baker, the progress reporter,
the external function, the form elements, the library functions, the sandbox and the scrubber, plus a
test data generator.

```
vendor/bin/phpunit --testsuite mod_edpreset_testsuite
```

CI (`.github/workflows/moodle-ci.yml`) runs the full `moodle-plugin-ci` set against Moodle 4.5 on PHP
8.1 and PostgreSQL 16: phplint, phpmd, phpcs, phpdoc, validate, savepoints, mustache lint, grunt,
PHPUnit and Behat.

## License

GNU GPL v3 or later — see [LICENSE](LICENSE).

## Author

Andrew Rowatt &lt;A.J.Rowatt@massey.ac.nz&gt;, Massey University.
