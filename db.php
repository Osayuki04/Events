<?php
// Shared setup. Every page starts with include("db.php");
//   0. Security headers + hide PHP errors from visitors
//   1. MySQL connection ($mysqli)
//   2. Session + form token (CSRF protection)
//   3. Twig, the template engine ($twig) - all HTML lives in the templates/ folder
//   4. Small helpers every page uses (clean input, render a page, answer Ajax requests)
//   5. Login: require_login() protects a page, check_captcha() checks the captcha

// ---------------------------------------------------------------- 0. Security

// Never show PHP errors to visitors (they reveal file paths and code). They go to the error log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Security headers, sent with every page
if (!headers_sent()) {
    header_remove('X-Powered-By');
    // Content-Security-Policy: the browser only runs scripts, styles, fonts and images from the places
    // listed here, so a script injected into the page (XSS) is blocked even if one ever got through.
    // No 'unsafe-inline' anywhere: the only scripts are this site's own files, and the only styles are
    // CSS files (Tailwind is built into assets/css/app.css, the icons and font come from their CDNs).
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
        . "img-src 'self' data: https://api.dicebear.com; "
        . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');                      // don't guess file types
    header('X-Frame-Options: DENY');                                 // can't be put inside another site (clickjacking)
    header('Referrer-Policy: same-origin');                          // other sites never see which page you came from
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Cross-origin isolation: no other site can open us in a popup and reach into the page (COOP),
    // load our files into theirs (CORP), and we only load other sites' files without our cookies (COEP)
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: credentialless');
    header('X-Robots-Tag: noindex, nofollow');                       // a private diary: keep it out of search engines
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');       // HTTPS only (on servers that have it)
    }
}

// ---------------------------------------------------------------- 1. MySQL

// Make MySQL errors throw exceptions (caught below so the real error is never shown)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // On XAMPP (localhost) use the local database, on mi-linux use the university one.
    if (in_array($_SERVER['SERVER_NAME'] ?? 'localhost', ['localhost', '127.0.0.1'])) {
        $mysqli = mysqli_connect("localhost", "root", "", "event_diary");
    } else {
        $mysqli = mysqli_connect("localhost", "2463526", "455qm2", "db2463526");
    }
} catch (mysqli_sql_exception $e) {
    // Don't show the real error (it contains connection details)
    die("Sorry, the database is not available right now.");
}

mysqli_set_charset($mysqli, "utf8mb4");

// ---------------------------------------------------------------- 2. Session

// The session cookie can't be read by JavaScript (HttpOnly) and isn't sent from other sites (SameSite).
// On HTTPS it is only ever sent over HTTPS (Secure). Strict mode refuses session ids the server didn't make.
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

// Form token (CSRF protection): every POST form sends it back, and pages check it.
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------- 3. Twig

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/greeting.php'; // greeting(), today_text(), time_ago()

// autoescape: every {{ value }} in a template is escaped with htmlspecialchars (XSS protection)
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates'), ['autoescape' => 'html']);

// Things every template can use
$twig->addGlobal('token', $_SESSION['token']);
$twig->addGlobal('user', $_SESSION['username'] ?? null); // who is logged in (null on the login page)
$twig->addFunction(new \Twig\TwigFunction('greeting', 'greeting'));
$twig->addFunction(new \Twig\TwigFunction('today_text', 'today_text'));
$twig->addFilter(new \Twig\TwigFilter('time_ago', 'time_ago'));
// asset('js/app.js') -> "assets/js/app.js?v=1794..." (the number changes when the file changes, so browsers reload it)
$twig->addFunction(new \Twig\TwigFunction('asset', function (string $path): string {
    return 'assets/' . $path . '?v=' . filemtime(__DIR__ . '/assets/' . $path);
}));

// ---------------------------------------------------------------- 4. Helpers

// Tidy one text value from a form or the address bar (input filtering):
//   - anything that isn't plain text (e.g. a list sent as name[]=x) becomes ''
//   - HTML tags are removed, so <script> etc. is never even stored (HTML injection)
//   - invisible control characters are removed, spaces trimmed, and the length is limited
// Output is escaped as well (Twig autoescape), so this is a second layer of protection.
function clean_text($value, int $max_length): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr(trim($value), 0, $max_length);
}

// Show a page: render a template, adding the "Event saved" style message if there is one
function render(string $template, array $data = []): void
{
    global $twig;
    $data['flash'] = $_SESSION['message'] ?? null;
    unset($_SESSION['message']);
    echo $twig->render($template, $data);
}

// Is this request coming from our JavaScript (Ajax)? app.js sends the header "X-Requested-With: fetch".
function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' || isset($_GET['ajax']);
}

// Answer an Ajax request with JSON and stop
function send_json(array $data): never
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Stop if a POST form didn't come from one of our pages (CSRF protection)
function check_token(): void
{
    $sent = $_POST['token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['token'], $sent)) {
        if (is_ajax()) {
            send_json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
        }
        die("Your session expired. Go back, refresh the page and try again.");
    }
}

