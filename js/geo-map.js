/**
 * CERTREEFY shared geomapping helpers (Leaflet).
 *
 * Purely presentational: every map reads data the page already rendered
 * (embedded JSON or hidden inputs) and writes only to form inputs the server
 * re-validates. Server-side guards remain the authorization boundary.
 *
 * Page prerequisites (CDN, matching the project's Bootstrap/Chart.js pattern):
 *   - leaflet@1.9.4 CSS + JS                        (all maps)
 *   - @geoman-io/leaflet-geoman-free@2.17.0         (boundary editors only)
 *   - leaflet.heat@0.2.0                            (heatmaps only)
 *
 * Basemaps: OpenStreetMap standard tiles (street) and Esri World Imagery
 * (satellite), both free-with-attribution.
 */
(function (window) {
    'use strict';

    if (typeof window.L === 'undefined') {
        return;
    }

    var L = window.L;

    // CENRO Sta. Cruz, Laguna — default view covering Districts 3 & 4.
    var DEFAULT_CENTER = [14.2814, 121.4161];
    var DEFAULT_ZOOM = 11;

    var CLASSIFICATION_COLORS = {
        allowed: '#4a7c59',
        restricted: '#d9a441',
        protected: '#b4552d',
        planting: '#2c6e8f'
    };

    function classificationColor(classification) {
        return CLASSIFICATION_COLORS[String(classification || '').toLowerCase()] || '#5f6b63';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Same point-in-polygon rule as includes/geo.php; supports holes and islands.
    function ringContainsPoint(ring, lat, lng) {
        var inside = false;
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            var xi = ring[i][0], yi = ring[i][1], xj = ring[j][0], yj = ring[j][1];
            var cross = (lng - xi) * (yj - yi) - (lat - yi) * (xj - xi);
            if (Math.abs(cross) < 1e-12 && lng >= Math.min(xi, xj) - 1e-10
                && lng <= Math.max(xi, xj) + 1e-10 && lat >= Math.min(yi, yj) - 1e-10
                && lat <= Math.max(yi, yj) + 1e-10) {
                return true;
            }
            if ((yi > lat) !== (yj > lat) && lng < (xj - xi) * (lat - yi) / (yj - yi) + xi) {
                inside = !inside;
            }
        }
        return inside;
    }

    function boundaryContainsPoint(boundary, lat, lng) {
        if (!isFinite(lat) || !isFinite(lng)) { return false; }
        var geometry = boundary.geometry || boundary;
        var polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
        return polygons.some(function (rings) {
            return ringContainsPoint(rings[0], lat, lng) && !rings.slice(1).some(function (hole) {
                return ringContainsPoint(hole, lat, lng);
            });
        });
    }

    function applyBoundary(map, boundary) {
        if (!boundary) { return; }
        var outline = L.geoJSON(boundary, {
            interactive: false,
            style: { color: '#2d6a4f', weight: 2, fillOpacity: 0.04 }
        }).addTo(map);
        var bounds = outline.getBounds().pad(0.04);
        // Fractional zoom avoids an extra whole zoom level of surrounding area.
        map.options.zoomSnap = 0.1;
        map.options.maxBoundsViscosity = 1;
        function showProvince() {
            map.fitBounds(bounds, { padding: [8, 8], animate: false });
        }
        function updateMinimumZoom() {
            var wasAtOverview = map.getZoom() <= map.getMinZoom();
            // Clear the old minimum so a narrower viewport can fit Laguna too.
            map.setMinZoom(0);
            map.setMinZoom(map.getBoundsZoom(bounds, false, L.point(16, 16)));
            map.setMaxBounds(bounds);
            if (wasAtOverview) { showProvince(); }
        }
        updateMinimumZoom();
        showProvince();
        map.on('resize', updateMinimumZoom);
        var reset = L.control({ position: 'topleft' });
        reset.onAdd = function () {
            var div = L.DomUtil.create('div', 'leaflet-bar');
            var link = L.DomUtil.create('a', '', div);
            link.href = '#';
            link.innerHTML = '&#8962;';
            link.title = 'Show all of Laguna';
            link.setAttribute('aria-label', 'Show all of Laguna');
            L.DomEvent.on(link, 'click', function (event) {
                L.DomEvent.stop(event);
                showProvince();
            });
            return div;
        };
        reset.addTo(map);
        map.certreefyBoundary = boundary;
        map.attributionControl.addAttribution('Laguna boundary: <a href="https://www.geoboundaries.org/">geoBoundaries</a> (NAMRIA/PSA/OCHA), CC BY 3.0 IGO');
    }

    /** Creates a Leaflet map with a Street/Satellite layer switcher. */
    function baseMap(elementOrId, options) {
        options = options || {};
        var street = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        });
        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 19,
                attribution: 'Imagery &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'
            }
        );
        var map = L.map(elementOrId, {
            center: options.center || DEFAULT_CENTER,
            zoom: options.zoom || DEFAULT_ZOOM,
            layers: [options.satellite ? satellite : street],
            scrollWheelZoom: options.scrollWheelZoom !== undefined ? options.scrollWheelZoom : true
        });
        L.control.layers({ 'Street': street, 'Satellite': satellite }, null, { position: 'topright' }).addTo(map);
        applyBoundary(map, options.boundary || readJson('lagunaMapBoundary', null));
        return map;
    }

    /** Parses stored GeoJSON text defensively; returns null when unusable. */
    function parseGeoJson(text) {
        if (!text) {
            return null;
        }
        if (typeof text === 'object') {
            return text;
        }
        try {
            return JSON.parse(text);
        } catch (err) {
            return null;
        }
    }

    /**
     * Renders zone/site polygons color-coded by classification.
     * zones: [{name, classification, geojson, popupHtml}]
     * Returns the combined feature group (null when nothing rendered).
     */
    function zonesOverlay(map, zones) {
        var group = L.featureGroup();
        (zones || []).forEach(function (zone) {
            var parsed = parseGeoJson(zone.geojson);
            if (!parsed) {
                return;
            }
            var color = classificationColor(zone.classification);
            var layer = L.geoJSON(parsed, {
                style: function () {
                    return { color: color, weight: 2, fillColor: color, fillOpacity: 0.18 };
                }
            });
            var popup = zone.popupHtml
                || '<strong>' + escapeHtml(zone.name) + '</strong>'
                + (zone.classification ? '<br>Classification: ' + escapeHtml(zone.classification) : '');
            layer.bindPopup(popup);
            layer.addTo(group);
        });
        if (group.getLayers().length === 0) {
            return null;
        }
        group.addTo(map);
        return group;
    }

    /**
     * Coordinate picker bound to a pair of form inputs. Click (or drag the
     * marker) to set latitude/longitude; clearing the inputs removes the pin.
     */
    function picker(elementOrId, opts) {
        opts = opts || {};
        opts.boundary = opts.boundary || readJson('lagunaMapBoundary', null);
        var latInput = typeof opts.latInput === 'string' ? document.getElementById(opts.latInput) : opts.latInput;
        var lngInput = typeof opts.lngInput === 'string' ? document.getElementById(opts.lngInput) : opts.lngInput;
        if (!latInput || !lngInput) {
            return null;
        }

        var initialLat = parseFloat(latInput.value);
        var initialLng = parseFloat(lngInput.value);
        var hasInitial = isFinite(initialLat) && isFinite(initialLng);

        var map = baseMap(elementOrId, {
            center: hasInitial ? [initialLat, initialLng] : opts.center,
            zoom: hasInitial ? 16 : opts.zoom,
            boundary: opts.boundary
        });
        zonesOverlay(map, opts.zones);

        var marker = null;
        var lastValidPosition = null;
        var feedback = null;
        if (opts.boundary && !opts.readOnly) {
            feedback = document.createElement('p');
            feedback.className = 'small text-danger mt-2 mb-0';
            feedback.setAttribute('role', 'status');
            feedback.hidden = true;
            map.getContainer().insertAdjacentElement('afterend', feedback);
        }
        function isAllowed(lat, lng) {
            var allowed = !opts.boundary || boundaryContainsPoint(opts.boundary, lat, lng);
            if (feedback) {
                feedback.textContent = allowed ? '' : 'Select a location within Laguna, Philippines.';
                feedback.hidden = allowed;
            }
            return allowed;
        }
        function selectLocation(lat, lng, pan) {
            if (!isAllowed(lat, lng)) {
                if (marker && lastValidPosition) { marker.setLatLng(lastValidPosition); }
                return;
            }
            lastValidPosition = [lat, lng];
            setMarker(lat, lng, pan);
            writeInputs(lat, lng);
        }
        function setMarker(lat, lng, pan) {
            if (marker === null) {
                marker = L.marker([lat, lng], { draggable: !opts.readOnly }).addTo(map);
                marker.on('dragend', function () {
                    var pos = marker.getLatLng();
                    selectLocation(pos.lat, pos.lng, false);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            if (pan) {
                map.panTo([lat, lng]);
            }
        }
        function writeInputs(lat, lng) {
            latInput.value = lat.toFixed(7);
            lngInput.value = lng.toFixed(7);
            latInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function syncFromInputs() {
            latInput.setCustomValidity('');
            lngInput.setCustomValidity('');
            var lat = parseFloat(latInput.value);
            var lng = parseFloat(lngInput.value);
            if ((latInput.value === '') !== (lngInput.value === '')) {
                var missing = latInput.value === '' ? latInput : lngInput;
                missing.setCustomValidity('Provide both latitude and longitude, or leave both blank.');
            }
            if (isFinite(lat) && isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                if (!isAllowed(lat, lng)) {
                    latInput.setCustomValidity('Select a location within Laguna, Philippines.');
                    lngInput.setCustomValidity('Select a location within Laguna, Philippines.');
                    if (marker !== null) { map.removeLayer(marker); marker = null; }
                    return;
                }
                lastValidPosition = [lat, lng];
                setMarker(lat, lng, true);
            } else if (marker !== null) {
                map.removeLayer(marker);
                marker = null;
            }
            if (feedback && latInput.value === '' && lngInput.value === '') {
                feedback.hidden = true;
                feedback.textContent = '';
            }
        }

        if (hasInitial) {
            setMarker(initialLat, initialLng, false);
            if (!opts.boundary || boundaryContainsPoint(opts.boundary, initialLat, initialLng)) {
                lastValidPosition = [initialLat, initialLng];
            } else {
                isAllowed(initialLat, initialLng);
            }
        }
        if (!opts.readOnly) {
            map.on('click', function (event) {
                selectLocation(event.latlng.lat, event.latlng.lng, false);
            });
            latInput.addEventListener('change', syncFromInputs);
            lngInput.addEventListener('change', syncFromInputs);
            latInput.addEventListener('input', syncFromInputs);
            lngInput.addEventListener('input', syncFromInputs);
            syncFromInputs();

            // "Use my location" control for phone-based field capture.
            var locate = L.control({ position: 'topleft' });
            locate.onAdd = function () {
                var div = L.DomUtil.create('div', 'leaflet-bar');
                var link = L.DomUtil.create('a', '', div);
                link.href = '#';
                link.title = 'Use my current location';
                link.innerHTML = '&#9737;';
                link.setAttribute('aria-label', 'Use my current location');
                L.DomEvent.on(link, 'click', function (event) {
                    L.DomEvent.stop(event);
                    map.locate({ setView: !opts.boundary, maxZoom: 17 });
                });
                return div;
            };
            locate.addTo(map);
            map.on('locationfound', function (event) {
                selectLocation(event.latlng.lat, event.latlng.lng, true);
            });
        }
        return map;
    }

    /**
     * Read-only display map.
     * points: [{lat, lng, label, popupHtml}] — invalid rows are skipped.
     */
    function display(elementOrId, opts) {
        opts = opts || {};
        var map = baseMap(elementOrId, opts);
        var zoneGroup = zonesOverlay(map, opts.zones);
        var markerGroup = L.featureGroup();
        (opts.points || []).forEach(function (point) {
            var lat = parseFloat(point.lat);
            var lng = parseFloat(point.lng);
            if (!isFinite(lat) || !isFinite(lng)) {
                return;
            }
            var marker = L.marker([lat, lng]);
            marker.bindPopup(point.popupHtml || escapeHtml(point.label || ''));
            marker.addTo(markerGroup);
        });
        if (markerGroup.getLayers().length > 0) {
            markerGroup.addTo(map);
            if (!map.certreefyBoundary) {
                map.fitBounds(markerGroup.getBounds().pad(0.35), { maxZoom: 16 });
            }
        } else if (zoneGroup !== null && !map.certreefyBoundary) {
            map.fitBounds(zoneGroup.getBounds().pad(0.2));
        }
        return map;
    }

    /**
     * Polygon boundary editor (requires Leaflet-Geoman). Serializes the drawn
     * shapes into a hidden input as a GeoJSON FeatureCollection; the server
     * re-validates the structure before storing it.
     */
    function zoneEditor(elementOrId, opts) {
        opts = opts || {};
        var input = typeof opts.geojsonInput === 'string' ? document.getElementById(opts.geojsonInput) : opts.geojsonInput;
        if (!input) {
            return null;
        }
        var map = baseMap(elementOrId, opts);
        zonesOverlay(map, opts.zones);

        var color = classificationColor(opts.classification);
        var drawn = L.featureGroup().addTo(map);

        var initial = parseGeoJson(input.value);
        if (initial) {
            L.geoJSON(initial, {
                style: function () {
                    return { color: color, weight: 2, fillColor: color, fillOpacity: 0.2 };
                }
            }).eachLayer(function (layer) {
                drawn.addLayer(layer);
            });
            if (drawn.getLayers().length > 0) {
                map.fitBounds(drawn.getBounds().pad(0.3));
            }
        }

        // Bounding-box center of the drawn shapes, matching the server's
        // geo_boundary_center(); null when nothing is drawn.
        function currentCenter() {
            if (drawn.getLayers().length === 0) {
                return null;
            }
            try {
                var c = drawn.getBounds().getCenter();
                return [c.lat, c.lng];
            } catch (err) {
                return null;
            }
        }

        function serialize() {
            var features = [];
            drawn.eachLayer(function (layer) {
                if (typeof layer.toGeoJSON === 'function') {
                    features.push(layer.toGeoJSON());
                }
            });
            input.value = features.length > 0
                ? JSON.stringify({ type: 'FeatureCollection', features: features })
                : '';
            // Fired only on user draw/edit/remove (not on initial load), so an
            // existing record's stored attributes are never overwritten on open.
            if (typeof opts.onChange === 'function') {
                opts.onChange(currentCenter());
            }
        }

        if (typeof map.pm !== 'undefined') {
            map.pm.addControls({
                position: 'topleft',
                drawMarker: false,
                drawCircleMarker: false,
                drawPolyline: false,
                drawCircle: false,
                drawText: false,
                drawRectangle: true,
                drawPolygon: true,
                editMode: true,
                dragMode: true,
                cutPolygon: false,
                removalMode: true,
                rotateMode: false
            });
            map.pm.setGlobalOptions({
                pathOptions: { color: color, weight: 2, fillColor: color, fillOpacity: 0.2 }
            });
            map.on('pm:create', function (event) {
                drawn.addLayer(event.layer);
                event.layer.on('pm:edit', serialize);
                serialize();
            });
            map.on('pm:remove', function (event) {
                drawn.removeLayer(event.layer);
                serialize();
            });
            drawn.eachLayer(function (layer) {
                layer.on('pm:edit', serialize);
            });
        }
        return map;
    }

    /**
     * Density heatmap (requires leaflet.heat).
     * points: [[lat, lng, weight]]; markers optional for exact records.
     */
    function heatmap(elementOrId, opts) {
        opts = opts || {};
        var map = baseMap(elementOrId, opts);
        zonesOverlay(map, opts.zones);
        var valid = (opts.points || []).filter(function (point) {
            return isFinite(parseFloat(point[0])) && isFinite(parseFloat(point[1]));
        });
        if (typeof L.heatLayer === 'function' && valid.length > 0) {
            L.heatLayer(valid, {
                radius: opts.radius || 28,
                blur: opts.blur || 18,
                maxZoom: 15
            }).addTo(map);
        }
        (opts.markers || []).forEach(function (point) {
            var lat = parseFloat(point.lat);
            var lng = parseFloat(point.lng);
            if (!isFinite(lat) || !isFinite(lng)) {
                return;
            }
            L.circleMarker([lat, lng], {
                radius: 5,
                color: point.color || '#b4552d',
                fillColor: point.color || '#b4552d',
                fillOpacity: 0.7,
                weight: 1
            }).bindPopup(point.popupHtml || escapeHtml(point.label || '')).addTo(map);
        });
        if (valid.length > 0) {
            map.fitBounds(L.latLngBounds(valid.map(function (point) {
                return [parseFloat(point[0]), parseFloat(point[1])];
            })).pad(0.3), { maxZoom: 14 });
        }
        return map;
    }

    /** Reads embedded JSON from a <script type="application/json"> element. */
    function readJson(id, fallback) {
        var element = document.getElementById(id);
        if (!element) {
            return fallback;
        }
        try {
            return JSON.parse(element.textContent);
        } catch (err) {
            return fallback;
        }
    }

    /**
     * Reverse-geocodes a point to Philippine administrative names using the
     * free OpenStreetMap Nominatim service (attribution-only, no key). The
     * callback receives {province, municipality, barangay} (strings, possibly
     * empty) or null on failure. Intended for low-volume, user-initiated calls
     * (one per boundary edit) well within Nominatim's fair-use policy.
     */
    function reverseGeocode(lat, lng, callback) {
        if (typeof fetch !== 'function') {
            callback(null);
            return;
        }
        var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=14&addressdetails=1'
            + '&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
        fetch(url, { headers: { 'Accept-Language': 'en' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !data.address) {
                    callback(null);
                    return;
                }
                var a = data.address;
                var province = a.province || a.state || a.region || '';
                var municipality = a.city || a.town || a.municipality || '';
                var barangay = a.quarter || a.neighbourhood || a.suburb || a.village || a.hamlet || '';
                // Avoid echoing the municipality into the barangay field when
                // OSM only tagged one of them.
                if (barangay && barangay === municipality) {
                    barangay = '';
                }
                callback({ province: province, municipality: municipality, barangay: barangay });
            })
            .catch(function () { callback(null); });
    }

    window.CertreefyGeo = {
        baseMap: baseMap,
        escapeHtml: escapeHtml,
        picker: picker,
        display: display,
        zoneEditor: zoneEditor,
        zonesOverlay: zonesOverlay,
        heatmap: heatmap,
        readJson: readJson,
        reverseGeocode: reverseGeocode,
        classificationColor: classificationColor,
        boundaryContainsPoint: boundaryContainsPoint,
        DEFAULT_CENTER: DEFAULT_CENTER
    };
}(window));
