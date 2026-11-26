<?php
// One person you met (person.php?id=7): their details, the event you met them at, the other people
// you met there and the jobs saved from it. Logic only - the HTML is in templates/person.twig
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

$id = (int) ($_GET['id'] ?? 0);

// The person - only if they were met at one of this user's own events. Changing the id in the
// address to someone else's person finds nothing, so it shows "Person not found" (404).
$person = fetch_row("SELECT p.*, e.title AS event_title, e.event_date, e.venue, e.city
                     FROM people p JOIN events e ON e.id = p.event_id
                     WHERE p.id = ? AND e.user_id = ?", [$id, $user_id]);

$others = [];
$jobs = [];
if ($person) {
    // Everyone else met at the same event
    $others = fetch_rows("SELECT id, name, job_title, company, follow_up FROM people
                          WHERE event_id = ? AND id != ? ORDER BY follow_up DESC, name", [$person['event_id'], $id]);

    // Jobs saved from the same event
    $jobs = fetch_rows("SELECT id, title, company, status FROM jobs
                        WHERE event_id = ? ORDER BY created_at DESC", [$person['event_id']]);
} else {
    http_response_code(404);
}

render('person.twig', ['page' => 'people', 'id' => $id, 'person' => $person, 'others' => $others, 'jobs' => $jobs]);
