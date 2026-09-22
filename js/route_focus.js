/**
 * route_focus.js  (v2)
 *
 * Click a row in the "Route Planned" table and the Leaflet map will:
 *   1. hide every other pin / line (no more clutter),
 *   2. show ONLY that route: A (origin) and B (destination) pins right away,
 *      then upgrade the line to the real road path (OSRM) when it arrives,
 *   3. zoom to fit the route.
 *
 * Click the same row again, press Esc, or press "Show all routes" to go back.
 *
 * What's new in v2:
 *   - Smarter address lookup for routes with no saved coordinates
 *     (drops house numbers / zip codes, then falls back step by step).
 *   - Every network call has a timeout, so nothing can hang silently.
 *   - The map always tells you what happened (found / approximate / not found).
 *   - Debug info in the browser console, prefixed with [route_focus].
 *
 * IMPORTANT: load this file BEFORE route_planner.js (see route.php).
 */
(function () {
    'use strict';

    const MAP_ID = 'route-map';
    const TABLE_BODY = '#route-table-body';
    const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving/';
    const GEOCODE_URL = 'https://nominatim.openstreetmap.org/search';

    // When a route has no saved coordinates and the address lookup finds them,
    // save them to the database so the next click is instant and exact.
    // Set to null to turn this off. (Approximate matches are never saved.)
    const SAVE_COORDS_URL = 'api/save_route_coords.php';

    let map = null;            // Leaflet map instance (captured via init hook)
    let hiddenLayers = [];     // pins/lines we hid so we can restore them
    let previousView = null;   // map center/zoom before we focused a route
    let focusLayer = null;     // layer group holding the focused route
    let banner = null;         // info box on the map
    let activeRow = null;      // currently highlighted <tr>
    let requestId = 0;         // used to ignore outdated async responses
    let lastGeocode = 0;       // Nominatim allows max 1 request / second
    const geocodeCache = new Map();

    const log = (...args) => console.info('[route_focus]', ...args);

    // ---- 1. Capture the map created by route_planner.js -------------------
    if (window.L && L.Map && L.Map.addInitHook) {
        L.Map.addInitHook(function () {
            if (this.getContainer().id === MAP_ID) {
                map = this;
                window.routeMapInstance = this; // handy for debugging
            }
        });
    }

    // ---- 2. Styles (injected so you don't need to touch your CSS) ----------
    const style = document.createElement('style');
    style.textContent = `
        ${TABLE_BODY} tr[data-route-id] { cursor: pointer; transition: background-color .15s; }
        ${TABLE_BODY} tr[data-route-id]:hover { background-color: rgba(148,163,184,.15); }
        ${TABLE_BODY} tr.route-active { background-color: rgba(59,130,246,.18) !important; }
        ${TABLE_BODY} tr.route-active td:first-child { box-shadow: inset 3px 0 0 #3b82f6; }
        .route-pin {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font: 600 13px/1 system-ui, sans-serif;
            border: 3px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.4);
        }
        .route-focus-banner {
            background: #fff; color: #1e293b; padding: 10px 12px;
            border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.3);
            max-width: 270px; font: 13px/1.4 system-ui, sans-serif;
        }
        .route-focus-banner .rf-title { font-weight: 600; margin-bottom: 2px; }
        .route-focus-banner .rf-meta { color: #64748b; font-size: 12px; }
        .route-focus-banner .rf-warn { color: #b45309; font-size: 12px; margin-top: 4px; }
        .route-focus-banner .rf-error { color: #b91c1c; font-size: 12px; margin-top: 4px; }
        .route-focus-banner button {
            margin-top: 8px; width: 100%; padding: 6px 10px; cursor: pointer;
            border: 1px solid #2563eb; color: #2563eb; background: #fff;
            border-radius: 8px; font-size: 12px; font-weight: 500;
        }
        .route-focus-banner button:hover { background: #eff6ff; }
    `;
    document.head.appendChild(style);

    // ---- 3. Small helpers ------------------------------------------------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    const num = (v) => {
        const n = parseFloat(v);
        return Number.isFinite(n) ? n : null;
    };

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    // fetch() that gives up after `ms` milliseconds instead of hanging forever
    async function fetchJson(url, ms = 8000) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), ms);
        try {
            const res = await fetch(url, { signal: controller.signal });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return await res.json();
        } finally {
            clearTimeout(timer);
        }
    }

    // Read saved coordinates from the row (data-origin-lat, data-origin-lng, ...)
    function rowCoords(row, key) {
        const lat = num(row.dataset[key + 'Lat']);
        const lng = num(row.dataset[key + 'Lng']);
        return lat !== null && lng !== null ? { lat, lng, approx: false } : null;
    }

    // Get names/addresses from data-* attributes, or fall back to what's printed in the row
    function rowText(row) {
        let origin = row.dataset.origin || '';
        let destination = row.dataset.destination || '';
        let name = row.dataset.routeName || '';

        if (!origin || !destination) {
            const sub = row.querySelector('td div')?.textContent || '';
            const parts = sub.split('→').map((s) => s.trim());
            origin = origin || parts[0] || '';
            destination = destination || parts[1] || '';
        }
        if (!name) {
            name = row.cells[0]?.childNodes[0]?.textContent.trim() || 'Selected route';
        }
        return { name, origin, destination };
    }

    // ---- 4. Address lookup for routes with no saved coordinates -----------
    // "1618-B Copernico, Makati City, Metro Manila" often finds nothing as-is,
    // so we try several cleaned-up versions, from most to least precise.
    function addressCandidates(text) {
        const list = [];
        const add = (q, approx) => {
            q = q.replace(/\s+/g, ' ').replace(/^[,\s]+|[,\s]+$/g, '');
            if (q && !list.some((c) => c.q === q)) list.push({ q, approx });
        };

        const full = String(text || '');
        add(full, false);

        // drop a leading house/unit number: "#2374 ", "1618-B ", "87 "
        const noNumber = full.replace(/^\s*#?\d+[\w-]*\s+/, '');
        add(noNumber, false);

        // drop 4-digit zip codes: "1234", "1900"
        const noZip = noNumber.replace(/\b\d{4}\b/g, '').replace(/\s+,/g, ',').replace(/,(\s*,)+/g, ',');
        add(noZip, false);

        // progressively drop the leading part (street -> barangay -> ...) = approximate
        const parts = noZip.split(',').map((s) => s.trim()).filter(Boolean);
        for (let i = 1; i < parts.length - 1; i++) add(parts.slice(i).join(', '), true); // never shrink to a single word

        return list.slice(0, 5);
    }

    async function geocode(text, isStale) {
        const key = String(text || '').trim().toLowerCase();
        if (!key) return null;
        if (geocodeCache.has(key)) return geocodeCache.get(key);

        for (const candidate of addressCandidates(text)) {
            if (isStale()) return null;

            // stay under Nominatim's 1 request/second limit
            const wait = 1100 - (Date.now() - lastGeocode);
            if (wait > 0) await sleep(wait);
            lastGeocode = Date.now();
            if (isStale()) return null;

            try {
                const url = `${GEOCODE_URL}?format=json&limit=1&countrycodes=ph&q=${encodeURIComponent(candidate.q)}`;
                const data = await fetchJson(url);
                if (data[0]) {
                    const found = {
                        lat: parseFloat(data[0].lat),
                        lng: parseFloat(data[0].lon),
                        approx: candidate.approx,
                    };
                    log('geocoded', text, '->', candidate.q, found);
                    geocodeCache.set(key, found);
                    return found;
                }
                log('no match for', candidate.q);
            } catch (e) {
                console.warn('[route_focus] geocode request failed for', candidate.q, e);
            }
        }
        return null;
    }

    // Remember a looked-up point on the row, and save it to the database
    function persistCoords(row, side, point) {
        if (!point || point.approx || !row.dataset.routeId) return;

        // remember it for this page visit even if saving fails
        row.dataset[side + 'Lat'] = point.lat;
        row.dataset[side + 'Lng'] = point.lng;

        if (!SAVE_COORDS_URL) return;
        fetch(SAVE_COORDS_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                route_id: row.dataset.routeId,
                side: side,
                lat: point.lat,
                lng: point.lng,
            }),
        })
            .then((res) => res.json())
            .then((data) => log('saved', side, 'coordinates for route', row.dataset.routeId, data))
            .catch((e) => console.warn('[route_focus] could not save coordinates', e));
    }

    // Ask OSRM for the real road path between the two points
    async function roadPath(o, d) {
        try {
            const url = `${OSRM_URL}${o.lng},${o.lat};${d.lng},${d.lat}?overview=full&geometries=geojson`;
            const data = await fetchJson(url, 12000);
            const r = data.routes && data.routes[0];
            if (data.code !== 'Ok' || !r) return null;
            return r.geometry.coordinates.map(([lng, lat]) => [lat, lng]);
        } catch (e) {
            console.warn('[route_focus] road path request failed', e);
            return null;
        }
    }

    // ---- 5. Map drawing ---------------------------------------------------
    function pin(label, color) {
        return L.divIcon({
            className: '',
            html: `<div class="route-pin" style="background:${color}">${label}</div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14],
        });
    }

    function setActive(row) {
        if (activeRow) activeRow.classList.remove('route-active');
        activeRow = row;
        if (row) row.classList.add('route-active');
    }

    function setBanner(html) {
        if (!map) return;
        if (!banner) {
            banner = L.control({ position: 'topright' });
            banner.onAdd = function () {
                const div = L.DomUtil.create('div', 'route-focus-banner');
                L.DomEvent.disableClickPropagation(div);
                div.addEventListener('click', (e) => {
                    if (e.target.closest('[data-show-all]')) showAll();
                });
                this._div = div;
                return div;
            };
            banner.addTo(map);
        }
        banner._div.innerHTML = html;
    }

    const SHOW_ALL_BTN = '<button type="button" data-show-all>Show all routes</button>';

    // Draw (or redraw) the single focused route
    function drawRoute(o, d, path, text) {
        if (focusLayer) map.removeLayer(focusLayer);
        focusLayer = L.featureGroup().addTo(map);

        L.polyline(path || [[o.lat, o.lng], [d.lat, d.lng]], {
            color: '#2563eb',
            weight: 5,
            opacity: 0.9,
            dashArray: path ? null : '8 8', // dashed = straight line, not the road yet
        }).addTo(focusLayer);

        L.marker([o.lat, o.lng], { icon: pin('A', '#16a34a') })
            .bindPopup(`<strong>Origin</strong><br>${esc(text.origin)}`)
            .addTo(focusLayer);
        L.marker([d.lat, d.lng], { icon: pin('B', '#dc2626') })
            .bindPopup(`<strong>Destination</strong><br>${esc(text.destination)}`)
            .addTo(focusLayer);

        map.fitBounds(focusLayer.getBounds(), { padding: [50, 50], maxZoom: 16 });
    }

    // ---- 6. Hide / restore the clutter -----------------------------------
    function isolateMap() {
        if (focusLayer) {
            map.removeLayer(focusLayer);
            focusLayer = null;
        }
        if (previousView) return; // already isolated, keep the first saved view

        previousView = { center: map.getCenter(), zoom: map.getZoom() };
        map.eachLayer((layer) => {
            if (layer instanceof L.Marker || layer instanceof L.Path) {
                hiddenLayers.push(layer);
            }
        });
        hiddenLayers.forEach((layer) => map.removeLayer(layer));
        map.closePopup();
    }

    function showAll() {
        requestId++; // cancel anything still loading
        if (!map) return;

        if (focusLayer) { map.removeLayer(focusLayer); focusLayer = null; }
        if (banner) { map.removeControl(banner); banner = null; }

        hiddenLayers.forEach((layer) => map.addLayer(layer));
        hiddenLayers = [];

        if (previousView) {
            map.setView(previousView.center, previousView.zoom);
            previousView = null;
        }
        setActive(null);
    }

    // ---- 7. Main: focus one route ----------------------------------------
    async function focusRoute(row) {
        if (!map) {
            console.warn('[route_focus] Map not found. Make sure route_focus.js loads BEFORE route_planner.js and after Leaflet.');
            return;
        }

        // Clicking the active row again = go back to all routes
        if (row === activeRow) { showAll(); return; }

        const myRequest = ++requestId;
        const isStale = () => myRequest !== requestId;

        const text = rowText(row);
        const distance = row.cells[1]?.textContent.trim() || '';
        const time = row.cells[3]?.textContent.trim() || '';
        const meta = [distance, time].filter(Boolean).join(' · ');
        const head = `<div class="rf-title">${esc(text.name)}</div>`;

        setActive(row);
        isolateMap();
        map.invalidateSize();

        // bring the map into view (the table sits below it)
        const mapEl = document.getElementById(MAP_ID);
        if (mapEl) {
            mapEl.style.scrollMarginTop = '90px';
            mapEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        setBanner(`${head}<div class="rf-meta">Locating origin and destination…</div>`);

        // Saved coordinates first; look the address up only if they're missing
        let o = rowCoords(row, 'origin');
        let d = rowCoords(row, 'destination');
        log('route', row.dataset.routeId, { savedOrigin: o, savedDestination: d, text });

        const hadOrigin = !!o;
        const hadDestination = !!d;

        if (!o) o = await geocode(text.origin, isStale);
        if (isStale()) return;
        if (!d) d = await geocode(text.destination, isStale);
        if (isStale()) return;

        // newly found points get remembered/saved (only ones we had to look up)
        if (!hadOrigin) persistCoords(row, 'origin', o);
        if (!hadDestination) persistCoords(row, 'destination', d);

        if (!o || !d) {
            const missing = [];
            if (!o) missing.push(`origin "${esc(text.origin)}"`);
            if (!d) missing.push(`destination "${esc(text.destination)}"`);
            setBanner(`${head}
                <div class="rf-error">Couldn't find ${missing.join(' and ')} on the map.</div>
                <div class="rf-meta">This route has no saved coordinates and the address search returned nothing.</div>
                ${SHOW_ALL_BTN}`);
            return;
        }

        const warn = (o.approx || d.approx)
            ? '<div class="rf-warn">Pin position is approximate (exact street address not found).</div>'
            : '';

        // 1) show the pins + a dashed straight line immediately
        drawRoute(o, d, null, text);
        setBanner(`${head}<div class="rf-meta">${esc(meta)}<br>Loading road path…</div>${warn}${SHOW_ALL_BTN}`);

        // 2) then upgrade to the real road path
        const path = await roadPath(o, d);
        if (isStale()) return;

        if (path) {
            drawRoute(o, d, path, text);
            setBanner(`${head}<div class="rf-meta">${esc(meta)}</div>${warn}${SHOW_ALL_BTN}`);
        } else {
            setBanner(`${head}<div class="rf-meta">${esc(meta)}</div>${warn}
                <div class="rf-warn">Road path unavailable, showing a straight line.</div>${SHOW_ALL_BTN}`);
        }
    }

    // ---- 8. Wire up events (delegated, so rows added later also work) -----
    document.addEventListener('click', (e) => {
        if (e.target.closest('a, button, input, select, textarea')) return;
        const row = e.target.closest(`${TABLE_BODY} tr[data-route-id]`);
        if (row) focusRoute(row);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !activeRow) return;
        if (document.querySelector('#route-ai-result:not(.hidden)')) return; // popup handles Esc
        showAll();
    });
})();