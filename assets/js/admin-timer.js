/**
 * BBAB Service Center - Timer JavaScript
 *
 * Handles timer functionality on time entry edit screens.
 * Migrated from: WPCode Snippet #2332
 */
(function($) {
    'use strict';

    var timerInterval;
    var $container = $('#bbab-timer-container');

    if (!$container.length) {
        return;
    }

    var postId = $container.data('post-id');

    /**
     * Update the timer display with elapsed time.
     */
    function updateTimerDisplay() {
        var startTimestamp = $('#bbab-start-timestamp').val();
        if (!startTimestamp) {
            return;
        }

        var elapsed = Math.floor(Date.now() / 1000) - parseInt(startTimestamp);
        var hours = Math.floor(elapsed / 3600);
        var minutes = Math.floor((elapsed % 3600) / 60);
        var seconds = elapsed % 60;

        var display =
            String(hours).padStart(2, '0') + ':' +
            String(minutes).padStart(2, '0') + ':' +
            String(seconds).padStart(2, '0');

        $('#bbab-timer-display').text(display);
    }

    // Start the interval if timer is running
    if ($('#bbab-start-timestamp').length) {
        updateTimerDisplay();
        timerInterval = setInterval(updateTimerDisplay, 1000);
    }

    /**
     * Start Timer button click handler.
     */
    $(document).on('click', '#bbab-start-timer', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Starting...');

        console.log('BBAB Timer: Starting AJAX call for post ' + postId);

        $.post(ajaxurl, {
            action: 'bbab_start_timer',
            post_id: postId,
            nonce: $('#bbab_timer_nonce').val()
        }, function(response) {
            console.log('BBAB Timer: Response received', response);
            if (response.success) {
                console.log('BBAB Timer: Success, reloading page');
                location.reload();
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('\u25B6 Start Timer'); // ▶
            }
        }).fail(function(xhr, status, error) {
            console.log('BBAB Timer: AJAX failed', status, error);
            alert('AJAX request failed: ' + error);
            $btn.prop('disabled', false).text('\u25B6 Start Timer');
        });
    });

    /**
     * Stop Timer button click handler.
     */
    $(document).on('click', '#bbab-stop-timer', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Stopping...');

        if (timerInterval) {
            clearInterval(timerInterval);
        }

        console.log('BBAB Timer: Stopping AJAX call for post ' + postId);

        $.post(ajaxurl, {
            action: 'bbab_stop_timer',
            post_id: postId,
            nonce: $('#bbab_timer_nonce').val()
        }, function(response) {
            console.log('BBAB Timer: Response received', response);
            if (response.success) {
                console.log('BBAB Timer: Success, reloading page');
                location.reload();
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('\u23F9 Stop Timer'); // ⏹
            }
        }).fail(function(xhr, status, error) {
            console.log('BBAB Timer: AJAX failed', status, error);
            alert('AJAX request failed: ' + error);
            $btn.prop('disabled', false).text('\u23F9 Stop Timer');
        });
    });

})(jQuery);
