// ================= AI RECOMMENDATION POPUP =================
const recommendationModal = document.getElementById('route-ai-result');

if (recommendationModal) {
    // Move the popup to <body> so no parent container can clip or offset it.
    document.querySelectorAll('body > #route-ai-result').forEach((el) => {
        if (el !== recommendationModal) el.remove();
    });
    document.body.appendChild(recommendationModal);

    // click the dark backdrop, the X, or Cancel to close
    recommendationModal.addEventListener('click', (e) => {
        if (!e.target.closest('.route-modal-card')) closeRecommendationModal();
    });
    ['route-modal-close', 'route-modal-cancel'].forEach((id) => {
        document.getElementById(id)?.addEventListener('click', closeRecommendationModal);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !recommendationModal.classList.contains('hidden')) {
            closeRecommendationModal();
        }
    });
}

function openRecommendationModal() {
    if (!recommendationModal) return;
    recommendationModal.classList.remove('hidden');
    recommendationModal.setAttribute('aria-hidden', 'false');
    document.getElementById('apply-route-btn')?.focus();
}

function closeRecommendationModal() {
    if (!recommendationModal) return;
    recommendationModal.classList.add('hidden');
    recommendationModal.setAttribute('aria-hidden', 'true');
    document.getElementById('optimize-route-btn')?.focus();
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.innerText = value ?? '—';
}

function formatPeso(value) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return Number.isFinite(n) ? '₱' + n.toLocaleString() : String(value);
}

document
    .getElementById('route-planner-form')
    .addEventListener('submit', async function (e) {

        e.preventDefault();

        const button = document.getElementById('optimize-route-btn');
        const routeName = document.getElementById('route-name').value;
        const origin = document.getElementById('route-origin').value;
        const destination = document.getElementById('route-destination').value;
        const vehicleId = document.getElementById('route-vehicle').value;
        const optimization = document.getElementById('optimization-type').value;

        button.disabled = true;
        button.innerHTML = '✨ Analyzing Route...';

        const formData = new FormData();

        formData.append('route_name', routeName);
        formData.append('origin', origin);
        formData.append('destination', destination);
        formData.append('vehicle_id', vehicleId);
        formData.append('optimization_type', optimization);

        try {
            const response = await fetch(
                'api/optimize_route.php',
                {
                    method: 'POST',
                    body: formData
                }
            );

            const data = await response.json();

            if (data.success) {

                setText('ai-route-name', routeName || `${origin} to ${destination}`);
                setText('ai-route-path', `${origin} → ${destination}`);
                setText('ai-route-summary', data.summary);
                setText('ai-distance', data.distance_km != null ? data.distance_km + ' km' : '—');
                setText('ai-duration', data.duration_minutes != null ? data.duration_minutes + ' min' : '—');
                setText('ai-fuel', data.estimated_fuel_l != null ? data.estimated_fuel_l + ' L' : '—');
                setText('ai-cost', formatPeso(data.estimated_cost));
                setText('ai-traffic', data.traffic_level || '—');
                setText('ai-optimization', data.optimization || '—');

                // stash the recommendation (plus route_name/trip_id) for the Apply button
                window.__lastRouteRecommendation = {
                    ...data,
                    route_name: routeName || `${origin} to ${destination}`,
                    trip_id: document.getElementById('route-trip-id')?.value || null,
                };

                // show the suggestion as a popup
                openRecommendationModal();

            } else {
                alert('Gemini Error: ' + data.error);
            }
        } catch (error) {
            console.error(error);
            alert('Unable to connect to the AI route planner');
        } finally {
            button.disabled = false;
            button.innerHTML = '✨ Optimize Route';
        }

    });


// ================= APPLY ROUTE =================
const applyRouteBtn = document.getElementById('apply-route-btn');

if (applyRouteBtn) {
    applyRouteBtn.addEventListener('click', async function () {

        const recommendation = window.__lastRouteRecommendation;
        if (!recommendation) return;

        applyRouteBtn.disabled = true;
        applyRouteBtn.innerText = 'Applying...';

        try {
            const response = await fetch('api/create_route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(recommendation),
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to save route.');
            }

            // Merge the AI recommendation with the saved route so the new row also
            // carries the coordinates (needed for click-to-focus on the map).
            addRouteRow({ ...recommendation, ...data });

            // Plot the newly created route on the Leaflet map, if it's on this page.
            if (typeof window.addRouteToMap === 'function') {
                window.addRouteToMap(data);
            }

            // Remove the trip from the picker since it's now routed.
            const tripSelect = document.getElementById('route-trip');
            if (tripSelect && recommendation.trip_id) {
                const option = tripSelect.querySelector(`option[value="${recommendation.trip_id}"]`);
                if (option) option.remove();
                tripSelect.value = '';
            }

            document.getElementById('route-planner-form').reset();
            document.getElementById('route-trip-id').value = '';
            closeRecommendationModal();
            window.__lastRouteRecommendation = null;

        } catch (error) {
            console.error(error);
            alert('Could not apply the route: ' + error.message);
        } finally {
            applyRouteBtn.disabled = false;
            applyRouteBtn.innerText = 'Apply Route';
        }
    });
}

function addRouteRow(route) {
    const tbody = document.getElementById('route-table-body');

    const emptyRow = document.getElementById('route-table-empty-row');
    if (emptyRow) emptyRow.remove();

    const tr = document.createElement('tr');
    tr.dataset.routeId = route.route_id;

    // Used by route_focus.js: click a row to show only that route on the map.
    tr.dataset.routeName = route.route_name ?? '';
    tr.dataset.origin = route.origin ?? '';
    tr.dataset.destination = route.destination ?? '';
    tr.dataset.originLat = route.origin_lat ?? '';
    tr.dataset.originLng = route.origin_lng ?? '';
    tr.dataset.destinationLat = route.destination_lat ?? '';
    tr.dataset.destinationLng = route.destination_lng ?? '';
    tr.title = 'Click to show this route on the map';

    tr.innerHTML = `
        <td class="py-3 px-4">
            ${escapeHtml(route.route_name)}
            <div class="text-xs text-slate-400">
                ${escapeHtml(route.origin)} → ${escapeHtml(route.destination)}
            </div>
        </td>
        <td>${route.estimated_distance != null ? Number(route.estimated_distance).toFixed(1) + ' km' : '—'}</td>
        <td>${route.estimated_cost != null ? '₱' + Number(route.estimated_cost).toLocaleString() : '—'}</td>
        <td>${route.estimated_duration != null ? route.estimated_duration + ' min' : '—'}</td>
        <td>${route.traffic_level ? escapeHtml(route.traffic_level) : '—'}</td>
        <td>
            <span class="text-xs px-2 py-1 rounded-full bg-slate-100">
                ${escapeHtml(route.status || 'active')}
            </span>
        </td>
    `;

    tbody.prepend(tr);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.innerText = str ?? '';
    return div.innerHTML;
}