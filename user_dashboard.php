<?php
require_once __DIR__ . '/auth.php';

$user = mapasaheRequireLogin();
$db = mapasaheDb();
$csrf = mapasaheCsrfToken();

$account = null;
$savedRoutes = [];
$recentSearches = [];
$searchCount = 0;
$dbError = $db === null;

if (!$dbError) {
    try {
        $stmt = $db->prepare('SELECT username, email, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $account = $stmt->fetch() ?: null;

        $stmt = $db->prepare(
            'SELECT id, origin_node_id, origin_name, destination_node_id, destination_name, created_at
             FROM saved_routes WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$user['id']]);
        $savedRoutes = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT id, origin_node_id, origin_name, destination_node_id, destination_name,
                    status, hybrid_cost_min, created_at
             FROM search_logs
             WHERE user_id = ? AND status <> 'invalid'
             ORDER BY id DESC LIMIT 60"
        );
        $stmt->execute([$user['id']]);

        // Show each trip once, at its most recent search.
        $seenTrips = [];

        foreach ($stmt->fetchAll() as $row) {
            $key = $row['origin_node_id'] . '>' . $row['destination_node_id'];

            if (!isset($seenTrips[$key]) && count($recentSearches) < 10) {
                $seenTrips[$key] = true;
                $recentSearches[] = $row;
            }
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM search_logs WHERE user_id = ? AND status <> 'invalid'");
        $stmt->execute([$user['id']]);
        $searchCount = (int) $stmt->fetchColumn();
    } catch (Throwable $error) {
        $dbError = true;
    }
}

$savedKeys = [];

foreach ($savedRoutes as $route) {
    $savedKeys[$route['origin_node_id'] . '>' . $route['destination_node_id']] = true;
}

function niceDate(string $datetime): string
{
    $time = strtotime($datetime);

    if (date('Y-m-d', $time) === date('Y-m-d')) {
        return 'Today, ' . date('g:i A', $time);
    }

    return date('M j, g:i A', $time);
}

/* A hidden form that re-runs a trip in the planner with one click. */
function runTripForm(array $trip, string $label, string $class): void
{ ?>
    <form method="POST" action="system1_3.php">
        <input type="hidden" name="origin_node_id" value="<?= mapasaheEsc($trip['origin_node_id']) ?>">
        <input type="hidden" name="destination_node_id" value="<?= mapasaheEsc($trip['destination_node_id']) ?>">
        <button type="submit" class="<?= mapasaheEsc($class) ?>"><?= mapasaheEsc($label) ?></button>
    </form>
<?php }

$notice = isset($_GET['notice']) ? (string) $_GET['notice'] : '';
$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0047AB">
    <title>My dashboard — MapaSahe</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="dashboard.css?v=<?= (int) @filemtime(__DIR__ . '/dashboard.css') ?>">
</head>

<body class="page page--user">

<header class="topbar">
    <a class="brand" href="system1_3.php">MapaSahe</a>

    <nav class="topbar__nav" aria-label="Account">
        <?php if ($user['role'] === 'admin'): ?>
            <a class="pill-link" href="admin_dashboard.php">Admin</a>
        <?php endif; ?>
        <a class="pill-link" href="system1_3.php">Planner</a>
        <form method="POST" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
            <button type="submit" class="pill-link">Log out</button>
        </form>
    </nav>
</header>

