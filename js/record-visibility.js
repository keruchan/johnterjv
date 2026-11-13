(function () {
    'use strict';
    var root = document.querySelector('[data-record-visibility]');
    if (!root || !window.bootstrap) { return; }
    var modal = new bootstrap.Modal(document.getElementById('recordVisibilityModal'));
    var history = new bootstrap.Modal(document.getElementById('recordVisibilityHistory'));
    var form = document.getElementById('recordVisibilityForm');
    var reason = document.getElementById('recordVisibilityReason');
    var error = document.getElementById('recordVisibilityError');
    var confirm = document.getElementById('recordVisibilityConfirm');
    var pending = null;
    var selectedView = new URLSearchParams(window.location.search).get('record_view');
    if (['active', 'archived', 'deleted'].includes(selectedView)) {
        document.querySelectorAll('form[method="get"]').forEach(function (filterForm) {
            if (filterForm.querySelector('[name="record_view"]')) { return; }
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'record_view'; input.value = selectedView;
            filterForm.appendChild(input);
        });
    }

    document.addEventListener('click', async function (event) {
        var button = event.target.closest('[data-record-visibility-action]');
        if (!button || button.disabled) { return; }
        var data = button.dataset;
        if (data.recordVisibilityAction === 'history') {
            var body = document.getElementById('recordVisibilityHistoryBody');
            body.textContent = 'Loading record activity…';
            history.show();
            try {
                var response = await fetch('../records/history.php?' + new URLSearchParams({ type: data.recordType, id: data.recordId }), { credentials: 'same-origin' });
                var result = await response.json();
                if (!response.ok) { throw new Error(result.error || 'Unable to load record activity.'); }
                body.replaceChildren();
                if (!result.events.length) { body.textContent = 'No archive, deletion, or restoration actions have been recorded.'; }
                result.events.forEach(function (item) {
                    var card = document.createElement('article');
                    card.className = 'border rounded p-3 mb-3';
                    var heading = document.createElement('div');
                    heading.className = 'fw-semibold';
                    var actionLabel = { archive: 'Archived', delete: 'Deleted', restore: 'Restored' }[item.action];
                    heading.textContent = item.actor_name + ' (' + item.actor_role.toUpperCase() + ') — ' + actionLabel;
                    var date = document.createElement('p');
                    date.className = 'small text-secondary mb-2';
                    date.textContent = item.created_at;
                    var text = document.createElement('p');
                    text.className = 'record-visibility-reason mb-0';
                    text.textContent = item.reason;
                    card.append(heading, date, text);
                    body.appendChild(card);
                });
            } catch (failure) { body.textContent = failure.message; }
            return;
        }
        var bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        pending = {
            entity_type: data.recordType, entity_id: data.recordId,
            action: data.recordVisibilityAction, version: data.recordVersion,
            request_key: Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('')
        };
        var titles = { archive: 'Archive', delete: 'Delete', restore: 'Restore' };
        document.getElementById('recordVisibilityTitle').textContent = titles[pending.action];
        document.getElementById('recordVisibilityLabel').textContent = data.recordLabel;
        confirm.textContent = titles[pending.action];
        reason.value = '';
        error.hidden = true;
        modal.show();
    });
    document.getElementById('recordVisibilityModal').addEventListener('shown.bs.modal', function () { reason.focus(); });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!pending || confirm.disabled) { return; }
        if (!reason.value.trim()) { reason.setCustomValidity('Provide a reason.'); reason.reportValidity(); return; }
        reason.setCustomValidity('');
        confirm.disabled = true;
        error.hidden = true;
        try {
            var body = new URLSearchParams(Object.assign({}, pending, { reason: reason.value, csrf_token: root.dataset.csrf }));
            var response = await fetch('../records/action.php', { method: 'POST', body: body, credentials: 'same-origin' });
            var result = await response.json();
            if (!response.ok) { throw new Error(result.error || 'Unable to complete this action.'); }
            window.location.reload();
        } catch (failure) {
            error.textContent = failure.message;
            error.hidden = false;
            confirm.disabled = false;
        }
    });
    reason.addEventListener('input', function () { reason.setCustomValidity(''); });
}());
