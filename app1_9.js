/*
 * MapaSahe user UI. Plain JS, no dependencies beyond Leaflet.
 *
 *   1. Helpers
 *   2. Status line
 *   3. Stop picker (searchable, built on top of the real <select>)
 *   4. Map
 *   5. Results (route cards) + background form submit
 *   6. Mobile bottom sheet
 *
 * Everything here is progressive enhancement: with this file missing
 * the page is still a working form that posts and re-renders.
 *
 * 1.5: each model's route is drawn in its own colour (sent by the
 * server: Hybrid yellow, Dijkstra red, A* green, GBFS blue). Jeepney
 * rides are solid lines and walks between stops are broken lines, in
 * the same colour, so the whole route reads as one colour.
 *
 * 1.9: each stop box can also search a place by name. Typing a name
 * that is not a stop offers "Search places for ...", which asks
 * OpenStreetMap's Nominatim service (one request per search, as its
 * usage policy requires; no search-as-you-type). Picking a result
 * selects the nearest jeepney stop, if one is within payload.snapLimitM
 * metres (100 m); otherwise it says no stop is close enough.
 *
 * 1.8: a jeepney-change dot is drawn at every new jeepney ride, even
 * when both rides have the same signboard name (for example the two
 * directions of "Paco - Pier South"). 1.5 compared route names, so a
 * change between same-named routes got no dot.
 */
