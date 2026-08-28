(function () {
    'use strict';

    window.initializeAuroraAddressPicker = function (config) {
        const municipality = document.getElementById(config.municipalitySelectId);
        const barangay = document.getElementById(config.barangaySelectId);
        const barangayName = document.getElementById(config.barangayNameInputId);
        const status = document.getElementById(config.statusId);
        if (!municipality || !barangay || !barangayName) return;

        const syncName = function () {
            const option = barangay.options[barangay.selectedIndex];
            barangayName.value = option?.value
                ? String(option.dataset.name || option.textContent || '').trim()
                : '';
        };

        const load = async function (clearSelection) {
            const municipalityId = Number(municipality.value || 0);
            const selectedCode = clearSelection ? '' : String(barangay.dataset.selectedCode || barangay.value || '');
            const selectedName = clearSelection ? '' : String(barangayName.value || '').trim();
            if (!municipalityId) {
                barangay.innerHTML = '<option value="">Select municipality first…</option>';
                barangay.disabled = true;
                barangayName.value = '';
                barangay.dataset.selectedCode = '';
                if (status) status.textContent = 'Select an Aurora municipality to load barangays.';
                return;
            }

            const previousHtml = barangay.innerHTML;
            barangay.disabled = true;
            if (status) status.textContent = 'Loading official PSGC barangays…';
            try {
                const response = await fetch(config.endpoint + '?municipality_id=' + encodeURIComponent(municipalityId), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok || payload.ok !== true || !Array.isArray(payload.barangays)) {
                    throw new Error(payload.message || 'Unable to load barangays.');
                }

                barangay.innerHTML = '<option value="">Select barangay…</option>';
                payload.barangays.forEach(function (row) {
                    const option = document.createElement('option');
                    option.value = String(row.code || '');
                    option.textContent = String(row.name || '').trim();
                    option.dataset.name = option.textContent;
                    if (option.value === selectedCode
                        || (selectedCode === '' && selectedName !== '' && option.textContent.toLowerCase() === selectedName.toLowerCase())) {
                        option.selected = true;
                    }
                    barangay.appendChild(option);
                });
                barangay.disabled = false;
                barangay.dataset.selectedCode = '';
                syncName();
                if (status) status.textContent = 'Official PSGC barangays for ' + payload.municipality.name + ', Aurora.';
            } catch (error) {
                barangay.innerHTML = previousHtml;
                barangay.disabled = !barangay.value;
                if (status) status.textContent = (error instanceof Error ? error.message : 'Unable to load barangays.')
                    + ' Retry by selecting the municipality again.';
            }
        };

        municipality.addEventListener('change', function () { load(true); });
        barangay.addEventListener('change', syncName);
        load(false);
    };
}());
