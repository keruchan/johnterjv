/** Field-level guidance for the existing permit wizard; server validation remains authoritative. */
(function (window) {
    'use strict';
    window.CertreefyPermitFeedback = function (form, reveal) {
        var tracked = new Map();
        var nextId = 0;
        var summary = document.getElementById('permit-field-errors');
        function focus(field) {
            reveal(field);
            field.focus();
        }
        function label(field) {
            return (field.labels && field.labels[0] ? field.labels[0].textContent : field.name)
                .replace(/\*/g, '').trim();
        }
        function mark(field, message) {
            var entry = tracked.get(field);
            if (!entry) {
                var feedback = document.createElement('div');
                feedback.id = 'permit-feedback-' + (++nextId);
                feedback.className = 'invalid-feedback d-block';
                field.insertAdjacentElement('afterend', feedback);
                entry = {feedback: feedback};
                tracked.set(field, entry);
                var described = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
                described.push(feedback.id);
                field.setAttribute('aria-describedby', described.join(' '));
            }
            entry.feedback.textContent = message;
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
        }
        function clear(field) {
            var entry = tracked.get(field);
            if (!entry) return;
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            var described = (field.getAttribute('aria-describedby') || '').split(/\s+/)
                .filter(function (id) { return id && id !== entry.feedback.id; });
            if (described.length) field.setAttribute('aria-describedby', described.join(' '));
            else field.removeAttribute('aria-describedby');
            entry.feedback.remove();
            tracked.delete(field);
        }
        function render() {
            summary.replaceChildren();
            tracked.forEach(function (entry, field) { if (!form.contains(field)) clear(field); });
            summary.hidden = tracked.size === 0;
            if (summary.hidden) return;
            var title = document.createElement('p');
            title.className = 'fw-semibold mb-1';
            title.textContent = 'Please correct the marked fields:';
            var list = document.createElement('ul');
            list.className = 'mb-0';
            tracked.forEach(function (entry, field) {
                var item = document.createElement('li');
                var link = document.createElement('a');
                link.href = '#' + field.id;
                link.textContent = label(field) + ': ' + entry.feedback.textContent;
                link.addEventListener('click', function (event) { event.preventDefault(); focus(field); });
                item.appendChild(link); list.appendChild(item);
            });
            summary.append(title, list);
        }
        function changed(event) {
            var field = event.target;
            if (!tracked.has(field)) return;
            if (field.checkValidity()) clear(field);
            else mark(field, field.validationMessage);
            render();
        }
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        var aliases = [
            ['property_classification', /property classification/i], ['property_relationship', /property relationship/i],
            ['property_owner_name', /property owner name/i], ['property_address', /detailed property location|property address/i],
            ['authorization_details', /authorization details/i], ['organization_name', /organization name/i],
            ['applicant_type', /applicant type/i], ['area_value', /area applied for/i],
            ['tree_origin', /tree origin|earth-balling/i], ['purpose_option', /purpose that belongs|^purpose is/i],
            ['cutting_purpose', /cutting purpose|purpose of cutting/i], ['declaration_confirmed', /applicant declaration/i],
            ['latitude', /tree-cutting property location|latitude|coordinate/i], ['longitude', /longitude/i], ['lot_number', /lot number/i],
            ['district', /district/i], ['barangay', /barangay/i], ['municipality', /municipality/i],
            ['province', /province/i], ['application_notes', /application notes/i]
        ];
        return {
            validate: function (section) {
                var invalid = [];
                section.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (field.form !== form) return;
                    if (field.willValidate && !field.checkValidity()) { mark(field, field.validationMessage); invalid.push(field); }
                    else clear(field);
                });
                render();
                if (invalid.length) focus(invalid[0]);
                return invalid.length === 0;
            },
            serverErrors: function () {
                var first = null;
                document.querySelectorAll('[data-permit-error]').forEach(function (item) {
                    var message = item.textContent.trim();
                    var tree = message.match(/^Tree (\d+) (common name|scientific name|quantity|diameter|estimated height|condition notes|origin)/i);
                    var field = null;
                    if (tree) {
                        var names = {'common name':'common_name','scientific name':'scientific_name','quantity':'quantity',
                            'diameter':'diameter_cm','estimated height':'estimated_height_m','condition notes':'condition_notes','origin':'tree_origin'};
                        field = document.getElementById('tree_' + (Number(tree[1]) - 1) + '_' + names[tree[2].toLowerCase()]);
                    } else {
                        var match = aliases.find(function (alias) { return alias[1].test(message); });
                        if (match) field = document.getElementById(match[0]);
                    }
                    if (!field || !form.contains(field) || field.disabled) return;
                    mark(field, message);
                    var link = document.createElement('a');
                    link.href = '#' + field.id; link.textContent = message;
                    link.addEventListener('click', function (event) { event.preventDefault(); focus(field); });
                    item.replaceChildren(link);
                    if (!first) first = field;
                });
                render();
                if (first) focus(first);
            }
        };
    };
})(window);
