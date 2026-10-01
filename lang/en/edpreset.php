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

$string['activitiesadded'] = 'Added to your course: {$a}';
$string['activitiesnotadded'] = 'Could not be added: {$a}. Please try again, and ask your administrator to check the site logs if it keeps happening.';
$string['backupnoactivities'] = 'This site\'s import defaults leave activities out of a backup, so preset activities cannot be copied. An administrator can change this in the general import defaults for backups.';
$string['cannotcreateinstance'] = 'The activity preset provider cannot be added to a course. It exists only to supply preset activities to the activity chooser.';

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
$string['chooser:filterbysection'] = 'Filter by recommended section {$a}';
$string['chooser:filterbytag'] = 'Filter by {$a}';
$string['chooser:inreview'] = 'For review';
$string['chooser:lastused'] = 'This template was last used in the course.';
$string['chooser:lastusedrecommended'] = 'Recommended because you last used this template in the course.';
$string['chooser:nopresets'] = 'There are no preset activities to show yet.';
$string['chooser:noresults'] = 'No preset activities match your filters.';
$string['chooser:nosectiontemplates'] = 'There are no section templates to show yet.';
$string['chooser:pagetitle'] = 'Preset activities';
$string['chooser:placeholderhelp'] = 'Browse the full list of preset activities, grouped by category. You can filter them, star the ones you use often, and add several to your course at once. You can also apply a section template, which adds a whole set of activities at once.';
$string['chooser:placeholdertitle'] = 'Preset activity or section template';
$string['chooser:recommended'] = 'Recommended';
$string['chooser:recommendedsection'] = 'Recommended section';
$string['chooser:removefavourite'] = 'Unstar {$a}';
$string['chooser:removefromlist'] = 'Remove {$a} from the list';
$string['chooser:reviewtitle'] = '{$a} (for review)';
$string['chooser:search'] = 'Search preset activities';
$string['chooser:searchplaceholder'] = 'Search by name, description or tag';
$string['chooser:sectioncount'] = '{$a} presets';
$string['chooser:sectioncountone'] = '1 preset';
$string['chooser:sectiontemplates'] = 'Section templates';
$string['chooser:starred'] = 'Starred';
$string['chooser:tagsheading'] = 'Filter by tag';
$string['chooser:templatecount'] = '{$a} activities';
$string['chooser:templatetypesmore'] = '{$a} and more';
$string['copyingactivity'] = 'Copying the activity into your course';
$string['copyinprogress'] = 'Another activity is already being added. Please wait for it to finish, then try again.';
$string['creatingactivities'] = 'Adding {$a} activities';
$string['creatingactivity'] = 'Adding {$a}';
$string['creatingtemplate'] = 'Adding {$a}';
$string['customfield:category'] = 'Activity preset provider';
$string['customfield:defaultsectiontemplate'] = 'Default section template';
$string['customfield:defaultsectiontemplate_desc'] = 'This field is used by the Activity preset provider (mod_edpreset). Current default section template for this course. It will be blank if no section template has been applied. Administrators can clear the contents if a new or different template will be the default. It is not recommended to set the value directly.';
$string['edpreset:addinstance'] = 'Add a new Preset';
$string['edpreset:reviewpresets'] = 'See and add preset activities that are ready for review';
$string['exemplarmissing'] = 'The exemplar activity for this preset no longer exists in the template course.';
$string['invalidpreset'] = 'That preset activity is not available. It may have been removed or withdrawn.';
$string['manage:col:category'] = 'Category';
$string['manage:col:dates'] = 'Dates cleared on copy';
$string['manage:col:inchooser'] = 'In activity chooser';
$string['manage:col:lasterror'] = 'Last copy failure';
$string['manage:col:preset'] = 'Preset';
$string['manage:col:status'] = 'Status';
$string['manage:col:type'] = 'Type';
$string['manage:gotosettings'] = 'Go to settings';
$string['manage:nodatefields'] = 'None';
$string['manage:nopresets'] = 'No presets yet. Add an activity to section 1 or above of the template course, fill in its "Preset details", then rescan.';
$string['manage:notconfigured'] = 'No template course is set, so no preset activities are offered.';
$string['manage:offeredcount'] = '{$a->released} of {$a->total} presets are released to teachers.';
$string['manage:rebuild'] = 'Rescan template course';
$string['manage:rebuild_help'] = 'Updates the preset list from the template course straight away. This also happens on its own within a few minutes of a change to the template course, and overnight.';
$string['manage:rebuilt'] = 'Rescanned the template course: {$a->scanned} presets found, {$a->removed} removed.';
$string['manage:sectionzeroignored'] = 'An activity becomes a preset once someone fills in its "Preset details" on its settings page. Only sections 1 and above are scanned, so section 0 can hold notes for whoever curates the course. Every preset is on the preset activities page, reached through the "Preset activity or section template" item in the activity chooser; a preset set to "Show in activity chooser" is in the activity chooser itself as well.';
$string['manage:suggestionsheading'] = 'Possible uncovered date fields';
$string['manage:suggestionsintro'] = 'These activity types have fields whose names look like dates but which are not in the built-in list, so they are not cleared. Some will be genuine dates; others will be settings or durations that must not be touched. Add the genuine ones under "Additional date fields" in the settings.';
$string['manage:tablecaption'] = 'Preset activities and their release status';
$string['manage:templateis'] = 'Preset activities are taken from';
$string['managepresets'] = 'Manage preset activities';

