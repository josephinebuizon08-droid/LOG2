/**
 * js/session_timeout.js
 *
 * Client-side half of the inactivity auto-logout. The server (see
 * auth/session_guard.php) is the real security boundary -- it rejects
 * requests on an idle session regardless of what the browser does. This
 * file exists so the tab itself redirects to the login page the moment
 * the user goes idle, instead of only finding out on their next click.
 *
 * Keep IDLE_TIMEOUT_MS in sync with SESSION_IDLE_TIMEOUT_SECONDS in
 * auth/session_guard.php.
 */
(function () {
  const IDLE_TIMEOUT_MS = 30 * 60 * 1000;   // 30 minutes
  const WARNING_BEFORE_MS = 60 * 1000;      // show warning 1 minute before logout
  const ACTIVITY_EVENTS = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart'];
  const PING_URL = 'auth/ping.php';
  const LOGOUT_URL = 'auth/logout.php';

  let idleTimer = null;
  let warningTimer = null;
  let warningEl = null;
  let lastPing = 0;

  function goToLogout() {
    window.location.href = LOGOUT_URL;
  }

  function showWarning() {
    if (warningEl) return;

    let secondsLeft = Math.round(WARNING_BEFORE_MS / 1000);

    warningEl = document.createElement('div');
    warningEl.setAttribute('role', 'alertdialog');
    warningEl.style.cssText =
      'position:fixed;bottom:24px;right:24px;z-index:9999;max-width:320px;' +
      'background:#0f172a;color:#fff;border-radius:12px;padding:16px 18px;' +
      'box-shadow:0 10px 30px -8px rgba(0,0,0,.5);font-family:Inter,ui-sans-serif,sans-serif;';

    warningEl.innerHTML =
      '<p style="margin:0 0 4px;font-weight:600;font-size:14px;">You\u2019ve been idle</p>' +
      '<p style="margin:0 0 12px;font-size:13px;color:#cbd5e1;">' +
      'You\u2019ll be signed out in <span data-count>' + secondsLeft + '</span>s due to inactivity.</p>' +
      '<button type="button" data-stay ' +
      'style="width:100%;padding:8px 0;border-radius:8px;border:none;background:#3b82f6;' +
      'color:#fff;font-size:13px;font-weight:600;cursor:pointer;">Stay signed in</button>';

    document.body.appendChild(warningEl);

    const countEl = warningEl.querySelector('[data-count]');
    const countdown = setInterval(() => {
      secondsLeft -= 1;
      if (countEl) countEl.textContent = String(Math.max(secondsLeft, 0));
      if (secondsLeft <= 0) clearInterval(countdown);
    }, 1000);

    warningEl.querySelector('[data-stay]').addEventListener('click', () => {
      clearInterval(countdown);
      hideWarning();
      keepAlive(true);
      resetTimers();
    });
  }

  function hideWarning() {
    if (warningEl) {
      warningEl.remove();
      warningEl = null;
    }
  }

  function keepAlive(force) {
    const now = Date.now();
    if (!force && now - lastPing < 60 * 1000) return;
    lastPing = now;

    fetch(PING_URL, { credentials: 'same-origin' })
      .then((res) => {
        if (res.status === 401) goToLogout();
      })
      .catch(() => {
      });
  }

  function resetTimers() {
    clearTimeout(idleTimer);
    clearTimeout(warningTimer);
    hideWarning();

    warningTimer = setTimeout(showWarning, IDLE_TIMEOUT_MS - WARNING_BEFORE_MS);
    idleTimer = setTimeout(goToLogout, IDLE_TIMEOUT_MS);
  }

  function onActivity() {
    keepAlive(false);
    if (!warningEl) resetTimers();
  }

  ACTIVITY_EVENTS.forEach((evt) => document.addEventListener(evt, onActivity, { passive: true }));
  document.addEventListener('DOMContentLoaded', resetTimers);
  resetTimers();
})();