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
 * Filtering, starring and selecting on the preset activities page.
 *
 * Adding is a plain form post to copy.php, so nothing here submits anything: the selection is kept
 * in a hidden field and the sticky footer's button is an ordinary submit.
 *
 * @module     mod_edpreset/chooser
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {debounce} from 'core/utils';
import {get_string as getString} from 'core/str';
import {setUserPreference} from 'core_user/repository';
import Notification from 'core/notification';
import Pending from 'core/pending';

const SELECTORS = {
    ROOT: '[data-region="edpreset-chooser"]',
    ADDFORM: '[data-region="addform"]',
    ADDTEMPLATE: '[data-action="addtemplate"]',
    CARD: '[data-region="card"]',
    GROUP: '[data-region="group"]',
    GROUPS: '[data-region="groups"]',
    GROUPCOUNT: '[data-region="groupcount"]',
    NORESULTS: '[data-region="noresults"]',
    PRESETS: '[data-region="presets"]',
    TAGBAR: '[data-region="tagbar"]',
    SEARCH: '[data-action="search"]',
    CLEARSEARCH: '[data-action="clearsearch"]',
    CLEARFILTERS: '[data-action="clearfilters"]',
    ADDSELECTED: '[data-action="addselected"]',
    TAG: '[data-action="tag"]',
    SELECT: '[data-action="select"]',
    FAVOURITE: '[data-action="favourite"]',
    TOGGLEGROUP: '[data-action="togglegroup"]',
};

const PREFERENCE_COLLAPSED = 'mod_edpreset_collapsed';

/** The preference column is char(1333) and set_user_preference() throws above it. */
const PREFERENCE_MAXLENGTH = 1333;

/** Active tag filters, lowercased. Empty means "no tag filter", not "match nothing". */
const activeTags = new Set();

/** Selected preset ids, in the order they were chosen. */
const selected = new Set();

/** Section keys the user has collapsed by hand, kept so a filter pass can restore them. */
const collapsed = new Set();

let root = null;
let searchTerm = '';

/**
 * Whether any filter is currently narrowing the list.
 *
 * @returns {boolean}
 */
const isFiltering = () => searchTerm !== '' || activeTags.size > 0;

/**
 * How many activities the target section already holds, teacher notes excluded.
 *
 * Sent with the page rather than fetched, so that adding a template to an empty section - the common
 * case, and the one where no dialogue is wanted - costs no round trip at all.
 *
 * @returns {Number}
 */
const sectionActivityCount = () =>
    parseInt(root.querySelector(SELECTORS.ADDFORM)?.dataset.sectionactivitycount ?? '0', 10);

/**
 * Ask the teacher where a section template's activities should go.
 *
 * Loaded on demand: the dialogue pulls in the modal and drag-and-drop machinery, and most visits to
 * this page never open one.
 *
 * @param {Number} templatesection The template's section number in the template course.
 * @param {String} templatetitle The template's name.
 */
const openReorder = (templatesection, templatetitle) => {
    const pending = new Pending('mod_edpreset/chooser:reorder');

    import('mod_edpreset/reorder')
        .then((reorder) => reorder.open(root, templatesection, templatetitle))
        .then(pending.resolve)
        .catch(Notification.exception);
};

/**
 * Whether one card survives the current filters.
 *
 * The text query and the tag set are ANDed, but the tags are ORed with each other: clicking a
 * second tag widens the result, which is what makes the tag bar usable for browsing.
 *
 * @param {HTMLElement} card
 * @returns {boolean}
 */
const cardMatches = (card) => {
    if (searchTerm !== '' && !card.dataset.searchtext.includes(searchTerm)) {
        return false;
    }
    if (activeTags.size === 0) {
        return true;
    }

    const tags = card.dataset.tagkeys ? card.dataset.tagkeys.split('|') : [];

    return tags.some((tag) => activeTags.has(tag));
};

/**
 * Show or hide a group's collapse panel without animating it.
 *
 * Bootstrap 4's collapse plugin is jQuery-driven and animates, which makes a filter pass over
 * several groups flicker. Toggling the classes directly is what core's own bulk UIs do.
 *
 * @param {HTMLElement} group
 * @param {boolean} expand
 */
