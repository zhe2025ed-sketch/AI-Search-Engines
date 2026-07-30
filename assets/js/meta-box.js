/**
 * AI Search Engines — Post Meta Box JavaScript
 *
 * Handles re-audit AJAX and FAQ/HowTo toggle interactions.
 */
(function ($) {
    'use strict';

    var config = window.aiseMetaBox || {};

    /* ── Re-audit Button ───────────────────────────────────────────────── */

    $(document).on('click', '#aise-reaudit-btn', function (e) {
        e.preventDefault();

        var $btn    = $(this).prop('disabled', true).text(config.strings ? config.strings.auditing : 'Auditing…');
        var $status = $('#aise-mb-status');
        var postId  = $btn.data('post-id') || $('#post_ID').val();

        $status.removeClass('aise-success aise-error').text('').hide();

        $.post(config.ajax_url, {
            action:  'aise_audit_single',
            nonce:   config.nonce,
            post_id: postId
        })
        .done(function (response) {
            if (response.success) {
                var data  = response.data;
                var score = data.score;

                // Update score display
                var $circle = $('.aise-mb-score-circle');
                $circle.removeClass('aise-green aise-yellow aise-red');
                if (score >= 70) $circle.addClass('aise-green');
                else if (score >= 40) $circle.addClass('aise-yellow');
                else $circle.addClass('aise-red');
                $circle.find('.aise-mb-score-num').text(score);

                // Update checklist
                var $list = $('.aise-mb-checklist');
                $list.empty();

                if (data.checks) {
                    $.each(data.checks, function (name, check) {
                        var iconClass = 'aise-pass';
                        var icon      = '✓';
                        if (check.status === 'warn') {
                            iconClass = 'aise-warn';
                            icon = '⚠';
                        } else if (check.status === 'fail') {
                            iconClass = 'aise-fail';
                            icon = '✗';
                        }

                        $list.append(
                            '<li>' +
                                '<span class="aise-mb-check-icon ' + iconClass + '">' + icon + '</span>' +
                                '<span>' +
                                    '<span class="aise-mb-check-name">' + escHtml(formatName(name)) + '</span> ' +
                                    '<span class="aise-mb-check-msg">' + escHtml(check.message || '') + '</span>' +
                                '</span>' +
                            '</li>'
                        );
                    });
                }

                $status.addClass('aise-success').text('Audit updated! Score: ' + score + '/100').show();
            } else {
                $status.addClass('aise-error').text(response.data.message || 'Audit failed.').show();
            }
        })
        .fail(function () {
            $status.addClass('aise-error').text('Connection error. Please try again.').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text(config.strings ? config.strings.reaudit : 'Re-audit');
        });
    });

    /* ── Helpers ────────────────────────────────────────────────────────── */

    /**
     * Convert check key to readable label.
     * 'intro_answer' → 'Intro Answer'
     */
    function formatName(key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });
    }

    /**
     * Escape HTML entities for safe insertion.
     */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
