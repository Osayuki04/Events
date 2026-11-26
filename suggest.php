<?php
// Ajax: suggestions while typing (autocomplete + form filling assistance). Used by the autocomplete script at the bottom of templates/base.twig
//
//   suggest.php?field=events&q=sum   ->  {"title": "...", "items": [{"value": "...", "detail": "...", "fill": {...}, "url": "..."}]}
//
//   value  = text put into the box when a suggestion is picked
//   detail = small grey text under it
//   fill   = other form fields to fill in at the same time (e.g. picking a past event fills its venue and city)
//   url    = open this page instead (e.g. "this person is already in your diary")
//
// Every suggestion comes only from the logged-in user's own diary: each query has "user_id = ?".
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

$field = clean_text($_GET['field'] ?? '', 30);
$q = clean_text($_GET['q'] ?? '', 80);

if ($q === '') {
    send_json(['items' => []]);
}

$like = "%" . $q . "%";
$starts = $q . "%";

// A list of distinct values from one column, the ones that start with the text first.
// The query's placeholders are: user id, LIKE text, starts-with text.
function distinct_values(string $sql, int $user_id, string $like, string $starts): array
{
    $items = [];
    foreach (fetch_rows($sql, [$user_id, $like, $starts]) as $row) {
        $items[] = ['value' => $row['value'], 'detail' => 'Used ' . $row['total'] . ($row['total'] == 1 ? ' time' : ' times')];
    }
    return $items;
}

$title = '';
$items = [];

switch ($field) {

    // ---- Search boxes ----
    case 'events':
        foreach (fetch_rows("SELECT e.title, e.event_date, c.name AS category FROM events e JOIN categories c ON c.id = e.category_id
                             WHERE e.user_id = ? AND (e.title LIKE ? OR e.venue LIKE ? OR e.city LIKE ?)
                             ORDER BY e.title LIKE ? DESC, e.event_date DESC LIMIT 6", [$user_id, $like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['title'], 'detail' => $row['category'] . ' · ' . date('j M Y', strtotime($row['event_date']))];
        }
        break;

    case 'people':
        foreach (fetch_rows("SELECT p.name, p.job_title, p.company FROM people p JOIN events e ON e.id = p.event_id
                             WHERE e.user_id = ? AND (p.name LIKE ? OR p.company LIKE ? OR p.job_title LIKE ?)
                             ORDER BY p.name LIKE ? DESC, p.name LIMIT 6", [$user_id, $like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['name'], 'detail' => implode(' · ', array_filter([$row['job_title'], $row['company']]))];
        }
        break;

    case 'jobs':
        foreach (fetch_rows("SELECT j.title, j.company, j.status FROM jobs j JOIN events e ON e.id = j.event_id
                             WHERE e.user_id = ? AND (j.title LIKE ? OR j.company LIKE ? OR j.location LIKE ?)
                             ORDER BY j.title LIKE ? DESC, j.title LIMIT 6", [$user_id, $like, $like, $like, $starts]) as $row) {
            $items[] = ['value' => $row['title'], 'detail' => $row['company'] . ' · ' . $row['status']];
        }
        break;

    // ---- Form filling assistance: event form ----
    case 'event_title':
        // Been to this event before? Picking it fills in the type, venue and city as well.
        $title = 'Events you have been to before';
        foreach (fetch_rows("SELECT title, category_id, venue, city, MAX(event_date) AS last_date FROM events
                             WHERE user_id = ? AND title LIKE ? GROUP BY title, category_id, venue, city
                             ORDER BY title LIKE ? DESC, last_date DESC LIMIT 6", [$user_id, $like, $starts]) as $row) {
            $items[] = [
                'value'  => $row['title'],
                'detail' => trim($row['venue'] . ', ' . $row['city'], ', ') . ' · fills in type, venue and city',
                'fill'   => ['category_id' => (string) $row['category_id'], 'venue' => $row['venue'], 'city' => $row['city']],
            ];
        }
        break;

    case 'venue':
        $items = distinct_values("SELECT venue AS value, COUNT(*) AS total FROM events WHERE user_id = ? AND venue != '' AND venue LIKE ?
                                  GROUP BY venue ORDER BY venue LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;

    case 'city':
        $items = distinct_values("SELECT city AS value, COUNT(*) AS total FROM events WHERE user_id = ? AND city != '' AND city LIKE ?
                                  GROUP BY city ORDER BY city LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;

    // ---- Form filling assistance: person form ----
    case 'person_name':
        // Warn about duplicates: is this person already in the diary?
        $title = 'Already in your diary';
        foreach (fetch_rows("SELECT p.id, p.name, e.title AS event_title FROM people p JOIN events e ON e.id = p.event_id
                             WHERE e.user_id = ? AND p.name LIKE ? ORDER BY p.name LIKE ? DESC, p.name LIMIT 5", [$user_id, $like, $starts]) as $row) {
            $items[] = ['value' => $row['name'], 'detail' => 'Met at ' . $row['event_title'] . ' · open their details', 'url' => 'person_form.php?id=' . $row['id']];
        }
        break;

    case 'person_job_title':
        $items = distinct_values("SELECT p.job_title AS value, COUNT(*) AS total FROM people p JOIN events e ON e.id = p.event_id
                                  WHERE e.user_id = ? AND p.job_title != '' AND p.job_title LIKE ?
                                  GROUP BY p.job_title ORDER BY p.job_title LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;

    case 'company':
        // Companies from both the people you met and the jobs you saved
        $items = distinct_values("SELECT company AS value, COUNT(*) AS total FROM
                                    (SELECT p.company, e.user_id FROM people p JOIN events e ON e.id = p.event_id
                                     UNION ALL
                                     SELECT j.company, e.user_id FROM jobs j JOIN events e ON e.id = j.event_id) AS all_companies
                                  WHERE user_id = ? AND company != '' AND company LIKE ?
                                  GROUP BY company ORDER BY company LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;

    // ---- Form filling assistance: job form ----
    case 'job_title':
        $items = distinct_values("SELECT j.title AS value, COUNT(*) AS total FROM jobs j JOIN events e ON e.id = j.event_id
                                  WHERE e.user_id = ? AND j.title LIKE ?
                                  GROUP BY j.title ORDER BY j.title LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;

    case 'location':
        $items = distinct_values("SELECT j.location AS value, COUNT(*) AS total FROM jobs j JOIN events e ON e.id = j.event_id
                                  WHERE e.user_id = ? AND j.location != '' AND j.location LIKE ?
                                  GROUP BY j.location ORDER BY j.location LIKE ? DESC, total DESC LIMIT 6", $user_id, $like, $starts);
        break;
}

send_json(['title' => $title, 'items' => $items]);
