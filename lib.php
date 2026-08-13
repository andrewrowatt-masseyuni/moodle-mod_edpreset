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
 * Library functions for the activity preset provider.
 *
 * This module is not an activity in the usual sense. It is never added to a course; it exists
 * solely so that pseudo activities can be contributed to the activity chooser. Core requires
 * chooser content items to originate from a mod_* component (see
 * \core_course\local\service\content_item_service::get_content_items_for_user_in_course), and
 * implementing mod_edpreset_get_course_content_items() causes core to discard this module's own
 * default chooser item, so "Activity preset provider" never appears in the chooser itself.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return the features this module supports.
 *
 * MOD_ARCHETYPE_SYSTEM keeps the provider out of the admin module pickers built by
 * admin_setting_configmultiselect_modules() and suppresses the missing-capability debugging
 * notice in course_allowed_module(). It does not affect which chooser tab a preset lands in:
 * that is driven by each content item's own archetype, which is copied from the exemplar.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false for supported features, null for unknown ones.
 */
function edpreset_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_SYSTEM,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ADMINISTRATION,
        FEATURE_NO_VIEW_LINK => true,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_MOD_INTRO => false,
        FEATURE_COMPLETION_TRACKS_VIEWS => false,
        FEATURE_COMPLETION_HAS_RULES => false,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_SHOW_DESCRIPTION => false,
        FEATURE_IDNUMBER => false,
        default => null,
    };
}

/**
 * Refuse to create an instance.
 *
 * Teachers hold mod/edpreset:addinstance (that capability is the per-course gate on whether
 * presets are offered), which means /course/modedit.php?add=edpreset is reachable by hand. This
 * is the guard that stops it producing anything.
 *
 * @param stdClass $data Form data.
 * @param mod_edpreset_mod_form|null $mform The form.
 * @return int Never returns.
 * @throws moodle_exception Always.
 */
function edpreset_add_instance($data, $mform = null) {
    throw new moodle_exception('cannotcreateinstance', 'mod_edpreset');
}

/**
 * Refuse to update an instance. See edpreset_add_instance().
 *
 * @param stdClass $data Form data.
 * @param mod_edpreset_mod_form|null $mform The form.
 * @return bool Never returns.
 * @throws moodle_exception Always.
 */
function edpreset_update_instance($data, $mform = null) {
    throw new moodle_exception('cannotcreateinstance', 'mod_edpreset');
}

/**
 * Delete an instance.
 *
 * No instance can exist, but course_delete_module() calls this unconditionally, so it must be
 * present and must succeed.
 *
 * @param int $id Instance id.
 * @return bool Always true.
 */
function edpreset_delete_instance($id) {
    return true;
}

/**
 * Reset user data.
 *
 * Unreachable in practice, but its absence would list this module under "reset not implemented"
 * on the course reset page.
 *
 * @param stdClass $data Reset form data.
 * @return array Always empty.
 */
function edpreset_reset_userdata($data) {
    return [];
}

/**
 * Contribute the pseudo activities to a course's activity chooser.
 *
 * Implementing this callback makes core discard the default chooser item it built for this module
 * (see \core_course\local\repository\content_item_readonly_repository::find_all_for_course), which
 * is why "Activity preset provider" never appears in the chooser itself.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The discarded default item.
 * @param stdClass $user The user the chooser is being built for.
 * @param stdClass $course The course whose chooser is being built.
 * @return \core_course\local\entity\content_item[]
 */
function mod_edpreset_get_course_content_items($defaultmodulecontentitem, $user, $course) {
    return \mod_edpreset\local\chooser::get_content_items($course, $user);
}

/**
 * Contribute the pseudo activities where no course context is available.
 *
 * This must be implemented alongside mod_edpreset_get_course_content_items(). Core's
 * content_item_service::add_to_user_favourites() resolves the favourited item by running
 * array_search() over the ids returned here, so a preset missing from this list would make
 * favouriting silently return a different item.
 *
 * It also makes presets available on the "Recommend activities" admin page.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The discarded default item.
 * @return \core_course\local\entity\content_item[]
 */
