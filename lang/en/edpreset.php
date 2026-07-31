<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for the activity preset provider.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activityaddednotice'] = '{$a} has been added to your course. If you cancel on this page, the activity will still be there - delete it from the course page if you do not want it.';
$string['backupproducednofile'] = 'The backup completed but produced no file.';
$string['backupstale'] = 'The stored backup for this preset activity is missing or out of date. It will be rebuilt automatically; please try again shortly.';
$string['backuptoolarge'] = 'The backup is {$a}, which exceeds the configured maximum. This preset will not be offered.';
$string['cannotcreateinstance'] = 'The activity preset provider cannot be added to a course. It exists only to supply preset activities to the activity chooser.';
$string['category'] = 'Category: {$a}';

$string['copyingactivity'] = 'Copying the activity into your course';
$string['copyinprogress'] = 'Another activity is already being added. Please wait for it to finish, then try again.';
$string['creatingactivity'] = 'Adding {$a}';
$string['invalidpreset'] = 'That preset activity is not available. It may have been removed, or its backup may be in the process of being rebuilt.';
$string['managepresets'] = 'Manage preset activities';
$string['manage:templateis'] = 'Preset activities are taken from';
$string['manage:sectionzeroignored'] = 'Activities in sections 1 and above become presets; section 0 is ignored, so it can hold notes for whoever curates the course.';
$string['manage:notconfigured'] = 'No template course is set, so no preset activities are offered.';
$string['manage:gotosettings'] = 'Go to settings';
$string['manage:rebuild'] = 'Rescan template course';
$string['manage:rebuildqueued'] = 'A rescan has been queued. Presets will update as it runs.';
$string['manage:clearcache'] = 'Delete all backups and rebuild';
$string['manage:clearcacheconfirm'] = 'Every preset will disappear from the activity chooser and reappear one at a time as its backup is rebuilt and tested. Continue?';
$string['manage:cachecleared'] = 'Deleted the backups for {$a} presets and queued a rebuild. They will reappear in the chooser as each one is rebuilt and tested.';
$string['manage:rebake'] = 'Rebuild';
$string['manage:rebakequeued'] = 'A rebuild of "{$a}" has been queued.';
$string['manage:actionsqueued'] = 'These run in the background, so nothing here happens instantly.';
$string['manage:offeredcount'] = '{$a->live} of {$a->total} presets are currently offered in the activity chooser.';
$string['manage:sandboxis'] = 'Backups are tested by restoring them into';
$string['manage:sandboxmissing'] = 'The restore test course ({$a}) does not exist yet; it will be created when the next backup is tested.';
$string['manage:nopresets'] = 'No presets yet. Add an activity to section 1 or below of the template course, then rescan.';
$string['manage:inchooser'] = 'In chooser';
$string['manage:tablecaption'] = 'Preset activities and their backup status';
$string['manage:col:preset'] = 'Preset';
$string['manage:col:type'] = 'Type';
$string['manage:col:category'] = 'Category';
$string['manage:col:status'] = 'Status';
$string['manage:col:dates'] = 'Dates cleared';
$string['manage:col:backup'] = 'Backup';
$string['manage:col:actions'] = 'Actions';
$string['manage:suggestionsheading'] = 'Possible uncovered date fields';
$string['manage:suggestionsintro'] = 'These activity types have fields whose names look like dates but which are not in the built-in list, so they are not cleared. Some will be genuine dates; others will be settings or durations that must not be touched. Add the genuine ones under "Additional date fields" in the settings.';

$string['status:pending'] = 'Waiting to build';
$string['status:baking'] = 'Building backup';
$string['status:validating'] = 'Testing restore';
$string['status:ready'] = 'Offered';
$string['status:failed'] = 'Failed';

$string['task:bakepreset'] = 'Build a preset activity backup';
$string['task:validatepreset'] = 'Test-restore a preset activity backup';
$string['task:rebuildpresets'] = 'Rescan the preset template course';
$string['task:reconcilepresets'] = 'Rebuild preset activity backups';
$string['locktimeout'] = 'Another preset activity backup is in progress.';

