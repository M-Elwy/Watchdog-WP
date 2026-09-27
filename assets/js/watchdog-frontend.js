(function () {
    if (!window || !navigator || !window.location) { return; }
    var endpoint = '/wp-json/site-watchdog/v1/js-error';
    var ignored = /chrome-extension|moz-extension|safari-extension|edge-extension|script error|ResizeObserver loop|csp/i;
    function send(payload) {
        try {
            if (typeof navigator.sendBeacon === 'function') {
                navigator.sendBeacon(endpoint, JSON.stringify(payload));
                return;
            }
            if (typeof fetch === 'function') {
                fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), keepalive: true });
            }
        } catch (e) { }
    }
    function clean(value) {
        if (typeof value === 'string') {
            return value.replace(/\s+/g, ' ').slice(0, 500);
        }
        return value;
    }
    window.onerror = function (msg, src, line, col, error) {
        if (ignored.test(String(msg || '')) || ignored.test(String(src || ''))) { return true; }
        send({ type: 'window.onerror', message: clean(msg), source: clean(src), line: line || 0, column: col || 0, stack: clean(error && error.stack || '') });
        return false;
    };
    window.onunhandledrejection = function (event) {
        var reason = event && event.reason;
        if (!reason) { return; }
        send({ type: 'unhandledrejection', message: clean(reason && reason.message || reason), stack: clean(reason && reason.stack || '') });
    };
})();
