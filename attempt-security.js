const AttemptSecurity = (() => {
  let state = null;
  let heartbeatTimer = null;
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
      stop();
      state?.onTerminated?.(data.result || null);
    }
    return data;
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
      const data = await response.json();
      if (data?.active === false) {
        const callback = state.onTerminated;
        stop();
        callback?.(null);
      }
    } catch {
      // Temporary connection errors must not destroy a valid attempt locally.
    }
  }

  function handleVisibility() {
    if (!state) return;
    if (document.hidden) {
      if (hiddenHandled) return;
      hiddenHandled = true;

      if (state.focusPolicy === 'strict') {
        state.locked = true;
        state.onLocked?.();
      }

      sendEvent('hidden', true).catch(() => {});
    } else {
      hiddenHandled = false;
      if (state.focusPolicy !== 'strict' || !state.locked) {
        sendEvent('visible').catch(() => {});
      }
    }
  }

  function start({ attemptId, focusPolicy = 'allow', onLocked = null, onTerminated = null }) {
    stop();
    state = {
      attemptId: Number(attemptId),
      focusPolicy,
      locked: false,
      onLocked,
      onTerminated
    };

    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('pagehide', handleVisibility);
    heartbeatTimer = window.setInterval(heartbeat, 15000);
    heartbeat();
  }

  function stop() {
    document.removeEventListener('visibilitychange', handleVisibility);
    window.removeEventListener('pagehide', handleVisibility);
    if (heartbeatTimer) window.clearInterval(heartbeatTimer);
    heartbeatTimer = null;
    state = null;
    hiddenHandled = false;
  }

  function isLocked() {
    return Boolean(state?.locked);
  }

  return { start, stop, isLocked };
})();
