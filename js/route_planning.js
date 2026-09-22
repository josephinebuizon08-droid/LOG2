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
            const tripId = document.getElementById('route-trip-id').value;

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
                'optimize_route.php',
                {
                    method: 'POST',
                    body: formData
                }
            );

            const data = await response.json();

            if (data.success) {

                document.getElementById('route-ai-result')
                    .classList.remove('hidden');

                document.getElementById('ai-route-summary')
                    .innerText = data.summary; 

                document.getElementById('ai-distance')
                    .innerText = data.distance_km + ' km'; 

                document.getElementById('ai-duration')
                    .innerText = data.duration_minutes + ' min'; 

                document.getElementById('ai-fuel')
                    .innerText = data.estimated_fuel_l + ' L'; 

                document.getElementById('ai-optimization')
                    .innerText = data.optimization; 

                // Stash everything "Apply Route" needs to save this route —
                // including trip_id (not part of the AI response) and the
                // AI's numbers, so applying doesn't need to call Gemini again.
                window.__lastRouteRecommendation = {
                    route_name: routeName,
                    origin: origin,
                    destination: destination,
                    vehicle_id: vehicleId,
                    trip_id: tripId,
                    distance_km: data.distance_km,
                    duration_minutes: data.duration_minutes,
                    estimated_fuel_l: data.estimated_fuel_l,
                    estimated_cost: data.estimated_cost,
                    traffic_level: data.traffic_level,
                    optimization: data.optimization
                };

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

// ---- Apply Route: saves the AI recommendation and adds it to the table ----
const applyRouteBtn = document.getElementById('apply-route-btn');

if (applyRouteBtn) {
    applyRouteBtn.addEventListener('click', async function () {
        const rec = window.__lastRouteRecommendation;

        if (!rec) {
            alert('Run "Optimize Route" first.');
            return;
        }

        const button = this;
        button.disabled = true;
        button.innerHTML = 'Applying...';

        const formData = new FormData();
        Object.entries(rec).forEach(([key, value]) => {
            formData.append(key, value ?? '');
        });

        try {
            const response = await fetch('apply_route.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                addRouteRow(data);
                document.getElementById('route-planner-form').reset();
                document.getElementById('route-ai-result').classList.add('hidden');
                window.__lastRouteRecommendation = null;
            } else {
                alert('Error saving route: ' + data.error);
            }
        } catch (error) {
            console.error(error);
            alert('Unable to save the route');
        } finally {
            button.disabled = false;
            button.innerHTML = 'Apply Route';
        }
    });
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.innerText = value ?? '';
    return div.innerHTML;
}

function addRouteRow(route) {
    const tbody = document.getElementById('route-table-body');
    if (!tbody) return;

    // Remove the "No routes planned yet" placeholder if present
    const emptyRow = document.getElementById('route-table-empty-row');
    if (emptyRow) emptyRow.remove();

    const tr = document.createElement('tr');
    tr.dataset.routeId = route.route_id;

    tr.innerHTML = `
        <td class="py-3 px-4">
            ${escapeHtml(route.route_name)}
            <div class="text-xs text-slate-400">${escapeHtml(route.origin)} → ${escapeHtml(route.destination)}</div>
        </td>
        <td>${route.distance_km ? Number(route.distance_km).toFixed(1) + ' km' : '—'}</td>
        <td>${route.estimated_cost ? '₱' + Number(route.estimated_cost).toLocaleString() : '—'}</td>
        <td>${route.duration_minutes ? route.duration_minutes + ' min' : '—'}</td>
        <td>${route.traffic_level ? escapeHtml(route.traffic_level) : '—'}</td>
        <td>
            <span class="text-xs px-2 py-1 rounded-full bg-slate-100">
                ${escapeHtml(route.status || 'active')}
            </span>
        </td>
    `;

    tbody.prepend(tr);
}