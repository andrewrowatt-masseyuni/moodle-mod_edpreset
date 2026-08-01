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

$string['chooser:addingprogress'] = 'Adding {$a->done} of {$a->total}';
$string['chooser:addselected'] = 'Add {$a} items to course';
$string['chooser:addselectednone'] = 'Add items to course';
$string['chooser:addselectedone'] = 'Add 1 item to course';
$string['chooser:addtocourse'] = 'Add to course';
$string['chooser:addtolist'] = 'Add to list';
$string['chooser:addtolistof'] = 'Add {$a} to the list';
$string['chooser:clearfilters'] = 'Clear filters';
$string['chooser:collapsesection'] = 'Collapse {$a}';
$string['chooser:expandsection'] = 'Expand {$a}';
$string['chooser:favourite'] = 'Star {$a}';
$string['chooser:filterbytag'] = 'Filter by {$a}';
$string['chooser:nopresets'] = 'There are no preset activities to show yet.';
$string['chooser:noresults'] = 'No preset activities match your filters.';
$string['chooser:pagetitle'] = 'Preset activities';
$string['chooser:placeholderhelp'] = 'Browse the full list of preset activities, grouped by category. You can filter them, star the ones you use often, and add several to your course at once.';
$string['chooser:placeholdertitle'] = 'Template';
$string['chooser:removefavourite'] = 'Unstar {$a}';
$string['chooser:removefromlist'] = 'Remove {$a} from the list';
$string['chooser:search'] = 'Search preset activities';
$string['chooser:searchplaceholder'] = 'Search by name, description or tag';
$string['chooser:sectioncount'] = '{$a} presets';
$string['chooser:sectioncountone'] = '1 preset';
$string['chooser:starred'] = 'Starred';
$string['chooser:tagsheading'] = 'Filter by tag';
$string['copyingactivity'] = 'Copying the activity into your course';
$string['copyinprogress'] = 'Another activity is already being added. Please wait for it to finish, then try again.';
$string['creatingactivity'] = 'Adding {$a}';
$string['invalidpreset'] = 'That preset activity is not available. It may have been removed, or its backup may be in the process of being rebuilt.';
$string['locktimeout'] = 'Another preset activity backup is in progress.';
$string['manage:actionsqueued'] = 'These run in the background, so nothing here happens instantly.';
$string['manage:cachecleared'] = 'Deleted the backups for {$a} presets and queued a rebuild. They will reappear in the chooser as each one is rebuilt and tested.';
$string['manage:clearcache'] = 'Delete all backups and rebuild';
$string['manage:clearcacheconfirm'] = 'Every preset will disappear from the activity chooser and reappear one at a time as its backup is rebuilt and tested. Continue?';
$string['manage:col:actions'] = 'Actions';
$string['manage:col:backup'] = 'Backup';
$string['manage:col:category'] = 'Category';
$string['manage:col:dates'] = 'Dates cleared';
$string['manage:col:preset'] = 'Preset';
$string['manage:col:status'] = 'Status';
$string['manage:col:type'] = 'Type';
$string['manage:gotosettings'] = 'Go to settings';
$string['manage:inchooser'] = 'In chooser';
$string['manage:nopresets'] = 'No presets yet. Add an activity to section 1 or above of the template course, fill in its "Preset details", then rescan.';
$string['manage:notconfigured'] = 'No template course is set, so no preset activities are offered.';
$string['manage:offeredcount'] = '{$a->live} of {$a->total} presets are currently offered in the activity chooser.';
$string['manage:rebake'] = 'Rebuild';
$string['manage:rebakequeued'] = 'A rebuild of "{$a}" has been queued.';
$string['manage:rebuild'] = 'Rescan template course';
$string['manage:rebuildqueued'] = 'A rescan has been queued. Presets will update as it runs.';
$string['manage:sandboxis'] = 'Backups are tested by restoring them into';
$string['manage:sandboxmissing'] = 'The restore test course ({$a}) does not exist yet; it will be created when the next backup is tested.';
$string['manage:sectionzeroignored'] = 'An activity becomes a preset once someone fills in its "Preset details" on its settings page. Only sections 1 and above are scanned, so section 0 can hold notes for whoever curates the course. Section 1 is offered in the activity chooser itself; everything above it is reached through the "Template" item there.';
$string['manage:suggestionsheading'] = 'Possible uncovered date fields';
$string['manage:suggestionsintro'] = 'These activity types have fields whose names look like dates but which are not in the built-in list, so they are not cleared. Some will be genuine dates; others will be settings or durations that must not be touched. Add the genuine ones under "Additional date fields" in the settings.';
$string['manage:tablecaption'] = 'Preset activities and their backup status';
$string['manage:templateis'] = 'Preset activities are taken from';
$string['managepresets'] = 'Manage preset activities';

