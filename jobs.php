<?php
// Every saved job. Logic only - the HTML is in templates/jobs.twig
// jobs.php?ajax=1&...  returns the ids of the matching jobs as JSON (live search, see app.js)
include("db.php");
$user_id = require_login(); // only for logged-in users (else sent to login.php); every query below only uses their records

$statuses = ['Saved', 'Applied', 'Interview', 'Offer', 'Rejected'];

// ---- Quick status change from a card (sent with Ajax when the dropdown changes) ----
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();
    $id = (int) ($_POST['id'] ?? 0);
    $new_status = clean_text($_POST['status'] ?? '', 20);

    if (in_array($new_status, $statuses)) {
        // Only a job from one of this user's own events can be changed
        $changed = run_query("UPDATE jobs j JOIN events e ON e.id = j.event_id
                              SET j.status = ? WHERE j.id = ? AND e.user_id = ?", [$new_status, $id, $user_id]);
        $message = $changed > 0 || fetch_row("SELECT j.id FROM jobs j JOIN events e ON e.id = j.event_id
                                              WHERE j.id = ? AND e.user_id = ?", [$id, $user_id])
            ? "Status changed to $new_status." : "That job no longer exists.";
    } else {
        $message = "Choose a status from the list.";
    }

    // Ajax: tell app.js to refresh the cards and the numbers
    if (is_ajax()) {
        send_json(['ok' => true, 'refresh' => true, 'message' => $message]);
    }
    // Without JavaScript: back to the same list (keeping the filters)
    $_SESSION['message'] = $message;
    header("Location: jobs.php?" . http_build_query(['status' => $_POST['filter'] ?? '', 'q' => $_POST['q'] ?? '']));
    exit;
}

// ---- Filters ----
$q = clean_text($_GET['q'] ?? '', 80);
$status = clean_text($_GET['status'] ?? '', 20);
if (!in_array($status, $statuses)) {
    $status = '';
}

// Only jobs from this user's own events, then the filters
$where = "e.user_id = ?";
$params = [$user_id];
if ($q != '') {
    $where .= " AND (j.title LIKE ? OR j.company LIKE ? OR j.location LIKE ? OR e.title LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like, $like);
}
if ($status != '') {
    $where .= " AND j.status = ?";
    $params[] = $status;
}

$order = "ORDER BY FIELD(j.status, 'Offer', 'Interview', 'Applied', 'Saved', 'Rejected'), j.created_at DESC";

// The ids of the jobs that match the search (the live search only needs these)
$ids = matching_ids($mysqli, "SELECT j.id FROM jobs j JOIN events e ON e.id = j.event_id WHERE $where $order", $params);
$label = count($ids) . (count($ids) == 1 ? ' job' : ' jobs');

// Number of jobs at each status (for the stat cards). '' = all jobs.
$stats = array_fill_keys($statuses, 0);
foreach (fetch_rows("SELECT j.status, COUNT(*) AS total FROM jobs j JOIN events e ON e.id = j.event_id
                     WHERE e.user_id = ? GROUP BY j.status", [$user_id]) as $a_row) {
    $stats[$a_row['status']] = (int) $a_row['total'];
}
$stats = ['' => array_sum($stats)] + $stats;

// Ajax (live search): only the matching ids (numbers), the label and the numbers for the stat cards
if (is_ajax()) {
    send_json(['label' => $label, 'ids' => $ids, 'stats' => $stats]);
}

// The page has every job; the ones that don't match the search are hidden (app.js shows them again)
$jobs = fetch_rows("SELECT j.*, e.title AS event_title, e.event_date
                    FROM jobs j JOIN events e ON e.id = j.event_id
                    WHERE e.user_id = ? $order", [$user_id]);
$data = ['jobs' => $jobs, 'visible' => array_flip($ids), 'statuses' => $statuses, 'status' => $status, 'q' => $q];

render('jobs.twig', $data + ['page' => 'jobs', 'label' => $label, 'stats' => $stats]);
