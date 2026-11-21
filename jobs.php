<?php
// Every saved job. Logic only - the HTML is in templates/jobs.twig
// jobs.php?ajax=1&...  returns just the cards as JSON (live search, see app.js)
include("db.php");

$statuses = ['Saved', 'Applied', 'Interview', 'Offer', 'Rejected'];

// ---- Quick status change from a card (sent with Ajax when the dropdown changes) ----
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();
    $id = (int) ($_POST['id'] ?? 0);
    $new_status = clean_text($_POST['status'] ?? '', 20);

    if (in_array($new_status, $statuses)) {
        $stmt = mysqli_prepare($mysqli, "UPDATE jobs SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $new_status, $id);
        mysqli_stmt_execute($stmt);
        $message = "Status changed to $new_status.";
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

$where = "1 = 1";
$params = [];
if ($q != '') {
    $where .= " AND (j.title LIKE ? OR j.company LIKE ? OR j.location LIKE ? OR e.title LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like, $like);
}
if ($status != '') {
    $where .= " AND j.status = ?";
    $params[] = $status;
}

$sql = "SELECT j.*, e.title AS event_title, e.event_date
        FROM jobs j
        JOIN events e ON e.id = j.event_id
        WHERE $where
        ORDER BY FIELD(j.status, 'Offer', 'Interview', 'Applied', 'Saved', 'Rejected'), j.created_at DESC";
$stmt = mysqli_prepare($mysqli, $sql);
if (count($params) > 0) {
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
}
mysqli_stmt_execute($stmt);
$jobs = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
$label = count($jobs) . (count($jobs) == 1 ? ' job' : ' jobs');

// Number of jobs at each status (for the stat cards). '' = all jobs.
$stats = array_fill_keys($statuses, 0);
$results = mysqli_query($mysqli, "SELECT status, COUNT(*) AS total FROM jobs GROUP BY status");
while ($a_row = mysqli_fetch_assoc($results)) {
    $stats[$a_row['status']] = (int) $a_row['total'];
}
$stats = ['' => array_sum($stats)] + $stats;

$data = ['jobs' => $jobs, 'statuses' => $statuses, 'status' => $status, 'q' => $q];

// Ajax (live search): send only the cards, plus the numbers for the stat cards
if (is_ajax()) {
    send_json(['label' => $label, 'html' => $twig->render('_job_cards.twig', $data), 'stats' => $stats]);
}

render('jobs.twig', $data + ['page' => 'jobs', 'label' => $label, 'stats' => $stats]);
