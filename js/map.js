// leaflet + OpenStreetMap

function observeMapResize(map, mapEl) {
    const resizeObserver = new ResizeObserver(() => {
        setTimeout(() => {
            map.invalidateSize({
                animate: false
            });
        }, 100);
    });

    resizeObserver.observe(mapEl);

    // Also handle browser window resizing
    window.addEventListener('resize', () => {
        setTimeout(() => {
            map.invalidateSize({
                animate: false
            });
        }, 100);
    });
}

// Wires up a real HTML button (passed by id) to toggle between the
// street and satellite tile layers on the given map.
function addBaseLayers(map, toggleBtnId) {
    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Esri, DigitalGlobe, GeoEye, Earthstar Geographics, CNES/Airbus DS, USDA, USGS, AeroGRID, IGN, and the GIS User Community',
        maxZoom: 19
    });

    let satelliteOn = false;
    const btn = document.getElementById(toggleBtnId);

    if (btn) {
        btn.addEventListener('click', () => {
            satelliteOn = !satelliteOn;
            if (satelliteOn) {
                map.removeLayer(streetLayer);
                map.addLayer(satelliteLayer);
                btn.textContent = 'Satellite: On';
                btn.classList.add('bg-blue-500', 'text-white');
                btn.classList.remove('bg-white', 'text-slate-600');
            } else {
                map.removeLayer(satelliteLayer);
                map.addLayer(streetLayer);
                btn.textContent = 'Satellite: Off';
                btn.classList.remove('bg-blue-500', 'text-white');
                btn.classList.add('bg-white', 'text-slate-600');
            }
        });
    }

    return streetLayer;
}


function initDashboardMap() {
    const mapEl = document.getElementById('fleet-map');
    const dataEl = document.getElementById('fleet-map-data');
    if (!mapEl || !dataEl) return;

    let vehicles = [];
    try {
        vehicles = JSON.parse(dataEl.textContent);
    } catch (e) {
        vehicles = [];
    }

    // Default view: Metro Manila
    const map = L.map(mapEl).setView([14.5995, 120.9842], 11);

    addBaseLayers(map, 'dashboard-satellite-toggle');

    const bounds = [];
    let justStartedMarker = null;

    // NOTE: modules/dashboard.php now excludes any vehicle with an active
    // ('In Transit') trip from this static snapshot, so vehicles here are
    // only ones NOT currently being live-tracked. In-transit vehicles are
    // rendered exclusively by initLiveDriverTracking() below, avoiding the
    // duplicate/stale-pin-vs-live-dot mismatch.
    vehicles.forEach(v => {
        if (v.lat === null || v.lng === null) return;

        const marker = L.marker([v.lat, v.lng]).addTo(map);

        marker.bindPopup(
            `<strong>${v.plate}</strong><br>
            ${v.status}
            ${v.driver ? '<br>Driver: ' + v.driver : ''}`
        );

        if (v.justStarted) {
            justStartedMarker = marker;
        }

        bounds.push([v.lat, v.lng]);
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, {
            padding: [30, 30],
            maxZoom: 14
        });
    }

    // A trip was just started (from Reservation & Dispatch) — draw
    // attention to that vehicle by opening its popup, WITHOUT re-centering
    // or zooming away from the rest of the fleet (fitBounds above already
    // includes this vehicle's location, so it's already in view).
    if (justStartedMarker) {
        setTimeout(() => {
            justStartedMarker.openPopup();
        }, 300);
    }

    // FIX: Recalculate Leaflet map when container changes size
    observeMapResize(map, mapEl);

    // Initial recalculation
    setTimeout(() => {
        map.invalidateSize();
    }, 200);

    // ---- Live driver tracking (polls /api/live/drivers.php) ----
    // This was missing from the version that ended up live -- without it,
    // the fleet map only ever shows the snapshot rendered when the page
    // loaded (modules/dashboard.php's server-side query) and never
    // updates again until you refresh the page. This is what actually
    // makes GPS positions move on screen without a manual reload.
    // Keyed by driver_id so repeated polls UPDATE an existing marker's
    // position instead of stacking a new one on top each time, and so a
    // driver who goes offline/completes their trip gets their marker
    // removed rather than left stale on the map.
    initLiveDriverTracking(map);
}

