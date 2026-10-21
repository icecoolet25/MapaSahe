<?php
require_once __DIR__ . '/auth.php';

$user = mapasaheRequireAdmin();
$csrf = mapasaheCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#002244">
    <title>Admin dashboard — MapaSahe</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="dashboard.css?v=<?= (int) @filemtime(__DIR__ . '/dashboard.css') ?>">
    <script defer src="admin.js?v=<?= (int) @filemtime(__DIR__ . '/admin.js') ?>"></script>
</head>

<body class="page page--admin">

<a class="skip-link" href="#overview">Skip to dashboard</a>

<aside class="sidebar">
    <a class="brand" href="system1_4.php">MapaSahe <span>Admin</span></a>

    <nav class="sidebar__nav" aria-label="Dashboard sections">
        <a href="#overview">Overview</a>
        <a href="#models">Model performance</a>
        <a href="#cost">Time complexity &amp; cost</a>
        <a href="#eta">Processing time</a>
        <a href="#paths">Frequent paths</a>
        <a href="#stops">Stops</a>
        <a href="#history">History log</a>
    </nav>

    <div class="sidebar__foot">
        <p>Signed in as <strong><?= mapasaheEsc($user['username']) ?></strong></p>
        <a class="pill-link" href="system1_4.php">Planner</a>
        <a class="pill-link" href="user_dashboard.php">My dashboard</a>
        <form method="POST" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
            <button type="submit" class="pill-link">Log out</button>
        </form>
    </div>
</aside>

<main class="admin-main">

    <header class="admin-head">
        <div>
            <h1>System dashboard</h1>
            <p class="admin-head__sub" id="updated" role="status" aria-live="polite">Loading…</p>
        </div>

        <div class="admin-head__tools">
            <div class="segmented" role="group" aria-label="Time period">
                <button type="button" data-range="today">Today</button>
                <button type="button" data-range="7d" aria-pressed="true">7 days</button>
                <button type="button" data-range="30d">30 days</button>
                <button type="button" data-range="all">All time</button>
            </div>

            <button type="button" class="btn btn--ghost btn--small" id="refresh">Refresh</button>
        </div>
    </header>

    <p class="flash flash--error" id="load-error" role="alert" hidden></p>

    <!-- Overview -->
    <section class="section" id="overview" aria-labelledby="overview-title">
        <h2 class="section__title" id="overview-title">Overview</h2>

        <div class="kpis" id="kpis"></div>

        <div class="card">
            <header class="card__head">
                <h3>Needs attention</h3>
            </header>
            <ul class="attention" id="attention"></ul>
        </div>
    </section>

    <!-- Performance per model -->
    <section class="section" id="models" aria-labelledby="models-title">
        <h2 class="section__title" id="models-title">Performance per model</h2>
        <p class="section__note">
            A model succeeds when it returns a path and fails when it finds none.
            Times are each model's own search time on the server.
        </p>

        <div class="model-cards" id="model-cards"></div>

        <div class="card card--flush">
            <div class="table-wrap">
                <table class="table" id="model-table">
                    <thead>
                        <tr>
                            <th scope="col">Model</th>
                            <th scope="col" class="num">Runs</th>
                            <th scope="col" class="num">Success</th>
                            <th scope="col" class="num">No path</th>
                            <th scope="col" class="num">Avg time</th>
                            <th scope="col" class="num">Median</th>
                            <th scope="col" class="num">95th pct.</th>
                            <th scope="col" class="num">Avg stops explored</th>
                            <th scope="col" class="num">Avg route cost</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Time complexity / relative cost -->
    <section class="section" id="cost" aria-labelledby="cost-title">
        <h2 class="section__title" id="cost-title">Time complexity &amp; relative cost</h2>
        <p class="section__note">
            Theoretical growth next to what was measured. Relative cost is each
            model's average time against the quickest model (1.0×).
            V = stops, E = connections, P = edges in the path.
        </p>

        <div class="split">
            <div class="card">
                <header class="card__head">
                    <h3>Model cost</h3>
                </header>
                <div id="cost-list" class="cost-list"></div>
            </div>

            <div class="card">
                <header class="card__head">
                    <h3>Where a request's time goes</h3>
                </header>
                <div id="stage-bar" class="stage-bar"></div>
                <ul id="stage-legend" class="stage-legend"></ul>
            </div>
        </div>
    </section>

    <!-- ETA / processing time -->
    <section class="section" id="eta" aria-labelledby="eta-title">
        <h2 class="section__title" id="eta-title">Processing time</h2>
        <p class="section__note">
            How long a search takes from request to result, measured on the server.
        </p>

        <div class="kpis kpis--small" id="eta-kpis"></div>

        <div class="card">
            <header class="card__head">
                <h3>Most recent searches</h3>
                <p class="card__hint">Oldest on the left. The dashed line is the period average.</p>
            </header>
            <div id="timeline" class="timeline"></div>
        </div>
    </section>

    <!-- Frequent paths -->
    <section class="section" id="paths" aria-labelledby="paths-title">
        <h2 class="section__title" id="paths-title">Frequently used paths</h2>
        <p class="section__note">The start and destination pairs searched most often.</p>

        <div class="card card--flush">
            <div class="table-wrap">
                <table class="table" id="paths-table">
                    <thead>
                        <tr>
                            <th scope="col" class="num">#</th>
                            <th scope="col">From → To</th>
                            <th scope="col">Searches</th>
                            <th scope="col" class="num">Avg time</th>
                            <th scope="col" class="num">Route found</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Stops -->
    <section class="section" id="stops" aria-labelledby="stops-title">
        <h2 class="section__title" id="stops-title">Stops</h2>
        <p class="section__note">
            How often each jeepney stop appears in searches: as the start, as the
            destination, or along the suggested route.
        </p>

        <div class="kpis kpis--small" id="stop-kpis"></div>

        <div class="card card--flush">
            <header class="card__head card__head--pad">
                <h3>Stop usage</h3>
                <label class="search">
                    <span class="visually-hidden">Filter stops by name</span>
                    <input type="search" id="stop-filter" placeholder="Filter stops…">
                </label>
            </header>

            <div class="table-wrap table-wrap--tall">
                <table class="table" id="stops-table">
                    <thead>
                        <tr>
                            <th scope="col">Stop</th>
                            <th scope="col">Uses</th>
                            <th scope="col" class="num">Start</th>
                            <th scope="col" class="num">Destination</th>
                            <th scope="col" class="num">Along route</th>
                            <th scope="col" class="num">Avg search time</th>
                            <th scope="col" class="num">No-route rate</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- History log -->
    <section class="section" id="history" aria-labelledby="history-title">
        <h2 class="section__title" id="history-title">History log</h2>
        <p class="section__note">Every search, login and account change, newest first.</p>

        <div class="card card--flush">
            <form class="filters" id="history-filters">
                <label class="input">
                    <span>Type</span>
                    <select name="type" id="history-type">
                        <option value="">All types</option>
                    </select>
                </label>

                <label class="input">
                    <span>From</span>
                    <input type="date" name="from">
                </label>

                <label class="input">
                    <span>To</span>
                    <input type="date" name="to">
                </label>

                <button type="submit" class="btn btn--primary btn--small">Apply</button>
                <button type="reset" class="btn btn--ghost btn--small">Clear</button>
            </form>

            <div class="table-wrap">
                <table class="table" id="history-table">
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Type</th>
                            <th scope="col">User</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="card__foot card__foot--center">
                <button type="button" class="btn btn--ghost btn--small" id="history-more" hidden>Load older events</button>
            </div>
        </div>
    </section>
</main>

</body>
</html>
