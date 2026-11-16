import _ from 'underscore';
import $ from 'jquery';
import mediator from 'oroui/js/mediator';
import persistentStorage from 'oroui/js/persistent-storage';
import BlockWidgetView from 'oroui/js/widget/block-widget';

/**
 * The SmartBrief widget; it owns the block states:
 *
 * - its deferred render is resolved at once, so the record page never waits for the AI;
 * - when the content arrives, the server-rendered "writing" state is dropped and the container stops being busy;
 * - the marked empty wrapper, and any failed request (the platform widget would show an alert box or refresh the
 *   page on 404), remove the whole block, so no error box ever appears on the record page.
 *
 * Nothing is built from the response text: the server-rendered fragment is shown as is.
 *
 * The block controls:
 * - Regenerate reloads the content with `refresh=1`, which writes the summary again; the current content stays,
 *   dimmed, until the new one arrives, and a failed request removes the block like any other failure;
 * - the collapse button hides the body. The block starts in the configured default state; the user's choice is
 *   kept in the browser (oroui/js/persistent-storage, as the collapsible sections of Oro do) per record, with the
 *   fingerprint of the facts it was made on, and is dropped once the facts change. A choice equal to the default is
 *   not stored;
 * - a link next to a fact filters a grid of this page to the records behind it, in place, through the grid's
 *   SmartBrief builder (orosmartbrief/js/datagrid/smart-brief-filter-builder), or scrolls to a section of this page;
 *   either way the page scrolls to the target and highlights it. Any other link (a grid that is not on this page or
 *   not ready, a record) is followed as a plain link, through the app navigation. Such a link carries the grid state
 *   in its URL, so the block of the opened page scrolls to the grid whose state the URL has.
 *
 * @export oro/smart-brief-widget
 * @class orosmartbrief.widget.SmartBriefWidgetView
 * @extends oro.BlockWidgetView
 */
