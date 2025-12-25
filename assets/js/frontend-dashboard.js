/**
 * BBAB Service Center - Frontend Dashboard Scripts
 */

(function($) {
    'use strict';

    // AJAX helper
    window.bbabScAjax = window.bbabScAjax || {};

    const BBAB = {
        /**
         * Make an AJAX request through the AjaxRouter
         */
        ajax: function(handler, data, successCallback, errorCallback) {
            $.ajax({
                url: bbabScAjax.url,
                type: 'POST',
                data: {
                    action: bbabScAjax.action,
                    handler: handler,
                    nonce: bbabScAjax.nonce,
                    data: JSON.stringify(data)
                },
                success: function(response) {
                    if (response.success) {
                        if (successCallback) successCallback(response.data);
                    } else {
                        if (errorCallback) errorCallback(response.data);
                        else console.error('BBAB AJAX Error:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    if (errorCallback) errorCallback({ message: error });
                    else console.error('BBAB AJAX Error:', error);
                }
            });
        },

        /**
         * Show loading state on an element
         */
        showLoading: function($element) {
            $element.addClass('bbab-loading-state');
            $element.append('<div class="bbab-loading"><div class="bbab-spinner"></div></div>');
        },

        /**
         * Hide loading state
         */
        hideLoading: function($element) {
            $element.removeClass('bbab-loading-state');
            $element.find('.bbab-loading').remove();
        },

        /**
         * Format a date string
         */
        formatDate: function(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        },

        /**
         * Format currency
         */
        formatCurrency: function(amount) {
            return '$' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }
    };

    // Expose to global scope
    window.BBAB = BBAB;

    // Document ready
    $(function() {
        // Initialize any dashboard components here
        console.log('BBAB Service Center frontend loaded');
    });

})(jQuery);
