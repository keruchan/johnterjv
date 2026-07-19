/**
 * CERTREEFY shared table tools — client-side search, column filters, and
 * pagination for any registry/list table, giving a consistent experience
 * across every role and page.
 *
 * Opt in on the <table>:
 *   data-table-tools                 enable the toolbar + pagination
 *   data-tt-search="false"           hide the in-table search box (use when the
 *                                    page already has a server-side search form)
 *   data-tt-page-size="10"           rows per page (default 10)
 *   data-tt-search-placeholder="…"   search box placeholder text
 * Mark a filterable column on its <th>:
 *   data-tt-filter="Status"          adds an "All Status" dropdown built from
 *                                    that column's distinct values
 *
 * Purely presentational: it shows/hides existing <tr> rows the server already
 * rendered. Tables that use server-side pagination should NOT opt in.
 */
(function () {
    'use strict';

    function rowText(row) {
        return (row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function cellText(row, index) {
        var cell = row.cells[index];
        return cell ? (cell.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }

    function isPlaceholder(row) {
        return row.cells.length === 1 && row.cells[0].hasAttribute('colspan');
    }

    function enhance(table) {
        if (table.__ttDone) { return; }
        table.__ttDone = true;

        var tbody = table.tBodies[0];
        if (!tbody) { return; }

        var allRows = Array.prototype.slice.call(tbody.rows);
        var placeholder = allRows.filter(isPlaceholder)[0] || null;
        var dataRows = allRows.filter(function (r) { return !isPlaceholder(r); });
        if (dataRows.length === 0) { return; }

        var enableSearch = table.getAttribute('data-tt-search') !== 'false';
        var pageSize = parseInt(table.getAttribute('data-tt-page-size') || '10', 10);
        if (isNaN(pageSize) || pageSize < 1) { pageSize = 10; }

        // Filterable columns from the header row.
        var filters = [];
        var headRow = table.tHead ? table.tHead.rows[0] : null;
        if (headRow) {
            Array.prototype.forEach.call(headRow.cells, function (th, idx) {
                if (th.hasAttribute('data-tt-filter')) {
                    filters.push({ idx: idx, label: (th.getAttribute('data-tt-filter') || th.textContent || '').trim() });
                }
            });
        }

        // ---- Build toolbar ----
        var toolbar = document.createElement('div');
        toolbar.className = 'tt-toolbar';

        var searchInput = null;
        if (enableSearch) {
            var sWrap = document.createElement('div');
            sWrap.className = 'tt-search';
            var ico = document.createElement('i');
            ico.className = 'bi bi-search';
            sWrap.appendChild(ico);
            searchInput = document.createElement('input');
            searchInput.type = 'search';
            searchInput.className = 'form-control form-control-sm';
            searchInput.placeholder = table.getAttribute('data-tt-search-placeholder') || 'Search this table';
            searchInput.setAttribute('aria-label', 'Search this table');
            sWrap.appendChild(searchInput);
            toolbar.appendChild(sWrap);
        }

        var filterSelects = [];
        filters.forEach(function (f) {
            var seen = {};
            var values = [];
            dataRows.forEach(function (r) {
                var t = cellText(r, f.idx);
                if (t && !seen[t]) { seen[t] = true; values.push(t); }
            });
            values.sort();
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm tt-filter';
            sel.setAttribute('aria-label', 'Filter by ' + f.label);
            var opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'All ' + f.label;
            sel.appendChild(opt0);
            values.forEach(function (v) {
                var o = document.createElement('option');
                o.value = v.toLowerCase();
                o.textContent = v;
                sel.appendChild(o);
            });
            sel.__ttIdx = f.idx;
            filterSelects.push(sel);
            toolbar.appendChild(sel);
        });

        var countLabel = document.createElement('span');
        countLabel.className = 'tt-count';
        toolbar.appendChild(countLabel);

        // ---- Position toolbar + footer around the table's scroll wrapper ----
        var wrapper = table.closest('.table-responsive') || table;
        wrapper.parentNode.insertBefore(toolbar, wrapper);

        var footer = document.createElement('div');
        footer.className = 'tt-footer';
        var pager = document.createElement('div');
        pager.className = 'tt-pager';
        footer.appendChild(pager);
        wrapper.parentNode.insertBefore(footer, wrapper.nextSibling);

        var state = { page: 1, filtered: dataRows.slice() };

        function applyFilter() {
            var q = (searchInput && searchInput.value || '').trim().toLowerCase();
            var active = filterSelects
                .map(function (s) { return { idx: s.__ttIdx, val: s.value }; })
                .filter(function (f) { return f.val !== ''; });

            state.filtered = dataRows.filter(function (r) {
                if (q && rowText(r).indexOf(q) === -1) { return false; }
                for (var i = 0; i < active.length; i++) {
                    if (cellText(r, active[i].idx).toLowerCase() !== active[i].val) { return false; }
                }
                return true;
            });
            state.page = 1;
            render();
        }

        function pageButton(label, page, disabled, active) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm ' + (active ? 'btn-certreefy' : 'btn-outline-secondary');
            b.innerHTML = label;
            if (disabled) { b.disabled = true; }
            if (active) { b.setAttribute('aria-current', 'page'); }
            b.addEventListener('click', function () { state.page = page; render(); });
            return b;
        }

        function render() {
            var total = state.filtered.length;
            var pages = Math.max(1, Math.ceil(total / pageSize));
            if (state.page > pages) { state.page = pages; }
            var start = (state.page - 1) * pageSize;
            var end = start + pageSize;

            dataRows.forEach(function (r) { r.style.display = 'none'; });
            state.filtered.slice(start, end).forEach(function (r) { r.style.display = ''; });

            if (placeholder) { placeholder.style.display = total === 0 ? '' : 'none'; }

            countLabel.textContent = total === 0
                ? 'No matching rows'
                : (start + 1) + '–' + Math.min(end, total) + ' of ' + total;

            pager.innerHTML = '';
            if (pages > 1) {
                pager.appendChild(pageButton('<i class="bi bi-chevron-left"></i>', state.page - 1, state.page <= 1, false));
                var addPage = function (p) { pager.appendChild(pageButton(String(p), p, false, p === state.page)); };
                if (pages <= 7) {
                    for (var p = 1; p <= pages; p++) { addPage(p); }
                } else {
                    addPage(1);
                    var s = Math.max(2, state.page - 1);
                    var e = Math.min(pages - 1, state.page + 1);
                    if (s > 2) { pager.appendChild(ellipsis()); }
                    for (var p2 = s; p2 <= e; p2++) { addPage(p2); }
                    if (e < pages - 1) { pager.appendChild(ellipsis()); }
                    addPage(pages);
                }
                pager.appendChild(pageButton('<i class="bi bi-chevron-right"></i>', state.page + 1, state.page >= pages, false));
            }
        }

        function ellipsis() {
            var span = document.createElement('span');
            span.className = 'tt-ellipsis';
            span.textContent = '…';
            return span;
        }

        if (searchInput) { searchInput.addEventListener('input', applyFilter); }
        filterSelects.forEach(function (s) { s.addEventListener('change', applyFilter); });

        render();
    }

    function init() {
        var tables = document.querySelectorAll('table[data-table-tools]');
        Array.prototype.forEach.call(tables, enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
