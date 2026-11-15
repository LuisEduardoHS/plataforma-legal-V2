export function createPoller({ run, active, delay = 10000, schedule = setTimeout, cancel = clearTimeout }) {
    let timer = null;
    let running = false;
    let stopped = false;
    async function tick() {
        timer = null;
        if (stopped || running) return;
        running = true;
        try { if (active()) await run(); }
        finally {
            running = false;
            if (!stopped) timer = schedule(tick, delay);
        }
    }
    return {
        start() { if (!stopped && timer === null && !running) timer = schedule(tick, delay); },
        wake() { if (stopped || running) return; if (timer !== null) cancel(timer); timer = schedule(tick, 0); },
        stop() { stopped = true; if (timer !== null) cancel(timer); timer = null; },
    };
}
