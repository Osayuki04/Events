<?php
// Delete an event (sent with Ajax from the event page, after the "Are you sure?" box). No HTML.
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: events.php");
    exit;
}
check_token();

$id = (int) ($_POST['id'] ?? 0);

// Deleting the event also deletes its people and jobs (ON DELETE CASCADE in the database).
// "AND user_id = ?": a user can only delete their own events; someone else's id deletes nothing.
$deleted = run_query("DELETE FROM events WHERE id = ? AND user_id = ?", [$id, $user_id]);

$message = $deleted > 0 ? "The event was deleted." : "That event no longer exists.";

// From the bin button on an events card: stay on the list
if (($_POST['from'] ?? '') === 'list') {
    deleted_from_list("events.php", $message);
}
done("events.php", $message);
