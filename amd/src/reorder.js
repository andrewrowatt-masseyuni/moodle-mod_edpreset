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
 * Deciding where a section template's activities go when the section is not empty.
 *
 * Nothing here adds anything. The dialogue only works out an order, writes it into the page's own
 * form and submits it, so a template added through the dialogue and one added by following the
 * card's link reach copy.php by exactly the same route.
 *
 * @module     mod_edpreset/reorder
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import Notification from 'core/notification';
import Pending from 'core/pending';
import SortableList from 'core/sortable_list';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    ADDFORM: '[data-region="addform"]',
    ITEM: '.edpreset-reorder-item',
    ORDER: '[data-region="order"]',
    PRESETS: '[data-region="presets"]',
    SOURCELIST: '[data-region="sourcelist"]',
    TARGETLIST: '[data-region="targetlist"]',
    TEMPLATE: '[data-region="template"]',
};

/** @var {Boolean} Whether the lists have been made sortable. See makeSortable(). */
let sortableInitialised = false;

/**
 * The tokens in a list, top to bottom.
 *
 * @param {HTMLElement} list The list element.
 * @returns {String[]}
 */
const tokensIn = (list) => Array.from(list.querySelectorAll(SELECTORS.ITEM)).map((item) => item.dataset.token);

/**
 * Let the two lists exchange items.
 *
 * Constructed from a selector string rather than from the elements themselves, which is what makes
 * this a one-off: given a string, core/sortable_list delegates its mousedown listener from body, so
 * it picks up the lists in every dialogue opened afterwards. That matters because the modal's markup
 * does not exist yet when this first runs, and core/modal re-attaches its root to the document -
 * either of which would strand a listener bound to a node.
 *
 * The one-time guard is therefore not an optimisation: registering twice would give every drag two
 * handlers.
 *
 * autoScroll is off because the base class scrolls the page, whereas the list that needs scrolling
 * here is inside the modal body.
 */
const makeSortable = () => {
    if (sortableInitialised) {
        return;
    }
    sortableInitialised = true;

    const lists = `${SELECTORS.TARGETLIST}, ${SELECTORS.SOURCELIST}`;

    new SortableList(lists, {
        targetListSelector: lists,
        autoScroll: false,
    });
};

/**
 * Post the chosen order through the page's own form.
 *
 * The presets field is cleared because copy.php takes a preset list or a template, never both.
 *
 * @param {HTMLElement} root The chooser root.
 * @param {Number} templatesection The template's section number in the template course.
 * @param {String[]} order The tokens, in the order the section should end up in.
 */
const submitOrder = (root, templatesection, order) => {
    const form = root.querySelector(SELECTORS.ADDFORM);

    form.querySelector(SELECTORS.PRESETS).value = '';
    form.querySelector(SELECTORS.TEMPLATE).value = templatesection;
    form.querySelector(SELECTORS.ORDER).value = order.join(',');

    form.submit();
};

/**
 * Ask where a section template's activities should go, then add them.
 *
 * @param {HTMLElement} root The chooser root.
 * @param {Number} templatesection The template's section number in the template course.
 * @param {String} templatetitle The template's name, used as the dialogue title.
 * @returns {Promise}
 */
export const open = async(root, templatesection, templatetitle) => {
    const pending = new Pending('mod_edpreset/reorder:open');
    const form = root.querySelector(SELECTORS.ADDFORM);

    try {
        // Read fresh rather than shipped with the page: the page may have been open a while, and
        // what the teacher has to arrange is the section as it is now.
        const items = await fetchMany([{
            methodname: 'mod_edpreset_get_template_items',
            args: {
                courseid: parseInt(form.querySelector('[name="course"]').value, 10),
                sectionnum: parseInt(form.querySelector('[name="section"]').value, 10),
                template: templatesection,
            },
        }])[0];

        const modal = await ModalSaveCancel.create({
            title: templatetitle,
            body: Templates.render('mod_edpreset/reorder_modal', {
                templateitems: items.templateitems,
                courseitems: items.courseitems,
                hascourseitems: items.courseitems.length > 0,
            }),
            large: true,
            removeOnClose: true,
        });

        modal.setSaveButtonText(await getString('reorder:confirm', 'mod_edpreset'));

        modal.getRoot().on(ModalEvents.save, () => {
            const body = modal.getRoot()[0];

            // Whatever the teacher left on the right goes after the template, keeping the order it
            // is already in - which makes confirming without dragging anything a sensible result
            // rather than a mistake, and is why the confirm button is never disabled.
            const order = [
                ...tokensIn(body.querySelector(SELECTORS.TARGETLIST)),
                ...tokensIn(body.querySelector(SELECTORS.SOURCELIST)),
            ];

            submitOrder(root, templatesection, order);
        });

        makeSortable();

        await modal.show();
    } catch (error) {
        Notification.exception(error);
    }

    pending.resolve();
};