const SmartBriefWidgetView = BlockWidgetView.extend({
    options: _.extend({}, BlockWidgetView.prototype.options, {
        type: 'smart-brief',
        // the server-rendered "writing" state is the loading indicator; no page mask
        loadingMaskEnabled: false
    }),

    /** The same wrapper the server returns for a hidden block */
    EMPTY_CONTENT: '<div class="widget-content" data-smart-brief-empty="1"></div>',

    BLOCK_SELECTOR: '.smart-brief',

    WRITING_SELECTOR: '[data-role="smart-brief-writing"]',

    REGENERATE_SELECTOR: '[data-role="smart-brief-regenerate"]',

    COLLAPSE_SELECTOR: '[data-role="smart-brief-collapse"]',

    PANEL_SELECTOR: '[data-smart-brief-collapsible]',

    COLLAPSED_CLASS: 'smart-brief-panel--collapsed',

    REGENERATING_CLASS: 'smart-brief--regenerating',

    STORAGE_KEY_PREFIX: 'oro-smart-brief-state-',

    LINK_SELECTOR: '[data-role="smart-brief-link"]',

    SECTION_SELECTOR: '.responsive-section',

    HIGHLIGHT_CLASS: 'smart-brief-target--highlighted',

    HIGHLIGHT_DURATION: 2000,

    /**
     * @inheritdoc
     */
    constructor: function SmartBriefWidgetView(options) {
        SmartBriefWidgetView.__super__.constructor.call(this, options);
    },

    /**
     * @inheritdoc
     */
    initialize: function(options) {
        SmartBriefWidgetView.__super__.initialize.call(this, options);
        this.on('contentLoad', this.onSummaryContentLoad, this);
    },

    /**
     * @inheritdoc
     */
    render: function() {
        SmartBriefWidgetView.__super__.render.call(this);
        // The page layout must not wait for the summary request.
        this._resolveDeferredRender();

        const click = 'click' + this.smartBriefEventNamespace();
        this.getBlockElement()
            .on(click, this.REGENERATE_SELECTOR, this.onRegenerateClick.bind(this))
            .on(click, this.COLLAPSE_SELECTOR, this.onCollapseClick.bind(this))
            .on(click, this.LINK_SELECTOR, this.onLinkClick.bind(this));
    },

    /**
     * @inheritdoc
     */
    dispose: function() {
        if (this.disposed) {
            return;
        }
        this.getBlockElement().off(this.smartBriefEventNamespace());
        SmartBriefWidgetView.__super__.dispose.call(this);
    },

    /**
     * @inheritdoc
     */
    _onContentLoadFail: function(jqxhr) {
        delete this.loading;
        if (jqxhr.statusText === 'abort') {
            return;
        }
        // Goes through the regular content path, so the widget life cycle completes and the block is removed.
        this._onContentLoad(this.EMPTY_CONTENT);
    },

    onSummaryContentLoad: function() {
        const $block = this.getBlockElement();
        if (this.$el.is('[data-smart-brief-empty]')) {
            this.removeBlock($block);
            return;
        }
        $block.find(this.WRITING_SELECTOR).remove();
        $block.removeClass(this.REGENERATING_CLASS).attr('aria-busy', 'false');
        this.applyStoredCollapsedState($block);
        this.revealGridOfUrl();
    },

    onRegenerateClick: function(event) {
        event.preventDefault();
        const $block = this.getBlockElement();
        if (this.loading) {
            return;
        }
        $block.addClass(this.REGENERATING_CLASS).attr('aria-busy', 'true');
        $block.find(this.REGENERATE_SELECTOR).prop('disabled', true);
        this.loadContent('refresh=1');
    },

    onCollapseClick: function(event) {
        event.preventDefault();
        const $block = this.getBlockElement();
        const $panel = $block.find(this.PANEL_SELECTOR);
        const collapsed = !$panel.hasClass(this.COLLAPSED_CLASS);
        this.setCollapsed($panel, collapsed);
        this.storeCollapsedState($block, $panel, collapsed);
    },

    onLinkClick: function(event) {
        const $link = $(event.currentTarget);
        const grid = $link.data('grid');
        let $target = $();
        if (grid) {
            $target = $('[data-smart-brief-grid="' + grid + '"]').first();
            if ($target.length) {
                mediator.trigger('smart-brief:filter:' + grid, $link.data('filters') || {});
            }
        } else if ($link.data('section')) {
            $target = $($link.data('section')).first();
        }
        if (!$target.length) {
            // not on this page: the link opens its page, whose block then scrolls to the grid
            return;
        }
        event.preventDefault();
        this.revealTarget($target);
    },

    /**
     * Scrolls to the grid whose state the page URL has (`grid[<grid name>]=…`), such as the one a link of another
     * page filtered. Only once: the content loaded again by Regenerate leaves the page where it is.
     */
    revealGridOfUrl: function() {
        if (this.urlGridRevealed) {
            return;
        }
        this.urlGridRevealed = true;
        const gridNames = [...new URLSearchParams(window.location.search).keys()]
            .map(key => (key.match(/^grid\[(.+)\]$/) || [])[1])
            .filter(Boolean);
        const $grid = $(gridNames.map(name => '[data-page-component-name="' + name + '"]').join(', ')).first();
        if (gridNames.length && $grid.length) {
            this.revealTarget($grid);
        }
    },

    /**
     * @param {jQuery} $target
     */
    revealTarget: function($target) {
        const $section = $target.closest(this.SECTION_SELECTOR);
        const $highlighted = $section.length ? $section : $target;
        $highlighted[0].scrollIntoView({behavior: 'smooth', block: 'start'});
        $highlighted.addClass(this.HIGHLIGHT_CLASS);
        setTimeout(() => $highlighted.removeClass(this.HIGHLIGHT_CLASS), this.HIGHLIGHT_DURATION);
    },

    /**
     * @param {jQuery} $block
     */
    applyStoredCollapsedState: function($block) {
        const $panel = $block.find(this.PANEL_SELECTOR);
        if (!$panel.length) {
            return;
        }
        const stored = this.readCollapsedState($block, $panel);
        this.setCollapsed($panel, stored === null ? this.isCollapsedByDefault($panel) : stored);
    },

    /**
     * @param {jQuery} $panel
     * @returns {boolean}
     */
    isCollapsedByDefault: function($panel) {
        return $panel.data('smartBriefExpandedDefault') !== 1;
    },

    /**
     * @param {jQuery} $panel
     * @param {boolean} collapsed
     */
    setCollapsed: function($panel, collapsed) {
        const $button = $panel.find(this.COLLAPSE_SELECTOR);
        const label = $button.data(collapsed ? 'labelExpand' : 'labelCollapse');
        $panel.toggleClass(this.COLLAPSED_CLASS, collapsed);
        $button.attr({'aria-expanded': String(!collapsed), 'title': label, 'aria-label': label});
        $button.find('[aria-hidden]')
            .toggleClass('fa-chevron-up', !collapsed)
            .toggleClass('fa-chevron-down', collapsed);
    },

    /**
     * @param {jQuery} $block
     * @param {jQuery} $panel
     * @returns {boolean|null} null when nothing is stored for the current facts or the storage is not available;
     *                         a choice made on other facts is removed
     */
    readCollapsedState: function($block, $panel) {
        try {
            const key = this.getStorageKey($block);
            const stored = JSON.parse(persistentStorage.getItem(key));
            if (!stored) {
                return null;
            }
            if (stored.fingerprint !== String($panel.data('smartBriefFingerprint'))) {
                persistentStorage.removeItem(key);
                return null;
            }
            return stored.collapsed === true;
        } catch (e) {
            return null;
        }
    },

    /**
     * @param {jQuery} $block
     * @param {jQuery} $panel
     * @param {boolean} collapsed
     */
    storeCollapsedState: function($block, $panel, collapsed) {
        try {
            const key = this.getStorageKey($block);
            if (collapsed === this.isCollapsedByDefault($panel)) {
                persistentStorage.removeItem(key);
            } else {
                persistentStorage.setItem(key, JSON.stringify({
                    collapsed: collapsed,
                    fingerprint: String($panel.data('smartBriefFingerprint'))
                }));
            }
        } catch (e) {
            // the state is a convenience; without storage the block starts in its default state on the next page
        }
    },

    /**
     * @param {jQuery} $block
     * @returns {string} one key per record
     */
    getStorageKey: function($block) {
        return this.STORAGE_KEY_PREFIX + $block.data('smartBriefEntityClass') + '-' + $block.data('smartBriefEntityId');
    },

    smartBriefEventNamespace: function() {
        return '.smartBrief' + this.cid;
    },

    /**
     * @returns {jQuery} the block container that holds this widget
     */
    getBlockElement: function() {
        return $(this.options.container).closest(this.BLOCK_SELECTOR);
    },

    /**
     * @param {jQuery} $block
     */
    removeBlock: function($block) {
        // Disposes the page components inside the block (this widget included) before the markup goes away.
        $block.trigger('content:remove');
        $block.remove();
    }
});

export default SmartBriefWidgetView;
