<?php
// Shared setup. Every page starts with include("db.php");
//   1. MySQL connection ($mysqli)
//   2. Session + form token (CSRF protection)
//   3. Twig, the template engine ($twig) - all HTML lives in the templates/ folder
//   4. Small helpers every page uses (render a page, answer Ajax requests)

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

// The session cookie can't be read by JavaScript (HttpOnly) and isn't sent from other sites (SameSite)
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
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
$twig->addFunction(new \Twig\TwigFunction('greeting', 'greeting'));
$twig->addFunction(new \Twig\TwigFunction('today_text', 'today_text'));
$twig->addFilter(new \Twig\TwigFilter('time_ago', 'time_ago'));
// asset('js/app.js') -> "assets/js/app.js?v=1794..." (the number changes when the file changes, so browsers reload it)
$twig->addFunction(new \Twig\TwigFunction('asset', function (string $path): string {
    return 'assets/' . $path . '?v=' . filemtime(__DIR__ . '/assets/' . $path);
}));

// ---------------------------------------------------------------- 4. Helpers

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
    if (($_POST['token'] ?? '') !== $_SESSION['token']) {
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
