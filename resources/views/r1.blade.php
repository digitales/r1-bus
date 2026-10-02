<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=240, height=282, initial-scale=1, user-scalable=no">
    <title>Bus times</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 240px; height: 282px; overflow: hidden; background: #000; color: #fff; font-family: -apple-system, system-ui, sans-serif; }
        body { display: flex; flex-direction: column; }
        [hidden] { display: none !important; }
        #head { padding: 6px 8px 4px; border-bottom: 1px solid #333; }
        #stop, #towards, .dest { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #top { display: flex; align-items: baseline; gap: 6px; }
        #stop { flex: 1; min-width: 0; font-size: 15px; font-weight: 700; }
        #count { font-size: 11px; color: #aaa; font-variant-numeric: tabular-nums; }
        #towards { font-size: 11px; color: #aaa; min-height: 13px; }
        #list { flex: 1; overflow-y: auto; list-style: none; }
        #list li { display: flex; align-items: center; gap: 6px; height: 38px; padding: 0 8px; border-bottom: 1px solid #222; }
        .route { min-width: 48px; font-size: 20px; font-weight: 800; color: #ff6b00; }
        .dest { flex: 1; font-size: 12px; }
        .mins { font-size: 15px; font-weight: 700; }
        #msg { flex: 1; display: flex; align-items: center; justify-content: center; padding: 12px; text-align: center; font-size: 16px; }
        #check { margin: 0 8px 6px; padding: 8px; border: 0; border-radius: 6px; background: #ff6b00; color: #000; font-size: 15px; font-weight: 700; }
        #foot { min-height: 22px; padding: 4px 8px; border-top: 1px solid #333; font-size: 11px; color: #aaa; }
    </style>
</head>
<body>
    <header id="head">
        <div id="top">
            <div id="stop">Bus times</div>
            <div id="count" hidden></div>
        </div>
        <div id="towards"></div>
    </header>
    <ul id="list" hidden></ul>
    <div id="msg">Loading</div>
    <button id="check" type="button" hidden>Check now</button>
    <footer id="foot"></footer>

    <script>
        (function () {
            var url = @json($arrivalsUrl);
            var every = @json($refreshSeconds) * 1000;
            var inWindow = false;
            var showingCheck = false;
            var busy = false;
            var footText = '';
            var lastLabel = null;
            var failedSince = null;
            var direction = 'outward';
            var again = false;
            var nextPollAt = Date.now() + every;
            var sleepUntil = null;

            function el(id) { return document.getElementById(id); }

            function setHead(title, sub) {
                el('stop').textContent = title;
                el('towards').textContent = sub;
            }

            function setMessage(text) {
                el('list').hidden = true;
                el('msg').hidden = false;
                el('msg').textContent = text;
            }

            function setList(arrivals) {
                var list = el('list');
                list.textContent = '';
                arrivals.forEach(function (arrival) {
                    var row = document.createElement('li');
                    [['route', arrival.route], ['dest', arrival.destination], ['mins', arrival.minutes < 1 ? 'due' : arrival.minutes + ' min']]
                        .forEach(function (cell) {
                            var span = document.createElement('span');
                            span.className = cell[0];
                            span.textContent = cell[1];
                            row.appendChild(span);
                        });
                    list.appendChild(row);
                });
                el('msg').hidden = true;
                list.hidden = false;
            }

            function setFoot(text) {
                footText = text;
                el('foot').textContent = text;
            }

            function setCheck(label) {
                el('check').hidden = label === null;
                if (label !== null) { el('check').textContent = label; }
            }

            // Seconds until the next automatic refresh. Only a window refreshes
            // on its own, so the count is hidden everywhere else.
            function tick() {
                var count = el('count');
                count.hidden = !inWindow;
                if (inWindow) {
                    count.textContent = busy ? '\u2026' : Math.max(0, Math.ceil((nextPollAt - Date.now()) / 1000)) + 's';
                }
            }

            function directionLabel() {
                return direction.charAt(0).toUpperCase() + direction.slice(1);
            }

            function stopTitle(stop) {
                return stop.letter ? stop.name + ', Stop ' + stop.letter : stop.name;
            }

            function show(data, wasCheck) {
                failedSince = null;

                // An answer for the direction that was showing before a flip.
                if (data.direction && data.direction !== direction) { return; }

                if (data.state === 'outside_window') {
                    inWindow = false;
                    // Nothing changes until the next window, so polling waits
                    // for it by the R1's own clock and the app can hibernate.
                    sleepUntil = data.next_window ? Date.parse(data.next_window) : null;
                    if (showingCheck) {
                        el('foot').textContent = footText;
                        return;
                    }
                    lastLabel = null;
                    // Each window starts on outward, unless a flip is waiting its turn.
                    if (!again) { direction = 'outward'; }
                    setHead('Outside hours', '');
                    setMessage(data.next_window_label);
                    setFoot('');
                    setCheck('Check now');
                    return;
                }

                inWindow = !wasCheck;
                showingCheck = wasCheck && data.state === 'live';

                if (data.state === 'no_stop') {
                    setHead('Bus times', directionLabel());
                    setMessage('No ' + direction + ' stop. Use admin');
                    lastLabel = null;
                    setFoot('');
                } else if (data.state === 'unavailable') {
                    setHead('Bus times', directionLabel());
                    setMessage('TfL unavailable, retrying');
                    lastLabel = null;
                    setFoot('');
                } else {
                    setHead(stopTitle(data.stop), directionLabel() + (data.stop.towards ? ', towards ' + data.stop.towards : ''));
                    if (data.arrivals.length) { setList(data.arrivals); } else { setMessage('No buses due'); }
                    lastLabel = data.fetched_label;
                    setFoot(data.stale
                        ? 'Stale, ' + data.stale_minutes + ' min old'
                        : (wasCheck ? 'Checked ' : 'Updated ') + data.fetched_label);
                }

                setCheck(inWindow ? null : (showingCheck ? 'Check again' : 'Check now'));
            }

            // Old bus times are worse than none: after a minute without a
            // response they come off the screen.
            function fail() {
                if (failedSince === null) { failedSince = Date.now(); }

                if (Date.now() - failedSince >= 60000) {
                    showingCheck = false;
                    lastLabel = null;
                    footText = '';
                    setMessage('No connection');
                    setCheck(inWindow ? null : 'Check now');
                }

                el('foot').textContent = lastLabel ? 'No connection, last ' + lastLabel : 'No connection, retrying';
            }

            function load(check) {
                if (busy) { return; }
                busy = true;
                tick();
                var controller = new AbortController();
                var timer = setTimeout(function () { controller.abort(); }, 15000);
                fetch(url + '?direction=' + direction + (check ? '&check=1' : ''), { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal })
                    .then(function (response) {
                        if (!response.ok) { throw new Error(response.status); }
                        return response.json();
                    })
                    .then(function (data) { show(data, check); })
                    .catch(fail)
                    .then(function () {
                        clearTimeout(timer);
                        busy = false;
                        tick();
                        if (again) {
                            again = false;
                            load(!inWindow);
                        }
                    });
            }

            // Outside a window the other direction can only be seen by checking it.
            function toggle() {
                direction = direction === 'outward' ? 'inward' : 'outward';
                el('towards').textContent = directionLabel();
                again = busy;
                load(!inWindow);
            }

            el('check').addEventListener('click', function () { load(true); });
            el('head').addEventListener('click', toggle);
            window.addEventListener('sideClick', function () { if (inWindow) { toggle(); } else { load(true); } });
            window.addEventListener('scrollDown', function () { el('list').scrollBy(0, 38); });
            window.addEventListener('scrollUp', function () { el('list').scrollBy(0, -38); });

            load(false);
            setInterval(function () {
                if (sleepUntil !== null && Date.now() < sleepUntil) { return; }
                nextPollAt = Date.now() + every;
                load(false);
            }, every);
            setInterval(tick, 1000);
        })();
    </script>
</body>
</html>