// After saving: go to $url and show $message there.
// For Ajax, the JSON tells app.js where to go.
function done(string $url, string $message): never
{
    $_SESSION['message'] = $message;
    if (is_ajax()) {
        send_json(['ok' => true, 'redirect' => $url]);
    }
    header("Location: $url");
    exit;
}

// A form had errors: Ajax gets them as JSON (shown under each field without reloading).
// Without JavaScript the page carries on and shows the form again with the errors.
function send_errors(array $errors): void
{
    if (is_ajax()) {
        send_json(['ok' => false, 'errors' => $errors]);
    }
}

// ---------------------------------------------------------------- 5. Login

// Log out after 30 minutes without doing anything
const IDLE_TIMEOUT = 30 * 60;

// Put at the top of every page that needs a login (straight after include("db.php")):
//     $user_id = require_login();
// Not logged in (or idle too long) -> sent to the login page, which brings you back afterwards.
// Returns the id of the logged-in user. Every query on events, people and jobs uses it, so each
// user only ever sees and changes their own diary.
function require_login(): int
{
    global $mysqli;
    $logged_in = !empty($_SESSION['user_id']);

    if ($logged_in && time() - ($_SESSION['last_seen'] ?? 0) > IDLE_TIMEOUT) {
        log_out();
        $_SESSION['message'] = 'You were logged out after 30 minutes without using the site.';
        $logged_in = false;
    }

    // The account must still exist (e.g. it was deleted while this session was open)
    if ($logged_in) {
        $stmt = mysqli_prepare($mysqli, "SELECT id FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            log_out();
            $logged_in = false;
        }
    }

    if (!$logged_in) {
        if (is_ajax()) {
            http_response_code(401);
            send_json(['ok' => false, 'redirect' => 'login.php']);
        }
        // Remember the page so the login can bring you back to it (only for normal page views)
        $page = basename($_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        header('Location: login.php' . ($_SERVER['REQUEST_METHOD'] === 'GET' ? '?next=' . urlencode($page) : ''));
        exit;
    }

    $_SESSION['last_seen'] = time();
    return (int) $_SESSION['user_id'];
}

// Logged in: a new session id (stops session fixation) and a new form token
function log_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['last_seen'] = time();
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Forget who was logged in and start a fresh session
function log_out(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Only allow going back to one of our own pages after logging in (no "?next=https://evil.com")
function safe_next(string $next): string
{
    // A page name like "events.php", maybe with "?..." after it. No spaces, backslashes or "//".
    return preg_match('/^[a-z_]+\.php(\?[^\s\x5C]*)?$/', $next) && !str_contains($next, '//') ? $next : 'index.php';
}

// Check the captcha typed in. Each captcha works once and for 10 minutes only.
function check_captcha($answer): bool
{
    $captcha = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']); // used up, right or wrong (so it can't be guessed again and again)
    if (!is_string($answer) || !$captcha || time() - $captcha['time'] > 600) {
        return false;
    }
    return hash_equals($captcha['code'], strtoupper(trim($answer)));
}

// How many accounts exist (0 = the site is new and asks you to create one)
function count_users(mysqli $mysqli): int
{
    return (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users"))[0];
}

// ---------------------------------------------------------------- 6. Queries

// Run a prepared statement with these values for the ? placeholders and return all the rows.
// Used for every query that includes the logged-in user's id (or anything else from outside).
function fetch_rows(string $sql, array $params = []): array
{
    global $mysqli;
    $stmt = mysqli_prepare($mysqli, $sql);
    if (count($params) > 0) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

// The same, for a query that returns one row (null if there is none)
function fetch_row(string $sql, array $params = []): ?array
{
    return fetch_rows($sql, $params)[0] ?? null;
}

// Run an INSERT / UPDATE / DELETE with these values for the ? placeholders; returns how many rows changed
function run_query(string $sql, array $params = []): int
{
    global $mysqli;
    $stmt = mysqli_prepare($mysqli, $sql);
    if (count($params) > 0) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_affected_rows($stmt);
}

// ---------------------------------------------------------------- 7. Live search

// Run a search and return just the ids of the rows that match, in order (whole numbers only).
// The live search sends these ids to the browser instead of HTML: the page already has every card
// (drawn by Twig) and app.js only shows, hides and orders them. No HTML is sent, so none can be injected.
function matching_ids(mysqli $mysqli, string $sql, array $params): array
{
    $stmt = mysqli_prepare($mysqli, $sql);
    if (count($params) > 0) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
    mysqli_stmt_execute($stmt);
    return array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'id'));
}

// Deleted with the bin button on a card in a list (the form sends from=list): stay on that list.
// Ajax gets "refresh" so app.js hides the card and updates the numbers; without JavaScript the list reloads.
// $page is always a fixed page name from the code, never from the request.
function deleted_from_list(string $page, string $message): never
{
    if (is_ajax()) {
        send_json(['ok' => true, 'refresh' => true, 'message' => $message]);
    }
    $_SESSION['message'] = $message;
    header("Location: $page");
    exit;
}
