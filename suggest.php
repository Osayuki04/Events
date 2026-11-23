<?php
// Ajax: suggestions while typing (autocomplete + form filling assistance). Used by assets/js/app.js
//
//   suggest.php?field=events&q=sum   ->  {"title": "...", "items": [{"value": "...", "detail": "...", "fill": {...}, "url": "..."}]}
//
//   value  = text put into the box when a suggestion is picked
//   detail = small grey text under it
//   fill   = other form fields to fill in at the same time (e.g. picking a past event fills its venue and city)
//   url    = open this page instead (e.g. "this person is already in your diary")
include("db.php");
require_login(); // only for logged-in users: otherwise sent to login.php

$field = clean_text($_GET['field'] ?? '', 30);
$q = clean_text($_GET['q'] ?? '', 80);

if ($q === '') {
    send_json(['items' => []]);
}

$like = "%" . $q . "%";
$starts = $q . "%";

// Run a prepared statement with the search text and return all rows
function rows(mysqli $mysqli, string $sql, array $params): array
{
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}

// A list of distinct values from one column, the ones that start with the text first
function distinct_values(mysqli $mysqli, string $sql, string $like, string $starts): array
{
    $items = [];
    foreach (rows($mysqli, $sql, [$like, $starts]) as $row) {
        $items[] = ['value' => $row['value'], 'detail' => 'Used ' . $row['total'] . ($row['total'] == 1 ? ' time' : ' times')];
    }
    return $items;
}

$title = '';
$items = [];

switch ($field) {

    // ---- Search boxes ----
    case 'events':
        foreach (rows($mysqli, "SELECT e.title, e.event_date, c.name AS category FROM events e JOIN categories c ON c.id = e.category_id
                                WHERE e.title LIKE ? OR e.venue LIKE ? OR e.city LIKE ?
                                ORDER BY e.title LIKE ? DESC, e.event_date DESC LIMIT 6", [$like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['title'], 'detail' => $row['category'] . ' · ' . date('j M Y', strtotime($row['event_date']))];
        }
        break;

    case 'people':
        foreach (rows($mysqli, "SELECT name, job_title, company FROM people
                                WHERE name LIKE ? OR company LIKE ? OR job_title LIKE ?
                                ORDER BY name LIKE ? DESC, name LIMIT 6", [$like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['name'], 'detail' => implode(' · ', array_filter([$row['job_title'], $row['company']]))];
        }
        break;

    case 'jobs':
        foreach (rows($mysqli, "SELECT title, company, status FROM jobs
                                WHERE title LIKE ? OR company LIKE ? OR location LIKE ?
                                ORDER BY title LIKE ? DESC, title LIMIT 6", [$like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['title'], 'detail' => $row['company'] . ' · ' . $row['status']];
        }
        break;

    // ---- Form filling assistance: event form ----
    case 'event_title':
        // Been to this event before? Picking it fills in the type, venue and city as well.
        $title = 'Events you have been to before';
        foreach (rows($mysqli, "SELECT title, category_id, venue, city, MAX(event_date) AS last_date FROM events
                                WHERE title LIKE ? GROUP BY title, category_id, venue, city
                                ORDER BY title LIKE ? DESC, last_date DESC LIMIT 6", [$like, $starts]) as $row) {
            $items[] = [
                'value'  => $row['title'],
                'detail' => trim($row['venue'] . ', ' . $row['city'], ', ') . ' · fills in type, venue and city',
                'fill'   => ['category_id' => (string) $row['category_id'], 'venue' => $row['venue'], 'city' => $row['city']],
            ];
        }
        break;

    case 'venue':
        $items = distinct_values($mysqli, "SELECT venue AS value, COUNT(*) AS total FROM events WHERE venue != '' AND venue LIKE ?
                                           GROUP BY venue ORDER BY venue LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;

    case 'city':
        $items = distinct_values($mysqli, "SELECT city AS value, COUNT(*) AS total FROM events WHERE city != '' AND city LIKE ?
                                           GROUP BY city ORDER BY city LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;

    // ---- Form filling assistance: person form ----
    case 'person_name':
        // Warn about duplicates: is this person already in the diary?
        $title = 'Already in your diary';
        foreach (rows($mysqli, "SELECT p.id, p.name, e.title AS event_title FROM people p JOIN events e ON e.id = p.event_id
                                WHERE p.name LIKE ? ORDER BY p.name LIKE ? DESC, p.name LIMIT 5", [$like, $starts]) as $row) {
            $items[] = ['value' => $row['name'], 'detail' => 'Met at ' . $row['event_title'] . ' · open their details', 'url' => 'person_form.php?id=' . $row['id']];
        }
        break;

    case 'person_job_title':
        $items = distinct_values($mysqli, "SELECT job_title AS value, COUNT(*) AS total FROM people WHERE job_title != '' AND job_title LIKE ?
                                           GROUP BY job_title ORDER BY job_title LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;

    case 'company':
        // Companies from both the people you met and the jobs you saved
        $items = distinct_values($mysqli, "SELECT company AS value, COUNT(*) AS total FROM
                                             (SELECT company FROM people UNION ALL SELECT company FROM jobs) AS all_companies
                                           WHERE company != '' AND company LIKE ?
                                           GROUP BY company ORDER BY company LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;

    // ---- Form filling assistance: job form ----
    case 'job_title':
        $items = distinct_values($mysqli, "SELECT title AS value, COUNT(*) AS total FROM jobs WHERE title LIKE ?
                                           GROUP BY title ORDER BY title LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;

    case 'location':
        $items = distinct_values($mysqli, "SELECT location AS value, COUNT(*) AS total FROM jobs WHERE location != '' AND location LIKE ?
                                           GROUP BY location ORDER BY location LIKE ? DESC, total DESC LIMIT 6", $like, $starts);
        break;
}

send_json(['title' => $title, 'items' => $items]);
