<?php
// Delete an event (sent with Ajax from the event page, after the "Are you sure?" box). No HTML.
include("db.php");
require_login(); // only for logged-in users: otherwise sent to login.php

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: events.php");
    exit;
}
check_token();

$id = (int) ($_POST['id'] ?? 0);

// Deleting the event also deletes its people and jobs (ON DELETE CASCADE in the database)
$stmt = mysqli_prepare($mysqli, "DELETE FROM events WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);

done("events.php", mysqli_stmt_affected_rows($stmt) > 0 ? "The event was deleted." : "That event no longer exists.");