$string['modulename'] = 'Activity preset provider';
$string['modulename_help'] = 'This is not an activity you add to a course. It supplies preset activities to the activity chooser, based on the exemplar activities held in a designated template course.';
$string['modulenameplural'] = 'Activity preset providers';
$string['novalidationarchive'] = 'There is no candidate backup to validate.';
$string['noviewpage'] = 'The activity preset provider has no view page.';
$string['pluginadministration'] = 'Activity preset provider administration';
$string['pluginname'] = 'Activity preset provider';
$string['presetdefaultname'] = 'Default activity name';
$string['presetdefaultname_help'] = 'The name given to the activity when a teacher adds this preset to their course. Leave blank to keep this exemplar\'s own name.';
$string['presetdefaultnametoolong'] = 'The default activity name must be {$a} characters or fewer.';
$string['presetdescription'] = 'Preset description';
$string['presetdescription_help'] = 'A teacher-facing explanation of what this preset is and when to use it. Plain text; Markdown formatting is allowed.';
$string['presetdetails'] = 'Preset details';
$string['presetdetails_desc'] = 'These are used to help guide the teacher.';
$string['presetdetails_help'] = 'These are used to help guide the teacher.

This activity is an exemplar in a preset template course. What you enter here is what teachers see when they choose it from the activity chooser or the preset list - it is separate from the activity name and description your students would see.';
$string['presetname'] = 'Preset name';
$string['presetname_help'] = 'The name teachers see when choosing this preset. Keep it short and say what the activity is for, not what it is called in this course.';
$string['presetnametoolong'] = 'The preset name must be {$a} characters or fewer.';
$string['presettags'] = 'Preset tags';
$string['presettags_help'] = 'A comma-separated list of words or short phrases, for example: Assessment, Engage with content, Content packages. Teachers can filter the preset list by these.';
$string['privacy:metadata:favourites'] = 'Preset activities a user has starred on the preset activities page.';
$string['privacy:metadata:preference:collapsed'] = 'Which groups of preset activities the user has collapsed on the preset activities page.';
$string['privacy:path:favourites'] = 'Starred preset activities';
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
$string['settings:templatecourseid'] = 'Template course ID';
$string['settings:templatecourseid_desc'] = 'The ID of the course holding the exemplar activities. An activity in sections 1 and above becomes a preset once its "Preset details" are filled in on its settings page, and the section name becomes its category. Section 0 is ignored, so it can be used for instructions to whoever curates the course.

Section 1 is the "priority" section: its presets appear in the activity chooser itself. Everything from section 2 onwards is reached through the "Template" item there.

Anyone who can edit this course controls what every teacher on the site sees in their activity chooser, so restrict its editing roles accordingly.';
$string['settings:templatecourseid_notfound'] = 'No course with ID {$a} exists.';
$string['settings:templatecourseid_notsandbox'] = 'The restore test course cannot be used as the template course; its contents are deleted before every validation.';
$string['settings:templatecourseid_notsite'] = 'The site home cannot be used as the template course.';
$string['status:baking'] = 'Building backup';
$string['status:failed'] = 'Failed';
$string['status:pending'] = 'Waiting to build';
$string['status:ready'] = 'Offered';
$string['status:validating'] = 'Testing restore';

$string['task:bakepreset'] = 'Build a preset activity backup';
$string['task:rebuildpresets'] = 'Rescan the preset template course';
$string['task:reconcilepresets'] = 'Rebuild preset activity backups';
$string['task:validatepreset'] = 'Test-restore a preset activity backup';




$string['validationnoinstance'] = 'The activity restored but left no {$a} record behind.';
