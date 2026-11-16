import mediator from 'oroui/js/mediator';

/**
 * Grid builder added by the SmartBrief bundle to the grids its links target
 * ({@see \Oro\Bundle\SmartBriefBundle\EventListener\Datagrid\SmartBriefFilterBuilderListener}).
 *
 * Once the grid is built, it marks the grid container with `data-smart-brief-grid` and applies the filters the block
 * sends on `smart-brief:filter:<grid name>` the way the grid's own filters do: the collection state takes the
 * filters (the filter hints follow it and the grid writes its state to the URL) and the grid fetches its rows again,
 * without a page reload.
 */
export default {
    /**
     * @param {jQuery.Deferred} deferred
     * @param {Object} options
     * @param {jQuery} options.$el container for the grid
     * @param {string} options.gridName grid name
     * @param {Object} options.gridPromise grid builder's promise
     */
    init(deferred, options) {
        options.gridPromise.done(grid => {
            grid.listenTo(mediator, 'smart-brief:filter:' + options.gridName, filters => {
                const collection = grid.collection;
                collection.state.filters = filters;
                collection.state.currentPage = 1;
                collection.trigger('updateState', collection);
                mediator.trigger('datagrid:doRefresh:' + options.gridName, true);
            });
            options.$el.attr('data-smart-brief-grid', options.gridName);
        });
        deferred.resolve();
    }
};
