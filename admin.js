/*
 * MapaSahe admin dashboard. Plain JS, no libraries.
 *
 * Loads admin_api.php?view=summary for the chosen period and draws
 * every section from that one response; the history log pages through
 * admin_api.php?view=history separately.
 */
(function () {
    'use strict';

    var API = 'admin_api.php';
    var range = '7d';
    var summary = null;
    var historyOldestId = 0;

    function $(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }

        return node;
    }

    function clear(node) { node.textContent = ''; return node; }

    // ── Formatting ─────────────────────────────────────────────
    function ms(value) {
        if (value === null || value === undefined) { return '—'; }
        if (value < 1) { return value.toFixed(2) + ' ms'; }
        if (value < 10) { return value.toFixed(1) + ' ms'; }
        if (value < 1000) { return Math.round(value) + ' ms'; }

        return (value / 1000).toFixed(2) + ' s';
    }

    function pct(value) {
        return value === null || value === undefined ? '—' : value + '%';
    }

    function num(value) {
        return value === null || value === undefined
            ? '—'
            : Number(value).toLocaleString('en-US');
    }

    function when(datetime) {
        var date = new Date(datetime.replace(' ', 'T'));

        return date.toLocaleString('en-PH', {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
        });
    }

    function getJson(params) {
        var query = Object.keys(params).filter(function (key) {
            return params[key] !== '' && params[key] !== null && params[key] !== undefined;
        }).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');

        return fetch(API + '?' + query, { credentials: 'same-origin' })
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || body.error) {
                        throw new Error(body.error || 'Request failed (' + response.status + ').');
                    }

                    return body;
                });
            });
    }

    // ── Small builders ─────────────────────────────────────────
    function kpi(label, value, hint, tone) {
        var box = el('div', 'kpi' + (tone ? ' kpi--' + tone : ''));

        box.appendChild(el('p', 'kpi__label', label));
        box.appendChild(el('p', 'kpi__value', value));

        if (hint) { box.appendChild(el('p', 'kpi__hint', hint)); }

        return box;
    }

    function modelTag(name, color) {
        var tag = el('span', 'model-tag', name);

        tag.style.setProperty('--c', color);

        return tag;
    }

    /* A cell holding a number with a proportional bar behind it. */
    function barCell(value, max, label, color) {
        var cell = el('td', 'bar-cell');
        var track = el('span', 'bar');
        var fill = el('span', 'bar__fill');

        fill.style.width = (max > 0 ? Math.max(2, 100 * value / max) : 0) + '%';

        if (color) { fill.style.background = color; }

        track.appendChild(fill);
        cell.appendChild(el('span', 'bar-cell__value', label));
        cell.appendChild(track);

        return cell;
    }

    function row(cells) {
        var tr = el('tr');

        cells.forEach(function (cell) {
            if (cell instanceof Node) {
                tr.appendChild(cell);
            } else {
                tr.appendChild(el('td', cell.className || '', cell.text));
            }
        });

        return tr;
    }

    function emptyRow(tbody, columns, text) {
        var tr = el('tr');
        var td = el('td', 'table__empty', text);

        td.colSpan = columns;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    // ── Sections ───────────────────────────────────────────────
    function renderOverview(data) {
        var k = data.kpis;
        var box = clear($('kpis'));

        box.appendChild(kpi('Searches', num(k.searches), 'in this period'));
        box.appendChild(kpi(
            'Route found',
            pct(k.success_rate),
            'of searches returned a route',
            k.success_rate !== null && k.success_rate < 90 ? 'warn' : ''
        ));
        box.appendChild(kpi('Avg processing time', ms(k.avg_ms), 'per search'));
        box.appendChild(kpi('Registered users', num(k.users), 'all time'));
        box.appendChild(kpi('Stops in network', num(k.stops_total), num(k.routes_total) + ' jeepney routes'));

        var list = clear($('attention'));

        data.attention.forEach(function (item) {
            var li = el('li', 'attention__item attention__item--' + item.level);

            li.appendChild(el('span', 'attention__mark', item.level === 'warn' ? '!' : (item.level === 'ok' ? '✓' : 'i')));
            li.appendChild(el('span', '', item.text));
            list.appendChild(li);
        });
    }

    function renderModels(data) {
        var cards = clear($('model-cards'));
        var body = clear($('model-table').querySelector('tbody'));
        var three = data.models.filter(function (m) { return m.model !== 'Hybrid'; });

        var maxMs = Math.max.apply(null, three.map(function (m) { return m.avg_ms || 0; }).concat([0]));
        var maxNodes = Math.max.apply(null, three.map(function (m) { return m.avg_nodes || 0; }).concat([0]));

        three.forEach(function (m) {
            var card = el('article', 'model-card');

            card.style.setProperty('--c', m.color);
            card.appendChild(modelTag(m.model, m.color));

            var big = el('p', 'model-card__value', m.runs ? pct(m.success_rate) : '—');

            card.appendChild(big);
            card.appendChild(el('p', 'model-card__label', 'success rate over ' + num(m.runs) + ' runs'));

            var facts = el('dl', 'model-card__facts');

            [
                ['Avg time', ms(m.avg_ms)],
                ['Last 20', ms(m.recent_avg_ms)],
                ['No path', pct(m.error_rate)],
                ['Stops explored', num(m.avg_nodes)]
            ].forEach(function (pair) {
                var wrap = el('div');

                wrap.appendChild(el('dt', '', pair[0]));
                wrap.appendChild(el('dd', '', pair[1]));
                facts.appendChild(wrap);
            });

            card.appendChild(facts);
            cards.appendChild(card);
        });

        data.models.forEach(function (m) {
            var name = el('th');

            name.scope = 'row';
            name.appendChild(modelTag(m.model, m.color));

            if (m.model === 'Hybrid') {
                name.appendChild(el('span', 'table__sub', 'stitching only'));
            }

            body.appendChild(row([
                name,
                { className: 'num', text: num(m.runs) },
                { className: 'num', text: pct(m.success_rate) },
                { className: 'num' + (m.error_rate > 0 ? ' is-warn' : ''), text: pct(m.error_rate) },
                m.model === 'Hybrid'
                    ? { className: 'num', text: ms(m.avg_ms) }
                    : barCell(m.avg_ms || 0, maxMs, ms(m.avg_ms), m.color),
                { className: 'num', text: ms(m.median_ms) },
                { className: 'num', text: ms(m.p95_ms) },
                m.model === 'Hybrid'
                    ? { className: 'num', text: num(m.avg_nodes) }
                    : barCell(m.avg_nodes || 0, maxNodes, num(m.avg_nodes), m.color),
                { className: 'num', text: m.avg_cost === null ? '—' : m.avg_cost.toFixed(2) + ' min' }
            ]));
        });
    }

    function renderCost(data) {
        var list = clear($('cost-list'));
        var three = data.models.filter(function (m) { return m.model !== 'Hybrid'; });
        var maxRelative = Math.max.apply(null, three.map(function (m) { return m.relative_cost || 0; }).concat([1]));
        var heaviest = three.slice().sort(function (a, b) {
            return (b.relative_cost || 0) - (a.relative_cost || 0);
        })[0];

        data.models.forEach(function (m) {
            var item = el('div', 'cost');

            item.style.setProperty('--c', m.color);

            var head = el('div', 'cost__head');

            head.appendChild(modelTag(m.model, m.color));
            head.appendChild(el('code', 'cost__bigo', m.big_o));

            if (heaviest && m.model === heaviest.model && m.relative_cost > 1) {
                head.appendChild(el('span', 'tag tag--warn', 'Heaviest'));
            }

            item.appendChild(head);

            if (m.model !== 'Hybrid') {
                var line = el('div', 'cost__meter');
                var track = el('span', 'bar bar--thick');
                var fill = el('span', 'bar__fill');

                fill.style.width = (m.relative_cost ? 100 * m.relative_cost / maxRelative : 0) + '%';
                fill.style.background = m.color;
                track.appendChild(fill);
                line.appendChild(track);
                line.appendChild(el('strong', 'cost__times', m.relative_cost ? m.relative_cost.toFixed(1) + '×' : '—'));
                item.appendChild(line);
            }

            item.appendChild(el(
                'p',
                'cost__measured',
                'Measured: ' + ms(m.avg_ms) + ' average' +
                (m.avg_nodes !== null ? ' · ' + num(m.avg_nodes) + ' stops explored' : '')
            ));
            item.appendChild(el('p', 'cost__note', m.note));
            list.appendChild(item);
        });

        var bar = clear($('stage-bar'));
        var legend = clear($('stage-legend'));
        var total = data.stages.reduce(function (sum, stage) { return sum + (stage.avg_ms || 0); }, 0);

        if (total <= 0) {
            bar.appendChild(el('p', 'empty', 'No timings yet.'));
            return;
        }

        data.stages.forEach(function (stage) {
            var share = 100 * (stage.avg_ms || 0) / total;
            var part = el('span', 'stage-bar__part');

            part.style.width = share + '%';
            part.style.background = stage.color;
            part.title = stage.stage + ': ' + ms(stage.avg_ms);
            bar.appendChild(part);

            var li = el('li');
            var swatch = el('span', 'stage-legend__swatch');

            swatch.style.background = stage.color;
            li.appendChild(swatch);
            li.appendChild(el('span', 'stage-legend__name', stage.stage));
            li.appendChild(el('span', 'stage-legend__value', ms(stage.avg_ms) + ' · ' + share.toFixed(share < 10 ? 1 : 0) + '%'));
            legend.appendChild(li);
        });
    }

    function renderEta(data) {
        var k = data.kpis;
        var box = clear($('eta-kpis'));
        var slower = k.recent_avg_ms !== null && k.avg_ms !== null && k.recent_avg_ms > 1.5 * k.avg_ms;

        box.appendChild(kpi('Average', ms(k.avg_ms)));
        box.appendChild(kpi('Typical (median)', ms(k.median_ms)));
        box.appendChild(kpi('Slow case (95th pct.)', ms(k.p95_ms)));
        box.appendChild(kpi('Last 20 searches', ms(k.recent_avg_ms), slower ? 'slower than usual' : '', slower ? 'warn' : ''));
        box.appendChild(kpi('Fastest / slowest', ms(k.fastest_ms) + ' / ' + ms(k.slowest_ms)));

        drawTimeline(data.timeline, k.avg_ms);
    }

    /* One bar per recent search, in SVG so it scales with the card. */
    function drawTimeline(points, average) {
        var host = clear($('timeline'));

        if (points.length === 0) {
            host.appendChild(el('p', 'empty', 'No searches in this period yet.'));
            return;
        }

        var NS = 'http://www.w3.org/2000/svg';
        var width = 720;
        var height = 180;
        var pad = { top: 12, right: 8, bottom: 22, left: 44 };
        var innerW = width - pad.left - pad.right;
        var innerH = height - pad.top - pad.bottom;
        var max = Math.max.apply(null, points.map(function (p) { return p.total_ms; }).concat([average || 0])) * 1.1 || 1;
        var slot = innerW / points.length;
        var barW = Math.max(2, Math.min(18, slot - 2));

        function node(name, attrs) {
            var n = document.createElementNS(NS, name);

            Object.keys(attrs).forEach(function (key) { n.setAttribute(key, attrs[key]); });

            return n;
        }

        function y(value) { return pad.top + innerH - innerH * value / max; }

        var svg = node('svg', {
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': 'Processing time of the ' + points.length + ' most recent searches'
        });

        [0, 0.5, 1].forEach(function (step) {
            var value = max * step;
            var label = node('text', { x: pad.left - 6, y: y(value) + 4, 'text-anchor': 'end', class: 'tl-axis' });

            label.textContent = step === 0 ? '0' : ms(value);
            svg.appendChild(node('line', { x1: pad.left, x2: width - pad.right, y1: y(value), y2: y(value), class: 'tl-grid' }));
            svg.appendChild(label);
        });

        points.forEach(function (point, index) {
            var x = pad.left + index * slot + (slot - barW) / 2;
            var bar = node('rect', {
                x: x,
                y: y(point.total_ms),
                width: barW,
                height: Math.max(1, pad.top + innerH - y(point.total_ms)),
                rx: 2,
                class: 'tl-bar' + (point.status !== 'ok' ? ' tl-bar--fail' : '')
            });
            var title = node('title', {});

            title.textContent = when(point.at) + ' · ' + ms(point.total_ms) +
                (point.status !== 'ok' ? ' · no route found' : '');
            bar.appendChild(title);
            svg.appendChild(bar);
        });

        if (average) {
            svg.appendChild(node('line', {
                x1: pad.left, x2: width - pad.right, y1: y(average), y2: y(average), class: 'tl-avg'
            }));
        }

        var first = node('text', { x: pad.left, y: height - 6, class: 'tl-axis' });
        var last = node('text', { x: width - pad.right, y: height - 6, 'text-anchor': 'end', class: 'tl-axis' });

        first.textContent = when(points[0].at);
        last.textContent = when(points[points.length - 1].at);
        svg.appendChild(first);
        svg.appendChild(last);
        host.appendChild(svg);
    }

    function renderPaths(data) {
        var body = clear($('paths-table').querySelector('tbody'));
        var max = data.paths.length ? data.paths[0].uses : 0;

        if (data.paths.length === 0) {
            emptyRow(body, 5, 'No searches in this period yet.');
            return;
        }

        data.paths.forEach(function (path, index) {
            body.appendChild(row([
                { className: 'num table__rank', text: index + 1 },
                { className: 'table__strong', text: path.origin + ' → ' + path.destination },
                barCell(path.uses, max, num(path.uses)),
                { className: 'num', text: ms(path.avg_ms) },
                { className: 'num' + (path.success_rate < 100 ? ' is-warn' : ''), text: pct(path.success_rate) }
            ]));
        });
    }

    function renderStops(data) {
        var k = data.kpis;
        var box = clear($('stop-kpis'));
        var share = k.stops_total ? Math.round(100 * k.stops_used / k.stops_total) : null;

        box.appendChild(kpi('Stops in the network', num(k.stops_total)));
        box.appendChild(kpi('Stops used in searches', num(k.stops_used), share !== null ? share + '% of the network' : ''));
        box.appendChild(kpi('Busiest stop', data.stops.length ? data.stops[0].name : '—', data.stops.length ? num(data.stops[0].uses) + ' uses' : ''));

        drawStops();
    }

    function drawStops() {
        var body = clear($('stops-table').querySelector('tbody'));
        var needle = $('stop-filter').value.trim().toLowerCase();
        var stops = (summary ? summary.stops : []).filter(function (stop) {
            return needle === '' || stop.name.toLowerCase().indexOf(needle) !== -1;
        });
        var max = summary && summary.stops.length ? summary.stops[0].uses : 0;

        if (stops.length === 0) {
            emptyRow(body, 7, needle ? 'No stop matches "' + needle + '".' : 'No stop usage in this period yet.');
            return;
        }

        var fragment = document.createDocumentFragment();

        stops.forEach(function (stop) {
            fragment.appendChild(row([
                { className: 'table__strong', text: stop.name },
                barCell(stop.uses, max, num(stop.uses)),
                { className: 'num', text: num(stop.as_origin) },
                { className: 'num', text: num(stop.as_destination) },
                { className: 'num', text: num(stop.as_via) },
                { className: 'num', text: ms(stop.avg_ms) },
                { className: 'num' + (stop.fail_rate > 0 ? ' is-warn' : ''), text: pct(stop.fail_rate) }
            ]));
        });

        body.appendChild(fragment);
    }

    // ── History log ────────────────────────────────────────────
    var TYPE_LABELS = {
        search: 'Search',
        search_failed: 'No route',
        search_invalid: 'Rejected search',
        login: 'Login',
        login_failed: 'Failed login',
        register: 'New account',
        logout: 'Logout',
        route_saved: 'Trip saved',
        route_removed: 'Trip removed',
        password_changed: 'Password change'
    };

    var TYPE_TONES = {
        search_failed: 'warn',
        login_failed: 'warn',
        search_invalid: 'muted',
        register: 'ok'
    };

    function typeLabel(type) { return TYPE_LABELS[type] || type; }

    function fillTypeFilter(types) {
        var select = $('history-type');
        var current = select.value;

        while (select.options.length > 1) { select.remove(1); }

        types.forEach(function (type) {
            var option = el('option', '', typeLabel(type));

            option.value = type;
            select.appendChild(option);
        });

        select.value = current;
    }

    function loadHistory(append) {
        var form = $('history-filters');
        var body = $('history-table').querySelector('tbody');
        var more = $('history-more');

        if (!append) { historyOldestId = 0; }

        more.disabled = true;

        return getJson({
            view: 'history',
            type: form.elements.type.value,
            from: form.elements.from.value,
            to: form.elements.to.value,
            before_id: append ? historyOldestId : '',
            limit: 50
        }).then(function (data) {
            if (!append) { clear(body); }

            if (data.events.length === 0 && !append) {
                emptyRow(body, 4, 'No events match these filters.');
            }

            var fragment = document.createDocumentFragment();

            data.events.forEach(function (event) {
                var type = el('td');

                type.appendChild(el('span', 'tag tag--' + (TYPE_TONES[event.type] || 'plain'), typeLabel(event.type)));

                fragment.appendChild(row([
                    { className: 'table__time', text: when(event.at) },
                    type,
                    { text: event.user },
                    { className: 'table__details', text: event.details }
                ]));

                historyOldestId = event.id;
            });

            body.appendChild(fragment);
            more.hidden = !data.has_more;
            more.disabled = false;
        }).catch(showError);
    }

    // ── Loading ────────────────────────────────────────────────
    function showError(error) {
        var box = $('load-error');

        box.textContent = error.message || 'Could not load the dashboard.';
        box.hidden = false;
        $('updated').textContent = 'Not updated';
    }

    function load() {
        $('load-error').hidden = true;
        $('updated').textContent = 'Loading…';
        document.body.classList.add('is-loading');

        return getJson({ view: 'summary', range: range }).then(function (data) {
            summary = data;

            renderOverview(data);
            renderModels(data);
            renderCost(data);
            renderEta(data);
            renderPaths(data);
            renderStops(data);
            fillTypeFilter(data.event_types);

            $('updated').textContent = 'Updated ' + when(data.generated_at);
        }).catch(showError).then(function () {
            document.body.classList.remove('is-loading');
        });
    }

    document.querySelectorAll('[data-range]').forEach(function (button) {
        button.addEventListener('click', function () {
            range = button.dataset.range;

            document.querySelectorAll('[data-range]').forEach(function (other) {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });

            load();
        });
    });

    $('refresh').addEventListener('click', function () {
        load();
        loadHistory(false);
    });

    $('stop-filter').addEventListener('input', drawStops);

    $('history-filters').addEventListener('submit', function (event) {
        event.preventDefault();
        loadHistory(false);
    });

    $('history-filters').addEventListener('reset', function () {
        // Wait for the browser to clear the fields before reloading.
        setTimeout(function () { loadHistory(false); }, 0);
    });

    $('history-more').addEventListener('click', function () { loadHistory(true); });

    load();
    loadHistory(false);
})();
