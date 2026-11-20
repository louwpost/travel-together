<?php
declare(strict_types=1);

session_name('travel_tracker');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectToHome(): never
{
    header('Location: ./');
    exit;
}

function respondWithJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function requireValidCsrfToken(): void
{
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new DomainException('Your session expired. Refresh the page and try again.');
    }
}

function saveSetting(PDO $database, string $key, string $value): void
{
    $statement = $database->prepare(
        'INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (:key, :value)'
    );
    $statement->execute(['key' => $key, 'value' => $value]);
}

function getVisitCounts(PDO $database): array
{
    $statement = $database->query(
        'SELECT person_id, COUNT(*) AS visit_count FROM visits GROUP BY person_id'
    );
    $counts = ['person_1' => 0, 'person_2' => 0];

    foreach ($statement->fetchAll() as $row) {
        $counts[$row['person_id']] = (int) $row['visit_count'];
    }

    $sharedCount = (int) $database->query(
        'SELECT COUNT(*) FROM visits AS first
         INNER JOIN visits AS second
            ON first.country_code = second.country_code
         WHERE first.person_id = \'person_1\' AND second.person_id = \'person_2\''
    )->fetchColumn();

    return ['people' => $counts, 'shared' => $sharedCount];
}

$countryJson = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'countries.json');
if ($countryJson === false) {
    throw new RuntimeException('The ISO country list could not be loaded.');
}

$countries = json_decode($countryJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($countries) || count($countries) !== 249) {
    throw new RuntimeException('The ISO country list is incomplete or invalid.');
}
uasort($countries, static fn (string $first, string $second): int => strcasecmp($first, $second));

$configuredDataDirectory = getenv('TRAVEL_TRACKER_DATA_DIR');
$dataDirectory = $configuredDataDirectory !== false && $configuredDataDirectory !== ''
    ? $configuredDataDirectory
    : __DIR__ . DIRECTORY_SEPARATOR . 'storage';

if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0700, true) && !is_dir($dataDirectory)) {
    throw new RuntimeException('The application data directory could not be created.');
}

$database = new PDO('sqlite:' . $dataDirectory . DIRECTORY_SEPARATOR . 'travel-tracker.sqlite');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec('PRAGMA busy_timeout = 5000');
$database->exec(
    'CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    )'
);
$database->exec(
    'CREATE TABLE IF NOT EXISTS visits (
        person_id TEXT NOT NULL,
        country_code TEXT NOT NULL,
        PRIMARY KEY (person_id, country_code)
    )'
);

