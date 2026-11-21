<?php
// Dashboard. Logic only - the HTML is in templates/index.twig
include("db.php");

// ---- Numbers for the four stat cards ----
$sql = "SELECT
            (SELECT COUNT(*) FROM events) AS events,
            (SELECT COUNT(*) FROM events WHERE event_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS events_month,
            (SELECT ROUND(AVG(rating), 1) FROM events WHERE rating IS NOT NULL) AS avg_rating,
            (SELECT COUNT(*) FROM events WHERE rating IS NOT NULL) AS rated,
            (SELECT COUNT(*) FROM people) AS people,
            (SELECT COUNT(*) FROM people WHERE follow_up = 1) AS follow_ups,
            (SELECT COUNT(*) FROM jobs) AS jobs,
            (SELECT COUNT(*) FROM jobs WHERE status IN ('Applied', 'Interview', 'Offer')) AS in_progress";
$stats = mysqli_fetch_assoc(mysqli_query($mysqli, $sql));

// ---- The three most recent events (the first one is also the "Latest event" card) ----
$sql = "SELECT e.*, c.name AS category,
               (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id) AS people_count,
               (SELECT COUNT(*) FROM jobs j WHERE j.event_id = e.id) AS job_count
        FROM events e
        JOIN categories c ON c.id = e.category_id
        ORDER BY e.event_date DESC, e.id DESC
        LIMIT 3";
$recent = mysqli_fetch_all(mysqli_query($mysqli, $sql), MYSQLI_ASSOC);

// ---- The three people you met most recently ----
$sql = "SELECT p.*, e.title AS event_title
        FROM people p JOIN events e ON e.id = p.event_id
        ORDER BY e.event_date DESC, p.id DESC
        LIMIT 3";
$people = mysqli_fetch_all(mysqli_query($mysqli, $sql), MYSQLI_ASSOC);

// ---- The three most recently saved jobs ----
$sql = "SELECT j.*, e.title AS event_title
        FROM jobs j JOIN events e ON e.id = j.event_id
        ORDER BY j.created_at DESC, j.id DESC
        LIMIT 3";
$jobs = mysqli_fetch_all(mysqli_query($mysqli, $sql), MYSQLI_ASSOC);

// ---- How many jobs are at each status ----
$statuses = ['Saved' => 0, 'Applied' => 0, 'Interview' => 0, 'Offer' => 0, 'Rejected' => 0];
$results = mysqli_query($mysqli, "SELECT status, COUNT(*) AS total FROM jobs GROUP BY status");
while ($a_row = mysqli_fetch_assoc($results)) {
    $statuses[$a_row['status']] = (int) $a_row['total'];
}

render('index.twig', [
    'page'     => 'index',
    'stats'    => $stats,
    'recent'   => $recent,
    'latest'   => $recent[0] ?? null,
    'people'   => $people,
    'jobs'     => $jobs,
    'statuses' => $statuses,
]);
