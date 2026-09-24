/**
 * Freshdesk Import — settings page: action buttons and live status (polled every 5 s while an import runs).
 */
(function ($) {
    if (!$) {
        return;
    }
    $(function () {
        var panel = $('.fdi-panel');
        if (!panel.length) {
            return;
        }
        var timer = null;

        var render = function (r) {
            var s = r.state || {};
            var status = s.status || 'idle';
            panel.attr('data-state', status);
            panel.find('.fdi-badge').text(panel.attr('data-l-' + status) || status);
            var msg = s.message || '';
            if (!r.cron_ok) {
                msg = panel.attr('data-l-cron');
            }
            panel.find('.fdi-message').text(msg);
            $.each(s.counts || {}, function (k, v) {
                panel.find('.fdi-n[data-k="' + k + '"]').text(v);
            });
            panel.find('.fdi-cursor').text(s.cursor && s.cursor.indexOf('2000-') !== 0 ? s.cursor.replace('T', ' ').replace('Z', ' UTC') : '—');
            var running = status === 'running';
            panel.find('[data-fdi="start"], [data-fdi="sync"], [data-fdi="forget"]').prop('disabled', running);
            panel.find('[data-fdi="stop"]').prop('disabled', !running);
            var log = panel.find('.fdi-log').empty();
            $.each(r.logs || [], function (i, l) {
                log.append($('<li></li>').addClass('fdi-log-' + l.level)
                    .append($('<span class="fdi-log-at"></span>').text(l.at))
                    .append(document.createTextNode(' ' + l.message)));
            });
            clearTimeout(timer);
            timer = setTimeout(refresh, running ? 5000 : 30000);
        };

        var refresh = function () {
            $.getJSON(panel.attr('data-status-url'), render);
        };

        panel.on('click', '[data-fdi]', function () {
            var action = $(this).attr('data-fdi');
            var confirmText = panel.attr('data-confirm-' + action);
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            var btn = $(this).prop('disabled', true);
            $.post(panel.attr('data-action-url'), { _token: $('meta[name="csrf-token"]').attr('content'), action: action }, function (r) {
                if (window.showFloatingAlert) {
                    showFloatingAlert(r.status === 'success' ? 'success' : 'error', r.msg);
                }
                btn.prop('disabled', false);
                refresh();
            }, 'json').fail(function () {
                btn.prop('disabled', false);
                if (window.showFloatingAlert) {
                    showFloatingAlert('error', 'Error');
                }
            });
        });

        refresh();
    });
})(window.jQuery);
