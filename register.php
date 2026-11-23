<?php
// Create your account. Logic only - the HTML is in templates/register.twig
//
// Only works while there are no accounts yet (the first time the site is opened).
// After that this page sends you to the login, so nobody else can make an account and read your diary.
//
// The password is never stored: password_hash() turns it into a one-way hash (bcrypt, with a random salt).
// Logging in checks the typed password against that hash with password_verify().
include("db.php");

if (count_users($mysqli) > 0) {
    header("Location: login.php");
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
        $errors['captcha'] = "The letters didn't match the picture. Try the new one.";
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($mysqli, "INSERT INTO users (username, password_hash) VALUES (?, ?)");
        mysqli_stmt_bind_param($stmt, "ss", $username, $hash);
        mysqli_stmt_execute($stmt);

        log_in(['id' => mysqli_insert_id($mysqli), 'username' => $username]);
        done('index.php', 'Your account is ready. Welcome to Yukylo, ' . $username . '.');
    }

    send_errors($errors);
}

render('register.twig', [
    'page'     => 'register',
    'username' => $username,
    'errors'   => $errors,
]);
