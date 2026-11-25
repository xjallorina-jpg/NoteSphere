/**
 * NoteSphere - keeps the Help Board / Messages navigation badges in sync.
 * Include with:
 *   <link rel="stylesheet" href="../assets/notification_badges.css">
 *   <script src="../assets/notification_badges.js" data-csrf="..." defer></script>
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var apiUrl = '../api/notification_counts.php';
    var csrf = '';

    if (script) {
        apiUrl = new URL('../api/notification_counts.php', new URL(script.src, window.location.href)).href;
        csrf = script.getAttribute('data-csrf') || '';
    } else {
        apiUrl = new URL('../api/notification_counts.php', window.location.href).href;
    }
    if (!csrf && window.NS_NOTIF_CSRF) csrf = String(window.NS_NOTIF_CSRF);

    function normalizeKind(raw) {
        raw = String(raw || '');
        return raw === 'help' || raw === 'direct_messages' ? raw : 'messages';
    }

    function labelFor(kind) {
        if (kind === 'help') return 'Unread Help Board comments';
        if (kind === 'direct_messages') return 'Unread private messages';
        return 'Unread messages';
    }

    function paint(counters) {
        if (!counters || counters.ok === false) return;

        document.querySelectorAll('[data-ns-count]').forEach(function (badge) {
            var kind = normalizeKind(badge.getAttribute('data-ns-count'));
            var raw = counters[kind];
            var count = Math.max(0, parseInt(raw, 10) || 0);

            badge.textContent = count > 99 ? '99+' : String(count);
            badge.setAttribute('aria-label', labelFor(kind) + ': ' + count);
            if (count > 0) {
                badge.removeAttribute('hidden');
            } else {
                badge.setAttribute('hidden', '');
            }
        });

        document.querySelectorAll('[data-ns-count-group]').forEach(function (node) {
            var kinds = (node.getAttribute('data-ns-count-group') || '').split(/\s+/);
            var total = kinds.reduce(function (sum, kind) {
                var key = normalizeKind(kind);
                return sum + (Math.max(0, parseInt(counters[key], 10) || 0));
            }, 0);
            node.textContent = total > 99 ? '99+' : String(total);
            if (node.hasAttribute('hidden') !== (total === 0)) {
                if (total > 0) node.removeAttribute('hidden');
                else node.setAttribute('hidden', '');
            }
        });
    }

    var inFlight = false;
    function refresh() {
        if (inFlight || document.hidden) return Promise.resolve(null);
        inFlight = true;
        return fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) paint(data); return data; })
            .catch(function () { return null; })
            .finally(function () { inFlight = false; });
    }

    function mark(action, payload) {
        var body = Object.assign({ csrf: csrf, action: action }, payload || {});
        return fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) paint(data); return data; })
            .catch(function () { return null; });
    }

    window.NSRefreshNotificationBadges = refresh;
    window.NSMarkHelpNotificationsRead = function () { return mark('mark_help'); };
    window.NSMarkCommentRead = function (questionId, commentId) {
        return mark('mark_comment', { question_id: questionId || 0, comment_id: commentId || 0 });
    };
    window.NSMarkConversationRead = function (peerId) { return mark('mark_message', { peer_id: peerId }); };
    window.NSMarkGroupRead = function (groupId) { return mark('mark_group', { group_id: groupId }); };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh, { once: true });
    } else {
        refresh();
    }

    window.setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh();
    });
    window.addEventListener('pageshow', refresh);
})();