function initLiveDriverTracking(map, pollIntervalMs = 10000) {
    const liveMarkers = {}; // driver_id -> L.marker

    function formatUpdatedAt(iso) {
        if (!iso) return 'unknown';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleTimeString();
    }

    // Maps a trip/vehicle status string to a marker color. Falls back to
    // blue for any status not explicitly listed (including the default
    // 'In Transit' case), so unrecognized statuses never render invisible
    // or broken.
    function statusColor(status) {
        const colorMap = {
            'in transit': '#2563eb', // blue - normal, moving
            'delayed':    '#f97316', // orange - behind schedule
            'idle':       '#94a3b8', // gray - stopped / no movement
            'issue':      '#ef4444', // red - problem reported
        };
        const key = (status || '').toLowerCase().trim();
        return colorMap[key] || '#2563eb';
    }

    // Builds a pin-style (not plain dot) Leaflet divIcon colored by status,
    // so different trip states are visually distinguishable at a glance
    // and the marker matches the look of the static fleet pins.
    function buildIcon(status) {
        const color = statusColor(status);
        return L.divIcon({
            className: 'live-driver-marker',
            html: `
                <div style="position:relative;">
                    <svg width="32" height="42" viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg">
                        <path d="M16 0C7.2 0 0 7.2 0 16c0 11 16 26 16 26s16-15 16-26c0-8.8-7.2-16-16-16z" fill="${color}"/>
                        <circle cx="16" cy="16" r="7" fill="white"/>
                    </svg>
                </div>
            `,
            iconSize: [32, 42],
            iconAnchor: [16, 42],
            popupAnchor: [0, -38]
        });
    }

    function popupHtml(d) {
        const color = statusColor(d.status);
        return `<strong>${d.plate_number ?? 'Vehicle'}</strong><br>
            ${d.driver_name} &middot; Trip #${d.trip_id}<br>
            <span style="color:${color};font-weight:600;">${d.status}</span><br>
            <span style="color:#64748b;font-size:12px;">Updated: ${formatUpdatedAt(d.updated_at)}</span>`;
    }

    async function poll() {
        let data;
        try {
            // Relative, not absolute -- this app is hosted under a
            // subfolder (PRIORITY_HANDLING/FTMS-fixed/), not the domain
            // root, so a leading slash here would 404.
            const res = await fetch('api/live/drivers.php', { credentials: 'same-origin' });
            data = await res.json();
        } catch (e) {
            // Network hiccup or session expired -- skip this cycle, try
            // again on the next interval rather than throwing.
            return;
        }

        if (!data || data.success !== true || !Array.isArray(data.drivers)) return;

        const seenDriverIds = new Set();

        data.drivers.forEach(d => {
            if (typeof d.latitude !== 'number' || typeof d.longitude !== 'number') return;

            seenDriverIds.add(d.driver_id);
            const latLng = [d.latitude, d.longitude];

            if (liveMarkers[d.driver_id]) {
                // Update position, popup, AND icon (status/color may have
                // changed since the last poll) instead of creating a
                // duplicate marker.
                liveMarkers[d.driver_id].setLatLng(latLng);
                liveMarkers[d.driver_id].setPopupContent(popupHtml(d));
                liveMarkers[d.driver_id].setIcon(buildIcon(d.status));
            } else {
                const marker = L.marker(latLng, {
                    icon: buildIcon(d.status)
                }).addTo(map);
                marker.bindPopup(popupHtml(d));
                liveMarkers[d.driver_id] = marker;
            }
        });

        // Remove markers for drivers who are no longer active (trip
        // completed/cancelled, or they stopped sending updates).
        Object.keys(liveMarkers).forEach(driverId => {
            if (!seenDriverIds.has(Number(driverId))) {
                map.removeLayer(liveMarkers[driverId]);
                delete liveMarkers[driverId];
            }
        });
    }

    poll(); // initial fetch immediately, don't wait for the first interval
    setInterval(poll, pollIntervalMs);
}



function initRouteMap() {
    const mapEl = document.getElementById('route-map');
    const dataEl = document.getElementById('route-map-data');
    if (!mapEl || !dataEl) return;

    let routes = [];
    try {
        routes = JSON.parse(dataEl.textContent);
    } catch (e) {
        routes = [];
    }

    const map = L.map(mapEl).setView([14.5995, 120.9842], 11);

    addBaseLayers(map, 'route-satellite-toggle');

    // Track bounds across the map's lifetime (not just this initial render)
    // so newly applied routes can extend the same view.
    const bounds = [];

    // Default view only plots the pins for each route's origin/destination.
    // The connecting line is intentionally NOT drawn here — with many routes
    // in the table that turned the map into an unreadable tangle of lines.
    // route_focus.js draws the line for a single route once you click its
    // row in "Route Planned" (see drawRoute() there), so the two views stay
    // separate: plain pins by default, one highlighted route on click.
    function plotRoute(r) {
        if (
            r.origin_lat === null || r.origin_lat === undefined ||
            r.origin_lng === null || r.origin_lng === undefined ||
            r.destination_lat === null || r.destination_lat === undefined ||
            r.destination_lng === null || r.destination_lng === undefined
        ) return;

        const origin = [r.origin_lat, r.origin_lng];
        const destination = [r.destination_lat, r.destination_lng];

        L.marker(origin)
            .addTo(map)
            .bindPopup(
                `<strong>${r.route_name}</strong><br>
                Origin: ${r.origin}`
            );

        L.marker(destination)
            .addTo(map)
            .bindPopup(
                `<strong>${r.route_name}</strong><br>
                Destination: ${r.destination}`
            );

        bounds.push(origin, destination);
    }

    // Default view stays a clean, empty map (just the Metro Manila view set
    // above) — no pins, no lines. Routes are only plotted on the map when
    // their row is clicked (route_focus.js) or right after "Apply Route"
    // creates one (window.addRouteToMap below).

    // FIX: Recalculate Leaflet map when container changes size
    observeMapResize(map, mapEl);

    // Initial recalculation
    setTimeout(() => {
        map.invalidateSize();
    }, 200);

    // Let other scripts (e.g. route-planner.js after "Apply Route") add a
    // newly created route to this same map without a full page reload.
    window.addRouteToMap = function (route) {
        plotRoute(route);

        if (bounds.length > 0) {
            map.fitBounds(bounds, {
                padding: [30, 30],
                maxZoom: 14
            });
        }
    };
}


document.addEventListener('DOMContentLoaded', () => {
    initDashboardMap();
    initRouteMap();
});