$settings = $database->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$isFirstRun = !isset($settings['password_hash']);
$error = '';
$action = (string) ($_POST['action'] ?? '');
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrfToken();

        if ($action === 'setup' && $isFirstRun) {
            $firstName = trim((string) ($_POST['person_1_name'] ?? ''));
            $secondName = trim((string) ($_POST['person_2_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

            if ($firstName === '' || $secondName === '' || strlen($firstName) > 40 || strlen($secondName) > 40) {
                throw new DomainException('Enter a name of 1–40 characters for each traveler.');
            }
            if (strlen($password) < 12) {
                throw new DomainException('Choose a password with at least 12 characters.');
            }
            if ($password !== $passwordConfirmation) {
                throw new DomainException('The passwords do not match.');
            }

            $database->beginTransaction();
            saveSetting($database, 'person_1_name', $firstName);
            saveSetting($database, 'person_2_name', $secondName);
            saveSetting($database, 'password_hash', password_hash($password, PASSWORD_DEFAULT));
            $database->commit();

            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            redirectToHome();
        }

        if ($action === 'login' && $isFirstRun === false) {
            $passwordHash = (string) $settings['password_hash'];
            if (password_verify((string) ($_POST['password'] ?? ''), $passwordHash)) {
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                redirectToHome();
            }

            $error = 'That password was not correct.';
        } elseif ($action === 'toggle' && !$isFirstRun && !empty($_SESSION['authenticated'])) {
            $countryCode = strtoupper((string) ($_POST['country_code'] ?? ''));
            $personId = (string) ($_POST['person_id'] ?? '');
            $visited = (string) ($_POST['visited'] ?? '');

            if (!isset($countries[$countryCode]) || !in_array($personId, ['person_1', 'person_2'], true)
                || !in_array($visited, ['0', '1'], true)) {
                throw new DomainException('That country or traveler selection is invalid.');
            }

            if ($visited === '1') {
                $statement = $database->prepare(
                    'INSERT OR IGNORE INTO visits (person_id, country_code)
                     VALUES (:person_id, :country_code)'
                );
            } else {
                $statement = $database->prepare(
                    'DELETE FROM visits WHERE person_id = :person_id AND country_code = :country_code'
                );
            }
            $statement->execute(['person_id' => $personId, 'country_code' => $countryCode]);

            if ($isAjaxRequest) {
                $counts = getVisitCounts($database);
                respondWithJson([
                    'counts' => $counts['people'],
                    'shared' => $counts['shared'],
                ]);
            }
            redirectToHome();
        } elseif ($action === 'toggle' && !$isFirstRun) {
            if ($isAjaxRequest) {
                respondWithJson(['error' => 'Your session expired. Sign in again.'], 401);
            }
            redirectToHome();
        } elseif ($action === 'save_names' && !$isFirstRun && !empty($_SESSION['authenticated'])) {
            $firstName = trim((string) ($_POST['person_1_name'] ?? ''));
            $secondName = trim((string) ($_POST['person_2_name'] ?? ''));

            if ($firstName === '' || $secondName === '' || strlen($firstName) > 40 || strlen($secondName) > 40) {
                throw new DomainException('Enter a name of 1–40 characters for each traveler.');
            }

            saveSetting($database, 'person_1_name', $firstName);
            saveSetting($database, 'person_2_name', $secondName);
            redirectToHome();
        } elseif ($action === 'change_password' && !$isFirstRun && !empty($_SESSION['authenticated'])) {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirmation = (string) ($_POST['new_password_confirmation'] ?? '');

            if (!password_verify($currentPassword, (string) $settings['password_hash'])) {
                throw new DomainException('Your current password was not correct.');
            }
            if (strlen($newPassword) < 12) {
                throw new DomainException('Choose a password with at least 12 characters.');
            }
            if ($newPassword !== $newPasswordConfirmation) {
                throw new DomainException('The new passwords do not match.');
            }

            saveSetting($database, 'password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
            redirectToHome();
        } elseif ($action === 'logout' && !$isFirstRun && !empty($_SESSION['authenticated'])) {
            $_SESSION = [];
            session_destroy();
            redirectToHome();
        } else {
            redirectToHome();
        }
    } catch (DomainException $exception) {
        if ($action === 'toggle' && $isAjaxRequest) {
            respondWithJson(['error' => $exception->getMessage()], 422);
        }
        $error = $exception->getMessage();
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$isAuthenticated = !empty($_SESSION['authenticated']);

if (!$isFirstRun && !$isAuthenticated) {
    $pageTitle = 'Sign in';
} elseif ($isFirstRun) {
    $pageTitle = 'Set up your travel tracker';
}

if (!$isFirstRun && $isAuthenticated) {
    $settings = $database->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $personNames = [
        'person_1' => $settings['person_1_name'] ?? 'You',
        'person_2' => $settings['person_2_name'] ?? 'Your partner',
    ];
    $visitedCountries = ['person_1' => [], 'person_2' => []];
    foreach ($database->query('SELECT person_id, country_code FROM visits')->fetchAll() as $visit) {
        $visitedCountries[$visit['person_id']][$visit['country_code']] = true;
    }
    $visitCounts = getVisitCounts($database);
    $worldMapPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'world-map.json';
    $worldMapSource = file_get_contents($worldMapPath);
    if ($worldMapSource === false) {
        throw new RuntimeException('The bundled world map could not be loaded.');
    }
    $worldMapData = json_decode($worldMapSource, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($worldMapData['features']) || !is_array($worldMapData['features'])) {
        throw new RuntimeException('The bundled world map data is invalid.');
    }
    $worldMapJson = json_encode(
        $worldMapData['features'],
        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111714">
    <title><?= e($pageTitle ?? 'Travel together') ?> · Travel together</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($isFirstRun || !$isAuthenticated): ?>
    <main class="auth-shell">
        <a class="brand" href="./"><span class="brand-mark" aria-hidden="true">T</span> Travel together</a>
        <section class="auth-card">
            <p class="eyebrow"><?= $isFirstRun ? 'A little place for your big adventures' : 'Welcome back' ?></p>
            <h1><?= $isFirstRun ? 'Start your travel story.' : 'Pick up where you left off.' ?></h1>
            <p class="muted"><?= $isFirstRun
                ? 'Set up a shared, private list of the places you have each explored.'
                : 'Sign in to see your countries and the ones you have visited together.' ?></p>

            <?php if ($error !== ''): ?>
                <p class="notice notice-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>

            <?php if ($isFirstRun): ?>
                <form method="post" class="form-stack">
                    <input type="hidden" name="action" value="setup">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <div class="form-row">
                        <label for="person-1-name">First traveler</label>
                        <input id="person-1-name" name="person_1_name" maxlength="40" value="You" required>
                    </div>
                    <div class="form-row">
                        <label for="person-2-name">Second traveler</label>
                        <input id="person-2-name" name="person_2_name" maxlength="40" value="Your partner" required>
                    </div>
                    <div class="form-row">
                        <label for="setup-password">Create a password</label>
                        <input id="setup-password" type="password" name="password" minlength="12" autocomplete="new-password" required>
                        <span class="field-hint">Use at least 12 characters.</span>
                    </div>
                    <div class="form-row">
                        <label for="setup-password-confirmation">Confirm password</label>
                        <input id="setup-password-confirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required>
                    </div>
                    <button class="button button-primary button-wide" type="submit">Create your tracker <span aria-hidden="true">→</span></button>
                </form>
            <?php else: ?>
                <form method="post" class="form-stack">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <div class="form-row">
                        <label for="login-password">Password</label>
                        <input id="login-password" type="password" name="password" autocomplete="current-password" required autofocus>
                    </div>
                    <button class="button button-primary button-wide" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
            <?php endif; ?>
            <p class="auth-footnote">Your country list stays in your own app database.</p>
        </section>
    </main>
<?php else: ?>
    <header class="topbar">
        <a class="brand" href="./"><span class="brand-mark" aria-hidden="true">T</span> Travel together</a>
        <div class="topbar-actions">
            <span class="topbar-note">Your shared travel journal</span>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <button class="button button-quiet" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <main class="page-shell">
        <?php if ($error !== ''): ?>
            <p class="notice notice-error dashboard-notice" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <section class="hero">
            <div>
                <p class="eyebrow">The world, one trip at a time</p>
                <h1>Your travels,<br><span>all in one place.</span></h1>
                <p class="hero-copy">Keep track of where you have each been, and celebrate the places you have seen together.</p>
            </div>
            <div class="hero-stamp" aria-hidden="true"><span>✳</span><small>MADE FOR<br>THE JOURNEY</small></div>
        </section>

        <section class="stats-grid" aria-label="Travel totals">
            <?php foreach (['person_1', 'person_2'] as $personId): ?>
                <article class="stat-card">
                    <span class="stat-label"><?= e($personNames[$personId]) ?> has visited</span>
                    <strong class="stat-number" data-count="<?= e($personId) ?>"><?= $visitCounts['people'][$personId] ?></strong>
                    <span class="stat-caption">countries &amp; territories</span>
                    <span class="stat-accent" aria-hidden="true"></span>
                </article>
            <?php endforeach; ?>
            <article class="stat-card stat-card-shared">
                <span class="stat-label">Adventures you share</span>
                <strong class="stat-number" data-shared-count><?= $visitCounts['shared'] ?></strong>
                <span class="stat-caption">visited by both of you</span>
                <span class="stat-accent" aria-hidden="true"></span>
            </article>
        </section>

        <section class="map-panel" aria-labelledby="map-heading">
            <div class="map-panel-heading">
                <div>
                    <p class="eyebrow">A view of your adventures</p>
                    <h2 id="map-heading">Your world map</h2>
                    <p class="panel-subtitle">See the places you have visited light up. Select a country to find it in your checklist; select it again to clear the search.</p>
                </div>
                <div class="map-filters" role="group" aria-label="Choose whose visits to show on the map">
                    <button class="map-filter-button" type="button" data-map-filter="all" aria-pressed="true">Either of us</button>
                    <button class="map-filter-button" type="button" data-map-filter="person_1" aria-pressed="false"><?= e($personNames['person_1']) ?></button>
                    <button class="map-filter-button" type="button" data-map-filter="person_2" aria-pressed="false"><?= e($personNames['person_2']) ?></button>
                    <button class="map-filter-button" type="button" data-map-filter="both" aria-pressed="false">Together</button>
                </div>
            </div>
            <div class="map-legend" aria-label="Map legend">
                <span><i class="legend-swatch legend-person-1" aria-hidden="true"></i><?= e($personNames['person_1']) ?></span>
                <span><i class="legend-swatch legend-person-2" aria-hidden="true"></i><?= e($personNames['person_2']) ?></span>
                <span><i class="legend-swatch legend-shared" aria-hidden="true"></i>Both</span>
            </div>
            <div class="world-map-wrap">
                <svg id="world-map" viewBox="0 0 1000 500" role="group" aria-labelledby="map-svg-title map-svg-description" xmlns="http://www.w3.org/2000/svg">
                    <title id="map-svg-title">World map showing visited destinations</title>
                    <desc id="map-svg-description">Select a country on the map to find its visit checkboxes in the checklist below. Select it again to clear the search.</desc>
                    <g id="world-map-countries"></g>
                </svg>
            </div>
        </section>

        <section class="country-panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Your checklist</p>
                    <h2>Everywhere you have been</h2>
                    <p class="panel-subtitle">A tick for each traveler. Tick both to mark a place you explored together.</p>
                </div>
                <span class="list-count">249 <span>ISO 3166 destinations</span></span>
            </div>
            <div class="toolbar">
                <div class="search-box">
                    <span class="search-icon" aria-hidden="true">⌕</span>
                    <label class="visually-hidden" for="country-search">Search countries</label>
                    <input id="country-search" type="search" placeholder="Find a country…" autocomplete="off">
                    <button class="search-clear" type="button" aria-label="Clear country search" hidden>×</button>
                    <kbd>/</kbd>
                </div>
                <div class="filter-buttons" role="group" aria-label="Filter destinations">
                    <button class="filter-button" type="button" data-filter="all" aria-pressed="true">All</button>
                    <button class="filter-button" type="button" data-filter="both" aria-pressed="false">Both</button>
                    <button class="filter-button" type="button" data-filter="one" aria-pressed="false">One of us</button>
                    <button class="filter-button" type="button" data-filter="none" aria-pressed="false">Not yet</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="country-table">
                    <thead>
                        <tr>
                            <th scope="col">Destination</th>
                            <th scope="col" class="person-column"><?= e($personNames['person_1']) ?></th>
                            <th scope="col" class="person-column"><?= e($personNames['person_2']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($countries as $countryCode => $countryName):
                        $firstVisited = isset($visitedCountries['person_1'][$countryCode]);
                        $secondVisited = isset($visitedCountries['person_2'][$countryCode]);
                    ?>
                        <tr class="country-row"
                            data-country-name="<?= e($countryName) ?>"
                            data-country-code="<?= e($countryCode) ?>">
                            <th scope="row">
                                <span class="country-name"><?= e($countryName) ?></span>
                                <span class="country-code"><?= e($countryCode) ?></span>
                            </th>
                            <?php foreach (['person_1' => $firstVisited, 'person_2' => $secondVisited] as $personId => $hasVisited): ?>
                                <td class="person-column">
                                    <form class="visit-toggle">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="country_code" value="<?= e($countryCode) ?>">
                                        <input type="hidden" name="person_id" value="<?= e($personId) ?>">
                                        <input type="hidden" name="visited" value="<?= $hasVisited ? '1' : '0' ?>">
                                        <input type="checkbox" data-visit aria-label="<?= e($personNames[$personId] . ': ' . $countryName) ?>" <?= $hasVisited ? 'checked' : '' ?>>
                                    </form>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="empty-state" id="empty-state" hidden>No destinations match your search.</p>
            </div>
            <p class="save-status" id="save-status" role="status" aria-live="polite"></p>
        </section>

        <details class="settings-panel">
            <summary>Personalize your tracker</summary>
            <div class="settings-content">
                <form method="post" class="settings-form">
                    <input type="hidden" name="action" value="save_names">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <h3>Traveler names</h3>
                    <div class="settings-fields">
                        <label><?= e($personNames['person_1']) ?><input name="person_1_name" maxlength="40" value="<?= e($personNames['person_1']) ?>" required></label>
                        <label><?= e($personNames['person_2']) ?><input name="person_2_name" maxlength="40" value="<?= e($personNames['person_2']) ?>" required></label>
                        <button class="button button-secondary" type="submit">Save names</button>
                    </div>
                </form>
                <form method="post" class="settings-form">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <h3>Change password</h3>
                    <div class="settings-fields password-fields">
                        <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
                        <label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label>
                        <label>Confirm new password<input type="password" name="new_password_confirmation" minlength="12" autocomplete="new-password" required></label>
                        <button class="button button-secondary" type="submit">Update password</button>
                    </div>
                </form>
            </div>
        </details>

        <footer class="page-footer"><span>One world. Two travelers. Countless stories.</span><span>Country codes follow ISO 3166-1 alpha-2.</span></footer>
    </main>
    <script type="application/json" id="world-map-data"><?= $worldMapJson ?></script>
    <script src="assets/app.js" defer></script>
<?php endif; ?>
</body>
</html>
