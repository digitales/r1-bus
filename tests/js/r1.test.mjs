// Runs the inline script from resources/views/r1.blade.php against a minimal
// fake DOM, so the screen rules can be checked without a browser.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/r1.blade.php', import.meta.url), 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace('@json($arrivalsUrl)', '"/r1/token/arrivals"')
    .replace('@json($refreshSeconds)', '20');

class El {
    constructor() {
        this.children = [];
        this.listeners = {};
        this.hidden = false;
        this.className = '';
        this.scrolled = 0;
        this.text = '';
    }
    get textContent() { return this.text; }
    set textContent(value) { this.text = String(value); this.children = []; }
    addEventListener(type, handler) { this.listeners[type] = handler; }
    appendChild(child) { this.children.push(child); }
    scrollBy(x, y) { this.scrolled += y; }
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

function boot() {
    const els = {};
    for (const id of ['head', 'stop', 'towards', 'list', 'msg', 'check', 'foot']) { els[id] = new El(); }
    els.list.hidden = true;
    els.check.hidden = true;

    const page = { els, calls: [], now: 0, timers: [], windowListeners: {}, poll: null };

    vm.runInNewContext(script, {
        document: { getElementById: (id) => els[id], createElement: () => new El() },
        window: { addEventListener: (type, handler) => { page.windowListeners[type] = handler; } },
        fetch: (url, options) => new Promise((resolve, reject) => {
            page.calls.push({ url, resolve, reject });
            if (options.signal) { options.signal.addEventListener('abort', () => reject(new Error('aborted'))); }
        }),
        setInterval: (handler) => { page.poll = handler; },
        setTimeout: (handler, ms) => { const timer = { handler, at: page.now + ms, cleared: false }; page.timers.push(timer); return timer; },
        clearTimeout: (timer) => { if (timer) { timer.cleared = true; } },
        Date: { now: () => page.now },
        AbortController,
    });

    page.respond = async (body) => {
        page.calls.at(-1).resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
        await flush();
    };
    page.drop = async () => {
        page.calls.at(-1).reject(new Error('offline'));
        await flush();
    };
    page.advance = async (ms) => {
        page.now += ms;
        for (const timer of page.timers) {
            if (!timer.cleared && timer.at <= page.now) { timer.cleared = true; timer.handler(); }
        }
        await flush();
    };
    page.rows = () => els.list.children.map((row) => row.children.map((cell) => cell.text).join(' | '));

    return page;
}

const base = { stop: null, arrivals: [], fetched_at: null, fetched_label: null, stale: false, stale_minutes: 0, next_window: null, next_window_label: null };
const buses = [{ route: '73', destination: 'Stoke Newington', minutes: 3 }, { route: '19', destination: 'Battersea', minutes: 0 }];
const live = (label, arrivals = buses) => ({
    ...base, state: 'live', direction: 'outward', stop: { name: 'Angel Station', letter: 'F', towards: 'Holborn' }, arrivals, fetched_label: label,
});
const liveInward = (label) => ({
    ...base, state: 'live', direction: 'inward', stop: { name: 'Kings Cross Station', letter: 'E', towards: null }, arrivals: buses, fetched_label: label,
});
const outside = { ...base, state: 'outside_window', next_window_label: 'Next check 14:30' };

test('inside a window it lists buses, hides the button and keeps polling', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    assert.equal(page.els.stop.text, 'Angel Station, Stop F');
    assert.equal(page.els.towards.text, 'Outward, towards Holborn');
    assert.deepEqual(page.rows(), ['73 | Stoke Newington | 3 min', '19 | Battersea | due']);
    assert.equal(page.els.list.hidden, false);
    assert.equal(page.els.check.hidden, true);
    assert.equal(page.els.foot.text, 'Updated 07:41');

    page.poll();
    assert.equal(page.calls.length, 2);
    assert.equal(page.calls[1].url, '/r1/token/arrivals?direction=outward');
});

test('outside a window it shows the next window and a Check now button', async () => {
    const page = boot();
    await page.respond(outside);

    assert.equal(page.els.stop.text, 'Outside hours');
    assert.equal(page.els.msg.text, 'Next check 14:30');
    assert.equal(page.els.check.hidden, false);
    assert.equal(page.els.check.text, 'Check now');
});

test('a Check now result stays on screen through later outside-window polls', async () => {
    const page = boot();
    await page.respond(outside);

    page.els.check.listeners.click();
    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=outward&check=1');
    await page.respond(live('10:02'));
    page.poll();
    await page.respond(outside);

    assert.equal(page.els.list.hidden, false);
    assert.equal(page.els.foot.text, 'Checked 10:02');
    assert.equal(page.els.check.text, 'Check again');
});

