const AttemptSecurity = (() => {
  let state = null;
  let heartbeatTimer = null;
  let focusLossTimer = null;
  const HEARTBEAT_MIN_MS = 25000;
  const HEARTBEAT_JITTER_MS = 10000;
  const FOCUS_LOSS_GRACE_MS = 1200;
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

  function terminateForFocusLoss(eventType = 'hidden') {
    if (!state || hiddenHandled) return;
    hiddenHandled = true;
    state.locked = true;
    if (focusLossTimer) window.clearTimeout(focusLossTimer);
    focusLossTimer = null;
    state.onLocked?.(eventType);
    state.onHidden?.(eventType);
    sendEvent(eventType === 'blur' ? 'blur' : 'hidden', true).catch(() => {});
  }

  function handleVisibility() {
    if (!state) return;
    if (document.hidden) {
      terminateForFocusLoss('hidden');
      return;
    }

    // A hidden tab never resumes the same attempt. Check the server
    // immediately when the student returns so the final result is shown.
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
      if (state && !document.hasFocus()) {
        terminateForFocusLoss('blur');
      }
    }, FOCUS_LOSS_GRACE_MS);
  }

  function handleWindowFocus() {
    if (focusLossTimer) window.clearTimeout(focusLossTimer);
    focusLossTimer = null;
    if (!state || state.locked) return;
    sendEvent('focus').catch(() => {});
  }

  function handlePageHide() {
    terminateForFocusLoss('hidden');
  }

  function start({ attemptId, focusPolicy = 'allow', onLocked = null, onHidden = null, onTerminated = null }) {
    stop();
    state = {
      attemptId: Number(attemptId),
      focusPolicy,
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
    if (focusLossTimer) window.clearTimeout(focusLossTimer);
    heartbeatTimer = null;
    focusLossTimer = null;
    state = null;
    hiddenHandled = false;
  }

  function isLocked() {
    return Boolean(state?.locked);
  }

  return { start, stop, isLocked };
})();
