<?php
// Everyone you've met. Logic only - the HTML is in templates/people.twig
// people.php?ajax=1&...  returns the ids of the matching people as JSON (live search, see app.js)
include("db.php");
require_login(); // only for logged-in users: otherwise sent to login.php

// ---- "Mark as followed up" / "Follow up again" button (sent with Ajax from the card) ----
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "UPDATE people SET follow_up = 1 - follow_up WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    // Ajax: tell app.js the new state (0 or 1) so it can update the card, then refresh the list and numbers
    if (is_ajax()) {
        $stmt = mysqli_prepare($mysqli, "SELECT follow_up FROM people WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        send_json(['ok' => true, 'refresh' => true, 'follow_up' => (int) ($row['follow_up'] ?? 0),
                   'message' => ($row['follow_up'] ?? 0) ? 'Moved back to follow up.' : 'Marked as followed up.']);
    }
    // Without JavaScript: back to the same list (keeping the search)
    header("Location: people.php?" . http_build_query(['q' => $_POST['q'] ?? '', 'follow_up' => $_POST['follow_up'] ?? '']));
    exit;
}

// ---- Search ----
$q = clean_text($_GET['q'] ?? '', 80);

// follow_up filter: "" = everyone, "1" = still to follow up, "0" = already followed up
$follow_up = clean_text($_GET['follow_up'] ?? '', 1);
if (!in_array($follow_up, ['1', '0'], true)) {
    $follow_up = '';
}

$where = "1 = 1";
$params = [];
if ($q != '') {
    $where .= " AND (p.name LIKE ? OR p.company LIKE ? OR p.job_title LIKE ? OR p.notes LIKE ? OR e.title LIKE ?)";
    $like = "%" . $q . "%";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($follow_up !== '') {
    $where .= " AND p.follow_up = ?";
    $params[] = $follow_up;
}

$order = "ORDER BY p.follow_up DESC, e.event_date DESC, p.name";

// The ids of the people that match the search (the live search only needs these)
$ids = matching_ids($mysqli, "SELECT p.id FROM people p JOIN events e ON e.id = p.event_id WHERE $where $order", $params);
$label = count($ids) . (count($ids) == 1 ? ' person' : ' people');

// Numbers for the stat cards
$counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS everyone, COALESCE(SUM(follow_up = 1), 0) AS to_follow,
                                                           COALESCE(SUM(follow_up = 0), 0) AS done FROM people"));
$stats = ['' => (int) $counts['everyone'], '1' => (int) $counts['to_follow'], '0' => (int) $counts['done']];

// Ajax (live search): only the matching ids (numbers), the label and the numbers for the stat cards
if (is_ajax()) {
    send_json(['label' => $label, 'ids' => $ids, 'stats' => $stats]);
}

// The page has everyone; the people that don't match the search are hidden (app.js shows them again)
$people = mysqli_fetch_all(mysqli_query($mysqli, "SELECT p.*, e.title AS event_title, e.event_date
                                                  FROM people p JOIN events e ON e.id = p.event_id $order"), MYSQLI_ASSOC);
$data = ['people' => $people, 'visible' => array_flip($ids), 'q' => $q, 'follow_up' => $follow_up];

render('people.twig', $data + ['page' => 'people', 'label' => $label, 'stats' => $stats]);