function mod_edpreset_get_all_content_items($defaultmodulecontentitem) {
    return \mod_edpreset\local\chooser::get_all_content_items();
}

/**
 * The form elements that make up the "Preset details" group, in the order they are shown.
 *
 * Every name is edpreset_ prefixed, and that is load-bearing rather than tidy:
 * standard_coursemodule_elements() adds elements named 'name', 'intro' and 'tags' before this
 * plugin's callback runs, and HTML_QuickForm silently drops an incompatible duplicate.
 *
 * @return array<string, string> Element name => lang string key.
 */
function edpreset_get_form_fieldmap(): array {
    return [
        'edpreset_presetname' => 'presetname',
        'edpreset_description' => 'presetdescription',
        'edpreset_teacherguidance' => 'presetteacherguidance',
        'edpreset_tags' => 'presettags',
        'edpreset_defaultname' => 'presetdefaultname',
    ];
}

/**
 * The options the curator's two rich text fields are created with.
 *
 * maxfiles is 0, which is also the element's own default, but it is stated because it is
 * load-bearing rather than incidental: this plugin implements no pluginfile callback by design, so
 * there is no URL that could ever serve a file embedded here, and the text is read by every teacher
 * on the site rather than only by whoever can reach the template course. Curators who want a picture
 * should link to one that is already served somewhere.
 *
 * @param int $courseid The template course the form is being built in.
 * @return array
 */
function edpreset_get_editor_options(int $courseid): array {
    return [
        'maxfiles' => 0,
        // The course rather than the module: the same options are used for an activity that does
        // not exist yet, which has no context of its own.
        'context' => \context_course::instance($courseid),
    ];
}

/**
 * Unpack the value of one of the rich text fields.
 *
 * An editor element submits text, format and itemid as an array, but these callbacks are also
 * reached with a plain string - a web service call, a restore, or a caller assembling the module
 * info by hand - so both shapes have to be understood. A string is taken to be HTML, which is what
 * the editor produces.
 *
 * @param mixed $value The submitted value, either an editor array or a plain string.
 * @return array{0: string, 1: int} The text, and the format to render it with.
 */
function edpreset_unpack_editor($value): array {
    if (is_array($value)) {
        return [(string)($value['text'] ?? ''), (int)($value['format'] ?? FORMAT_HTML)];
    }

    // Cast because core defines the FORMAT_* constants as strings, and the caller stores this in a
    // PARAM_INT property.
    return [(string)$value, (int)FORMAT_HTML];
}

/**
 * Whether an activity's settings form should ask for preset details.
 *
 * @param moodleform_mod $formwrapper The form wrapper.
 * @return bool
 */
function edpreset_form_wants_details($formwrapper): bool {
    if (!\mod_edpreset\local\template::is_enabled()) {
        return false;
    }

    $course = $formwrapper->get_course();
    if (!$course || !\mod_edpreset\local\template::is_template_course((int)$course->id)) {
        return false;
    }

    $current = $formwrapper->get_current();
    if (empty($current->modulename)) {
        return false;
    }

    // Section 0 holds instructions for whoever curates the template course, so its contents are
    // documentation rather than exemplars and must not be made to supply preset details.
    $section = (int)($current->section ?? 0);
    if ($section < \mod_edpreset\local\template::first_scanned_section()) {
        return false;
    }

    // A module that could never be baked into a preset should not be asked to describe itself as
    // one. Subsections and modules without backup support are the two cases.
    return \mod_edpreset\local\baker::modname_is_scannable($current->modulename);
}

/**
 * Add the "Preset details" group to activities in a template course.
 *
 * @param moodleform_mod $formwrapper The form wrapper.
 * @param MoodleQuickForm $mform The form itself.
 */
