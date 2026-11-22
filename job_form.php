<?php
// Save a job from an event (job_form.php?event_id=3) or edit/remove it (job_form.php?id=4).
// Logic only - the HTML is in templates/job_form.twig
// The form is sent with Ajax: errors come back as JSON and show under each field (see app.js).
include("db.php");

$id = (int) ($_GET['id'] ?? 0);
$event_id = (int) ($_GET['event_id'] ?? 0);
$job = null;

if ($id > 0) {
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM jobs WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $job = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $event_id = $job ? (int) $job['event_id'] : 0;
}

// The event the job came from
$stmt = mysqli_prepare($mysqli, "SELECT id, title, event_date FROM events WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $event_id);
mysqli_stmt_execute($stmt);
$event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$statuses = ['Saved', 'Applied', 'Interview', 'Offer', 'Rejected'];

$form = $job ?? ['title' => '', 'company' => '', 'location' => '', 'url' => '', 'status' => 'Saved', 'notes' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $event) {
    check_token();

    // ---- Remove ----
    if (($_POST['action'] ?? '') == 'delete' && $job) {
        $stmt = mysqli_prepare($mysqli, "DELETE FROM jobs WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        done("event.php?id=" . $event_id . "#jobs", "The job was removed.");
    }

    // ---- Save ----
    $form = [
        'title'    => clean_text($_POST['title'] ?? '', 120),
        'company'  => clean_text($_POST['company'] ?? '', 80),
        'location' => clean_text($_POST['location'] ?? '', 80),
        'url'      => clean_text($_POST['url'] ?? '', 255),
        'status'   => clean_text($_POST['status'] ?? '', 20),
        'notes'    => clean_text($_POST['notes'] ?? '', 2000),
    ];

    if (strlen($form['title']) < 2) {
        $errors['title'] = "Enter the job title.";
    }
    if (strlen($form['company']) < 2) {
        $errors['company'] = "Enter the company.";
    }
    if ($form['url'] != '' && (!filter_var($form['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $form['url']))) {
        $errors['url'] = "Use a full link starting with https://";
    }
    if (!in_array($form['status'], $statuses)) {
        $errors['status'] = "Choose a status.";
    }

    if (count($errors) == 0) {
        if ($job) {
            $stmt = mysqli_prepare($mysqli, "UPDATE jobs SET title = ?, company = ?, location = ?, url = ?, status = ?, notes = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssssssi", $form['title'], $form['company'], $form['location'], $form['url'],
                $form['status'], $form['notes'], $id);
            mysqli_stmt_execute($stmt);
            done("event.php?id=" . $event_id . "#jobs", "Job updated.");
        }
        $stmt = mysqli_prepare($mysqli, "INSERT INTO jobs (event_id, title, company, location, url, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issssss", $event_id, $form['title'], $form['company'], $form['location'], $form['url'],
            $form['status'], $form['notes']);
        mysqli_stmt_execute($stmt);
        done("event.php?id=" . $event_id . "#jobs", "Job saved.");
    }

    send_errors($errors); // Ajax: errors as JSON. Otherwise the form is shown again below.
}

// The job or its event doesn't exist (wrong id or deleted): 404 status
if (!$event || ($id > 0 && !$job)) {
    http_response_code(404);
}

render('job_form.twig', [
    'page'     => 'jobs',
    'id'       => $id,
    'event_id' => $event_id,
    'job'      => $job,
    'event'    => $event,
    'form'     => $form,
    'errors'   => $errors,
    'statuses' => array_combine($statuses, $statuses),
]);
