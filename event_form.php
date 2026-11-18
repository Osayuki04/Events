<?php
// Add an event (event_form.php) or edit one (event_form.php?id=5).
// Logic only - the HTML is in templates/event_form.twig
// The form is sent with Ajax: errors come back as JSON and show under each field (see app.js).
include("db.php");

$id = (int) ($_GET['id'] ?? 0);
$event = null;

if ($id > 0) {
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM events WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

$categories = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM categories ORDER BY name"), MYSQLI_ASSOC);

// What is shown in the form: the event being edited, or empty values for a new one
$form = $event ?? [
    'title' => '', 'category_id' => '', 'event_date' => date('Y-m-d'), 'venue' => '',
    'city' => '', 'seat' => '', 'rating' => null, 'summary' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($id == 0 || $event)) {
    check_token();

    // Read and tidy every field
    $form = [
        'title'       => substr(trim($_POST['title'] ?? ''), 0, 120),
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'event_date'  => $_POST['event_date'] ?? '',
        'venue'       => substr(trim($_POST['venue'] ?? ''), 0, 120),
        'city'        => substr(trim($_POST['city'] ?? ''), 0, 60),
        'seat'        => substr(trim($_POST['seat'] ?? ''), 0, 80),
        'rating'      => (int) ($_POST['rating'] ?? 0),
        'summary'     => substr(trim($_POST['summary'] ?? ''), 0, 5000),
    ];

    // Check them
    if (strlen($form['title']) < 2) {
        $errors['title'] = "Give the event a name.";
    }
    if (!in_array($form['category_id'], array_column($categories, 'id'))) {
        $errors['category_id'] = "Choose what kind of event it was.";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['event_date']) || $form['event_date'] > date('Y-m-d')) {
        $errors['event_date'] = "Pick the date you went (today or earlier).";
    }
    if ($form['rating'] < 1 || $form['rating'] > 5) {
        $form['rating'] = null; // 0 or anything else = not rated
    }
    if (strlen($form['summary']) < 10) {
        $errors['summary'] = "Write a short summary (at least 10 characters).";
    }

    if (count($errors) == 0) {
        if ($event) {
            $stmt = mysqli_prepare($mysqli, "UPDATE events SET title = ?, category_id = ?, event_date = ?, venue = ?, city = ?,
                                             seat = ?, rating = ?, summary = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "sissssisi", $form['title'], $form['category_id'], $form['event_date'],
                $form['venue'], $form['city'], $form['seat'], $form['rating'], $form['summary'], $id);
            mysqli_stmt_execute($stmt);
            done("event.php?id=" . $id, "Event saved.");
        }
        $stmt = mysqli_prepare($mysqli, "INSERT INTO events (title, category_id, event_date, venue, city, seat, rating, summary)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sissssis", $form['title'], $form['category_id'], $form['event_date'],
            $form['venue'], $form['city'], $form['seat'], $form['rating'], $form['summary']);
        mysqli_stmt_execute($stmt);
        done("event.php?id=" . mysqli_insert_id($mysqli), "Event added. Now add the people you met and any jobs you saved.");
    }

    send_errors($errors); // Ajax: errors as JSON. Otherwise the form is shown again below.
}

render('event_form.twig', [
    'page'       => 'events',
    'id'         => $id,
    'event'      => $event,
    'not_found'  => $id > 0 && !$event,
    'form'       => $form,
    'errors'     => $errors,
    'categories' => array_column($categories, 'name', 'id'),
    'today'      => date('Y-m-d'),
]);
