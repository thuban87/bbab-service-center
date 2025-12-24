/**
 * Brad's Workbench Admin JavaScript
 *
 * Handles AJAX interactions and UI enhancements.
 *
 * @package BBAB\Core
 * @since   1.0.0
 */

(function($) {
    'use strict';

    /**
     * BBAB Workbench module.
     */
    var BBWorkbench = {

        /**
         * Initialize the module.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Future: Add event handlers for AJAX refresh, expand/collapse, etc.
            // Example:
            // $(document).on('click', '.bbab-refresh-btn', this.refreshBox);
        },

        /**
         * Refresh a dashboard box via AJAX.
         *
         * @param {Event} e Click event.
         */
        refreshBox: function(e) {
            e.preventDefault();

            var $button = $(this);
            var $box = $button.closest('.bbab-box');
            var boxType = $box.data('box-type');

            if (!boxType) {
                return;
            }

            $box.find('.bbab-box-content').html(
                '<div class="bbab-loading">' +
                '<span class="spinner is-active"></span>' +
                '<p>Loading...</p>' +
                '</div>'
            );

            $.ajax({
                url: bbabWorkbench.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bbab_refresh_box',
                    box_type: boxType,
                    nonce: bbabWorkbench.nonce
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $box.find('.bbab-box-content').html(response.data.html);
                    }
                },
                error: function() {
                    $box.find('.bbab-box-content').html(
                        '<div class="bbab-empty-state">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<p>Failed to load data. Please try again.</p>' +
                        '</div>'
                    );
                }
            });
        }
    };

    // Initialize on document ready.
    $(document).ready(function() {
        BBWorkbench.init();
    });

})(jQuery);
