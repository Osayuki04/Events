<?php
// Dashboard. Logic only - the HTML is in templates/index.twig
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

// ---- Numbers for the four stat cards (only this user's events, and the people and jobs from them) ----
$sql = "SELECT
            (SELECT COUNT(*) FROM events WHERE user_id = ?) AS events,
            (SELECT COUNT(*) FROM events WHERE user_id = ? AND event_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS events_month,
            (SELECT ROUND(AVG(rating), 1) FROM events WHERE user_id = ? AND rating IS NOT NULL) AS avg_rating,
            (SELECT COUNT(*) FROM events WHERE user_id = ? AND rating IS NOT NULL) AS rated,
            (SELECT COUNT(*) FROM people p JOIN events e ON e.id = p.event_id WHERE e.user_id = ?) AS people,
            (SELECT COUNT(*) FROM people p JOIN events e ON e.id = p.event_id WHERE e.user_id = ? AND p.follow_up = 1) AS follow_ups,
            (SELECT COUNT(*) FROM jobs j JOIN events e ON e.id = j.event_id WHERE e.user_id = ?) AS jobs,
            (SELECT COUNT(*) FROM jobs j JOIN events e ON e.id = j.event_id WHERE e.user_id = ? AND j.status IN ('Applied', 'Interview', 'Offer')) AS in_progress";
$stats = fetch_row($sql, array_fill(0, 8, $user_id));

// ---- The three most recent events (the first one is also the "Latest event" card) ----
$recent = fetch_rows("SELECT e.*, c.name AS category,
                             (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id) AS people_count,
                             (SELECT COUNT(*) FROM jobs j WHERE j.event_id = e.id) AS job_count
                      FROM events e
                      JOIN categories c ON c.id = e.category_id
                      WHERE e.user_id = ?
                      ORDER BY e.event_date DESC, e.id DESC
                      LIMIT 3", [$user_id]);

// ---- The three people you met most recently ----
$people = fetch_rows("SELECT p.*, e.title AS event_title
                      FROM people p JOIN events e ON e.id = p.event_id
                      WHERE e.user_id = ?
                      ORDER BY e.event_date DESC, p.id DESC
                      LIMIT 3", [$user_id]);

// ---- The three most recently saved jobs ----
$jobs = fetch_rows("SELECT j.*, e.title AS event_title
                    FROM jobs j JOIN events e ON e.id = j.event_id
                    WHERE e.user_id = ?
                    ORDER BY j.created_at DESC, j.id DESC
                    LIMIT 3", [$user_id]);

// ---- How many jobs are at each status ----
$statuses = ['Saved' => 0, 'Applied' => 0, 'Interview' => 0, 'Offer' => 0, 'Rejected' => 0];
foreach (fetch_rows("SELECT j.status, COUNT(*) AS total FROM jobs j JOIN events e ON e.id = j.event_id
                     WHERE e.user_id = ? GROUP BY j.status", [$user_id]) as $a_row) {
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
