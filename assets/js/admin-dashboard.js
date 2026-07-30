/**
 * AI Search Engines — Admin Dashboard JavaScript
 *
 * Handles AJAX calls for audits, file generation, and donut chart rendering.
 */
(function ($) {
    'use strict';

    var config = window.aiseDashboard || {};

    /* ── Status display helper ──────────────────────────────────────────── */

    function showStatus(message, type) {
        var $el = $('#aise-action-status');
        $el.removeClass('aise-status-running aise-status-success aise-status-error')
           .addClass('aise-status-' + type)
           .html(type === 'running'
               ? '<span class="aise-spinner"></span> ' + message
               : message)
           .show();

        if (type !== 'running') {
            setTimeout(function () { $el.fadeOut(400); }, 5000);
        }
    }

    /* ── Run Full Site Audit ────────────────────────────────────────────── */

    $('#aise-run-audit').on('click', function () {
        if (!confirm(config.strings.confirm_audit)) {
            return;
        }

        var $btn = $(this).prop('disabled', true);
        showStatus(config.strings.running, 'running');

        $.post(config.ajax_url, {
            action: 'aise_run_audit',
            nonce: config.nonce
        })
        .done(function (response) {
            if (response.success) {
                showStatus(
                    config.strings.complete + ' ' + response.data.message +
                    ' — <strong>' + response.data.average_score + '/100</strong> average.',
                    'success'
                );
                // Refresh the page to show updated data
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                showStatus(response.data.message || config.strings.error, 'error');
            }
        })
        .fail(function () {
            showStatus(config.strings.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Regenerate llms.txt ────────────────────────────────────────────── */

    $('#aise-regen-llms').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        showStatus(config.strings.generating, 'running');

        $.post(config.ajax_url, {
            action: 'aise_generate_llms',
            nonce: config.nonce
        })
        .done(function (response) {
            if (response.success) {
                showStatus(
                    response.data.message +
                    ' <a href="' + response.data.url + '" target="_blank">View →</a>',
                    'success'
                );
            } else {
                showStatus(response.data.message || config.strings.error, 'error');
            }
        })
        .fail(function () {
            showStatus(config.strings.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Regenerate AI Sitemap ──────────────────────────────────────────── */

    $('#aise-regen-sitemap').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        showStatus(config.strings.generating, 'running');

        $.post(config.ajax_url, {
            action: 'aise_generate_sitemap',
            nonce: config.nonce
        })
        .done(function (response) {
            if (response.success) {
                showStatus(
                    response.data.message +
                    ' <a href="' + response.data.url + '" target="_blank">View →</a>',
                    'success'
                );
            } else {
                showStatus(response.data.message || config.strings.error, 'error');
            }
        })
        .fail(function () {
            showStatus(config.strings.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* ── Donut Chart ────────────────────────────────────────────────────── */

    function drawDonut() {
        var canvas = document.getElementById('aise-donut-chart');
        if (!canvas || !canvas.getContext) return;

        var ctx  = canvas.getContext('2d');
        var data = [
            { value: parseInt(canvas.dataset.excellent || 0, 10), color: '#00a32a' },
            { value: parseInt(canvas.dataset.good || 0, 10),      color: '#2271b1' },
            { value: parseInt(canvas.dataset.needsWork || 0, 10), color: '#dba617' },
            { value: parseInt(canvas.dataset.poor || 0, 10),      color: '#d63638' }
        ];

        var total = 0;
        for (var i = 0; i < data.length; i++) {
            total += data[i].value;
        }

        if (total === 0) {
            // Draw empty ring
            ctx.beginPath();
            ctx.arc(90, 90, 65, 0, 2 * Math.PI);
            ctx.strokeStyle = '#dcdcde';
            ctx.lineWidth = 24;
            ctx.stroke();
            return;
        }

        var startAngle = -Math.PI / 2; // Start from top
        var centerX = 90;
        var centerY = 90;
        var radius  = 65;
        var lineW   = 24;

        for (var j = 0; j < data.length; j++) {
            if (data[j].value === 0) continue;

            var sliceAngle = (data[j].value / total) * 2 * Math.PI;

            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, startAngle, startAngle + sliceAngle);
            ctx.strokeStyle = data[j].color;
            ctx.lineWidth = lineW;
            ctx.lineCap = 'butt';
            ctx.stroke();

            startAngle += sliceAngle;
        }

        // Center text — total count
        ctx.fillStyle = '#1d2327';
        ctx.font = 'bold 22px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(total, centerX, centerY - 6);

        ctx.fillStyle = '#646970';
        ctx.font = '10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        ctx.fillText('POSTS', centerX, centerY + 12);
    }

    $(document).ready(function () {
        drawDonut();
    });

})(jQuery);
