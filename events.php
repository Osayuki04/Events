<?php
// Events list with multi-criteria search. Logic only - the HTML is in templates/events.twig
// events.php?ajax=1&...  returns the ids of the matching events as JSON (live search, see the search_box macro in templates/macros.twig)
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

// Lists for the dropdowns (types are shared; cities come from this user's own events)
$categories = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM categories ORDER BY name"), MYSQLI_ASSOC);
$cities = fetch_rows("SELECT DISTINCT city FROM events WHERE user_id = ? AND city != '' ORDER BY city", [$user_id]);

// ---- Read the search form (every value is checked before it is used) ----
$q = clean_text($_GET['q'] ?? '', 80);

$category = (int) ($_GET['category'] ?? 0);
if (!in_array($category, array_column($categories, 'id'))) {
    $category = 0;
}

$city = clean_text($_GET['city'] ?? '', 60);
if (!in_array($city, array_column($cities, 'city'))) {
    $city = '';
}

$min_rating = (int) ($_GET['rating'] ?? 0);
if ($min_rating < 1 || $min_rating > 5) {
    $min_rating = 0;
}

$from = clean_text($_GET['from'] ?? '', 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}

$to = clean_text($_GET['to'] ?? '', 10);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'oldest', 'rating', 'title'])) {
    $sort = 'newest';
}

// ---- Build the search: only this user's events, then every filter that was filled in is added with AND ----
$where = "e.user_id = ?";
$params = [$user_id];

if ($q != '') {
    $where .= " AND (e.title LIKE ? OR e.summary LIKE ? OR e.venue LIKE ? OR e.city LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like, $like);
}
if ($category > 0) {
    $where .= " AND e.category_id = ?";
    $params[] = $category;
}
if ($city != '') {
    $where .= " AND e.city = ?";
    $params[] = $city;
}
if ($min_rating > 0) {
    $where .= " AND e.rating >= ?";
    $params[] = $min_rating;
}
if ($from != '') {
    $where .= " AND e.event_date >= ?";
    $params[] = $from;
}
if ($to != '') {
    $where .= " AND e.event_date <= ?";
    $params[] = $to;
}

// The sort order comes from a fixed list, never from the user's text
$orders = [
    'newest' => "e.event_date DESC, e.id DESC",
    'oldest' => "e.event_date ASC",
    'rating' => "e.rating IS NULL, e.rating DESC, e.event_date DESC",
    'title'  => "e.title ASC",
];

$order = "ORDER BY " . $orders[$sort];

// The ids of the events that match, in the chosen order (prepared statement: the ? are filled in
// by MySQL, so input can't change the SQL). The live search only needs these.
$ids = matching_ids($mysqli, "SELECT e.id FROM events e JOIN categories c ON c.id = e.category_id WHERE $where $order", $params);
$label = count($ids) . (count($ids) == 1 ? ' event' : ' events');

// ---- Stat cards: "All events" + one per type you've been to (up to 5) ----
$all_events = (int) fetch_row("SELECT COUNT(*) AS total FROM events WHERE user_id = ?", [$user_id])['total'];
$type_stats = fetch_rows("SELECT c.id, c.name, COUNT(e.id) AS total, MAX(e.event_date) AS latest
                          FROM categories c JOIN events e ON e.category_id = c.id
                          WHERE e.user_id = ?
                          GROUP BY c.id ORDER BY total DESC, c.name LIMIT 5", [$user_id]);

// Ajax (live search): only the matching ids (numbers, in order), the label and the numbers for the stat cards
// ('' = all events, then category id => number of events), so they update after a delete
if (is_ajax()) {
    send_json(['label' => $label, 'ids' => $ids, 'stats' => ['' => $all_events] + array_map('intval', array_column($type_stats, 'total', 'id'))]);
}

// The page has every event (in the chosen order); the ones that don't match are hidden (the live search script shows them again)
$events = fetch_rows("SELECT e.*, c.name AS category,
                             (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id) AS people_count,
                             (SELECT COUNT(*) FROM jobs j WHERE j.event_id = e.id) AS job_count
                      FROM events e JOIN categories c ON c.id = e.category_id
                      WHERE e.user_id = ? $order", [$user_id]);

render('events.twig', [
    'page'       => 'events',
    'events'     => $events,
    'visible'    => array_flip($ids),
    'label'      => $label,
    'categories' => array_column($categories, 'name', 'id'),
    'cities'     => array_column($cities, 'city', 'city'),
    'all_events' => $all_events,
    'type_stats' => $type_stats,
    'search'     => ['q' => $q, 'category' => $category ?: '', 'city' => $city, 'rating' => $min_rating ?: '',
                     'from' => $from, 'to' => $to, 'sort' => $sort],
]);