function mod_edpreset_coursemodule_standard_elements($formwrapper, $mform) {
    if (!edpreset_form_wants_details($formwrapper)) {
        return;
    }

    // The group belongs above the activity name, which means splicing it in rather than appending.
    // mod_hvp adds 'name' as a hidden element before its first header, so inserting there would
    // strand the group outside every fieldset - leave those forms alone.
    if (!$mform->elementExists('name') || $mform->getElement('name')->getType() === 'hidden') {
        return;
    }

    // Note that get_course() returns the course record, whatever core's phpdoc on it says.
    $editoroptions = edpreset_get_editor_options((int)$formwrapper->get_course()->id);

    $elements = [
        'edpreset_detailsheading' => $mform->createElement(
            'static',
            'edpreset_detailsheading',
            get_string('presetdetails', 'mod_edpreset'),
            html_writer::div(get_string('presetdetails_help', 'mod_edpreset'), 'text-muted small')
        ),
        'edpreset_presetname' => $mform->createElement(
            'text',
            'edpreset_presetname',
            get_string('presetname', 'mod_edpreset')
            . \html_writer::span(
                get_string('presetname_help', 'mod_edpreset'),
                'edpreset-field-desc d-block small text-muted fw-normal'
            ),
            ['maxlength' => \mod_edpreset\meta::PRESETNAME_MAXLENGTH, 'size' => 60]
        ),
        'edpreset_description' => $mform->createElement(
            'editor',
            'edpreset_description',
            get_string('presetdescription', 'mod_edpreset')
            . \html_writer::span(
                get_string('presetdescription_help', 'mod_edpreset'),
                'edpreset-field-desc d-block small text-muted fw-normal'
            ),
            ['rows' => 5],
            $editoroptions
        ),
        'edpreset_teacherguidance' => $mform->createElement(
            'editor',
            'edpreset_teacherguidance',
            get_string('presetteacherguidance', 'mod_edpreset')
            . \html_writer::span(
                get_string('presetteacherguidance_help', 'mod_edpreset'),
                'edpreset-field-desc d-block small text-muted fw-normal'
            ),
            ['rows' => 8],
            $editoroptions
        ),
        'edpreset_tags' => $mform->createElement(
            'text',
            'edpreset_tags',
            get_string('presettags', 'mod_edpreset')
            . \html_writer::span(
                get_string('presettags_help', 'mod_edpreset'),
                'edpreset-field-desc d-block small text-muted fw-normal'
            ),
            ['size' => 60]
        ),
        'edpreset_defaultname' => $mform->createElement(
            'text',
            'edpreset_defaultname',
            get_string('presetdefaultname', 'mod_edpreset')
            . \html_writer::span(
                get_string('presetdefaultname_help', 'mod_edpreset'),
                'edpreset-field-desc d-block small text-muted fw-normal'
            ),
            ['maxlength' => \mod_edpreset\meta::DEFAULTNAME_MAXLENGTH, 'size' => 60]
        ),
    ];

    // Only the first and last rows carry the border's top and bottom edges.
    $edgeclasses = [
        'edpreset_detailsheading' => ' edpreset-detail-first',
        'edpreset_defaultname' => ' edpreset-detail-last',
    ];

    foreach (array_keys($elements) as $elementname) {
        // These classes end up on the row wrapper, which is how the group's border is drawn across
        // a run of otherwise unrelated form rows (see styles.css).
        //
        // Set as 'class', which templatable_form_element exports as 'extraclasses', rather than as
        // the more obviously-named 'parentclass': core's core_form/element-template renders both,
        // but theme_snap's overriding template renders only extraclasses, so parentclass would
        // leave the group unstyled on that theme with nothing to show for it. 'class' is not
        // rendered on the input itself - export_for_template_base excludes it from the element's
        // own attributes - so this is safe for every element type here.
        //
        // It is set here rather than in the constructors because HTML_QuickForm_static takes no
        // attributes argument at all.
        $elements[$elementname]->updateAttributes([
            'class' => 'edpreset-detail' . ($edgeclasses[$elementname] ?? ''),
        ]);

        // Two things about this call, both silent when wrong:
        // - insertElementBefore() takes its element BY REFERENCE and stores that reference in the
        // form ($this->_elements[$idx] =& $element). It must therefore be handed an array slot
        // that is never written again, not a loop variable: a loop variable is reassigned on the
        // next iteration, which re-points every slot already inserted at the last element, and
        // the whole group renders as five copies of "Default activity name".
        // - It inserts immediately before 'name', so forward order is what produces the intended
        // layout. Inserting in reverse would reverse the group.
        $mform->insertElementBefore($elements[$elementname], 'name');
    }

    $mform->setType('edpreset_presetname', PARAM_TEXT);
    // PARAM_RAW because the field is the rich text editor's HTML: anything narrower strips the
    // markup the curator just wrote. It is cleaned once, at bake time, by
    // format_text(..., ['noclean' => false]) - the same point at which it becomes visible to
    // anyone outside this course. The element registers the types of its own [format] and [itemid]
    // keys when it is created, so only [text] is left to declare, and setType() on the element name
    // is how core does that for every other editor on the site.
    $mform->setType('edpreset_description', PARAM_RAW);
    // The editor's HTML too, and cleaned at the same point, for the same reason.
    $mform->setType('edpreset_teacherguidance', PARAM_RAW);
    $mform->setType('edpreset_tags', PARAM_TEXT);
    $mform->setType('edpreset_defaultname', PARAM_TEXT);

    $mform->addRule('edpreset_presetname', get_string('required'), 'required', null, 'client');
    // MoodleQuickForm_Rule_Required understands an editor's array value, and formslib appends
    // [text] when it builds the client-side check, so this needs no special handling here. What it
    // will not catch on a site with $CFG->strictformsrequired off is the empty paragraph an editor
    // leaves behind - mod_edpreset_coursemodule_validation() is what rejects that.
    $mform->addRule('edpreset_description', get_string('required'), 'required', null, 'client');

    // Loaded here rather than in data_preprocessing(), which a plugin callback cannot reach.
    // definition() runs before set_data(), and mform keeps the defaults of any element the
    // submitted data does not mention.
    $cmid = (int)($formwrapper->get_current()->coursemodule ?? 0);
    $meta = $cmid ? \mod_edpreset\meta::get_for_cm($cmid) : null;
    if ($meta) {
        $mform->setDefault('edpreset_presetname', $meta->get('presetname'));
        // An editor takes its value as an array. No itemid: maxfiles is 0, so there is no draft
        // area to point at.
        $mform->setDefault('edpreset_description', [
            'text' => $meta->get('description'),
            'format' => (int)$meta->get('descriptionformat'),
        ]);
        $mform->setDefault('edpreset_teacherguidance', [
            'text' => $meta->get('teacherguidance'),
            'format' => (int)$meta->get('teacherguidanceformat'),
        ]);
        $mform->setDefault('edpreset_tags', $meta->get('tags'));
        $mform->setDefault('edpreset_defaultname', $meta->get('defaultname'));
    }
}

