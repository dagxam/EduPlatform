const AttemptSecurity = (() => {
  let state = null;
  let heartbeatTimer = null;
  let focusLossTimer = null;
  let hiddenTimer = null;
  const HEARTBEAT_MIN_MS = 25000;
  const HEARTBEAT_JITTER_MS = 10000;
  const FOCUS_LOSS_GRACE_MS = 3000;
  const HIDDEN_GRACE_MS = 1800;
  let hiddenHandled = false;

  async function sendEvent(eventType, keepalive = false) {
    if (!state) return null;
    const response = await fetch('./api/attempts/security-event.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ attempt_id: state.attemptId, event_type: eventType }),
      keepalive
    });
    const data = await response.json().catch(() => null);
    if (data?.terminated) {
      const callback = state?.onTerminated;
      stop();
      callback?.(data.result || null, data?.reason || null);
    }
    return data;
  }

  function scheduleHeartbeat(delay = null) {
    if (!state) return;
    if (heartbeatTimer) window.clearTimeout(heartbeatTimer);
    const nextDelay = delay ?? (HEARTBEAT_MIN_MS + Math.floor(Math.random() * HEARTBEAT_JITTER_MS));
    heartbeatTimer = window.setTimeout(async () => {
      await heartbeat();
      scheduleHeartbeat();
    }, nextDelay);
  }

  async function heartbeat() {
    if (!state || document.hidden) return;
    try {
      const response = await fetch('./api/attempts/heartbeat.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ attempt_id: state.attemptId })
      });
      const data = await response.json().catch(() => null);
      if (data?.active === false) {
        const callback = state.onTerminated;
        stop();
        callback?.(data?.result || null, data?.reason || null);
      }
    } catch {
      // Temporary connection errors must not destroy a valid attempt locally.
    }
  }

  function clearFocusTimers() {
    if (focusLossTimer) window.clearTimeout(focusLossTimer);
    if (hiddenTimer) window.clearTimeout(hiddenTimer);
    focusLossTimer = null;
    hiddenTimer = null;
  }

  function isStrict() {
    return String(state?.focusPolicy || 'allow') === 'strict';
  }

  function terminateForFocusLoss(eventType = 'hidden') {
    if (!state || hiddenHandled || !isStrict()) return;
    hiddenHandled = true;
    state.locked = true;
    clearFocusTimers();
    state.onLocked?.(eventType);
    state.onHidden?.(eventType);
    sendEvent(eventType === 'blur' ? 'blur' : 'hidden', true).catch(() => {});
  }

  function handleVisibility() {
    if (!state) return;

    if (document.hidden) {
      if (!isStrict()) {
        sendEvent('hidden', true).catch(() => {});
        return;
      }

      if (hiddenTimer) window.clearTimeout(hiddenTimer);
      hiddenTimer = window.setTimeout(() => {
        hiddenTimer = null;
        if (state && document.hidden) {
          terminateForFocusLoss('hidden');
        }
      }, HIDDEN_GRACE_MS);
      return;
    }

    if (hiddenTimer) window.clearTimeout(hiddenTimer);
    hiddenTimer = null;

    if (state.locked) {
      heartbeat();
      window.setTimeout(heartbeat, 700);
      return;
    }

    hiddenHandled = false;
    sendEvent('visible').catch(() => {});
  }

  function handleWindowBlur() {
    if (!state || state.locked) return;
    if (focusLossTimer) window.clearTimeout(focusLossTimer);

    focusLossTimer = window.setTimeout(() => {
      focusLossTimer = null;
      if (!state || document.hasFocus()) return;

      if (isStrict()) {
        terminateForFocusLoss('blur');
      } else {
        sendEvent('blur', true).catch(() => {});
      }
    }, FOCUS_LOSS_GRACE_MS);
  }

  function handleWindowFocus() {
    if (focusLossTimer) window.clearTimeout(focusLossTimer);
    focusLossTimer = null;
    if (!state || state.locked) return;
    hiddenHandled = false;
    sendEvent('focus').catch(() => {});
  }

  function handlePageHide() {
    if (!state) return;
    if (isStrict()) {
      terminateForFocusLoss('hidden');
    } else {
      sendEvent('hidden', true).catch(() => {});
    }
  }

  function start({ attemptId, focusPolicy = 'allow', onLocked = null, onHidden = null, onTerminated = null }) {
    stop();
    state = {
      attemptId: Number(attemptId),
      focusPolicy: String(focusPolicy || 'allow'),
      locked: false,
      onLocked,
      onHidden,
      onTerminated
    };

    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('blur', handleWindowBlur);
    window.addEventListener('focus', handleWindowFocus);
    window.addEventListener('pagehide', handlePageHide);
    heartbeat();
    scheduleHeartbeat(25000 + Math.floor(Math.random() * 5000));
  }

  function stop() {
    document.removeEventListener('visibilitychange', handleVisibility);
    window.removeEventListener('blur', handleWindowBlur);
    window.removeEventListener('focus', handleWindowFocus);
    window.removeEventListener('pagehide', handlePageHide);
    if (heartbeatTimer) window.clearTimeout(heartbeatTimer);
    clearFocusTimers();
    heartbeatTimer = null;
    state = null;
    hiddenHandled = false;
  }

  function isLocked() {
    return Boolean(state?.locked);
  }

  return { start, stop, isLocked };
})();
