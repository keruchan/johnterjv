(function () {
    'use strict';
    document.querySelectorAll('[data-permit-reason]').forEach(function (select) {
        var form = select.form;
        var notes = document.getElementById(select.dataset.permitReason);
        function update() {
            var replacement = select.name === 'review_reason';
            var active = !replacement || !!form.querySelector('input[name="replace[]"]:checked');
            select.disabled = !active;
            select.required = active;
            notes.required = active && select.value === 'other';
        }
        form.addEventListener('change', update);
        update();
    });
})();
