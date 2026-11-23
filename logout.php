<?php
// Log out (the button in the top bar). Only a POST with the form token works,
// so another site can't log you out with a link or an image.
include("db.php");

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: index.php");
    exit;
}
check_token();

log_out();
done('login.php', 'You have logged out.');