$string['modulename'] = 'Activity preset provider';
$string['modulename_help'] = 'This is not an activity you add to a course. It supplies preset activities to the activity chooser, based on the exemplar activities held in a designated template course.';
$string['modulenameplural'] = 'Activity preset providers';
$string['novalidationarchive'] = 'There is no candidate backup to validate.';
$string['noviewpage'] = 'The activity preset provider has no view page.';
$string['pluginadministration'] = 'Activity preset provider administration';
$string['pluginname'] = 'Activity preset provider';
$string['privacy:metadata'] = 'The activity preset provider plugin does not store any personal data. It stores backups of exemplar activities taken from a template course.';
$string['restoreprecheckfailed'] = 'The activity could not be restored: {$a}';
$string['restorewrongactivitycount'] = 'The backup contained {$a} activities; exactly one was expected.';


$string['sandboxfullname'] = 'Activity preset restore test (do not use)';
$string['sandboxsummary'] = 'This hidden course is used to test-restore preset activity backups before they are offered in the activity chooser. Its contents are deleted automatically before every test - do not put anything here. If you delete this course it will be recreated automatically.';
$string['scrubbrokerestore'] = 'None - clearing dates prevented this activity from restoring, so the original backup is used instead.';
$string['scrubextractfailed'] = 'The backup archive could not be extracted for rewriting.';
$string['scrubfailed'] = 'None - the backup could not be rewritten.';
$string['scrubnothingtodo'] = 'None - this module has no dates in the supported list.';
$string['scrubrepackfailed'] = 'The rewritten backup archive could not be repacked.';
$string['scrubwritefailed'] = 'Could not write the rewritten backup file {$a}.';
$string['settings:datefields'] = 'Additional date fields';
$string['settings:datefields_desc'] = 'Dates are cleared from a preset\'s backup using a built-in list of fields per activity type. Use this to cover an activity type the list does not know about, one per line, as "activityname: field, field".

Fields are matched by name and set to zero. Deliberately conservative: a name that merely looks like a date is often a setting or a duration, and clearing one of those would silently change what the preset does. Anything cleared here is shown on the preset management page, and if clearing a field stops the activity restoring, the original backup is published instead.';
$string['settings:enabled'] = 'Enable preset activities';
$string['settings:enabled_desc'] = 'Offer the exemplar activities from the template course in every course\'s activity chooser. When disabled, no presets are offered and no backups are taken.';
$string['settings:maxbackupsize'] = 'Maximum backup size (bytes)';
$string['settings:maxbackupsize_desc'] = 'Exemplars whose backup exceeds this size are not offered as presets. Set to 0 for no limit. A large package - a SCORM or H5P file, for instance - makes every copy slow.';
$string['settings:maxpresets'] = 'Maximum presets';
$string['settings:maxpresets_desc'] = 'The most presets that will be offered at once. Every chooser item is sent to the browser with its full description, so a very large template course makes the chooser slow to open.';
$string['settings:sandboxcategoryid'] = 'Restore test course category';
$string['settings:sandboxcategoryid_desc'] = 'The category the restore test course is created in, if it does not already exist.';
$string['settings:sandboxheading'] = 'Restore testing';
$string['settings:sandboxheading_desc'] = 'Every backup is test-restored into a hidden course before it is offered in the activity chooser, so a preset that cannot be restored is never shown to teachers. The course is created automatically, its contents are deleted before each test, and deleting it is safe - it will be recreated.';
$string['settings:sandboxshortname'] = 'Restore test course short name';
$string['settings:sandboxshortname_desc'] = 'The short name of the hidden course used for test restores. It is looked up by this name, so the course can be deleted and will simply be recreated.';
$string['settings:showcategoryinhelp'] = 'Show category in preset description';
$string['settings:showcategoryinhelp_desc'] = 'Include the template course section name in each preset\'s description. This also makes the category searchable, because the activity chooser\'s search matches descriptions as well as titles.';
$string['settings:templatecourseid'] = 'Template course ID';
$string['settings:templatecourseid_desc'] = 'The ID of the course holding the exemplar activities. Each activity in sections 1 and above becomes a preset, and the section name becomes its category. Section 0 is ignored, so it can be used for instructions to whoever curates the course.

Anyone who can edit this course controls what every teacher on the site sees in their activity chooser, so restrict its editing roles accordingly.';
$string['settings:templatecourseid_notfound'] = 'No course with ID {$a} exists.';
$string['settings:templatecourseid_notsandbox'] = 'The restore test course cannot be used as the template course; its contents are deleted before every validation.';
$string['settings:templatecourseid_notsite'] = 'The site home cannot be used as the template course.';
$string['validationnoinstance'] = 'The activity restored but left no {$a} record behind.';
