(function () {
    'use strict';

    var KENYA = [0.3, 37.9];
    var KENYA_ZOOM = 6;
    var map = null, marker = null, circle = null, accuracyCircle = null;
    var latEl, lngEl, radEl, statusEl, searchEl, resultsEl, searchBtn;
    var syncing = false;

    function num(el) { var n = parseFloat(el.value); return isNaN(n) ? null : n; }
    function radius() { var r = parseInt(radEl.value, 10); return r > 0 ? r : 100; }

    function setStatus(msg, kind) {
        if (!statusEl) { return; }
        statusEl.textContent = msg;
        statusEl.className = 'geo-status' + (kind ? ' ' + kind : '');
    }

    function describe() {
        var lat = num(latEl), lng = num(lngEl);
        if (lat === null || lng === null) {
            setStatus('Search for the venue, or click the map to place the pin. Switch to Satellite to see the building.');
            return;
        }
        setStatus('Pin set at ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '. Attendees within ' + radius() + ' m can sign in.', 'ok');
    }

    function drawCircle(lat, lng) {
        if (!map) { return; }
        if (!circle) {
            circle = L.circle([lat, lng], { radius: radius(), color: '#0f5132', weight: 2, fillColor: '#1a7b32', fillOpacity: 0.15 }).addTo(map);
        } else {
            circle.setLatLng([lat, lng]);
            circle.setRadius(radius());
        }
    }

    function place(lat, lng, opts) {
        opts = opts || {};
        syncing = true;
        latEl.value = lat.toFixed(7);
        lngEl.value = lng.toFixed(7);
        syncing = false;
        if (map) {
            if (!marker) {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('drag', function () { var p = marker.getLatLng(); drawCircle(p.lat, p.lng); });
                marker.on('dragend', function () { var p = marker.getLatLng(); place(p.lat, p.lng); });
            } else {
                marker.setLatLng([lat, lng]);
            }
            drawCircle(lat, lng);
            if (opts.fit) { map.fitBounds(circle.getBounds(), { maxZoom: opts.zoom || 18, padding: [30, 30] }); }
            else if (opts.zoom) { map.setView([lat, lng], opts.zoom); }
        }
        describe();
    }

    function syncFromInputs() {
        if (syncing || !map) { return; }
        var lat = num(latEl), lng = num(lngEl);
        if (lat === null || lng === null || lat < -90 || lat > 90 || lng < -180 || lng > 180) { return; }
        place(lat, lng, { fit: true });
    }

    function initGeoMap() {
        if (map || typeof L === 'undefined') { return; }
        var mapEl = document.getElementById('geo-map');
        if (!mapEl) { return; }

        var street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
        });
        var imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19, attribution: 'Tiles &copy; Esri, Maxar, Earthstar Geographics'
        });
        var labels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19
        });
        var satellite = L.layerGroup([imagery, labels]);

        map = L.map('geo-map', { layers: [street], scrollWheelZoom: true }).setView(KENYA, KENYA_ZOOM);
        L.control.layers({ 'Street': street, 'Satellite': satellite }, null, { collapsed: false, position: 'topright' }).addTo(map);
        L.control.scale({ imperial: false }).addTo(map);

        map.on('click', function (e) { place(e.latlng.lat, e.latlng.lng); });

        var lat = num(latEl), lng = num(lngEl);
        if (lat !== null && lng !== null) { place(lat, lng, { fit: true }); }
        else { describe(); }
    }

    function showResults(items) {
        resultsEl.innerHTML = '';
        items.forEach(function (it) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.tabIndex = 0;
            li.textContent = it.display_name;
            function choose() {
                resultsEl.hidden = true;
                place(parseFloat(it.lat), parseFloat(it.lon), { fit: false, zoom: 17 });
            }
            li.addEventListener('click', choose);
            li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { choose(); } });
            resultsEl.appendChild(li);
        });
        resultsEl.hidden = items.length === 0;
    }

    function nominatim(q, kenyaOnly) {
        var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=' + encodeURIComponent(q) + (kenyaOnly ? '&countrycodes=ke' : '');
        return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) {
            if (!r.ok) { throw new Error('search failed'); }
            return r.json();
        });
    }

    function runSearch() {
        var q = searchEl.value.trim();
        if (q.length < 3) { setStatus('Type at least three letters to search.', 'warn'); return; }
        searchBtn.disabled = true;
        setStatus('Searching…');
        nominatim(q, true)
            .then(function (res) { return res.length ? res : nominatim(q, false); })
            .then(function (res) {
                searchBtn.disabled = false;
                if (!res.length) { showResults([]); setStatus('No place found. Try a nearby landmark, or click the map to place the pin.', 'warn'); return; }
                if (res.length === 1) { showResults([]); place(parseFloat(res[0].lat), parseFloat(res[0].lon), { zoom: 17 }); return; }
                showResults(res);
                setStatus('Choose the right place from the list.');
            })
            .catch(function () {
                searchBtn.disabled = false;
                setStatus('Search is unavailable right now. Click the map to place the pin.', 'warn');
            });
    }

    function detectLocation() {
        if (!navigator.geolocation) {
            setStatus('This browser cannot share its location. Search for the venue or click the map.', 'warn');
            return;
        }
        setStatus('Finding your location…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            var acc = Math.round(pos.coords.accuracy || 0);
            place(pos.coords.latitude, pos.coords.longitude, { zoom: 17 });
            if (map) {
                if (accuracyCircle) { map.removeLayer(accuracyCircle); }
                accuracyCircle = L.circle([pos.coords.latitude, pos.coords.longitude], { radius: acc, color: '#64748b', weight: 1, dashArray: '4 4', fillOpacity: 0.05 }).addTo(map);
            }
            if (acc > 100) {
                setStatus('Located within ' + acc + ' m, which is rough (common on a computer). Check the pin on Satellite and drag it onto the building.', 'warn');
            } else {
                setStatus('Located within ' + acc + ' m. Pin set at ' + pos.coords.latitude.toFixed(5) + ', ' + pos.coords.longitude.toFixed(5) + '.', 'ok');
            }
        }, function (err) {
            setStatus(err && err.code === 1
                ? 'Location access is blocked. Allow it in your browser, or search for the venue.'
                : 'Could not get your location. Search for the venue or click the map.', 'warn');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    }

    window.initGeoMap = initGeoMap;
    window.detectLocation = detectLocation;

    document.addEventListener('DOMContentLoaded', function () {
        var modeSelect = document.getElementById('event_mode_id');
        var geoFields  = document.getElementById('geo-fields');
        latEl = document.getElementById('geo_lat');
        lngEl = document.getElementById('geo_lng');
        radEl = document.getElementById('geo_radius_m');
        statusEl = document.getElementById('geo-status');
        searchEl = document.getElementById('geo-search');
        resultsEl = document.getElementById('geo-results');
        searchBtn = document.getElementById('geo-search-btn');

        if (modeSelect && geoFields && latEl && lngEl && radEl) {
            latEl.addEventListener('input', syncFromInputs);
            lngEl.addEventListener('input', syncFromInputs);
            radEl.addEventListener('input', function () {
                if (circle) { circle.setRadius(radius()); if (map) { map.fitBounds(circle.getBounds(), { maxZoom: 18, padding: [30, 30] }); } }
                if (num(latEl) !== null) { describe(); }
            });
            document.querySelectorAll('.geo-chip').forEach(function (b) {
                b.addEventListener('click', function () {
                    radEl.value = b.getAttribute('data-radius');
                    radEl.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
            if (searchBtn) { searchBtn.addEventListener('click', runSearch); }
            if (searchEl) {
                searchEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
            }
            var locateBtn = document.getElementById('geo-locate-btn');
            if (locateBtn) { locateBtn.addEventListener('click', detectLocation); }

            function toggleGeoFields() {
                var selected = modeSelect.options[modeSelect.selectedIndex];
                var key = selected ? selected.dataset.key : '';
                if (key === 'onsite' || key === 'hybrid') {
                    geoFields.classList.add('active');
                    if (typeof L === 'undefined') {
                        setStatus('The map could not load. Enter the latitude and longitude below.', 'warn');
                    } else {
                        initGeoMap();
                        setTimeout(function () { if (map) { map.invalidateSize(); if (circle) { map.fitBounds(circle.getBounds(), { maxZoom: 18, padding: [30, 30] }); } } }, 60);
                    }
                } else {
                    geoFields.classList.remove('active');
                }
            }

            modeSelect.addEventListener('change', toggleGeoFields);
            window.addEventListener('pageshow', toggleGeoFields);
            toggleGeoFields();

            var form = document.getElementById('event-form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!geoFields.classList.contains('active')) { return; }
                    if (num(latEl) === null || num(lngEl) === null) {
                        e.preventDefault();
                        setStatus('Place the venue pin before saving: search for the venue or click the map.', 'warn');
                        geoFields.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        if (searchEl) { searchEl.focus(); }
                    }
                });
            }
        }

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

        // Opened with an accreditation number already filled in (for example from a PMS activity): look up the name now.
        const providerPrefilled = accInput.value.trim();
        if (providerPrefilled.length >= 3 && !providerNameDisplay.value) {
            fetch('/events/provider_lookup.php?accreditation_no=' + encodeURIComponent(providerPrefilled))
                .then(r => r.json())
                .then(d => {
                    if (d.found) {
                        providerIdInput.value = d.id;
                        providerNameDisplay.value = d.name;
                        providerStatus.textContent = 'Provider found.';
                        providerStatus.style.color = 'var(--kvb-green-dark)';
                    }
                })
                .catch(() => {});
        }
    }
});
})();