/**
 * Validate the preset details.
 *
 * addRule() alone is not enough: a module can be created by web service or restore without the
 * form ever being submitted, and this is also where the required-ness of a field that only exists
 * in template courses can be expressed without touching every module's own validation.
 *
 * @param moodleform_mod $formwrapper The form wrapper.
 * @param array $data The submitted data.
 * @return array Errors keyed by element name.
 */
function mod_edpreset_coursemodule_validation($formwrapper, $data) {
    if (!edpreset_form_wants_details($formwrapper)) {
        return [];
    }

    $errors = [];

    $presetname = trim((string)($data['edpreset_presetname'] ?? ''));
    if ($presetname === '') {
        $errors['edpreset_presetname'] = get_string('required');
    } else if (\core_text::strlen($presetname) > \mod_edpreset\meta::PRESETNAME_MAXLENGTH) {
        $errors['edpreset_presetname'] = get_string(
            'presetnametoolong',
            'mod_edpreset',
            \mod_edpreset\meta::PRESETNAME_MAXLENGTH
        );
    }

    // Blankness is html_is_blank() rather than trim(): an editor the curator typed into and then
    // emptied submits "<p></p>" or "<p><br></p>", neither of which is an empty string and both of
    // which would otherwise pass as a description. It keeps img and the other tags that are content
    // in themselves, so a description that is only a picture still counts as filled in.
    [$description] = edpreset_unpack_editor($data['edpreset_description'] ?? '');
    if (html_is_blank($description)) {
        $errors['edpreset_description'] = get_string('required');
    }

    $defaultname = trim((string)($data['edpreset_defaultname'] ?? ''));
    if (\core_text::strlen($defaultname) > \mod_edpreset\meta::DEFAULTNAME_MAXLENGTH) {
        $errors['edpreset_defaultname'] = get_string(
            'presetdefaultnametoolong',
            'mod_edpreset',
            \mod_edpreset\meta::DEFAULTNAME_MAXLENGTH
        );
    }

    return $errors;
}

