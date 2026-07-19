/**
 * CERTREEFY notification centre — the client behind the shared bell + panel.
 * Meta/Facebook-style: unread badge, dropdown feed with read/unread states,
 * relative timestamps, "mark all read", "load more", per-item mark
 * read/unread and delete, and click-through routing.
 *
 * Endpoints (relative to a pages/{role}/ page):
 *   ../notifications/feed.php?before=<id>              GET  -> { unread_count, items, has_more }
 *   ../notifications/mark.php  action=all|read|unread|delete, id=<n> for all but "all"
 *                                                        POST -> { ok, unread_count }
 */
(function () {
    'use strict';

    var panel = document.getElementById('notifPanel');
    if (!panel) { return; }

    var listEl = panel.querySelector('[data-notif-list]');
    var moreBtn = panel.querySelector('[data-notif-more]');
    var markAllBtn = panel.querySelector('[data-notif-markall]');
    var backdrop = document.querySelector('[data-notif-backdrop]');
    var csrf = panel.getAttribute('data-notif-csrf') || '';

    var FEED = '../notifications/feed.php';
    var MARK = '../notifications/mark.php';

    var state = { items: [], lastId: 0, hasMore: false, loaded: false, open: false, loading: false };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function badges() { return document.querySelectorAll('[data-notif-badge]'); }

    function setUnread(count) {
        count = parseInt(count, 10) || 0;
        badges().forEach(function (b) {
            b.textContent = count > 99 ? '99+' : String(count);
            b.hidden = count === 0;
        });
        if (markAllBtn) { markAllBtn.disabled = count === 0; }
    }

    function itemHtml(n) {
        var routeAttr = n.route ? ' data-route="' + esc(n.route) + '"' : '';
        var toggleLabel = n.unread ? 'Mark as read' : 'Mark as unread';
        var toggleIcon = n.unread ? 'bi-check2-circle' : 'bi-circle';
        return '<div class="notif-item' + (n.unread ? ' is-unread' : '') + '" data-id="' + esc(n.id) + '">'
            + '<button type="button" class="notif-item-main"' + routeAttr + '>'
            + '<span class="notif-ico ' + esc(n.accent) + '"><i class="bi ' + esc(n.icon) + '"></i></span>'
            + '<span class="notif-body">'
            + '<span class="notif-title">' + esc(n.title) + '</span>'
            + '<span class="notif-msg">' + esc(n.message) + '</span>'
            + '<span class="notif-when" title="' + esc(n.full_time) + '">' + esc(n.time) + '</span>'
            + '</span>'
            + '</button>'
            + (n.unread ? '<span class="notif-dot" aria-hidden="true"></span>' : '')
            + '<span class="notif-item-actions">'
            + '<button type="button" class="notif-action" data-action="toggle-read" title="' + esc(toggleLabel) + '" aria-label="' + esc(toggleLabel) + '"><i class="bi ' + toggleIcon + '"></i></button>'
            + '<button type="button" class="notif-action notif-action-delete" data-action="delete" title="Delete notification" aria-label="Delete notification"><i class="bi bi-trash3"></i></button>'
            + '</span>'
            + '</div>';
    }

    function render() {
        if (state.items.length === 0) {
            listEl.innerHTML = '<div class="notif-state"><i class="bi bi-bell-slash d-block mb-2"></i>No notifications yet.</div>';
        } else {
            listEl.innerHTML = state.items.map(itemHtml).join('');
        }
        if (moreBtn) { moreBtn.hidden = !state.hasMore; }
    }

    function getJSON(url, opts) {
        return fetch(url, opts || {}).then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('application/json') === -1) { throw new Error('bad response'); }
            return r.json();
        });
    }

    function loadFirst() {
        if (state.loading) { return Promise.resolve(); }
        state.loading = true;
        return getJSON(FEED + '?before=0').then(function (data) {
            state.items = data.items || [];
            state.hasMore = !!data.has_more;
            state.lastId = state.items.length ? state.items[state.items.length - 1].id : 0;
            state.loaded = true;
            setUnread(data.unread_count);
            if (state.open) { render(); }
        }).catch(function () {
            // Session may have expired or the network failed; leave the UI as-is.
        }).finally(function () { state.loading = false; });
    }

    function loadMore() {
        if (state.loading || !state.hasMore || !state.lastId) { return; }
        state.loading = true;
        if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Loading…'; }
        getJSON(FEED + '?before=' + state.lastId).then(function (data) {
            state.items = state.items.concat(data.items || []);
            state.hasMore = !!data.has_more;
            state.lastId = state.items.length ? state.items[state.items.length - 1].id : state.lastId;
            setUnread(data.unread_count);
            render();
        }).catch(function () {}).finally(function () {
            state.loading = false;
            if (moreBtn) { moreBtn.disabled = false; moreBtn.textContent = 'Load more'; }
        });
    }

    function markPost(body) {
        body.append('csrf_token', csrf);
        return getJSON(MARK, { method: 'POST', body: body });
    }

    function markAll() {
        var body = new FormData();
        body.append('action', 'all');
        markPost(body).then(function (data) {
            state.items.forEach(function (n) { n.unread = false; });
            setUnread(data.unread_count);
            render();
        }).catch(function () {});
    }

    function activateItem(mainBtn) {
        var row = mainBtn.closest('.notif-item');
        var id = row ? parseInt(row.getAttribute('data-id'), 10) : NaN;
        var route = mainBtn.getAttribute('data-route');
        var item = state.items.filter(function (n) { return n.id === id; })[0];
        var wasUnread = item && item.unread;

        if (wasUnread) {
            var body = new FormData();
            body.append('id', String(id));
            markPost(body).then(function (data) {
                if (item) { item.unread = false; }
                setUnread(data.unread_count);
            }).catch(function () {}).finally(function () {
                if (route) { window.location.href = route; }
            });
        } else if (route) {
            window.location.href = route;
        }

        if (!row) { return; }
        if (item) { item.unread = false; }
        row.classList.remove('is-unread');
        var dot = row.querySelector('.notif-dot');
        if (dot) { dot.remove(); }
        var toggleBtn = row.querySelector('[data-action="toggle-read"]');
        if (toggleBtn) {
            toggleBtn.title = 'Mark as unread';
            toggleBtn.setAttribute('aria-label', 'Mark as unread');
            var icon = toggleBtn.querySelector('i');
            if (icon) { icon.className = 'bi bi-circle'; }
        }
    }

    /** Toggles a notification's read/unread state via its action button (no navigation). */
    function toggleRead(id) {
        var item = state.items.filter(function (n) { return n.id === id; })[0];
        if (!item) { return; }
        var makeUnread = !item.unread;
        item.unread = makeUnread;
        render();
        var body = new FormData();
        body.append('action', makeUnread ? 'unread' : 'read');
        body.append('id', String(id));
        markPost(body).then(function (data) { setUnread(data.unread_count); }).catch(function () {});
    }

    /** Removes a notification for the current user only. */
    function deleteItem(id) {
        state.items = state.items.filter(function (n) { return n.id !== id; });
        render();
        var body = new FormData();
        body.append('action', 'delete');
        body.append('id', String(id));
        markPost(body).then(function (data) { setUnread(data.unread_count); }).catch(function () {});
    }

    function openPanel() {
        state.open = true;
        panel.hidden = false;
        if (backdrop) { backdrop.hidden = false; }
        document.querySelectorAll('[data-notif-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        requestAnimationFrame(function () { panel.classList.add('is-open'); });
        if (!state.loaded) {
            listEl.innerHTML = '<div class="notif-state">Loading…</div>';
            loadFirst().then(render);
        } else {
            render();
        }
    }

    function closePanel() {
        state.open = false;
        panel.classList.remove('is-open');
        if (backdrop) { backdrop.hidden = true; }
        document.querySelectorAll('[data-notif-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        setTimeout(function () { if (!state.open) { panel.hidden = true; } }, 180);
    }

    function togglePanel() { state.open ? closePanel() : openPanel(); }

    // ---- Wiring ----
    document.querySelectorAll('[data-notif-toggle]').forEach(function (b) {
        b.addEventListener('click', function (e) { e.stopPropagation(); togglePanel(); });
    });
    if (backdrop) { backdrop.addEventListener('click', closePanel); }
    if (markAllBtn) { markAllBtn.addEventListener('click', markAll); }
    if (moreBtn) { moreBtn.addEventListener('click', loadMore); }
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    listEl.addEventListener('click', function (e) {
        var actionBtn = e.target.closest('[data-action]');
        if (actionBtn) {
            var row = actionBtn.closest('.notif-item');
            var id = row ? parseInt(row.getAttribute('data-id'), 10) : NaN;
            if (!id) { return; }
            var action = actionBtn.getAttribute('data-action');
            if (action === 'delete') { deleteItem(id); } else if (action === 'toggle-read') { toggleRead(id); }
            return;
        }
        var mainBtn = e.target.closest('.notif-item-main');
        if (mainBtn) { activateItem(mainBtn); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.open) { closePanel(); } });

    // Initial unread count, then a light poll (only while the panel is closed).
    loadFirst();
    setInterval(function () { if (!state.open && !document.hidden) { loadFirst(); } }, 60000);
})();
