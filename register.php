<?php
// Create an account. Logic only - the HTML is in templates/register.twig
//
// Anyone can sign up. Each account has its own diary: every event is saved with the user's id and
// every page only shows that user's events, people and jobs, so nobody can see anyone else's records.
//
// The password is never stored: password_hash() turns it into a one-way hash (bcrypt, with a random salt).
// Logging in checks the typed password against that hash with password_verify().
include("db.php");

// Already logged in: nothing to sign up for
if (!empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$username = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();

    $username = clean_text($_POST['username'] ?? '', 40);
    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';
    $confirm = $_POST['confirm'] ?? '';
    $confirm = is_string($confirm) ? $confirm : '';

    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
        $errors['username'] = 'Use 3 to 40 letters, numbers, dots, dashes or underscores.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Use 72 characters or fewer.'; // bcrypt only uses the first 72
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Use at least one letter and one number.';
    } elseif (strcasecmp($password, $username) === 0) {
        $errors['password'] = "Your password can't be your username.";
    }
    if (!isset($errors['password']) && $confirm !== $password) {
        $errors['confirm'] = "The two passwords don't match.";
    }
    if (!check_captcha($_POST['captcha'] ?? '')) {
        $errors['captcha'] = "That didn't match the picture. Type the characters in the new one.";
    }

    // Is the username free? Only checked once the captcha is right, so a program can't use this
    // form to find out which usernames exist. (The column is also UNIQUE in the database.)
    if (!$errors && fetch_row("SELECT id FROM users WHERE username = ?", [$username])) {
        $errors['username'] = 'That username is taken. Try another one.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            run_query("INSERT INTO users (username, password_hash) VALUES (?, ?)", [$username, $hash]);
        } catch (mysqli_sql_exception $e) {
            // Someone took the same username at the same moment (UNIQUE key)
            send_errors(['username' => 'That username is taken. Try another one.']);
            render('register.twig', ['page' => 'register', 'username' => $username,
                                     'errors' => ['username' => 'That username is taken. Try another one.']]);
            exit;
        }

        log_in(['id' => mysqli_insert_id($mysqli), 'username' => $username]);
        done('index.php', 'Your account is ready. Welcome to Yukylo, ' . $username . '. Start by adding an event you went to.');
    }

    send_errors($errors);
}

render('register.twig', [
    'page'     => 'register',
    'username' => $username,
    'errors'   => $errors,
]);