const setGroupExpanded = (group, expand) => {
    const toggle = group.querySelector(SELECTORS.TOGGLEGROUP);
    const panel = group.querySelector('.collapse');
    if (!toggle || !panel) {
        return;
    }

    panel.classList.toggle('show', expand);
    toggle.classList.toggle('collapsed', !expand);
    toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
};

/**
 * Apply the current filters to every card and group.
 */
const applyFilters = () => {
    const pending = new Pending('mod_edpreset/chooser:filter');
    const filtering = isFiltering();
    let visibleTotal = 0;

    root.querySelectorAll(SELECTORS.GROUP).forEach((group) => {
        let visible = 0;

        group.querySelectorAll(SELECTORS.CARD).forEach((card) => {
            const matches = cardMatches(card);
            card.classList.toggle('d-none', !matches);
            if (matches) {
                visible++;
            }
        });

        visibleTotal += visible;
        group.classList.toggle('d-none', visible === 0);

        const count = group.querySelector(SELECTORS.GROUPCOUNT);
        if (count) {
            count.textContent = visible;
        }

        // While a filter is on, a group holding a match is opened whatever the user's saved state
        // says - a hidden match is indistinguishable from no match. Clearing the filter puts their
        // own choices back.
        if (filtering) {
            setGroupExpanded(group, visible > 0);
        } else {
            setGroupExpanded(group, !collapsed.has(group.dataset.groupkey));
        }
    });

    root.querySelector(SELECTORS.NORESULTS)?.classList.toggle('d-none', visibleTotal > 0);
    root.querySelector(SELECTORS.CLEARFILTERS).disabled = !filtering;

    pending.resolve();
};

/**
 * Reflect the active tag set on every tag button, on the cards and in the tag bar alike.
 */
