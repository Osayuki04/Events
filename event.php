<?php
// One event: summary, people met, jobs saved. Logic only - the HTML is in templates/event.twig
include("db.php");
require_login(); // only for logged-in users: otherwise sent to login.php

$id = (int) ($_GET['id'] ?? 0);

// The event
$stmt = mysqli_prepare($mysqli, "SELECT e.*, c.name AS category
                                 FROM events e JOIN categories c ON c.id = e.category_id
                                 WHERE e.id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$people = [];
$jobs = [];
if ($event) {
    // People met at this event (people to follow up first)
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM people WHERE event_id = ? ORDER BY follow_up DESC, name");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $people = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    // Jobs saved from this event
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM jobs WHERE event_id = ? ORDER BY created_at DESC");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $jobs = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

// Event doesn't exist (wrong id or deleted): tell the browser with a 404 status
if (!$event) {
    http_response_code(404);
}

render('event.twig', ['page' => 'events', 'id' => $id, 'event' => $event, 'people' => $people, 'jobs' => $jobs]);
