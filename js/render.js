
function badge(text, colorClasses) {
  return `<span class="px-2 py-0.5 rounded-full ${colorClasses} text-xs">${text}</span>`;
}

function manifestTag(text) {
  return `<span class="manifest px-2 py-0.5 text-xs">${text}</span>`;
}

function renderDashboard() {
  const statsEl = document.getElementById('dashboard-stats');
  if (statsEl) statsEl.innerHTML = DASHBOARD_STATS.map(s => `
    <div class="card accent-l ${s.accent} p-5">
      <p class="text-xs text-slate-500">${s.label}</p>
      <p class="text-2xl font-medium mt-1">${s.value}</p>
      <p class="text-xs ${s.noteColor} mt-1">${s.note}</p>
    </div>
  `).join('');

  const dispatchBody = document.getElementById('dispatch-table-body');
  if (dispatchBody) dispatchBody.innerHTML = RECENT_DISPATCHES.map(d => `
    <tr>
      <td class="py-2.5">${manifestTag(d.trip)}</td>
      <td class="tag text-xs">${d.vehicle}</td>
      <td>${d.driver}</td>
      <td>${d.route}</td>
      <td>${badge(d.status, d.statusColor)}</td>
    </tr>
  `).join('');

  const fleetStatusEl = document.getElementById('fleet-status');
  if (fleetStatusEl) fleetStatusEl.innerHTML = FLEET_STATUS.map(f => `
    <div class="flex justify-between text-sm"><span class="text-slate-500">${f.label}</span><span class="font-medium">${f.value}</span></div>
    <div class="w-full h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full ${f.color}" style="width:${f.pct}%"></div></div>
  `).join('');
}

function renderfleet() {
  const body = document.getElementById('fvm-table-body');
  if (!body) return; // fleet.php now renders this table server-side via PHP
  body.innerHTML = VEHICLES.map(v => `
    <tr>
      <td class="py-3 px-4">${manifestTag(v.id)}</td>
      <td>${v.type}</td>
      <td class="tag text-xs">${v.plate}</td>
      <td>${v.driver}</td>
      <td>${v.lastMaintenance}</td>
      <td>${badge(v.status, v.statusColor)}</td>
    </tr>
  `).join('');
}

function renderreservation() {
  const body = document.getElementById('vrds-table-body');
  if (!body) return; // reservation.php now renders this table server-side via PHP
  body.innerHTML = RESERVATIONS.map(r => `
    <tr>
      <td class="py-3 px-4">${manifestTag(r.id)}</td>
      <td>${r.requestedBy}</td>
      <td>${r.route}</td>
      <td class="tag text-xs">${r.vehicle}</td>
      <td>${r.driver}</td>
      <td>${badge(r.status, r.statusColor)}</td>
    </tr>
  `).join('');
}

function rendermonitoring() {
  const statsEl = document.getElementById('dtpm-stats');
  if (!statsEl) return; // monitoring.php now renders this section server-side via PHP
  statsEl.innerHTML = DTPM_STATS.map(s => `
    <div class="card accent-l ${s.accent} p-5">
      <p class="text-xs text-slate-500">${s.label}</p>
      <p class="text-2xl font-medium mt-1 ${s.valueColor}">${s.value}</p>
    </div>
  `).join('');

  const body = document.getElementById('dtpm-table-body');
  body.innerHTML = DRIVER_PERFORMANCE.map(d => `
    <tr>
      <td class="py-3 px-4">${d.driver}</td>
      <td>${manifestTag(d.trip)}</td>
      <td>${d.distance}</td>
      <td><span class="${d.scoreColor} font-medium">${d.score}</span></td>
      <td>${d.flags}</td>
    </tr>
  `).join('');
}

function renderfuel() {
  const statsEl = document.getElementById('fuel-stats');
  if (statsEl) statsEl.innerHTML = FUEL_STATS.map(s => `
    <div class="card accent-l ${s.accent} p-5 ${s.icon ? 'flex items-center justify-between' : ''}">
      <div>
        <p class="text-xs text-slate-500">${s.label}</p>
        <p class="text-2xl font-medium mt-1 ${s.valueColor}">${s.value}</p>
      </div>
      ${s.icon ? '<i class="ti ti-alert-triangle text-red-400 text-2xl"></i>' : ''}
    </div>
  `).join('');

  const body = document.getElementById('fuel-table-body');
  if (body) body.innerHTML = FUEL_LOGS.map(f => `
    <tr>
      <td class="py-3 px-4">${manifestTag(f.vehicle)}</td>
      <td>${f.date}</td>
      <td>${f.liters}</td>
      <td>${f.cost}</td>
      <td>${f.odometer}</td>
      <td>${badge(f.validation, f.validationColor)}</td>
    </tr>
  `).join('');
}

function rendertransport() {
  const breakdownEl = document.getElementById('cost-breakdown');
  if (!breakdownEl) return; // transport.php now renders this section server-side via PHP
  breakdownEl.innerHTML = COST_BREAKDOWN.map(c => `
    <div>
      <div class="flex justify-between mb-1"><span>${c.label}</span><span class="text-slate-500">${c.value}</span></div>
      <div class="w-full h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full ${c.color}" style="width:${c.pct}%"></div></div>
    </div>
  `).join('');

  const recEl = document.getElementById('recommendations');
  recEl.innerHTML = RECOMMENDATIONS.map(r => `
    <li class="flex gap-2"><i class="ti ti-bulb text-signal mt-0.5"></i> ${r}</li>
  `).join('');
}

function renderroute() {
  // route.php now renders the Route Planned table server-side via PHP
  // (and route_planner.js appends new rows live after "Apply Route").
  // ROUTES in data.js is unused legacy data — do NOT overwrite the table
  // here, or it wipes out the real server-rendered rows on every page load.
  return;
}

function rendersettings() {
  // settings.php renders all forms server-side via PHP
  // No client-side rendering needed
  return;
}

function rendersecurity() {
  // security.php renders all forms server-side via PHP
  // No client-side rendering needed
  return;
}

function renderAll() {
  renderDashboard();
  renderfleet();
  renderreservation();
  rendermonitoring();
  renderfuel();
  rendertransport();
  renderroute();
  rendersettings();
  rendersecurity();
}