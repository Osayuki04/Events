<?php
// Everyone you've met. Logic only - the HTML is in templates/people.twig
// people.php?ajax=1&...  returns just the cards as JSON (live search, see app.js)
include("db.php");

// ---- "Mark as followed up" / "Follow up again" button (sent with Ajax from the card) ----
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    check_token();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, "UPDATE people SET follow_up = 1 - follow_up WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);

    // Ajax: tell app.js to refresh the cards and the numbers
    if (is_ajax()) {
        send_json(['ok' => true, 'refresh' => true, 'message' => 'Saved.']);
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

$sql = "SELECT p.*, e.title AS event_title, e.event_date
        FROM people p
        JOIN events e ON e.id = p.event_id
        WHERE $where
        ORDER BY p.follow_up DESC, e.event_date DESC, p.name";
$stmt = mysqli_prepare($mysqli, $sql);
if (count($params) > 0) {
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
}
mysqli_stmt_execute($stmt);
$people = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
$label = count($people) . (count($people) == 1 ? ' person' : ' people');

// Numbers for the stat cards
$counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS everyone, COALESCE(SUM(follow_up = 1), 0) AS to_follow,
                                                           COALESCE(SUM(follow_up = 0), 0) AS done FROM people"));
$stats = ['' => (int) $counts['everyone'], '1' => (int) $counts['to_follow'], '0' => (int) $counts['done']];

$data = ['people' => $people, 'q' => $q, 'follow_up' => $follow_up];

// Ajax (live search): send only the cards, plus the numbers for the stat cards
if (is_ajax()) {
    send_json(['label' => $label, 'html' => $twig->render('_people_cards.twig', $data), 'stats' => $stats]);
}

render('people.twig', $data + ['page' => 'people', 'label' => $label, 'stats' => $stats]);