test('the side button flips direction inside a window and checks outside one', async () => {
    const inside = boot();
    await inside.respond(live('07:41'));
    inside.windowListeners.sideClick();
    assert.equal(inside.calls.at(-1).url, '/r1/token/arrivals?direction=inward');

    const out = boot();
    await out.respond(outside);
    out.windowListeners.sideClick();
    assert.equal(out.calls.at(-1).url, '/r1/token/arrivals?direction=outward&check=1');
});

test('tapping the header shows the other direction and tapping again comes back', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.els.head.listeners.click();
    assert.equal(page.els.towards.text, 'Inward');
    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=inward');
    await page.respond(liveInward('07:41'));

    assert.equal(page.els.stop.text, 'Kings Cross Station, Stop E');
    assert.equal(page.els.towards.text, 'Inward');
    assert.equal(page.els.check.hidden, true);

    page.els.head.listeners.click();
    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=outward');
    await page.respond(live('07:42'));
    assert.equal(page.els.towards.text, 'Outward, towards Holborn');
});

test('tapping the header outside a window checks the other direction', async () => {
    const page = boot();
    await page.respond(outside);

    page.els.head.listeners.click();
    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=inward&check=1');
    await page.respond(liveInward('10:02'));

    assert.equal(page.els.stop.text, 'Kings Cross Station, Stop E');
    assert.equal(page.els.check.text, 'Check again');
});

test('a flip during a request ignores the old answer and asks again', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.poll();
    page.els.head.listeners.click();
    assert.equal(page.calls.length, 2);
    await page.respond(live('07:42'));

    assert.equal(page.els.towards.text, 'Inward');
    assert.equal(page.calls.length, 3);
    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=inward');
    await page.respond(liveInward('07:42'));
    assert.equal(page.els.stop.text, 'Kings Cross Station, Stop E');
});

test('a direction with no stop says which one is missing', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.els.head.listeners.click();
    await page.respond({ ...base, state: 'no_stop', direction: 'inward' });

    assert.equal(page.els.msg.text, 'No inward stop. Use admin');
    assert.equal(page.els.towards.text, 'Inward');
});

test('the next window starts on outward again', async () => {
    const page = boot();
    await page.respond(live('08:59'));
    page.els.head.listeners.click();
    await page.respond(liveInward('08:59'));

    page.poll();
    await page.respond(outside);
    page.poll();

    assert.equal(page.calls.at(-1).url, '/r1/token/arrivals?direction=outward');
});

test('the scroll wheel moves the list', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.windowListeners.scrollDown();
    page.windowListeners.scrollDown();
    page.windowListeners.scrollUp();

    assert.equal(page.els.list.scrolled, 38);
});

test('a window opening replaces a Check now result with live polling', async () => {
    const page = boot();
    await page.respond(outside);
    page.els.check.listeners.click();
    await page.respond(live('14:29'));

    page.poll();
    await page.respond(live('14:30'));

    assert.equal(page.els.check.hidden, true);
    assert.equal(page.els.foot.text, 'Updated 14:30');
});

test('a stalled request is given up after 15 seconds so polling carries on', async () => {
    const page = boot();

    await page.advance(15000);
    page.poll();

    assert.equal(page.calls.length, 2);
    assert.equal(page.els.foot.text, 'No connection, retrying');
});

test('a failed poll keeps the time of the last good update in the footer', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.poll();
    await page.drop();

    assert.equal(page.els.foot.text, 'No connection, last 07:41');
    assert.equal(page.els.list.hidden, false);
});

test('after a minute of failures the old bus times are taken off the screen', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    for (let attempt = 0; attempt < 4; attempt++) {
        page.poll();
        await page.drop();
        await page.advance(20000);
    }

    assert.equal(page.els.list.hidden, true);
    assert.equal(page.els.msg.text, 'No connection');
});

test('a short outage does not take the bus times off the screen', async () => {
    const page = boot();
    await page.respond(live('07:41'));

    page.poll();
    await page.drop();
    await page.advance(20000);
    page.poll();
    await page.drop();

    assert.equal(page.els.list.hidden, false);
});

test('the footer of a Check now result comes back after a failed poll recovers', async () => {
    const page = boot();
    await page.respond(outside);
    page.els.check.listeners.click();
    await page.respond(live('10:02'));

    page.poll();
    await page.drop();
    assert.equal(page.els.foot.text, 'No connection, last 10:02');

    page.poll();
    await page.respond(outside);
    assert.equal(page.els.foot.text, 'Checked 10:02');
});

test('bus times come back after an outage ends', async () => {
    const page = boot();
    await page.respond(live('07:41'));
    for (let attempt = 0; attempt < 4; attempt++) {
        page.poll();
        await page.drop();
        await page.advance(20000);
    }

    page.poll();
    await page.respond(live('07:43'));

    assert.equal(page.els.list.hidden, false);
    assert.equal(page.els.foot.text, 'Updated 07:43');
});