<main class="user-main">

    <section class="hero">
        <div>
            <p class="hero__eyebrow">My dashboard</p>
            <h1>Hi, <?= mapasaheEsc($user['username']) ?></h1>
            <p class="hero__sub">
                <?= $searchCount ?> <?= $searchCount === 1 ? 'search' : 'searches' ?>
                ·
                <?= count($savedRoutes) ?> saved <?= count($savedRoutes) === 1 ? 'trip' : 'trips' ?>
            </p>
        </div>

        <a class="btn btn--light" href="system1_3.php">Plan a route</a>
    </section>

    <?php if ($notice !== ''): ?>
        <p class="flash flash--ok" role="status"><?= mapasaheEsc($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="flash flash--error" role="alert"><?= mapasaheEsc($error) ?></p>
    <?php endif; ?>

    <?php if ($dbError): ?>
        <p class="flash flash--error" role="alert">
            Cannot reach the database, so your trips cannot be shown right now.
        </p>
    <?php endif; ?>

    <div class="user-grid">

        <section class="card" aria-labelledby="saved-title">
            <header class="card__head">
                <h2 id="saved-title">Saved routes</h2>
            </header>

            <?php if (empty($savedRoutes)): ?>
                <p class="empty">
                    Nothing saved yet. Run a search in the planner and press
                    "Save this trip", or save one from your recent searches.
                </p>
            <?php else: ?>
                <ul class="trip-list">
                    <?php foreach ($savedRoutes as $route): ?>
                        <li class="trip">
                            <div class="trip__text">
                                <p class="trip__name">
                                    <?= mapasaheEsc($route['origin_name']) ?>
                                    <span aria-hidden="true">→</span>
                                    <span class="visually-hidden">to</span>
                                    <?= mapasaheEsc($route['destination_name']) ?>
                                </p>
                                <p class="trip__meta">Saved <?= mapasaheEsc(niceDate($route['created_at'])) ?></p>
                            </div>

                            <div class="trip__actions">
                                <?php runTripForm($route, 'Go', 'btn btn--small btn--primary'); ?>

                                <form method="POST" action="user_actions.php">
                                    <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
                                    <input type="hidden" name="action" value="remove_route">
                                    <input type="hidden" name="route_id" value="<?= (int) $route['id'] ?>">
                                    <button type="submit" class="btn btn--small btn--ghost">Remove</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="recent-title">
            <header class="card__head">
                <h2 id="recent-title">My recent searches</h2>
            </header>

            <?php if (empty($recentSearches)): ?>
                <p class="empty">
                    Searches you run while logged in will show up here.
                </p>
            <?php else: ?>
                <ul class="trip-list">
                    <?php foreach ($recentSearches as $search):
                        $isSaved = isset($savedKeys[$search['origin_node_id'] . '>' . $search['destination_node_id']]);
                    ?>
                        <li class="trip">
                            <div class="trip__text">
                                <p class="trip__name">
                                    <?= mapasaheEsc($search['origin_name']) ?>
                                    <span aria-hidden="true">→</span>
                                    <span class="visually-hidden">to</span>
                                    <?= mapasaheEsc($search['destination_name']) ?>
                                </p>
                                <p class="trip__meta">
                                    <?= mapasaheEsc(niceDate($search['created_at'])) ?>
                                    ·
                                    <?php if ($search['status'] === 'ok' && $search['hybrid_cost_min'] !== null): ?>
                                        about <?= (int) max(1, round($search['hybrid_cost_min'])) ?> min
                                    <?php elseif ($search['status'] === 'ok'): ?>
                                        route found
                                    <?php else: ?>
                                        <span class="tag tag--warn">No route found</span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="trip__actions">
                                <?php runTripForm($search, 'Run again', 'btn btn--small btn--primary'); ?>

                                <?php if ($isSaved): ?>
                                    <span class="tag">Saved</span>
                                <?php else: ?>
                                    <form method="POST" action="user_actions.php">
                                        <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
                                        <input type="hidden" name="action" value="save_route">
                                        <input type="hidden" name="origin_node_id" value="<?= mapasaheEsc($search['origin_node_id']) ?>">
                                        <input type="hidden" name="origin_name" value="<?= mapasaheEsc($search['origin_name']) ?>">
                                        <input type="hidden" name="destination_node_id" value="<?= mapasaheEsc($search['destination_node_id']) ?>">
                                        <input type="hidden" name="destination_name" value="<?= mapasaheEsc($search['destination_name']) ?>">
                                        <button type="submit" class="btn btn--small btn--ghost">Save</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="account-title">
            <header class="card__head">
                <h2 id="account-title">Account</h2>
            </header>

            <dl class="facts">
                <div>
                    <dt>Username</dt>
                    <dd><?= mapasaheEsc($account['username'] ?? $user['username']) ?></dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><?= mapasaheEsc($account['email'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Account type</dt>
                    <dd><?= ($account['role'] ?? $user['role']) === 'admin' ? 'Admin' : 'User' ?></dd>
                </div>
                <div>
                    <dt>Member since</dt>
                    <dd><?= $account ? mapasaheEsc(date('M j, Y', strtotime($account['created_at']))) : '—' ?></dd>
                </div>
            </dl>

            <details class="disclosure">
                <summary>Change password</summary>

                <form method="POST" action="user_actions.php" class="stack">
                    <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <label class="input">
                        <span>Current password</span>
                        <input type="password" name="current_password" autocomplete="current-password" required>
                    </label>

                    <label class="input">
                        <span>New password (at least 8 characters)</span>
                        <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                    </label>

                    <button type="submit" class="btn btn--primary">Change password</button>
                </form>
            </details>

            <form method="POST" action="logout.php" class="card__foot">
                <input type="hidden" name="csrf_token" value="<?= mapasaheEsc($csrf) ?>">
                <button type="submit" class="btn btn--ghost">Log out</button>
            </form>
        </section>
    </div>
</main>

</body>
</html>
