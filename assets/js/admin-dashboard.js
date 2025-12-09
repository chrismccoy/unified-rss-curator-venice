/**
 * Admin Dashboard JS
 *
 * Handles the "Rewrite & Publish" button interactions on the AI Dashboard.
 *
 * @package    UnifiedCurator
 * @since      1.0.0
 */
jQuery(document).ready(function($) {
    'use strict';

    /**
     * Handle Rewrite Button Click
     */
    $(document).on('click', '.urf-rewrite-btn', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const $wrapper = $btn.closest('.urf-action-wrapper');
        const $spinner = $wrapper.find('.spinner');

        // Clear ANY previous global notices or inline errors
        $('#urf-global-notice-area').empty();
        $wrapper.find('.urf-error-container').remove();

        // Prepare Data
        const payload = {
            action: 'urf_rewrite_publish',
            nonce: urf_dash.nonce,
            title: $btn.data('title'),
            link: $btn.data('link'),
            source: $btn.data('source'),
            content: $btn.data('content')
        };

        $btn.addClass('disabled');
        $spinner.addClass('is-active');

        // Perform AJAX
        $.ajax({
            url: urf_dash.ajax_url,
            type: 'POST',
            data: payload,
            dataType: 'json',
            success: function(res) {
                $spinner.removeClass('is-active');
                $btn.removeClass('disabled');

                if (res.success) {
                    $row.css('background-color', '#edfaef');

                    // Construct new buttons HTML
                    // Edit Link
                    const editBtn = `
                        <a href="${res.data.edit_url}" class="button button-secondary" target="_blank" style="width: 100%; text-align:center; margin-bottom:5px;">
                            <span class="dashicons dashicons-edit"></span> Edit Draft
                        </a>`;

                    // Rewrite Again Button
                    const redoBtn = $('<button>', {
                        type: 'button',
                        class: 'button button-small urf-rewrite-btn',
                        'data-title': payload.title,
                        'data-link': payload.link,
                        'data-source': payload.source,
                        'data-content': payload.content,
                        style: 'width: 100%;',
                        html: '<span class="dashicons dashicons-update"></span> Rewrite Again'
                    });

                    $wrapper.find('.urf-rewrite-btn').remove();
                    $wrapper.find('a.button').remove();

                    $wrapper.prepend(redoBtn);

                    $wrapper.prepend(editBtn);
                    $wrapper.find('.urf-rewrite-btn').insertAfter($wrapper.find('a.button'));

                } else {
                    let errorMsg = 'Unknown error occurred.';
                    if (typeof res.data === 'string') {
                        errorMsg = res.data;
                    } else if (res.data && res.data.message) {
                        errorMsg = res.data.message;
                    }

                    showGlobalError(errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $spinner.removeClass('is-active');
                $btn.removeClass('disabled');

                let detailedMsg = 'Server connection failed.';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    detailedMsg = xhr.responseJSON.data;
                } else if (error) {
                    detailedMsg = error;
                }

                showGlobalError(detailedMsg);
            }
        });
    });

    /**
     * Helper to display a global, dismissible admin notice
     */
    function showGlobalError(message) {
        $('html, body').animate({ scrollTop: 0 }, 'fast');

        const $notice = $('<div>', {
            class: 'notice notice-error is-dismissible',
            style: 'margin-left: 0; margin-top: 15px;'
        });

        const $p = $('<p>').html('<strong>Error:</strong> ' + message);

        const $dismissBtn = $('<button>', {
            type: 'button',
            class: 'notice-dismiss',
            html: '<span class="screen-reader-text">Dismiss this notice.</span>'
        });

        $dismissBtn.on('click', function() {
            $notice.fadeOut(function() { $(this).remove(); });
        });

        $notice.append($p).append($dismissBtn);
        $('#urf-global-notice-area').html($notice);
    }
});