/**
 * Store the preset details after a module in a template course is created or updated.
 *
 * @param stdClass $moduleinfo The module info, carrying the submitted form fields.
 * @param stdClass $course The course the module is in.
 * @return stdClass The module info, which core reassigns from the return value.
 */
function mod_edpreset_coursemodule_edit_post_actions($moduleinfo, $course) {
    if (!\mod_edpreset\local\template::is_enabled()) {
        return $moduleinfo;
    }
    if (!\mod_edpreset\local\template::is_template_course((int)$course->id)) {
        return $moduleinfo;
    }
    // The fields are absent whenever the module was not created through the settings form -
    // a web service call, a restore, or a form this plugin declined to extend.
    if (!isset($moduleinfo->edpreset_presetname)) {
        return $moduleinfo;
    }

    $cmid = (int)$moduleinfo->coursemodule;
    $meta = \mod_edpreset\meta::get_for_cm($cmid) ?? new \mod_edpreset\meta();

    [$description, $descriptionformat] = edpreset_unpack_editor($moduleinfo->edpreset_description ?? '');
    [$guidance, $guidanceformat] = edpreset_unpack_editor($moduleinfo->edpreset_teacherguidance ?? '');

    $meta->set('cmid', $cmid);
    $meta->set('presetname', trim((string)$moduleinfo->edpreset_presetname));
    $meta->set('description', trim($description));
    $meta->set('descriptionformat', $descriptionformat);
    // Normalised to '' here rather than stored as the "<p></p>" an emptied editor submits, because
    // "has guidance" is a plain emptiness test in the baker, in the copier's emit_note() and in
    // mod_ednote. An empty paragraph would make every preset emit an empty teacher note.
    $meta->set('teacherguidance', html_is_blank($guidance) ? '' : trim($guidance));
    $meta->set('teacherguidanceformat', $guidanceformat);
    $meta->set('tags', \mod_edpreset\meta::normalise_tags((string)($moduleinfo->edpreset_tags ?? '')));
    $meta->set('defaultname', trim((string)($moduleinfo->edpreset_defaultname ?? '')));

    if ($meta->get('id')) {
        $meta->update();
    } else {
        $meta->create();
    }

    return $moduleinfo;
}

/**
 * Declare the user preferences this plugin writes.
 *
 * Without this, core_user::get_preference_definition() throws and the AJAX write from the preset
 * chooser page is rejected. Note the frankenstyle function name.
 *
 * @return array
 */
function mod_edpreset_user_preferences(): array {
    return [
        \mod_edpreset\output\chooser_page::PREF_COLLAPSED => [
            'type' => PARAM_RAW,
            'null' => NULL_NOT_ALLOWED,
            'default' => '',
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
