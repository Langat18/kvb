const DEFAULT_LAT = -1.2921;
const DEFAULT_LNG = 36.8219;
let geoMap = null;
let geoMarker = null;
let syncingFromMap = false;

function initGeoMap() {
    if (geoMap) return;

    const latInput = document.getElementById('geo_lat');
    const lngInput = document.getElementById('geo_lng');
    const startLat = parseFloat(latInput.value) || DEFAULT_LAT;
    const startLng = parseFloat(lngInput.value) || DEFAULT_LNG;

    geoMap = L.map('geo-map').setView([startLat, startLng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(geoMap);

    geoMarker = L.marker([startLat, startLng], { draggable: true }).addTo(geoMap);

    geoMarker.on('dragend', function () {
        const pos = geoMarker.getLatLng();
        syncingFromMap = true;
        latInput.value = pos.lat.toFixed(7);
        lngInput.value = pos.lng.toFixed(7);
        syncingFromMap = false;
    });

    geoMap.on('click', function (e) {
        geoMarker.setLatLng(e.latlng);
        syncingFromMap = true;
        latInput.value = e.latlng.lat.toFixed(7);
        lngInput.value = e.latlng.lng.toFixed(7);
        syncingFromMap = false;
    });

    latInput.addEventListener('input', syncMarkerFromInputs);
    lngInput.addEventListener('input', syncMarkerFromInputs);
}

function syncMarkerFromInputs() {
    if (syncingFromMap || !geoMap || !geoMarker) return;
    const lat = parseFloat(document.getElementById('geo_lat').value);
    const lng = parseFloat(document.getElementById('geo_lng').value);
    if (!isNaN(lat) && !isNaN(lng)) {
        geoMarker.setLatLng([lat, lng]);
        geoMap.panTo([lat, lng]);
    }
}

function detectLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        syncingFromMap = true;
        document.getElementById('geo_lat').value = lat.toFixed(7);
        document.getElementById('geo_lng').value = lng.toFixed(7);
        syncingFromMap = false;
        if (geoMap && geoMarker) {
            geoMarker.setLatLng([lat, lng]);
            geoMap.setView([lat, lng], 15);
        }
    }, function () {
        alert('Unable to retrieve your location. Please enter coordinates manually or drop a pin on the map.');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const modeSelect = document.getElementById('event_mode_id');
    const geoFields  = document.getElementById('geo-fields');

    function toggleGeoFields() {
        const selected = modeSelect.options[modeSelect.selectedIndex];
        const key = selected ? selected.dataset.key : '';
        if (key === 'onsite' || key === 'hybrid') {
            geoFields.classList.add('active');
            initGeoMap();
            setTimeout(function () { if (geoMap) geoMap.invalidateSize(); }, 50);
        } else {
            geoFields.classList.remove('active');
        }
    }

    modeSelect.addEventListener('change', toggleGeoFields);
    window.addEventListener('pageshow', toggleGeoFields);
    toggleGeoFields();

    const accInput = document.getElementById('provider_accreditation_no');
    if (accInput) {
        const providerIdInput     = document.getElementById('provider_id');
        const providerNameDisplay = document.getElementById('provider_name_display');
        const providerStatus      = document.getElementById('provider-status');
        let providerTimer;

        accInput.addEventListener('input', function () {
            clearTimeout(providerTimer);
            providerIdInput.value = '';
            providerNameDisplay.value = '';
            providerStatus.textContent = '';

            const acc = accInput.value.trim();
            if (acc.length < 3) return;

            providerTimer = setTimeout(function () {
                fetch('/events/provider_lookup.php?accreditation_no=' + encodeURIComponent(acc))
                    .then(r => r.json())
                    .then(d => {
                        if (d.found) {
                            providerIdInput.value = d.id;
                            providerNameDisplay.value = d.name;
                            providerStatus.textContent = 'Provider found.';
                            providerStatus.style.color = 'var(--kvb-green-dark)';
                        } else {
                            providerStatus.textContent = 'No matching provider found for this accreditation number.';
                            providerStatus.style.color = 'var(--kvb-red-dark)';
                        }
                    })
                    .catch(() => {});
            }, 500);
        });
    }
});
