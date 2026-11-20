export function createPoller({ run, active, delay = 10000, schedule = setTimeout, cancel = clearTimeout }) {
    let timer = null;
    let running = false;
    let stopped = false;
    let paused = false;
    async function tick() {
        timer = null;
        if (stopped || paused || running) return;
        running = true;
        try { if (active()) await run(); }
        finally {
            running = false;
            if (!stopped && !paused) timer = schedule(tick, delay);
        }
    }
    return {
        start() { if (!stopped && !paused && timer === null && !running) timer = schedule(tick, delay); },
        wake() { if (stopped || paused || running) return; if (timer !== null) cancel(timer); timer = schedule(tick, 0); },
        pause() { paused = true; if (timer !== null) cancel(timer); timer = null; },
        resume() { if (stopped) return; paused = false; this.start(); },
        stop() { stopped = true; if (timer !== null) cancel(timer); timer = null; },
    };
}