const refreshTagButtons = () => {
    root.querySelectorAll(SELECTORS.TAG).forEach((button) => {
        const active = activeTags.has(button.dataset.tag.toLowerCase());
        button.classList.toggle('edpreset-tag-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
};

/**
 * Update the sticky footer's add button to match the current selection.
 *
 * @returns {Promise<void>}
 */
const refreshAddButton = async() => {
    const button = root.querySelector(SELECTORS.ADDSELECTED);
    button.disabled = selected.size === 0;

    let label;
    if (selected.size === 0) {
        label = await getString('chooser:addselectednone', 'mod_edpreset');
    } else if (selected.size === 1) {
        label = await getString('chooser:addselectedone', 'mod_edpreset');
    } else {
        label = await getString('chooser:addselected', 'mod_edpreset', selected.size);
    }

    button.textContent = label;
};

/**
 * Toggle a preset's selection, keeping both copies of a starred card in step.
 *
 * @param {number} presetid
 */
const toggleSelected = (presetid) => {
    const nowSelected = !selected.has(presetid);
    if (nowSelected) {
        selected.add(presetid);
    } else {
        selected.delete(presetid);
    }

    root.querySelectorAll(`${SELECTORS.CARD}[data-presetid="${presetid}"]`).forEach((card) => {
        card.classList.toggle('edpreset-card-selected', nowSelected);
        const button = card.querySelector(SELECTORS.SELECT);
        button.dataset.selected = nowSelected ? '1' : '0';
        button.setAttribute('aria-pressed', nowSelected ? 'true' : 'false');
    });

    // What the form actually posts. PARAM_SEQUENCE on the other end, and the insertion order of a
    // Set is the order the teacher chose, which is the order the activities end up in.
    root.querySelector(SELECTORS.PRESETS).value = Array.from(selected).join(',');

    refreshAddButton().catch(Notification.exception);
};

/**
 * Star or unstar a preset.
 *
 * The "Starred" group is built server-side, so the page is not rebuilt here: the star state is
 * updated on every copy of the card, and the group appears or moves on the next page load.
 *
 * @param {number} presetid
 * @param {HTMLElement} button
 * @returns {Promise<void>}
 */
const toggleFavourite = async(presetid, button) => {
    const pending = new Pending('mod_edpreset/chooser:favourite');
    const favourite = button.dataset.favourited !== '1';

    try {
        await fetchMany([{
            methodname: 'mod_edpreset_set_favourite',
            args: {presetid, favourite},
        }])[0];

        root.querySelectorAll(`${SELECTORS.CARD}[data-presetid="${presetid}"]`).forEach((card) => {
            const star = card.querySelector(SELECTORS.FAVOURITE);
            star.dataset.favourited = favourite ? '1' : '0';
            star.setAttribute('aria-pressed', favourite ? 'true' : 'false');
            star.classList.toggle('text-primary', favourite);
            star.classList.toggle('text-muted', !favourite);
        });
    } catch (error) {
        Notification.exception(error);
    }

    pending.resolve();
};

/**
 * Remember which groups are collapsed.
 *
 * The value is a list of hashes of section names rather than the names themselves, because the
 * whole set has to fit in a char(1333) column - see \mod_edpreset\output\chooser_page::section_key.
 * A user with more collapsed groups than that simply stops accumulating new ones.
 */
const saveCollapsed = () => {
    let value = Array.from(collapsed).join(',');
    while (value.length > PREFERENCE_MAXLENGTH) {
        value = value.slice(0, value.lastIndexOf(','));
    }

    setUserPreference(PREFERENCE_COLLAPSED, value).catch(Notification.exception);
};

/**
 * Wire up the page.
 */
export const init = () => {
    root = document.querySelector(SELECTORS.ROOT);
    if (!root || root.dataset.initialised) {
        return;
    }
    // With no presets to show, the page is a single notice: there is no toolbar, no footer and
    // nothing here to wire up.
    if (!root.querySelector(SELECTORS.GROUPS)) {
        return;
    }
    root.dataset.initialised = '1';

    root.querySelectorAll(SELECTORS.GROUP).forEach((group) => {
        if (!group.querySelector('.collapse')?.classList.contains('show')) {
            collapsed.add(group.dataset.groupkey);
        }
    });

    const search = root.querySelector(SELECTORS.SEARCH);
    const clearSearch = root.querySelector(SELECTORS.CLEARSEARCH);

    if (search) {
        const onSearch = debounce(() => {
            searchTerm = search.value.trim().toLowerCase();
            clearSearch?.classList.toggle('d-none', searchTerm === '');
            applyFilters();
        }, 300);
        search.addEventListener('input', onSearch);
    }

    root.addEventListener('click', (event) => {
        const tag = event.target.closest(SELECTORS.TAG);
        if (tag) {
            const key = tag.dataset.tag.toLowerCase();
            if (activeTags.has(key)) {
                activeTags.delete(key);
            } else {
                activeTags.add(key);
            }
            refreshTagButtons();
            applyFilters();
            return;
        }

        const addtemplate = event.target.closest(SELECTORS.ADDTEMPLATE);
        if (addtemplate) {
            // An empty section, or one holding a single activity, has no ordering worth asking
            // about - so the link is left to do its own work and no dialogue appears at all.
            if (sectionActivityCount() <= 1) {
                return;
            }

            event.preventDefault();
            const card = addtemplate.closest(SELECTORS.CARD);
            openReorder(parseInt(card.dataset.templatesection, 10), card.dataset.title);
            return;
        }

        const select = event.target.closest(SELECTORS.SELECT);
        if (select) {
            toggleSelected(parseInt(select.closest(SELECTORS.CARD).dataset.presetid, 10));
            return;
        }

        const favourite = event.target.closest(SELECTORS.FAVOURITE);
        if (favourite) {
            const presetid = parseInt(favourite.closest(SELECTORS.CARD).dataset.presetid, 10);
            toggleFavourite(presetid, favourite);
            return;
        }

        if (event.target.closest(SELECTORS.CLEARSEARCH)) {
            search.value = '';
            searchTerm = '';
            clearSearch.classList.add('d-none');
            applyFilters();
            return;
        }

        if (event.target.closest(SELECTORS.CLEARFILTERS)) {
            if (search) {
                search.value = '';
            }
            searchTerm = '';
            activeTags.clear();
            clearSearch?.classList.add('d-none');
            refreshTagButtons();
            applyFilters();
            return;
        }

        const toggle = event.target.closest(SELECTORS.TOGGLEGROUP);
        if (toggle) {
            // Bootstrap has not run yet, so this reads the state the click is about to leave.
            const group = toggle.closest(SELECTORS.GROUP);
            if (toggle.getAttribute('aria-expanded') === 'true') {
                collapsed.add(group.dataset.groupkey);
            } else {
                collapsed.delete(group.dataset.groupkey);
            }
            // A collapse made while filtering is a view of the filtered list, not a preference.
            if (!isFiltering()) {
                saveCollapsed();
            }
        }
    });

    refreshAddButton().catch(Notification.exception);
};