$string['modulename'] = 'Activity preset provider';
$string['modulename_help'] = 'This is not an activity you add to a course. It supplies preset activities to the activity chooser, based on the exemplar activities held in a designated template course.';
$string['modulenameplural'] = 'Activity preset providers';
$string['noviewpage'] = 'The activity preset provider has no view page.';
$string['pluginadministration'] = 'Activity preset provider administration';
$string['pluginname'] = 'Activity preset provider';
$string['presetdefaultname'] = 'Default activity name';
$string['presetdefaultname_help'] = 'The name given to the activity when a teacher adds this preset to their course. Leave blank to keep this exemplar\'s own name.';
$string['presetdefaultnametoolong'] = 'The default activity name must be {$a} characters or fewer.';
$string['presetdescription'] = 'Preset description';
$string['presetdescription_help'] = 'A teacher-facing explanation of what this preset is and when to use it. Keep the formatting light - it is shown in a preset card, not on a page of its own.';
$string['presetdetails'] = 'Preset details';
$string['presetdetails_desc'] = 'These are used to help guide the teacher.';
$string['presetdetails_help'] = 'These are used to help guide the teacher.

This activity is an exemplar in a preset template course. What you enter here is what teachers see when they choose it from the activity chooser or the preset list - it is separate from the activity name and description your students would see.';
$string['presetname'] = 'Preset name';
$string['presetname_help'] = 'The name teachers see when choosing this preset. Keep it short and say what the activity is for, not what it is called in this course.';
$string['presetnametoolong'] = 'The preset name must be {$a} characters or fewer.';
$string['presetrecommendedsection'] = 'Recommended section';
$string['presetrecommendedsection_help'] = 'The section of a teacher\'s course this preset is meant for, for example: Nau mai | Welcome. Teachers can filter the preset list by it. It is a recommendation only - the preset can still be added to any section.';
$string['presetrecommendedsectiontoolong'] = 'The recommended section must be {$a} characters or fewer.';
$string['presetshowinchooser'] = 'Show in activity chooser';
$string['presetshowinchooser_help'] = 'Display at the end of other Moodle activities in the activity chooser.';
$string['presetstatus'] = 'Release status';
$string['presetstatus_help'] = 'Who is offered this preset. Draft and Archived: nobody. Ready for review: only people who can review presets. Released: every teacher. Teachers always receive the activity exactly as it is at the moment they add it, so to rework a released preset, duplicate this activity (the copy starts as a draft with these details), change the copy, then release it and archive this one.';
$string['presettags'] = 'Preset tags';
$string['presettags_help'] = 'A comma-separated list of words or short phrases, for example: Assessment, Engage with content, Content packages. Teachers can filter the preset list by these.';
$string['privacy:metadata:edpreset_item'] = 'The preset activities offered to teachers, derived from the exemplar activities in the template course. This includes the ID of the last user to save the preset.';
$string['privacy:metadata:edpreset_item:timecreated'] = 'The time the preset activity was first derived.';
$string['privacy:metadata:edpreset_item:timemodified'] = 'The time the preset activity was last rebuilt.';
$string['privacy:metadata:edpreset_item:usermodified'] = 'The ID of the user who last saved the preset activity.';
$string['privacy:metadata:edpreset_meta'] = 'The preset details a curator records against an exemplar activity in the template course. This includes the ID of the last user to save those details.';
$string['privacy:metadata:edpreset_meta:timecreated'] = 'The time the preset details were first recorded.';
$string['privacy:metadata:edpreset_meta:timemodified'] = 'The time the preset details were last saved.';
$string['privacy:metadata:edpreset_meta:usermodified'] = 'The ID of the user who last saved the preset details.';
$string['privacy:metadata:favourites'] = 'Preset activities a user has starred on the preset activities page.';
$string['privacy:metadata:preference:collapsed'] = 'Which groups of preset activities the user has collapsed on the preset activities page.';
$string['privacy:path:authored'] = 'Preset activities last saved by this user';
$string['privacy:path:favourites'] = 'Starred preset activities';
$string['reorder:confirm'] = 'Add to course';
$string['reorder:existingpill'] = 'Existing';
$string['reorder:existingpill_help'] = 'This activity is from your course';
$string['reorder:instructions'] = 'These activities will be added to your section. Drag the activities already in your course from the right onto the left, and drop them above, below or between the template activities to set the order. The template activities stay in the order shown. Anything you leave on the right is added below the template.';
$string['reorder:move'] = 'Move {$a}';
$string['reorder:sourceempty'] = 'There is nothing else in this section.';
$string['reorder:sourceheading'] = 'Already in your course';
$string['reorder:targetheading'] = 'Your section will look like this';
$string['reorder:templatepill'] = 'Template';
$string['reorder:templatepill_help'] = 'This activity is from the template';
$string['restoreprecheckfailed'] = 'The activity could not be restored: {$a}';
$string['restorewrongactivitycount'] = 'The backup contained {$a} activities; exactly one was expected.';
$string['section:applytemplate'] = 'Apply template';
$string['settings:datefields'] = 'Additional date fields';
$string['settings:datefields_desc'] = 'Dates are cleared from every copied activity using a built-in list of fields per activity type. Use this to cover an activity type the list does not know about, one per line, as "activityname: field, field".