(function () {
    'use strict';

    // ── 1. Helpers ─────────────────────────────────────────────
    var DEFAULT_ROUTE_COLOR = '#0047AB';
    var mobileQuery = window.matchMedia('(max-width: 767px)');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function $(id, root) {
        return (root || document).getElementById(id);
    }

    function readPayload(root) {
        var node = $('mapasahe-data', root);

        try {
            return JSON.parse(node.textContent) || {};
        } catch (error) {
            return {};
        }
    }

    // "Sta. Niño" and "sta nino" should match each other.
    function fold(text) {
        return String(text)
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }

        return node;
    }

    var payload = readPayload();
    var form = $('trip-form');
    var results = $('results');
    var planner = $('planner');
    var originSelect = $('origin_node_id');
    var destinationSelect = $('destination_node_id');

    // ── 2. Status line ─────────────────────────────────────────
    var statusBox = $('status');
    var statusText = $('status-text');

    function setStatus(kind, message) {
        statusBox.className = 'status status--' + kind;
        statusText.textContent = message;
    }

    function selectionHint() {
        var hasOrigin = originSelect.value !== '';
        var hasDestination = destinationSelect.value !== '';

        if (hasOrigin && hasDestination) {
            setStatus('ready', 'Ready. Press Find routes.');
        } else if (hasOrigin) {
            setStatus('idle', 'Start set. Now choose where you are going.');
        } else if (hasDestination) {
            setStatus('idle', 'Destination set. Now choose where you are starting.');
        } else {
            setStatus('idle', 'Choose where you are starting and where you are going.');
        }
    }

    // ── 3. Stop picker ─────────────────────────────────────────
    /*
     * The <select> stays in the form and still carries the value that
     * gets posted (origin_node_id / destination_node_id). This only
     * puts a type-to-search box in front of it, because scrolling a
     * native list of 110+ terminals is slow, worst of all on a phone.
     */
    function enhancePicker(select) {
        var field = select.closest('.field');
        var label = field.querySelector('label');
        var listId = select.id + '-list';
        var activeIndex = -1;
        var matches = [];

        var options = Array.prototype.filter.call(
            select.options,
            function (option) { return option.value !== ''; }
        ).map(function (option) {
            var name = option.textContent.trim();
            var city = option.dataset.city || '';
            var routes = option.dataset.routes || '';

            return {
                value: option.value,
                name: name,
                meta: [city, routes].filter(Boolean).join(' · '),
                haystack: fold(name + ' ' + city + ' ' + routes)
            };
        });

        var note = el('p', 'field__note');

        note.hidden = true;
        note.setAttribute('aria-live', 'polite');

        function setNote(text, isError) {
            note.textContent = text || '';
            note.hidden = !text;
            note.classList.toggle('field__note--error', !!isError);
        }

        var wrapper = el('div', 'combo');
        var input = el('input', 'combo__input');
        var clear = el('button', 'combo__clear', '×');
        var list = el('ul', 'combo__list');

        input.type = 'text';
        input.id = select.id + '-search';
        input.placeholder = select.dataset.placeholder || '';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        clear.type = 'button';
        clear.hidden = true;
        clear.setAttribute('aria-label', 'Clear ' + label.textContent.trim().toLowerCase());

        list.id = listId;
        list.hidden = true;
        list.setAttribute('role', 'listbox');

        wrapper.appendChild(input);
        wrapper.appendChild(clear);
        wrapper.appendChild(list);
        field.appendChild(wrapper);
        field.appendChild(note);

        label.htmlFor = input.id;
        select.hidden = true;
        select.required = false;   // validated in JS and again on the server

        function current() {
            for (var i = 0; i < options.length; i++) {
                if (options[i].value === select.value) { return options[i]; }
            }

            return null;
        }

        function syncFromSelect() {
            var chosen = current();

            input.value = chosen ? chosen.name : '';
            clear.hidden = !chosen;
        }

        function setActive(index) {
            var items = list.querySelectorAll('.combo__option');

            if (items[activeIndex]) {
                items[activeIndex].setAttribute('aria-selected', 'false');
            }

            activeIndex = index;

            if (items[index]) {
                items[index].setAttribute('aria-selected', 'true');
                items[index].scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', items[index].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function render(query, placeResults) {
            var words = fold(query).split(' ').filter(Boolean);
            var fragment = document.createDocumentFragment();

            if (placeResults) {
                // Results of a place search replace the stop list.
                matches = placeResults;
            } else {
                matches = options.filter(function (option) {
                    return words.every(function (word) {
                        return option.haystack.indexOf(word) !== -1;
                    });
                }).slice(0, 60);

                // Offer a place search for anything three letters or longer.
                if (query.trim().length >= 3) {
                    matches.push({
                        kind: 'search',
                        query: query.trim(),
                        name: 'Search places for "' + query.trim() + '"',
                        meta: 'Finds the nearest jeepney stop to a place'
                    });
                }
            }

            matches.forEach(function (option, index) {
                var item = el('li', 'combo__option');

                item.id = listId + '-' + index;
                item.dataset.index = index;

                if (option.kind) {
                    item.classList.add('combo__option--' + option.kind);
                }

                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.appendChild(el('span', 'combo__option-name', option.name));

                if (option.meta) {
                    item.appendChild(el('span', 'combo__option-meta', option.meta));
                }

                fragment.appendChild(item);
            });

            if (matches.length === 0) {
                fragment.appendChild(el(
                    'li',
                    'combo__empty',
                    placeResults
                        ? 'No place found for "' + query.trim() + '" in Manila or Mandaluyong.'
                        : 'No stop matches "' + query.trim() + '".'
                ));
            }

            list.textContent = '';
            list.appendChild(fragment);
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
        }

        function open(query, placeResults) {
            render(query, placeResults);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function haversineMeters(lat1, lon1, lat2, lon2) {
            var toRad = Math.PI / 180;
            var dLat = (lat2 - lat1) * toRad;
            var dLon = (lon2 - lon1) * toRad;
            var a =
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);

            return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        // Nearest stop in this list to a point, within the limit.
        function snapToStop(place) {
            var limit = Number(payload.snapLimitM) || 100;
            var best = null;
            var bestDistance = Infinity;

            options.forEach(function (option) {
                var stop = payload.termini && payload.termini[option.value];

                if (!stop) { return; }

                var distance = haversineMeters(
                    place.lat, place.lon, stop.latitude, stop.longitude
                );

                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = option;
                }
            });

            if (best && bestDistance <= limit) {
                choose(best.value);
                setNote(
                    'Nearest stop to ' + place.name + ': ' + best.name +
                    ', about ' + Math.round(bestDistance) + ' m away.'
                );
            } else {
                choose('');
                input.value = place.name;
                setNote(
                    'No jeepney stop within ' + limit + ' m of ' + place.name +
                    (best ? ' (nearest is ' + best.name + ', ' + Math.round(bestDistance) + ' m away).' : '.') +
                    ' Try a different place or pick a stop.',
                    true
                );
            }

            input.blur();
        }

        function searchPlaces(query) {
            var bounds = payload.datasetBounds;

            render(query, []);
            list.textContent = '';
            list.appendChild(el('li', 'combo__empty', 'Searching places…'));
            list.hidden = false;

            var url =
                'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5' +
                '&countrycodes=ph&accept-language=en' +
                '&q=' + encodeURIComponent(query);

            // Keep results inside Manila + Mandaluyong (the stops' area).
            if (Array.isArray(bounds)) {
                url +=
                    '&bounded=1&viewbox=' +
                    bounds[0][1] + ',' + bounds[1][0] + ',' +
                    bounds[1][1] + ',' + bounds[0][0];
            }

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }

                    return response.json();
                })
                .then(function (places) {
                    open(query, (places || []).map(function (place) {
                        var parts = String(place.display_name || '').split(', ');

                        return {
                            kind: 'place',
                            name: place.name || parts[0],
                            meta: parts.slice(1, 4).join(', '),
                            lat: Number(place.lat),
                            lon: Number(place.lon)
                        };
                    }));

                    if (matches.length > 0) { setActive(0); }
                })
                .catch(function () {
                    list.textContent = '';
                    list.appendChild(el(
                        'li',
                        'combo__empty',
                        'Place search is unavailable right now. Pick a stop from the list instead.'
                    ));
                });
        }

        // A list item was picked: a stop, "Search places", or a place.
        function pick(option) {
            if (option.kind === 'search') {
                searchPlaces(option.query);
            } else if (option.kind === 'place') {
                snapToStop(option);
            } else {
                setNote('');
                choose(option.value);
                input.blur();
            }
        }

        function choose(value) {
            if (select.value !== value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            syncFromSelect();
            close();
        }

        input.addEventListener('focus', function () {
            input.select();
            open('');
        });

        input.addEventListener('input', function () {
            setNote('');
            open(input.value);

            if (matches.length > 0) { setActive(0); }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                if (list.hidden) { open(''); }
                if (matches.length === 0) { return; }

                var step = event.key === 'ArrowDown' ? 1 : -1;

                setActive((activeIndex + step + matches.length) % matches.length);
            } else if (event.key === 'Enter') {
                // Enter picks the highlighted stop; it only submits the
                // form when the list is closed.
                if (!list.hidden && matches[activeIndex]) {
                    event.preventDefault();
                    pick(matches[activeIndex]);
                }
            } else if (event.key === 'Escape') {
                syncFromSelect();
                close();
            }
        });

        input.addEventListener('blur', function () {
            // Typed text that was never picked is not a selection
            // (unless a failed place search left its name to show).
            if (!note.classList.contains('field__note--error')) {
                syncFromSelect();
            }

            close();
        });

        // mousedown, not click, so the pick lands before the blur above.
        list.addEventListener('mousedown', function (event) {
            var item = event.target.closest('.combo__option');

            event.preventDefault();

            if (item) {
                pick(matches[Number(item.dataset.index)]);
            }
        });

        clear.addEventListener('click', function () {
            setNote('');
            choose('');
            input.focus();
        });

        select.addEventListener('change', function () {
            field.classList.remove('is-invalid');
            syncFromSelect();
        });

        syncFromSelect();
    }

    // ── 4. Map ─────────────────────────────────────────────────
    var mapView = (function () {
        var map = null;
        var routeGroups = {};     // label -> { lines, transfers, color }
        var pins = { origin: null, destination: null };
        var selected = null;

        function fitPadding() {
            if (!mobileQuery.matches) {
                return { paddingTopLeft: [40, 40], paddingBottomRight: [40, 40] };
            }

            // On a phone the sheet covers the bottom of the map, so
            // keep the route inside the part that is still visible.
            var covered = planner.classList.contains('is-peek')
                ? 76
                : Math.min(planner.offsetHeight, window.innerHeight * 0.6);

            return { paddingTopLeft: [24, 56], paddingBottomRight: [24, covered + 16] };
        }

        function fit(bounds) {
            if (!map || !bounds) { return; }

            var options = fitPadding();

            options.maxZoom = 17;
            options.animate = !reducedMotion.matches;
            map.fitBounds(bounds, options);
        }

        function init() {
            if (!window.L) {
                $('map').appendChild(el(
                    'p',
                    'map-fallback',
                    'The map could not load. Your routes and directions still work in the panel.'
                ));
                $('map-key').hidden = true;

                return;
            }

            map = L.map('map', { zoomControl: false });

            if (!mobileQuery.matches) {
                L.control.zoom({ position: 'topright' }).addTo(map);
            }

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            map.createPane('transferPane').style.zIndex = 620;

            if (Array.isArray(payload.datasetBounds)) {
                map.fitBounds(payload.datasetBounds, { padding: [24, 24] });
            } else {
                map.setView([14.5995, 121.0369], 12);
            }
        }

        function setPin(kind, node) {
            if (!map) { return; }

            if (pins[kind]) {
                map.removeLayer(pins[kind]);
                pins[kind] = null;
            }

            if (!node) { return; }

            var letter = kind === 'origin' ? 'A' : 'B';

            pins[kind] = L.marker([node.latitude, node.longitude], {
                icon: L.divIcon({
                    className: '',
                    html: '<span class="map-pin map-pin--' + letter.toLowerCase() + '">' + letter + '</span>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 14]
                }),
                title: (kind === 'origin' ? 'Start: ' : 'Destination: ') + node.name,
                zIndexOffset: 1000
            }).addTo(map);
        }

        function showSelection() {
            var bounds = [];

            ['origin', 'destination'].forEach(function (kind) {
                if (pins[kind]) { bounds.push(pins[kind].getLatLng()); }
            });

            if (bounds.length === 2) {
                fit(L.latLngBounds(bounds));
            } else if (bounds.length === 1) {
                map.setView(bounds[0], Math.max(map.getZoom(), 15), {
                    animate: !reducedMotion.matches
                });
            }
        }

        function stopName(id) {
            return (payload.stopNames && payload.stopNames[id]) || id;
        }

        /*
         * The server sends one segment per stop-to-stop edge. Joining
         * consecutive segments that share a route and style into one
         * polyline cuts the map from hundreds of layers to a handful
         * per route, which is what keeps panning smooth on phones.
         */
        function toRuns(segments, legs) {
            var runs = [];
            var legOf = [];

            // legs[] says how many edges each ride or walk spans, so
            // the map breaks the line where the directions list does.
            (legs || []).forEach(function (leg, legIndex) {
                for (var i = 0; i < leg.edges; i++) { legOf.push(legIndex); }
            });

            segments.forEach(function (segment, index) {
                var leg = legs && legs[legOf[index]];
                var isWalk = segment.edge_type === 'walking_transfer';
                var routeName = leg && !isWalk ? leg.route_name : segment.route_name;

                var key = [
                    isWalk,
                    leg ? legOf[index] : routeName,
                    segment.synthesis_type,
                    segment.filled_by
                ].join('|');

                var last = runs[runs.length - 1];

                if (!last || last.key !== key) {
                    last = {
                        key: key,
                        walk: isWalk,
                        routeName: routeName,
                        synthesis: segment.synthesis_type,
                        filledBy: segment.filled_by,
                        from: segment.from,
                        legIndex: leg ? legOf[index] : null,
                        points: [segment.points[0]],
                        distance: 0
                    };
                    runs.push(last);
                }

                // A segment is two points when drawn straight, or many
                // when it follows an estimated road path. Skip its first
                // point when it repeats the end of the previous segment.
                segment.points.forEach(function (point, pointIndex) {
                    var previous = last.points[last.points.length - 1];

                    if (
                        pointIndex === 0 &&
                        previous[0] === point[0] &&
                        previous[1] === point[1]
                    ) {
                        return;
                    }

                    last.points.push(point);
                });
                last.to = segment.to;
                last.distance += Number(segment.distance_m) || 0;
            });

            return runs;
        }

        function runTooltip(label, run) {
            var text = run.walk
                ? 'Walk ' + Math.round(run.distance) + ' m: '
                : run.routeName + ': ';

            text += stopName(run.from) + ' → ' + stopName(run.to);

            if (label === 'Hybrid' && run.synthesis) {
                text += run.synthesis === 'agreed'
                    ? ' (all three searches agree)'
                    : ' (gap filled by ' + run.filledBy + ')';
            }

            return text;
        }

        /*
         * Each model's route is drawn in that model's colour. Jeepney
         * rides are solid; walks between stops are broken lines in the
         * same colour. The selected route is bold; the other models'
         * routes stay as a faint trace underneath.
         */
        function lineStyle(label, group, run, isSelected) {
            return {
                color: group.color || DEFAULT_ROUTE_COLOR,
                weight: isSelected ? (run.walk ? 5 : 7) : 3,
                opacity: isSelected ? 0.95 : 0.05,
                dashArray: run.walk ? '2, 10' : null,
                lineCap: 'round',
                lineJoin: 'round'
            };
        }

        /*
         * A jeepney change is a filled dot in the route colour; a walking
         * transfer is a white dot ringed in the route colour.
         */
        function transferMarker(point, color, text, isWalk) {
            return L.circleMarker(point, {
                pane: 'transferPane',
                radius: isWalk ? 8 : 10,
                color: isWalk ? color : '#ffffff',
                weight: 4,
                fillColor: isWalk ? '#ffffff' : color,
                fillOpacity: 0.95
            }).bindTooltip(text);
        }

        function setRoutes(routes) {
            if (!map) { return; }

            Object.keys(routeGroups).forEach(function (label) {
                map.removeLayer(routeGroups[label].lines);
                map.removeLayer(routeGroups[label].transfers);
            });

            routeGroups = {};
            selected = null;

            Object.keys(routes || {}).forEach(function (label) {
                var group = {
                    color: routes[label].color || DEFAULT_ROUTE_COLOR,
                    lines: L.featureGroup(),
                    transfers: L.layerGroup()
                };

                var runs = toRuns(routes[label].segments || [], routes[label].legs);

                runs.forEach(function (run, index) {
                    var line = L.polyline(run.points, lineStyle(label, group, run, false))
                        .bindTooltip(runTooltip(label, run), { sticky: true });

                    line.msRun = run;

                    group.lines.addLayer(line);

                    var previous = runs[index - 1];

                    if (run.walk) {
                        group.transfers.addLayer(transferMarker(
                            run.points[0],
                            group.color,
                            'Get off at ' + stopName(run.from) + ' and walk ' + Math.round(run.distance) + ' m',
                            true
                        ));
                    } else if (
                        previous &&
                        !previous.walk &&
                        (run.legIndex !== null
                            ? previous.legIndex !== run.legIndex
                            : previous.routeName !== run.routeName)
                    ) {
                        // Each ride leg in the directions is a separate
                        // jeepney, so a new leg always means a change.
                        var sameName = previous.routeName === run.routeName;

                        group.transfers.addLayer(transferMarker(
                            run.points[0],
                            group.color,
                            'Change jeepney at ' + stopName(run.from) + ': ' + previous.routeName + ' → ' +
                                run.routeName + (sameName ? ' (another trip of the same route)' : ''),
                            false
                        ));
                    }
                });

                routeGroups[label] = group;
            });
        }

        function refresh() {
            if (!map) { return; }

            Object.keys(routeGroups).forEach(function (label) {
                var group = routeGroups[label];
                var isSelected = label === selected;

                group.lines.addTo(map);

                if (isSelected) { group.transfers.addTo(map); } else { map.removeLayer(group.transfers); }

                group.lines.eachLayer(function (layer) {
                    layer.setStyle(lineStyle(label, group, layer.msRun, isSelected));
                });
            });

            if (routeGroups[selected]) {
                routeGroups[selected].lines.bringToFront();
            }
        }

        function select(label, shouldFit) {
            selected = label;
            refresh();

            // The map key's line and dot samples take the selected
            // route's colour.
            var key = $('map-key');

            if (key && routeGroups[label]) {
                key.style.setProperty('--map-route', routeGroups[label].color);
            }

            Array.prototype.forEach.call(
                document.querySelectorAll('.key-route'),
                function (item) {
                    var known = !!routeGroups[label];

                    item.classList.toggle('is-active', known && item.dataset.route === label);
                    item.classList.toggle('is-dimmed', known && item.dataset.route !== label);
                }
            );

            var group = routeGroups[label];

            if (group && shouldFit !== false) {
                var bounds = group.lines.getBounds();

                if (bounds.isValid()) { fit(bounds); }
            }
        }

        return {
            init: init,
            setPin: setPin,
            showSelection: showSelection,
            setRoutes: setRoutes,
            select: select,
            refit: function () { select(selected); },
            resize: function () { if (map) { map.invalidateSize(); } },
            hasRoutes: function () { return Object.keys(routeGroups).length > 0; }
        };
    })();

    // ── 5. Results + background submit ─────────────────────────
    var sheetLabel = $('sheet-label');

    function selectRoute(label, shouldFit) {
        var cards = results.querySelectorAll('.route-card[data-route]');

        Array.prototype.forEach.call(cards, function (card) {
            var isSelected = card.dataset.route === label;

            card.classList.toggle('is-active', isSelected);
            card.querySelector('.route-card__head')
                .setAttribute('aria-expanded', isSelected ? 'true' : 'false');

            if (isSelected) {
                var time = card.querySelector('.route-card__time strong');

                sheetLabel.textContent = label + ' · ' + (time ? time.textContent.trim() + ' min' : '');
            }
        });

        mapView.select(label, shouldFit);
    }

    // One listener on the container, so it keeps working after the
    // cards are replaced by a new search.
    results.addEventListener('click', function (event) {
        var showMap = event.target.closest('[data-action="show-map"]');

        if (showMap) {
            sheet.peek(true);
            return;
        }

        var head = event.target.closest('button.route-card__head');

        if (head) {
            selectRoute(head.closest('.route-card').dataset.route);
        }
    });

    function applyResults(shouldScroll) {
        var state = results.dataset.state || 'idle';

        mapView.setRoutes(payload.routes);

        if (state === 'idle') {
            sheetLabel.textContent = 'Plan your trip';
            selectionHint();
            return;
        }

        setStatus(state, results.dataset.message || '');

        if (payload.defaultRoute) {
            selectRoute(payload.defaultRoute);
        } else {
            sheetLabel.textContent = 'Plan your trip';
            mapView.showSelection();
        }

        if (shouldScroll) {
            results.scrollIntoView({
                block: 'start',
                behavior: reducedMotion.matches ? 'auto' : 'smooth'
            });
        }
    }

    function validate() {
        var firstInvalid = null;

        [originSelect, destinationSelect].forEach(function (select) {
            var field = select.closest('.field');
            var missing = select.value === '';

            field.classList.toggle('is-invalid', missing);

            if (missing && !firstInvalid) { firstInvalid = field; }
        });

        if (firstInvalid) {
            setStatus('error', 'Choose both a start and a destination from the lists.');
            (firstInvalid.querySelector('.combo__input') || firstInvalid.querySelector('select')).focus();
            return false;
        }

        if (originSelect.value === destinationSelect.value) {
            setStatus('error', 'Start and destination are the same stop. Pick two different ones.');
            return false;
        }

        return true;
    }

    var submitButton = $('trip-submit');
    var submitLabel = submitButton.querySelector('.btn__label');
    var pending = null;

    function setLoading(isLoading) {
        submitButton.disabled = isLoading;
        submitButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        submitLabel.textContent = isLoading ? 'Finding routes…' : 'Find routes';
        results.setAttribute('aria-busy', isLoading ? 'true' : 'false');

        if (isLoading) {
            setStatus('loading', 'Finding routes…');

            if (!results.querySelector('.route-card')) {
                results.innerHTML =
                    '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
            }
        }
    }

    /*
     * Posts the same fields to the same URL as a normal submit, then
     * lifts the results block and the route data out of the page that
     * comes back. The server needs no changes and no new endpoint, and
     * the map keeps its tiles instead of reloading from scratch.
     */
    form.addEventListener('submit', function (event) {
        if (!validate()) {
            event.preventDefault();
            return;
        }

        if (!window.fetch || !window.DOMParser || !window.AbortController) {
            return;   // let the browser do a normal form post
        }

        event.preventDefault();

        if (pending) { pending.abort(); }

        var request = new AbortController();

        pending = request;
        setLoading(true);

        fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            signal: request.signal
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                return response.text();
            })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var fresh = $('results', page);

                if (!fresh) { throw new Error('Unexpected response'); }

                results.innerHTML = fresh.innerHTML;
                results.dataset.state = fresh.dataset.state;
                results.dataset.message = fresh.dataset.message;
                payload = readPayload(page);

                setLoading(false);
                applyResults(true);
            })
            .catch(function (error) {
                if (error.name === 'AbortError') { return; }

                setLoading(false);

                if (results.querySelector('.skeleton')) { results.textContent = ''; }

                setStatus('error', 'Could not load routes. Check your connection and try again.');
            })
            .then(function () {
                if (pending === request) { pending = null; }
            });
    });

    // ── 6. Mobile bottom sheet ─────────────────────────────────
    var sheet = (function () {
        var handle = $('sheet-handle');

        function peek(shouldPeek) {
            planner.classList.toggle('is-peek', shouldPeek);
            handle.setAttribute('aria-expanded', shouldPeek ? 'false' : 'true');

            if (mapView.hasRoutes()) { mapView.refit(); }
        }

        handle.addEventListener('click', function () {
            peek(!planner.classList.contains('is-peek'));
        });

        mobileQuery.addEventListener('change', function () {
            planner.classList.remove('is-peek');
            handle.setAttribute('aria-expanded', 'true');
            mapView.resize();
        });

        return { peek: peek };
    })();

    // ── Start ──────────────────────────────────────────────────
    mapView.init();

    if (mobileQuery.matches) { $('map-key').open = false; }

    [
        [originSelect, 'origin'],
        [destinationSelect, 'destination']
    ].forEach(function (pair) {
        var select = pair[0];

        enhancePicker(select);

        select.addEventListener('change', function () {
            mapView.setPin(pair[1], payload.termini && payload.termini[select.value]);
            mapView.showSelection();
            selectionHint();
        });

        mapView.setPin(pair[1], payload.termini && payload.termini[select.value]);
    });

    applyResults(false);

    if (results.dataset.state === 'idle') { mapView.showSelection(); }
})();
