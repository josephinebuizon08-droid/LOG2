<?php 
 
    require_once __DIR__ . '/UI_helpers.php';
    require_once __DIR__ . '/../config/ftms_db.php';
 
    // active routes 
       $activeRoutes = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM routes WHERE status = 'active'"), 0, 0);
       $totalRoutes = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) AS TOTAL FROM routes"), 0, 0);
       $distanceSaved = pg_fetch_result(pg_query($conn, "SELECT SUM (estimated_distance) FROM routes "),0 ,0);
       $estfuel = pg_fetch_result(pg_query($conn, "SELECT COALESCE(SUM(estimated_fuel_saved), 0) FROM routes"),
    0,
    0);

       // ================= ROUTE MAP DATA =================
       $routeMapRes = pg_query($conn, "
           SELECT route_name, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng
           FROM routes
           WHERE origin_lat IS NOT NULL AND origin_lng IS NOT NULL
             AND destination_lat IS NOT NULL AND destination_lng IS NOT NULL
       ");
       $routeMapData = [];
       while ($row = pg_fetch_assoc($routeMapRes)) {
           $routeMapData[] = [
               'route_name'      => $row['route_name'],
               'origin'          => $row['origin'],
               'destination'     => $row['destination'],
               'origin_lat'      => (float) $row['origin_lat'],
               'origin_lng'      => (float) $row['origin_lng'],
               'destination_lat' => (float) $row['destination_lat'],
               'destination_lng' => (float) $row['destination_lng'],
           ];
       }

       // ================= TRIPS AWAITING A ROUTE =================
       // Scheduled trips whose dispatch hasn't been matched to a route yet.
       $pendingTripsRes = pg_query($conn, "
           SELECT
               t.trip_id,
               t.departure_time,
               r.pickup_location,
               r.destination,
               r.requestor,
               v.plate_number,
               v.vehicle_id
           FROM trips t
           JOIN reservations r ON r.reservation_id = t.reservation_id
           LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
           WHERE t.route_id IS NULL
             AND LOWER(t.status) = 'scheduled'
           ORDER BY t.departure_time ASC
       ");
       $pendingTrips = [];
       if ($pendingTripsRes) {
           while ($row = pg_fetch_assoc($pendingTripsRes)) {
               $pendingTrips[] = $row;
           }
       }

       // ================= PLANNED ROUTES TABLE =================
       $plannedRoutesRes = pg_query($conn, "
           SELECT route_id, route_name, origin, destination,
                  origin_lat, origin_lng, destination_lat, destination_lng,
                  estimated_distance, estimated_duration, estimated_fuel_saved,
                  estimated_cost, traffic_level, status
           FROM routes
           ORDER BY route_id DESC
           LIMIT 50
       ");
       $plannedRoutes = [];
       if ($plannedRoutesRes) {
           while ($row = pg_fetch_assoc($plannedRoutesRes)) {
               $plannedRoutes[] = $row;
           }
       }
 ?>                      
            <section id="route" class="section space-y-4">
            
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Active Routes</p>
                                <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $activeRoutes ?></p>
                                <p class="text-xs text-slate-400 mt-1">Active</p>
                            </div>
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(16 185 129 / 0.1)">
                                <i class="ti ti-route text-emerald-500 dark:text-emerald-400 text-lg"></i>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Total Routes</p>
                                <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $totalRoutes ?></p>
                                <p class="text-xs text-slate-400 mt-1">All-time</p>
                            </div>
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(59 130 246 / 0.1)">
                                <i class="ti ti-map-2 text-blue-500 dark:text-blue-400 text-lg"></i>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Distance Saved</p>
                                <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $distanceSaved ?> Km</p>
                                <p class="text-xs text-slate-400 mt-1">Optimized routing</p>
                            </div>
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(245 158 11 / 0.1)">
                                <i class="ti ti-road text-amber-500 dark:text-amber-400 text-lg"></i>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Est. Fuel Saved</p>
                                <p class="text-2xl font-semibold mt-1 text-ink-900 dark:text-white"><?= (int) $estfuel ?> L</p>
                                <p class="text-xs text-slate-400 mt-1">Estimated</p>
                            </div>
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: rgb(239 68 68 / 0.1)">
                                <i class="ti ti-droplet text-red-500 dark:text-red-400 text-lg"></i>
                            </div>
                        </div>
                    </div>

                </div>
                        <!-- map -->
                         <div class="flex gap-4 w-full dark:text-slate-400">
                               <div id="route-map" class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5" style="height: 450px; width: 65%; padding: 0; overflow: hidden;"></div>
                               <script type="application/json" id="route-map-data"><?= json_encode($routeMapData) ?></script>

                              <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5" style="height: 450px; width: 35%; overflow-y: auto;">

                                     <h3 class="font-medium text-gray-700 text-lg mb-4 dark:text-slate-400">
                                        Build Route
                                    </h3>

                                <form id="route-planner-form">

                            <!-- Trip (from Reservation & Dispatch) -->
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">
                                    Trip
                                </label>

                                <select
                                    id="route-trip"
                                    class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none dark:text-slate-400"
                                >
                                    <option value="">Plan manually (no trip)</option>
                                    <?php foreach ($pendingTrips as $trip): ?>
                                        <option
                                            value="<?= (int) $trip['trip_id'] ?>"
                                            data-origin="<?= htmlspecialchars($trip['pickup_location']) ?>"
                                            data-destination="<?= htmlspecialchars($trip['destination']) ?>"
                                            data-vehicle="<?= (int) $trip['vehicle_id'] ?>"
                                            data-plate="<?= htmlspecialchars($trip['plate_number'] ?? '') ?>"
                                            data-requestor="<?= htmlspecialchars($trip['requestor'] ?? '') ?>"
                                        >
                                            #<?= (int) $trip['trip_id'] ?> —
                                            <?= htmlspecialchars($trip['pickup_location']) ?> to
                                            <?= htmlspecialchars($trip['destination']) ?>
                                            (<?= htmlspecialchars($trip['plate_number'] ?? 'no vehicle') ?>,
                                            <?= date('M j, g:i A', strtotime($trip['departure_time'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (empty($pendingTrips)): ?>
                                    <p class="text-xs text-slate-400 mt-1">
                                        No scheduled trips are waiting on a route right now.
                                    </p>
                                <?php endif; ?>

                                <input type="hidden" id="route-trip-id" name="trip_id" value="">
                            </div>

                            <!-- Route Name -->
                            <div class="mb-3">
                                <label class="block text-sm font-medium mb-1">
                                    Route Name
                                </label>

                        <input
                            type="text"
                            id="route-name"
                            name="route_name"
                            class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none"
                            placeholder="e.g. Manila Delivery Route"
                            required
                        >
                    </div>

                    <!-- Origin -->
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">
                            Origin
                        </label>

                            <input
                                type="text"
                                id="route-origin"
                                name="origin"
                                class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none"
                                placeholder="Enter starting location"
                                required
            >
                            </div>

                        <!-- Destination -->
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">
                                Destination
                            </label>

                            <input
                                type="text"
                                id="route-destination"
                                name="destination"
                                class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none"
                                placeholder="Enter destination"
                                required
                            >
                        </div>

                        <!-- Vehicle -->
                        <div class="mb-3">
                            <label class="block text-sm font-medium mb-1">
                                Vehicle
                            </label>

                            <select
                                id="route-vehicle"
                                name="vehicle_id"
                                class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none"
                            >
                                <option value="">Select vehicle</option>
                                 <?php 
                                    $availableVehicles = pg_query($conn, "SELECT vehicle_id, plate_number, vehicle_type, brand, model, capacity FROM vehicles WHERE status = 'Available' ORDER BY plate_number" );

                                        if($availableVehicles):
                                            while ($vehicle = pg_fetch_assoc($availableVehicles)):
                                 ?>
                                        <option value="<?= (int)$vehicle ['vehicle_id'] ?>">
                                        <?=  htmlspecialchars($vehicle['plate_number']) ?>
                                         <?=  htmlspecialchars($vehicle['vehicle_type']) ?>
                                        <?php if (!empty($vehicle['brand'])): ?>
                                         <?= htmlspecialchars($vehicle['brand']) ?>
                                         
                                         <?php endif; ?> 
                                        </option>
                                     <?php 
                                           endwhile;
                                        endif;    
                                     ?>   
                            </select>
                        </div>

                        <!-- Optimization -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-2"> Optimization </label>
                                <select
                                    id="optimization-type"
                                    class="w-full border rounded-lg border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-700 outline-none"
                                >
                                    <option value="balanced">Balanced</option>
                                    <option value="shortest">Shortest Distance</option>
                                    <option value="fastest">Fastest Route</option>
                                    <option value="fuel">Fuel Efficient</option>
                                </select>
                            </div>

                                    <button
                                        type="submit"
                                        id="optimize-route-btn"
                                        class="w-full bg-linear-to-br from-sidebar-blue-900 via-sidebar-blue-300 to-sidebar-blue-600 text-white rounded-lg px-4 py-2 text-sm font-medium"
                                    >
                                        ✨ Optimize Route
                                    </button>

                                </form>



                            </div>
                         </div>

                        <!-- table -->
                         <div class="grid -cols-6 gap-5 dark:text-slate-400 dark:border-slate-700 dark:bg-slate-900">
                            <div class="col-span-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5">
                                <p class="text-sm font-medium mb-4">Route Planned</p>
                                <div class="overflow-hidden rounded-lg border border-slate-100 dark:border-slate-700 dark:bg-slate-900">
                                <table class="w-full text-sm dark:bg-slate-900">
                                  <thead>
                                    <tr class="text-left text-xs text-slate-500 bg-slate-50 border-b border-slate-200 dark:text-slate-400 dark:bg-slate-800 dark:border-slate-700">
                                        <th class="py-3 px-4 font-normal">Route</th>
                                        <th class="font-normal">Distance</th>
                                        <th class="font-normal">Est. cost</th>
                                        <th class="font-normal">Est. time</th>
                                        <th class="font-normal">Traffic</th>
                                        <th class="font-normal">Status</th>
                                    </tr>
                                 </thead>
                                    <tbody id="route-table-body" class="divide-y divide-slate-100">
                                    <?php if (empty($plannedRoutes)): ?>
                                        <tr id="route-table-empty-row">
                                            <td colspan="6" class="py-4 px-4 text-center text-slate-400">
                                                No routes planned yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($plannedRoutes as $route): ?>
                                            <tr data-route-id="<?= (int) $route['route_id'] ?>"
                                                data-route-name="<?= htmlspecialchars((string) $route['route_name']) ?>"
                                                data-origin="<?= htmlspecialchars((string) $route['origin']) ?>"
                                                data-destination="<?= htmlspecialchars((string) $route['destination']) ?>"
                                                data-origin-lat="<?= htmlspecialchars((string) $route['origin_lat']) ?>"
                                                data-origin-lng="<?= htmlspecialchars((string) $route['origin_lng']) ?>"
                                                data-destination-lat="<?= htmlspecialchars((string) $route['destination_lat']) ?>"
                                                data-destination-lng="<?= htmlspecialchars((string) $route['destination_lng']) ?>"
                                                title="Click to show this route on the map">
                                                <td class="py-3 px-4">
                                                    <?= htmlspecialchars($route['route_name']) ?>
                                                    <div class="text-xs text-slate-400">
                                                        <?= htmlspecialchars($route['origin']) ?> → <?= htmlspecialchars($route['destination']) ?>
                                                    </div>
                                                </td>
                                                <td><?= $route['estimated_distance'] !== null ? round((float) $route['estimated_distance'], 1) . ' km' : '—' ?></td>
                                                <td><?= $route['estimated_cost'] !== null ? '₱' . number_format((float) $route['estimated_cost'], 2) : '—' ?></td>
                                                <td><?= $route['estimated_duration'] !== null ? (int) $route['estimated_duration'] . ' min' : '—' ?></td>
                                                <td><?= $route['traffic_level'] !== null ? htmlspecialchars($route['traffic_level']) : '—' ?></td>
                                                <td>
                                                    <span class="text-xs px-2 py-1 rounded-full bg-slate-100">
                                                        <?= htmlspecialchars($route['status'] ?? 'active') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                         </div>
                    </section>

<!-- ================= AI ROUTE RECOMMENDATION POPUP ================= -->
<style>
    .route-modal { position: fixed; inset: 0; z-index: 9999; overflow-y: auto; background: rgba(2, 6, 23, .6); backdrop-filter: blur(2px); }
    .route-modal.hidden { display: none; }
    .route-modal-wrap { min-height: 100%; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .route-modal-card {
        width: 100%; max-width: 30rem; background: #fff; color: #0f172a;
        border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem 1.5rem;
        box-shadow: 0 20px 50px rgba(0, 0, 0, .35); animation: route-modal-in .18s ease-out;
    }
    .dark .route-modal-card { background: #0f172a; color: #e2e8f0; border-color: #1e293b; }
    @keyframes route-modal-in { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .route-modal-card { animation: none; } }

    .route-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
    .route-modal-eyebrow { font-size: .75rem; font-weight: 600; letter-spacing: .04em; color: #2563eb; }
    .dark .route-modal-eyebrow { color: #60a5fa; }
    .route-modal-title { font-size: 1.05rem; font-weight: 600; margin-top: .15rem; }
    .route-modal-path { font-size: .75rem; color: #64748b; margin-top: .2rem; word-break: break-word; }
    .dark .route-modal-path { color: #94a3b8; }
    .route-modal-x {
        flex-shrink: 0; width: 2rem; height: 2rem; border-radius: 9999px; border: 0; cursor: pointer;
        background: transparent; color: #64748b; font-size: 1.4rem; line-height: 1;
    }
    .route-modal-x:hover { background: rgba(148, 163, 184, .2); }

    .route-modal-summary { margin-top: .9rem; font-size: .875rem; line-height: 1.5; color: #475569; }
    .dark .route-modal-summary { color: #cbd5e1; }

    .route-modal-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .6rem; margin-top: 1rem; }
    .route-modal-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .75rem; padding: .6rem .8rem; }
    .dark .route-modal-stat { background: #1e293b; border-color: #334155; }
    .route-modal-stat span { display: block; font-size: .7rem; color: #94a3b8; }
    .route-modal-stat p { margin-top: .1rem; font-size: .95rem; font-weight: 600; text-transform: capitalize; }

    .route-modal-actions { display: flex; gap: .6rem; margin-top: 1.25rem; }
    .route-modal-actions button { flex: 1; padding: .55rem 1rem; border-radius: .6rem; font-size: .875rem; font-weight: 500; cursor: pointer; }
    .route-btn-secondary { background: transparent; border: 1px solid #cbd5e1; color: inherit; }
    .route-btn-secondary:hover { background: rgba(148, 163, 184, .15); }
    .route-btn-primary { border: 1px solid transparent; color: #fff; background: linear-gradient(135deg, #1e3a8a, #2563eb); }
    .route-btn-primary:hover { filter: brightness(1.1); }
    .route-btn-primary:disabled { opacity: .6; cursor: wait; }
</style>

<div id="route-ai-result" class="route-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ai-route-name" aria-hidden="true">
    <div class="route-modal-wrap">
        <div class="route-modal-card">

            <div class="route-modal-header">
                <div>
                    <p class="route-modal-eyebrow">✨ AI ROUTE RECOMMENDATION</p>
                    <h4 id="ai-route-name" class="route-modal-title"></h4>
                    <p id="ai-route-path" class="route-modal-path"></p>
                </div>
                <button type="button" id="route-modal-close" class="route-modal-x" aria-label="Close">&times;</button>
            </div>

            <p id="ai-route-summary" class="route-modal-summary"></p>

            <div class="route-modal-grid">
                <div class="route-modal-stat"><span>Distance</span><p id="ai-distance"></p></div>
                <div class="route-modal-stat"><span>Est. Time</span><p id="ai-duration"></p></div>
                <div class="route-modal-stat"><span>Est. Fuel</span><p id="ai-fuel"></p></div>
                <div class="route-modal-stat"><span>Est. Cost</span><p id="ai-cost"></p></div>
                <div class="route-modal-stat"><span>Traffic</span><p id="ai-traffic"></p></div>
                <div class="route-modal-stat"><span>Optimization</span><p id="ai-optimization"></p></div>
            </div>

            <div class="route-modal-actions">
                <button type="button" id="route-modal-cancel" class="route-btn-secondary">Cancel</button>
                <button type="button" id="apply-route-btn" class="route-btn-primary">Apply Route</button>
            </div>

        </div>
    </div>
</div>

<!-- route_focus.js must come BEFORE route_planner.js -->
<script src="js/route_focus.js" defer></script>
<script src="js/route_planner.js" defer></script>
<script>
// Auto-fill the Build Route form when a trip is selected.
const routeTripSelect = document.getElementById('route-trip');

if (routeTripSelect) {
    routeTripSelect.addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        const tripIdInput = document.getElementById('route-trip-id');
        const nameInput = document.getElementById('route-name');
        const originInput = document.getElementById('route-origin');
        const destinationInput = document.getElementById('route-destination');
        const vehicleSelect = document.getElementById('route-vehicle');

        if (!this.value) {
            tripIdInput.value = '';
            return;
        }

        tripIdInput.value = this.value;
        originInput.value = selected.dataset.origin || '';
        destinationInput.value = selected.dataset.destination || '';

        if (!nameInput.value) {
            // Use a short label (just the part before the first comma) rather
            // than the full address, so auto-generated names stay readable
            // even when Origin/Destination are full street addresses.
            const shortLabel = (text) => (text || '').split(',')[0].trim();
            nameInput.value = `${shortLabel(selected.dataset.origin)} to ${shortLabel(selected.dataset.destination)}`;
        }

        // Pre-select the vehicle already assigned to this trip's dispatch, if present.
        // That vehicle won't be in the dropdown if its status isn't "Available"
        // (it's usually Reserved/In Transit precisely because it's on this trip),
        // so add it as an option rather than silently failing to select it.
        const vehicleId = selected.dataset.vehicle;
        if (vehicleSelect && vehicleId) {
            let option = vehicleSelect.querySelector(`option[value="${vehicleId}"]`);
            if (!option) {
                option = document.createElement('option');
                option.value = vehicleId;
                option.textContent = selected.dataset.plate
                    ? `${selected.dataset.plate} (assigned to this trip)`
                    : `Vehicle #${vehicleId} (assigned to this trip)`;
                vehicleSelect.appendChild(option);
            }
            vehicleSelect.value = vehicleId;
        }
    });
}
</script>