<?php
// Log in. Logic only - the HTML is in templates/login.twig
//
// Security:
//   - captcha must be right before the password is even checked
//   - password_verify() compares the password with the stored hash (the password itself is never stored)
//   - 5 wrong passwords lock the account for 5 minutes (stops guessing)
//   - the same message for a wrong username and a wrong password (doesn't reveal which usernames exist)
//   - a new session id after logging in (session fixation)
include("db.php");

const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES = 5;

// A brand new site has no accounts yet: create the first one
if (count_users($mysqli) === 0) {
    header("Location: register.php");
    exit;
}

$next = safe_next(clean_text($_GET['next'] ?? '', 300));

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header("Location: $next");
    exit;
}

$username = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();

    $username = clean_text($_POST['username'] ?? '', 40);
    // The password is used exactly as typed (not cleaned), only checked to be text of a sensible length
    $password = $_POST['password'] ?? '';
    $password = is_string($password) && strlen($password) <= 200 ? $password : '';

    if ($username === '') {
        $errors['username'] = 'Enter your username.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }
    if (!check_captcha($_POST['captcha'] ?? '')) {
        $errors['captcha'] = "That didn't match the picture. Type the characters in the new one.";
    }

    if (!$errors) {
        $stmt = mysqli_prepare($mysqli, "SELECT * FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            $errors['password'] = "Too many wrong passwords. Try again in $minutes minute" . ($minutes == 1 ? '' : 's') . '.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            // Right password: reset the counter, and upgrade the hash if PHP now has a stronger one
            $hash = password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : $user['password_hash'];
            $stmt = mysqli_prepare($mysqli, "UPDATE users SET failed_logins = 0, locked_until = NULL, password_hash = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hash, $user['id']);
            mysqli_stmt_execute($stmt);

            log_in($user);
            done($next, greeting() . ', ' . $user['username'] . '.');
        } else {
            if ($user) {
                // Count the wrong password; after too many, lock the account for a few minutes
                $failed = $user['failed_logins'] + 1;
                $locked_until = $failed >= MAX_FAILED_LOGINS ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
                if ($locked_until) {
                    $failed = 0; // start counting again once the lock is over
                }
                $stmt = mysqli_prepare($mysqli, "UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "isi", $failed, $locked_until, $user['id']);
                mysqli_stmt_execute($stmt);
            } else {
                // No such user: still spend the time of a real password check, so the response time
                // doesn't reveal which usernames exist
                password_hash($password, PASSWORD_DEFAULT);
            }
            $errors['password'] = 'Wrong username or password.';
        }
    }

    send_errors($errors); // Ajax: errors as JSON. Otherwise the form is shown again below.
}

render('login.twig', [
    'page'     => 'login',
    'next'     => $next,
    'username' => $username,
    'errors'   => $errors,
]);
