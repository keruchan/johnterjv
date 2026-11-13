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
 *   data-tt-stage-status             adds separate Stage and Status dropdowns;
 *                                    cells provide data-tt-stage/status values
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

    function filterText(row, filter) {
        var cell = row.cells[filter.idx];
        return filter.part && cell
            ? (cell.getAttribute('data-tt-' + filter.part) || '').trim()
            : cellText(row, filter.idx);
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
                if (th.hasAttribute('data-tt-stage-status')) {
                    filters.push({ idx: idx, label: 'Stage', part: 'stage' });
                    filters.push({ idx: idx, label: 'Status', part: 'status' });
                } else if (th.hasAttribute('data-tt-filter')) {
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
        function populateFilter(sel, rows) {
            var previous = sel.value;
            var f = sel.__ttFilter;
            var seen = new Set();
            var values = [];
            rows.forEach(function (r) {
                var t = filterText(r, f);
                if (t && !seen.has(t)) { seen.add(t); values.push(t); }
            });
            values.sort();
            sel.replaceChildren();
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
            sel.value = values.some(function (v) { return v.toLowerCase() === previous; }) ? previous : '';
        }
        filters.forEach(function (f) {
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm tt-filter';
            sel.setAttribute('aria-label', 'Filter by ' + f.label);
            sel.__ttFilter = f;
            populateFilter(sel, dataRows);
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
                .map(function (s) { return { filter: s.__ttFilter, val: s.value }; })
                .filter(function (f) { return f.val !== ''; });

            state.filtered = dataRows.filter(function (r) {
                if (q && rowText(r).indexOf(q) === -1) { return false; }
                for (var i = 0; i < active.length; i++) {
                    if (filterText(r, active[i].filter).toLowerCase() !== active[i].val) { return false; }
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
        filterSelects.forEach(function (s) {
            s.addEventListener('change', function () {
                if (s.__ttFilter.part === 'stage') {
                    var stageRows = dataRows.filter(function (r) {
                        return !s.value || filterText(r, s.__ttFilter).toLowerCase() === s.value;
                    });
                    filterSelects.forEach(function (dependent) {
                        if (dependent.__ttFilter.part === 'status' && dependent.__ttFilter.idx === s.__ttFilter.idx) {
                            populateFilter(dependent, stageRows);
                        }
                    });
                }
                applyFilter();
            });
        });

        render();
    }

    function addHorizontalScrollbar(wrapper) {
        if (wrapper.__ttScrollbar) { return; }
        var table = wrapper.querySelector('table');
        if (!table) { return; }
        wrapper.__ttScrollbar = true;
        var scrollbar = document.createElement('div');
        scrollbar.className = 'table-scrollbar';
        scrollbar.hidden = true;
        scrollbar.tabIndex = 0;
        scrollbar.setAttribute('role', 'region');
        scrollbar.setAttribute('aria-label', 'Scroll table columns left or right');
        var track = document.createElement('div');
        track.className = 'table-scrollbar-track';
        scrollbar.appendChild(track);
        wrapper.parentNode.insertBefore(scrollbar, wrapper);

        function update() {
            track.style.width = wrapper.scrollWidth + 'px';
            scrollbar.hidden = wrapper.scrollWidth <= wrapper.clientWidth + 1;
            scrollbar.scrollLeft = wrapper.scrollLeft;
        }
        scrollbar.addEventListener('scroll', function () {
            if (Math.abs(wrapper.scrollLeft - scrollbar.scrollLeft) > 1) {
                wrapper.scrollLeft = scrollbar.scrollLeft;
            }
        });
        wrapper.addEventListener('scroll', function () {
            if (Math.abs(scrollbar.scrollLeft - wrapper.scrollLeft) > 1) {
                scrollbar.scrollLeft = wrapper.scrollLeft;
            }
        });
        if (typeof ResizeObserver !== 'undefined') {
            var observer = new ResizeObserver(update);
            observer.observe(wrapper);
            observer.observe(table);
        } else {
            window.addEventListener('resize', update);
        }
        update();
    }

    function enableRecordRows() {
        document.querySelectorAll('table tbody tr').forEach(function (row) {
            var link = row.querySelector('[data-row-link]');
            var trigger = row.querySelector('[data-row-trigger]');
            if ((!link && !trigger) || row.__recordRow) { return; }
            row.__recordRow = true;
            row.classList.add('record-row');
            function openRecord(event) {
                if (link) {
                    if (event.ctrlKey || event.metaKey || link.target === '_blank') {
                        window.open(link.href, '_blank', 'noopener');
                    } else {
                        window.location.assign(link.href);
                    }
                } else {
                    trigger.click();
                }
            }
            row.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.target.closest('a, button, input, select, textarea, label, form, [data-record-actions], [contenteditable="true"]')) { return; }
                if (window.getSelection().toString()) { return; }
                openRecord(event);
            });
            // Linked references retain native Tab/Enter and open-in-new-tab support.
            // Modal-only records get a keyboard target on the row itself.
            if (!link) {
                row.tabIndex = 0;
                row.setAttribute('role', 'button');
                row.setAttribute('aria-label', 'Open details: ' + row.cells[0].textContent.trim());
                row.addEventListener('keydown', function (event) {
                    if (event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                        event.preventDefault();
                        openRecord(event);
                    }
                });
            }
        });
    }

    function init() {
        enableRecordRows();
        var tables = document.querySelectorAll('table[data-table-tools]');
        Array.prototype.forEach.call(tables, enhance);
        Array.prototype.forEach.call(document.querySelectorAll('.table-responsive'), addHorizontalScrollbar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
