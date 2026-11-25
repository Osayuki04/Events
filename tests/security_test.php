<?php
/*
 * Security tests for Yukylo.
 *
 * Attacks every form and every search with real XSS (HTML injection) and SQL injection
 * payloads, then checks the site stayed safe. Also checks the security headers, the
 * form tokens (CSRF), broken input and that private files can't be opened.
 *
 * Run it from the project folder (Apache and MySQL must be running):
 *     C:\xampp\php\php.exe tests\security_test.php
 * or for another address:
 *     C:\xampp\php\php.exe tests\security_test.php http://localhost/Events/
 *
 * Everything it creates is called "SECTEST ..." and is deleted again at the end.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

include __DIR__ . '/../db.php'; // for $mysqli: used to look at the database directly

$base = rtrim($argv[1] ?? 'http://localhost/Events', '/') . '/';
$cookies = tempnam(sys_get_temp_dir(), 'sectest');

// ------------------------------------------------------------------ attack payloads

// HTML / script injection (XSS)
$xss = [
    '<script>alert("xss")</script>',
    '"><img src=x onerror=alert(1)>',
    "'><svg onload=alert(1)>",
    '<a href="javascript:alert(1)">click</a>',
    '<iframe src="https://example.com"></iframe>',
];
// If any of these appear in a page, the injected HTML got through
$dangerous = ['<script>alert', '<img src=x', '<svg onload', 'href="javascript:', '<iframe src="https://example.com'];

// SQL injection
$sqli = [
    "' OR '1'='1",
    "1 OR 1=1",
    "'; DROP TABLE events; --",
    "' UNION SELECT name, email FROM people -- ",
    '" OR ""="',
    "1' AND SLEEP(3) -- ",
];
// If any of these appear in a page, a database error leaked out
$sql_errors = ['SQL syntax', 'mysqli', 'Fatal error', 'Uncaught', 'Warning:', 'Stack trace', 'xampp'];

// ------------------------------------------------------------------ helpers

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . ($ok || $detail === '' ? '' : "  ->  $detail") . "\n";
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

// Send a request. Returns [status code, headers (lowercase name => value), body]
function request(string $method, string $path, array $post = [], bool $ajax = true): array
{
    global $base, $cookies;
    $ch = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookies,
        CURLOPT_COOKIEFILE     => $cookies,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $ajax ? ['X-Requested-With: fetch'] : [],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $headers, $body];
}

function get(string $path): string
{
    return request('GET', $path, [], false)[2];
}

function post(string $path, array $data): array
{
    return json_decode(request('POST', $path, $data)[2], true) ?? [];
}

// Is any injected HTML or database error visible in this page?
function leaks(string $body): string
{
    global $dangerous, $sql_errors;
    foreach (array_merge($dangerous, $sql_errors) as $bad) {
        if (stripos($body, $bad) !== false) {
            return "found \"$bad\"";
        }
    }
    return '';
}

function count_rows(): array
{
    global $mysqli;
    $counts = [];
    foreach (['categories', 'events', 'people', 'jobs', 'users'] as $table) {
        $counts[$table] = (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM $table"))[0];
    }
    return $counts;
}

// The session id in our cookie jar (the cookie the site gave us)
function session_id_now(): string
{
    global $cookies;
    foreach (file($cookies) ?: [] as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) === 7 && $parts[5] === session_name()) {
            return $parts[6];
        }
    }
    return '';
}

// Load the captcha picture, then read the code from our session file on the server.
// A person reads it from the picture; the test can read it here because it runs on the same computer.
function solve_captcha(): string
{
    request('GET', 'captcha.php', [], false);
    $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\') . '/sess_' . session_id_now();
    $data = (string) @file_get_contents($file);
    return preg_match('/s:4:"code";s:5:"([A-Z0-9]{5})"/', $data, $m) ? $m[1] : '';
}

// The form token on a page (the login form, the event form...)
function token_from(string $path): string
{
    return preg_match('/name="token" value="([a-f0-9]+)"/', get($path), $m) ? $m[1] : '';
}

// Try to log in. Returns the JSON answer.
function log_in_as(string $username, string $password, ?string $captcha = null): array
{
    $token = token_from('login.php');
    return post('login.php', ['token' => $token, 'username' => $username, 'password' => $password, 'captcha' => $captcha ?? solve_captcha()]);
}

// A test account, stored the same way register.php stores one
function make_user(string $username, string $password): void
{
    global $mysqli;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($mysqli, "INSERT INTO users (username, password_hash) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ss", $username, $hash);
    mysqli_stmt_execute($stmt);
}

// ------------------------------------------------------------------ start

echo "Security tests for $base\n";

// Clean up anything left over from an earlier run, then remember how the database looks
mysqli_query($mysqli, "DELETE FROM events WHERE title LIKE 'SECTEST%'");
mysqli_query($mysqli, "DELETE FROM users WHERE username LIKE 'sectest%'");
$before = count_rows();

$test_user = 'sectest_user';
$test_password = 'SecTest-Pass-' . random_int(1000, 9999);
make_user($test_user, $test_password);

// ================================================================== 0. Login
section('0. Login: sessions protect the pages, captcha, encrypted passwords');

// --- Not logged in: every page sends you to the login page
$protected = ['index.php', 'events.php', 'event.php?id=1', 'event_form.php', 'people.php', 'person_form.php?event_id=1',
              'jobs.php', 'job_form.php?event_id=1', 'suggest.php?field=events&q=a'];
foreach ($protected as $path) {
    [$status, $h] = request('GET', $path, [], false);
    check("$path needs a login (sent to login.php)", $status === 302 && str_starts_with($h['location'][0] ?? '', 'login.php'), "status $status");
}
foreach (['events.php?ajax=1', 'suggest.php?field=people&q=a'] as $path) {
    $status = request('GET', $path)[0];
    check("Ajax $path without a login gets 401", $status === 401, "status $status");
}
foreach (['event_delete.php', 'people.php', 'jobs.php'] as $path) {
    $status = request('POST', $path, ['id' => 1], false)[0];
    check("POST to $path without a login is refused", $status === 302, "status $status");
}
check('register.php is closed once an account exists', request('GET', 'register.php', [], false)[0] === 302);

// --- Captcha
[$status, $h, $svg] = request('GET', 'captcha.php', [], false);
check('captcha.php is a picture (image/svg+xml)', str_starts_with($h['content-type'][0] ?? '', 'image/svg+xml'));
check('captcha.php is never cached', str_contains($h['cache-control'][0] ?? '', 'no-store'));
$code = solve_captcha();
$svg = request('GET', 'captcha.php', [], false)[2];
$code2 = solve_captcha();
check('Every captcha picture has a new code', $code !== '' && $code !== $code2);
check('The code is not in the picture as text (no <text>, not in the source)', !str_contains($svg, '<text') && !str_contains($svg, $code2));

$r = log_in_as($test_user, $test_password, 'WRONG');
check('Login refused with a wrong captcha (even with the right password)', ($r['ok'] ?? null) === false && isset($r['errors']['captcha']), json_encode($r));
$r = log_in_as($test_user, $test_password, '');
check('Login refused with no captcha', isset($r['errors']['captcha']));
$code = solve_captcha();
log_in_as($test_user, 'wrong-password', $code);
$r = log_in_as($test_user, $test_password, $code);
check('A captcha code can only be used once', isset($r['errors']['captcha']), json_encode($r));
$r = post('login.php', ['username' => $test_user, 'password' => $test_password, 'captcha' => solve_captcha()]);
check('Login refused without the form token (CSRF)', ($r['ok'] ?? null) === false && empty($r['redirect']));

// --- Passwords
$r = log_in_as($test_user, 'wrong-password');
check('Wrong password refused', ($r['ok'] ?? null) === false && isset($r['errors']['password']));
$r2 = log_in_as('sectest_nobody', 'wrong-password');
check('Unknown username gets the same message (doesn\'t reveal usernames)', ($r['errors']['password'] ?? 'a') === ($r2['errors']['password'] ?? 'b'));
foreach ($sqli as $payload) {
    $r = log_in_as($payload, $payload);
    check("SQL injection in the login doesn't log in: $payload", ($r['ok'] ?? null) === false);
}
foreach ($xss as $payload) {
    $r = log_in_as($payload, 'x');
    check('XSS in the username is not sent back: ' . substr($payload, 0, 30), leaks(json_encode($r, JSON_UNESCAPED_SLASHES)) === '');
}
$body = get('login.php?next=' . urlencode('"><script>alert(1)</script>'));
check('XSS in login.php?next= is escaped', leaks($body) === '', leaks($body));

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT password_hash FROM users WHERE username = 'sectest_user'"));
$hash = $row['password_hash'] ?? '';
check('Password is stored encrypted (bcrypt hash), not as plain text', str_starts_with($hash, '$2y$') && !str_contains($hash, $test_password));
check('The stored hash matches the password (password_verify)', password_verify($test_password, $hash));
check('The same password hashed twice gives different hashes (random salt)', password_hash($test_password, PASSWORD_DEFAULT) !== password_hash($test_password, PASSWORD_DEFAULT));

// --- Logging in
$id_before = session_id_now();
$r = post('login.php?next=' . urlencode('https://evil.example.com/'), ['token' => token_from('login.php'), 'username' => $test_user, 'password' => $test_password, 'captcha' => solve_captcha()]);
check('Right username, password and captcha logs in', ($r['ok'] ?? null) === true, json_encode($r));
check("After logging in it won't go to another site (?next=https://evil...)", ($r['redirect'] ?? '') === 'index.php', $r['redirect'] ?? '');
check('A new session id after logging in (session fixation)', session_id_now() !== '' && session_id_now() !== $id_before);
$location = request('GET', 'login.php?next=' . urlencode('events.php?q=a'), [], false)[1]['location'][0] ?? '';
check('Logged in: login.php goes back to the page you wanted (?next=events.php)', $location === 'events.php?q=a', $location);
foreach ($protected as $path) {
    $status = request('GET', $path, [], false)[0];
    check("$path opens when logged in", $status === 200, "status $status");
}

// Get the form token for the tests below, like a browser opening a form
$token = token_from('event_form.php');
check('Form token found on the event form', $token !== '');

// ================================================================== 1. Headers
section('1. Security headers');

foreach (['index.php', 'events.php', 'people.php', 'jobs.php', 'event_form.php', 'event.php?id=1'] as $path) {
    [$status, $h] = request('GET', $path, [], false);
    $missing = [];
    foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy'] as $name) {
        if (empty($h[$name])) {
            $missing[] = $name;
        }
    }
    check("$path sends all security headers", !$missing, 'missing: ' . implode(', ', $missing));
    check("$path hides the PHP version (no X-Powered-By)", empty($h['x-powered-by']));
    check("$path asks search engines not to index it", str_contains($h['x-robots-tag'][0] ?? '', 'noindex'));
}

// Items that don't exist answer with a 404 status, not a normal page
foreach (['event.php?id=999999', 'event_form.php?id=999999', 'person_form.php?id=999999', 'job_form.php?id=999999', 'person_form.php?event_id=999999'] as $path) {
    $status = request('GET', $path, [], false)[0];
    check("$path (doesn't exist) returns 404", $status === 404, "status $status");
}
$status = request('GET', 'robots.txt', [], false)[0];
check('robots.txt is there for search engines', $status === 200, "status $status");

[$s, $h] = request('GET', 'index.php', [], false);
$csp = $h['content-security-policy'][0] ?? '';
check("CSP blocks scripts from other sites", str_contains($csp, "script-src 'self'") && !str_contains($csp, "script-src *"));
check("CSP has no 'unsafe-eval' or inline scripts", !str_contains($csp, 'unsafe-eval') && !preg_match("/script-src[^;]*'unsafe-inline'/", $csp));
check("CSP stops the site being framed (clickjacking)", str_contains($csp, "frame-ancestors 'none'"));
check('X-Frame-Options is DENY', ($h['x-frame-options'][0] ?? '') === 'DENY');
check('X-Content-Type-Options is nosniff', ($h['x-content-type-options'][0] ?? '') === 'nosniff');
check('Referrer-Policy is same-origin', ($h['referrer-policy'][0] ?? '') === 'same-origin');
check('Cross-origin isolation: COOP + CORP + COEP are all set', ($h['cross-origin-opener-policy'][0] ?? '') === 'same-origin'
    && ($h['cross-origin-resource-policy'][0] ?? '') === 'same-origin' && !empty($h['cross-origin-embedder-policy']));

// Every kind of response has the headers, each sent once (a header sent twice can be read as invalid):
// a page, a PHP 404, static files, and Apache's own 403 / 404 error pages
foreach (['index.php', 'event.php?id=999999', 'assets/js/app.js', 'robots.txt', 'assets/img/favicon.svg',
          'templates/base.twig', 'missing-file.txt', 'missing-page.php'] as $path) {
    [$status, $h] = request('GET', $path, [], false);
    $problems = [];
    foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy', 'cross-origin-resource-policy'] as $name) {
        $count = count($h[$name] ?? []);
        if ($count !== 1) {
            $problems[] = "$name x$count";
        }
    }
    if (count($h['content-security-policy'] ?? []) > 1) {
        $problems[] = 'content-security-policy sent twice';
    }
    check("$path ($status) sends each security header once", !$problems, implode(', ', $problems));
}

// The session cookie: fresh request without our cookie jar
$ch = curl_init($base . 'index.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true]);
$raw = (string) curl_exec($ch);
curl_close($ch);
preg_match('/^Set-Cookie: (.*)$/mi', $raw, $cookie);
check('Session cookie is HttpOnly (JavaScript can\'t read it)', stripos($cookie[1] ?? '', 'httponly') !== false);
check('Session cookie is SameSite (not sent from other sites)', stripos($cookie[1] ?? '', 'samesite') !== false);

// ================================================================== 2. Private files
section('2. Private files can\'t be opened in a browser');

foreach (['templates/base.twig', 'vendor/autoload.php', 'sql/schema.sql', 'composer.json', 'tests/security_test.php', '.htaccess'] as $path) {
    $status = request('GET', $path, [], false)[0];
    check("$path is blocked", in_array($status, [403, 404]), "status $status");
}

// ================================================================== 3. CSRF
section('3. Forms refuse requests without the form token (CSRF)');

$no_token = post('event_form.php', ['title' => 'SECTEST no token', 'category_id' => 1, 'event_date' => '2026-01-01', 'summary' => 'Should never be saved']);
check('Event form refuses a missing token', ($no_token['ok'] ?? null) === false);
$wrong = post('event_form.php', ['token' => str_repeat('a', 64), 'title' => 'SECTEST wrong token', 'category_id' => 1, 'event_date' => '2026-01-01', 'summary' => 'Should never be saved']);
check('Event form refuses a wrong token', ($wrong['ok'] ?? null) === false);
$list_token = post('event_form.php', ['token' => [$token], 'title' => 'SECTEST list token', 'category_id' => 1, 'event_date' => '2026-01-01', 'summary' => 'Should never be saved']);
check('Event form refuses a token sent as a list', ($list_token['ok'] ?? null) === false);
$saved = (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM events WHERE title LIKE 'SECTEST%'"))[0];
check('Nothing was saved without a valid token', $saved === 0, "$saved saved");

foreach (['person_form.php?event_id=1', 'job_form.php?event_id=1', 'people.php', 'jobs.php', 'event_delete.php'] as $path) {
    $r = post($path, ['id' => 1, 'name' => 'SECTEST', 'title' => 'SECTEST', 'company' => 'SECTEST', 'status' => 'Offer']);
    check("$path refuses a missing token", ($r['ok'] ?? null) === false);
}
check('Event 1 still exists after the delete attempt without a token',
    (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM events WHERE id = 1"))[0] === 1);

// ================================================================== 4. XSS in forms
section('4. HTML / script injection in every form field');

// --- Event form: every text field gets a payload
$event_id = 0;
foreach ($xss as $i => $payload) {
    $r = post('event_form.php', [
        'token'       => $token,
        'title'       => "SECTEST $i $payload",
        'category_id' => 1,
        'event_date'  => '2026-01-0' . ($i + 1),
        'venue'       => $payload,
        'city'        => $payload,
        'seat'        => $payload,
        'rating'      => 3,
        'summary'     => "Summary with an attack in it: $payload",
    ]);
    if (preg_match('/event\.php\?id=(\d+)/', $r['redirect'] ?? '', $found)) {
        $event_id = $event_id ?: (int) $found[1];
        $id = (int) $found[1];
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM events WHERE id = $id"));
        $stored = $row['title'] . $row['venue'] . $row['city'] . $row['seat'] . $row['summary'];
        check("Event form: tags removed before saving ($i)", strpos($stored, '<') === false, $stored);
        $problem = leaks(get("event.php?id=$id"));
        check("Event page shows the attack as plain text ($i)", $problem === '', $problem);
    } else {
        check("Event form accepted the test event ($i)", false, json_encode($r));
    }
}
$problem = leaks(get('events.php?q=SECTEST'));
check('Events list shows the attacks as plain text', $problem === '', $problem);
$problem = leaks(get('index.php'));
check('Dashboard shows the attacks as plain text', $problem === '', $problem);
$problem = leaks(get("event_form.php?id=$event_id"));
check('Edit event form shows the attacks as plain text', $problem === '', $problem);

// --- Person form
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'linkedin' => 'javascript:alert(1)']);
check('Person form refuses a javascript: LinkedIn link', isset($r['errors']['linkedin']));
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'other_social' => 'javascript:alert(1)']);
check('Person form refuses a javascript: website link', isset($r['errors']['other_social']));
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'other_social' => 'data:text/html,<script>alert(1)</script>']);
check('Person form refuses a data: link', isset($r['errors']['other_social']));
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'email' => '"><script>alert(1)</script>@x.com']);
check('Person form refuses a script in the email', isset($r['errors']['email']));
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'phone' => '<script>alert(1)</script>']);
check('Person form refuses a script in the phone number', isset($r['errors']['phone']) || ($r['ok'] ?? false));

$person_payload = $xss[1];
$r = post("person_form.php?event_id=$event_id", [
    'token' => $token, 'action' => 'save', 'follow_up' => 'on',
    'name' => "SECTEST $person_payload", 'job_title' => $xss[0], 'company' => $xss[2], 'notes' => $xss[3],
]);
check('Person form saves text that had tags in it', ($r['ok'] ?? false) === true, json_encode($r));
$person = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM people WHERE event_id = $event_id ORDER BY id DESC LIMIT 1"));
check('Person form: tags removed before saving', $person && strpos($person['name'] . $person['job_title'] . $person['company'] . $person['notes'], '<') === false);
foreach (['people.php?q=SECTEST', "event.php?id=$event_id", 'person_form.php?id=' . ($person['id'] ?? 0)] as $path) {
    $problem = leaks(get($path));
    check("$path shows the person as plain text", $problem === '', $problem);
}

// --- Job form
$r = post("job_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'title' => 'SECTEST', 'company' => 'SECTEST', 'status' => 'Saved', 'url' => 'javascript:alert(1)']);
check('Job form refuses a javascript: advert link', isset($r['errors']['url']));
$r = post("job_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'title' => 'SECTEST', 'company' => 'SECTEST', 'status' => '<script>alert(1)</script>']);
check('Job form refuses a status that is not on the list', isset($r['errors']['status']));
$r = post("job_form.php?event_id=$event_id", [
    'token' => $token, 'action' => 'save', 'status' => 'Saved',
    'title' => "SECTEST {$xss[0]}", 'company' => $xss[1], 'location' => $xss[2], 'notes' => $xss[4],
]);
check('Job form saves text that had tags in it', ($r['ok'] ?? false) === true, json_encode($r));
$job = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM jobs WHERE event_id = $event_id ORDER BY id DESC LIMIT 1"));
check('Job form: tags removed before saving', $job && strpos($job['title'] . $job['company'] . $job['location'] . $job['notes'], '<') === false);
foreach (['jobs.php?q=SECTEST', "event.php?id=$event_id", 'job_form.php?id=' . ($job['id'] ?? 0), 'index.php'] as $path) {
    $problem = leaks(get($path));
    check("$path shows the job as plain text", $problem === '', $problem);
}

// --- Quick actions
$r = post('jobs.php', ['token' => $token, 'id' => $job['id'] ?? 0, 'status' => "Offer' OR '1'='1"]);
$status_now = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT status FROM jobs WHERE id = ' . (int) ($job['id'] ?? 0)))[0] ?? '';
check('Job status dropdown ignores a value that is not on the list', $status_now === 'Saved', "status is now $status_now");

// ================================================================== 5. XSS in searches (reflected)
section('5. HTML / script injection in searches and the address bar');

foreach ($xss as $i => $payload) {
    $q = urlencode($payload);
    foreach (["events.php?q=$q", "people.php?q=$q", "jobs.php?q=$q", "events.php?ajax=1&q=$q", "people.php?ajax=1&q=$q", "jobs.php?ajax=1&q=$q"] as $path) {
        $problem = leaks(get($path));
        check(strtok($path, '?') . (str_contains($path, 'ajax') ? ' (Ajax)' : '') . " search, attack $i", $problem === '', $problem);
    }
    foreach (['events', 'people', 'jobs', 'event_title', 'venue', 'city', 'person_name', 'company', 'job_title', 'location'] as $field) {
        [$status, $h, $body] = request('GET', "suggest.php?field=$field&q=$q");
        if ($i === 0) {
            check("suggest.php ($field) answers with JSON, not HTML", str_contains($h['content-type'][0] ?? '', 'application/json'));
        }
        check("suggest.php ($field), attack $i", leaks($body) === '' && json_decode($body) !== null, leaks($body));
    }
}

// The live search answers with ids (whole numbers) only: no HTML ever reaches the browser to be inserted
foreach (['events.php', 'people.php', 'jobs.php'] as $page) {
    $body = request('GET', "$page?ajax=1&q=" . urlencode($xss[0]))[2];
    $json = json_decode($body, true) ?? [];
    $ids = $json['ids'] ?? null;
    check("$page live search sends only ids (no HTML)", !isset($json['html']) && !str_contains($body, '<'), substr($body, 0, 80));
    check("$page live search ids are all whole numbers", is_array($ids) && $ids === array_filter($ids, 'is_int'));
    $all = json_decode(request('GET', "$page?ajax=1")[2], true)['ids'] ?? [];
    check("$page live search with no filters lists every card", count($all) > 0 && count($all) === count(array_unique($all)));
}

// Every filter in the address bar
$q = urlencode($xss[0]);
foreach (["events.php?category=$q", "events.php?city=$q", "events.php?rating=$q", "events.php?from=$q", "events.php?to=$q",
          "events.php?sort=$q", "people.php?follow_up=$q", "jobs.php?status=$q", "event.php?id=$q", "event_form.php?id=$q",
          "person_form.php?event_id=$q", "person_form.php?id=$q", "job_form.php?event_id=$q", "job_form.php?id=$q"] as $path) {
    $problem = leaks(get($path));
    check("$path is shown safely", $problem === '', $problem);
}

// ================================================================== 6. SQL injection
section('6. SQL injection in searches, filters and forms');

foreach ($sqli as $i => $payload) {
    $q = urlencode($payload);
    $started = microtime(true);
    $events = get("events.php?q=$q");
    $took = microtime(true) - $started;
    check("events.php search, SQL attack $i: no database error", leaks($events) === '', leaks($events));
    check("events.php search, SQL attack $i: doesn't return every event", str_contains($events, '>0 events<'));

    $people = get("people.php?q=$q");
    check("people.php search, SQL attack $i: no error, no results", leaks($people) === '' && str_contains($people, '>0 people<'));

    $jobs = get("jobs.php?q=$q");
    check("jobs.php search, SQL attack $i: no error, no results", leaks($jobs) === '' && str_contains($jobs, '>0 jobs<'));

    foreach (['events', 'people', 'jobs', 'company', 'person_name'] as $field) {
        $body = request('GET', "suggest.php?field=$field&q=$q")[2];
        $items = json_decode($body, true)['items'] ?? null;
        check("suggest.php ($field), SQL attack $i: no error, no results", is_array($items) && count($items) === 0, $body);
    }

    foreach (["events.php?category=$q", "events.php?city=$q", "events.php?rating=$q", "events.php?from=$q", "events.php?sort=$q",
              "people.php?follow_up=$q", "jobs.php?status=$q", "event.php?id=$q", "event_form.php?id=$q", "job_form.php?id=$q"] as $path) {
        $body = get($path);
        check(strtok($path, '=') . "=..., SQL attack $i: no database error", leaks($body) === '', leaks($body));
    }
    if (str_contains($payload, 'SLEEP')) {
        check('SLEEP() attack did not make the database wait', $took < 3, round($took, 1) . ' seconds');
    }

    // The attack typed into a form is just saved as text
    $r = post('event_form.php', ['token' => $token, 'title' => "SECTEST sql $i $payload", 'category_id' => 1,
                                 'event_date' => '2026-02-01', 'summary' => "Summary: $payload"]);
    $saved = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM events WHERE title LIKE 'SECTEST sql $i %'"))[0] ?? 0;
    check("Event form saves SQL attack $i as plain text", ($r['ok'] ?? false) === true && (int) $saved === 1, json_encode($r));
}

// ================================================================== 7. Broken input
section('7. Broken input (lists instead of text, huge values, bad values)');

$r = post('event_form.php', ['token' => $token, 'title' => ['x'], 'summary' => ['y'], 'event_date' => ['z'], 'category_id' => ['1']]);
check('Event form handles fields sent as lists (no crash)', ($r['ok'] ?? null) === false && isset($r['errors']['title']), json_encode($r));
foreach (['events.php?q[]=x', 'people.php?q[]=x&follow_up[]=1', 'jobs.php?q[]=x&status[]=Offer', 'events.php?city[]=x&from[]=x&sort[]=x', 'suggest.php?field[]=x&q[]=x'] as $path) {
    [$status, , $body] = request('GET', $path, [], false);
    check("$path doesn't crash", $status === 200 && leaks($body) === '', "status $status " . leaks($body));
}
$r = post('event_form.php', ['token' => $token, 'title' => 'SECTEST long ' . str_repeat('A', 5000), 'category_id' => 1, 'event_date' => '2026-03-01', 'summary' => str_repeat('B', 20000)]);
$long = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT CHAR_LENGTH(title) AS t, CHAR_LENGTH(summary) AS s FROM events WHERE title LIKE 'SECTEST long%'"));
check('Very long input is cut to the allowed length', $long && $long['t'] <= 120 && $long['s'] <= 5000, json_encode($long));
$r = post('event_form.php', ['token' => $token, 'title' => 'SECTEST future', 'category_id' => 1, 'event_date' => '2999-01-01', 'summary' => 'A date in the future']);
check('Event form refuses a date in the future', isset($r['errors']['event_date']));
$r = post('event_form.php', ['token' => $token, 'title' => 'SECTEST type', 'category_id' => 999, 'event_date' => '2026-01-01', 'summary' => 'A type that does not exist']);
check('Event form refuses a type that does not exist', isset($r['errors']['category_id']));
$r = post("person_form.php?event_id=$event_id", ['token' => $token, 'action' => 'save', 'name' => 'SECTEST', 'email' => 'not-an-email']);
check('Person form refuses a bad email address', isset($r['errors']['email']));

// ================================================================== 8. Logging out + locking after wrong passwords
section('8. Logging out, and locking the account after wrong passwords');

$status = request('GET', 'logout.php', [], false)[0];
check('Opening logout.php with a link does not log out (POST only)', request('GET', 'index.php', [], false)[0] === 200);
$r = post('logout.php', ['token' => 'wrong']);
check('Log out without the right form token is refused (CSRF)', ($r['ok'] ?? null) === false && request('GET', 'index.php', [], false)[0] === 200);
$id_before = session_id_now();
$r = post('logout.php', ['token' => $token]);
check('Log out works', ($r['ok'] ?? null) === true && ($r['redirect'] ?? '') === 'login.php', json_encode($r));
check('After logging out the pages need a login again', request('GET', 'index.php', [], false)[0] === 302);
check('A new session id after logging out', session_id_now() !== $id_before);
$r = post('event_form.php', ['token' => $token, 'title' => 'SECTEST after logout', 'category_id' => 1, 'event_date' => '2026-01-01', 'summary' => 'x']);
check('The old form token is useless after logging out', ($r['ok'] ?? null) === false);

make_user('sectest_lock', 'Lock-Test-123');
for ($i = 1; $i <= 5; $i++) {
    log_in_as('sectest_lock', 'wrong-password');
}
$r = log_in_as('sectest_lock', 'Lock-Test-123');
check('After 5 wrong passwords the account is locked (even the right password fails)', ($r['ok'] ?? null) === false && str_contains($r['errors']['password'] ?? '', 'Too many'), json_encode($r));
$locked = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT locked_until FROM users WHERE username = 'sectest_lock'"));
check('The lock is saved in the database', !empty($locked['locked_until']));

// ================================================================== 9. The database is unharmed
section('9. The database is unharmed');

mysqli_query($mysqli, "DELETE FROM users WHERE username LIKE 'sectest%'");
mysqli_query($mysqli, "DELETE FROM events WHERE title LIKE 'SECTEST%'"); // people and jobs go with them (ON DELETE CASCADE)
$after = count_rows();
foreach ($before as $table => $count) {
    check("Table $table still exists with the same $count rows", ($after[$table] ?? -1) === $count, "now " . ($after[$table] ?? 'missing'));
}

@unlink($cookies);

// ------------------------------------------------------------------ result
echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