Fields are matched by name and set to zero. Deliberately conservative: a name that merely looks like a date is often a setting or a duration, and clearing one of those would silently change what the preset does. The fields cleared for each preset are shown on the preset management page.';
$string['settings:enabled'] = 'Enable preset activities';
$string['settings:enabled_desc'] = 'Offer the exemplar activities from the template course in every course\'s activity chooser. When disabled, no presets are offered.';
$string['settings:ignoreinvalidtemplate'] = 'Ignore previously selected templates that are now invalid.';
$string['settings:ignoreinvalidtemplate_desc'] = 'If selected, then if a section template previously selected is no longer available (i.e., deleted or renamed) then automatically override the <em>Prevent mixing of templates</em> setting above.';
$string['settings:maxpresets'] = 'Maximum presets';
$string['settings:maxpresets_desc'] = 'The most presets that will be offered at once. Every chooser item is sent to the browser with its full description, so a very large template course makes the chooser slow to open.';
$string['settings:preventmixing'] = 'Prevent mixing of templates';
$string['settings:preventmixing_desc'] = 'Courses will be locked into using only a previously selected template. This can be overridden by Stream support. If a section template has never been used, all templates will be available to be selected.';
$string['settings:templatecourseid'] = 'Template course ID';
$string['settings:templatecourseid_desc'] = 'The ID of the course holding the exemplar activities. An activity in sections 1 and above becomes a preset once its "Preset details" are filled in on its settings page, and the section name becomes its category. Section 0 is ignored, so it can be used for instructions to whoever curates the course.

Every preset is offered on the preset activities page, reached through the "Preset activity or section template" item in the activity chooser. A preset whose details say "Show in activity chooser" appears in the activity chooser itself as well.

Anyone who can edit this course controls what every teacher on the site sees in their activity chooser, so restrict its editing roles accordingly.';
$string['settings:templatecourseid_notfound'] = 'No course with ID {$a} exists.';
$string['settings:templatecourseid_notsite'] = 'The site home cannot be used as the template course.';
$string['settings:templateheading'] = 'Section templates';
$string['settings:templateheading_desc'] = 'A section of the template course whose name ends in "[Template]" is offered as a single card that adds its whole set of activities at once. Its activities still need their own "Preset details", and are not offered individually.

End the name in "[Template,restricted]" instead to offer the template only to courses that have already used it, and to users who can manage activities at site level or in the course\'s top-level category. Anyone else does not see it at all.';

$string['status:archived'] = 'Archived';
$string['status:draft'] = 'Draft';
$string['status:released'] = 'Released';
$string['status:review'] = 'Ready for review';
$string['task:rebuildpresets'] = 'Rescan the preset template course';
$string['task:reconcilepresets'] = 'Rescan the preset template course';
$string['templatelocked'] = 'You cannot select this template because a different template has already been used in the course. Contact Stream support if you need to resolve this.';
$string['templaterestricted'] = 'This section template is restricted to courses that already use it. Contact Stream support if you need to use it in this course.';
$string['toomanypositions'] = 'No more than {$a} activities can be reordered at once.';
$string['toomanypresets'] = 'No more than {$a} preset activities can be added at once.';
