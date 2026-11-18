<?php
// Events list with multi-criteria search. Logic only - the HTML is in templates/events.twig
// events.php?ajax=1&...  returns just the cards as JSON (live search, see app.js)
include("db.php");

// Lists for the dropdowns
$categories = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM categories ORDER BY name"), MYSQLI_ASSOC);
$cities = mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT city FROM events WHERE city != '' ORDER BY city"), MYSQLI_ASSOC);

// ---- Read the search form (every value is checked before it is used) ----
$q = substr(trim($_GET['q'] ?? ''), 0, 80);

$category = (int) ($_GET['category'] ?? 0);
if (!in_array($category, array_column($categories, 'id'))) {
    $category = 0;
}

$city = $_GET['city'] ?? '';
if (!in_array($city, array_column($cities, 'city'))) {
    $city = '';
}

$min_rating = (int) ($_GET['rating'] ?? 0);
if ($min_rating < 1 || $min_rating > 5) {
    $min_rating = 0;
}

$from = $_GET['from'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}

$to = $_GET['to'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'oldest', 'rating', 'title'])) {
    $sort = 'newest';
}

// ---- Build the search: every filter that was filled in is added with AND ----
$where = "1 = 1";
$params = [];

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

$sql = "SELECT e.*, c.name AS category,
               (SELECT COUNT(*) FROM people p WHERE p.event_id = e.id) AS people_count,
               (SELECT COUNT(*) FROM jobs j WHERE j.event_id = e.id) AS job_count
        FROM events e
        JOIN categories c ON c.id = e.category_id
        WHERE $where
        ORDER BY " . $orders[$sort];

// Prepared statement: the ? are filled in by MySQL, so input can't change the SQL
$stmt = mysqli_prepare($mysqli, $sql);
if (count($params) > 0) {
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
}
mysqli_stmt_execute($stmt);
$events = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
$label = count($events) . (count($events) == 1 ? ' event' : ' events');

// Ajax (live search): send only the cards
if (is_ajax()) {
    send_json(['label' => $label, 'html' => $twig->render('_event_cards.twig', ['events' => $events])]);
}

// ---- Stat cards: "All events" + one per type you've been to (up to 5) ----
$all_events = (int) mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS total FROM events"))['total'];
$type_stats = mysqli_fetch_all(mysqli_query($mysqli, "SELECT c.id, c.name, COUNT(e.id) AS total, MAX(e.event_date) AS latest
                                                      FROM categories c JOIN events e ON e.category_id = c.id
                                                      GROUP BY c.id ORDER BY total DESC, c.name LIMIT 5"), MYSQLI_ASSOC);

render('events.twig', [
    'page'       => 'events',
    'events'     => $events,
    'label'      => $label,
    'categories' => array_column($categories, 'name', 'id'),
    'cities'     => array_column($cities, 'city', 'city'),
    'all_events' => $all_events,
    'type_stats' => $type_stats,
    'search'     => ['q' => $q, 'category' => $category ?: '', 'city' => $city, 'rating' => $min_rating ?: '',
                     'from' => $from, 'to' => $to, 'sort' => $sort],
]);
