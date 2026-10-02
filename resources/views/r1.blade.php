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
        #stop { font-size: 15px; font-weight: 700; }
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
        <div id="stop">Bus times</div>
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

            function setCheck(label) {
                el('check').hidden = label === null;
                if (label !== null) { el('check').textContent = label; }
            }

            function stopTitle(stop) {
                return stop.letter ? stop.name + ', Stop ' + stop.letter : stop.name;
            }

            function show(data, wasCheck) {
                if (data.state === 'outside_window') {
                    inWindow = false;
                    if (showingCheck) { return; }
                    setHead('Outside hours', '');
                    setMessage(data.next_window_label);
                    el('foot').textContent = '';
                    setCheck('Check now');
                    return;
                }

                inWindow = !wasCheck;
                showingCheck = wasCheck && data.state === 'live';

                if (data.state === 'no_stop') {
                    setHead('Bus times', '');
                    setMessage('No stop set. Use admin');
                    el('foot').textContent = '';
                } else if (data.state === 'unavailable') {
                    setHead('Bus times', '');
                    setMessage('TfL unavailable, retrying');
                    el('foot').textContent = '';
                } else {
                    setHead(stopTitle(data.stop), data.stop.towards ? 'towards ' + data.stop.towards : '');
                    if (data.arrivals.length) { setList(data.arrivals); } else { setMessage('No buses due'); }
                    el('foot').textContent = data.stale
                        ? 'Stale, ' + data.stale_minutes + ' min old'
                        : (wasCheck ? 'Checked ' : 'Updated ') + data.fetched_label;
                }

                setCheck(inWindow ? null : (showingCheck ? 'Check again' : 'Check now'));
            }

            function load(check) {
                if (busy) { return; }
                busy = true;
                fetch(url + (check ? '?check=1' : ''), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                    .then(function (response) {
                        if (!response.ok) { throw new Error(response.status); }
                        return response.json();
                    })
                    .then(function (data) { show(data, check); })
                    .catch(function () { el('foot').textContent = 'No connection, retrying'; })
                    .then(function () { busy = false; });
            }

            el('check').addEventListener('click', function () { load(true); });
            window.addEventListener('sideClick', function () { load(!inWindow); });
            window.addEventListener('scrollDown', function () { el('list').scrollBy(0, 38); });
            window.addEventListener('scrollUp', function () { el('list').scrollBy(0, -38); });

            load(false);
            setInterval(function () { load(false); }, every);
        })();
    </script>
</body>
</html>
