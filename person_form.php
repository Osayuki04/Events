<?php
// Add someone you met (person_form.php?event_id=3) or edit/remove them (person_form.php?id=7).
// Logic only - the HTML is in templates/person_form.twig
// The form is sent with Ajax: errors come back as JSON and show under each field (see app.js).
include("db.php");
require_login(); // only for logged-in users: otherwise sent to login.php

$id = (int) ($_GET['id'] ?? 0);
$event_id = (int) ($_GET['event_id'] ?? 0);
$person = null;

if ($id > 0) {
    $stmt = mysqli_prepare($mysqli, "SELECT * FROM people WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $person = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $event_id = $person ? (int) $person['event_id'] : 0;
}

// The event they were met at
$stmt = mysqli_prepare($mysqli, "SELECT id, title, event_date FROM events WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $event_id);
mysqli_stmt_execute($stmt);
$event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$form = $person ?? [
    'name' => '', 'job_title' => '', 'company' => '', 'email' => '', 'phone' => '',
    'linkedin' => '', 'other_social' => '', 'notes' => '', 'follow_up' => 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $event) {
    check_token();

    // ---- Remove ----
    if (($_POST['action'] ?? '') == 'delete' && $person) {
        $stmt = mysqli_prepare($mysqli, "DELETE FROM people WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        done("event.php?id=" . $event_id . "#people", $person['name'] . " was removed.");
    }

    // ---- Save ----
    $form = [
        'name'         => clean_text($_POST['name'] ?? '', 80),
        'job_title'    => clean_text($_POST['job_title'] ?? '', 80),
        'company'      => clean_text($_POST['company'] ?? '', 80),
        'email'        => clean_text($_POST['email'] ?? '', 120),
        'phone'        => clean_text($_POST['phone'] ?? '', 30),
        'linkedin'     => clean_text($_POST['linkedin'] ?? '', 255),
        'other_social' => clean_text($_POST['other_social'] ?? '', 255),
        'notes'        => clean_text($_POST['notes'] ?? '', 2000),
        'follow_up'    => isset($_POST['follow_up']) ? 1 : 0,
    ];

    if (strlen($form['name']) < 2) {
        $errors['name'] = "Enter their name.";
    }
    if ($form['email'] != '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Enter a valid email address, like name@example.com.";
    }
    if ($form['phone'] != '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $form['phone'])) {
        $errors['phone'] = "Use numbers, spaces and + ( ) - only.";
    }
    // Links must be full web addresses (this also blocks "javascript:" links)
    if ($form['linkedin'] != '' && (!filter_var($form['linkedin'], FILTER_VALIDATE_URL)
            || !preg_match('#^https?://([a-z]+\.)?linkedin\.com/#i', $form['linkedin']))) {
        $errors['linkedin'] = "Paste their LinkedIn profile link, e.g. https://www.linkedin.com/in/name";
    }
    if ($form['other_social'] != '' && (!filter_var($form['other_social'], FILTER_VALIDATE_URL)
            || !preg_match('#^https?://#i', $form['other_social']))) {
        $errors['other_social'] = "Use a full link starting with https://";
    }

    if (count($errors) == 0) {
        if ($person) {
            $stmt = mysqli_prepare($mysqli, "UPDATE people SET name = ?, job_title = ?, company = ?, email = ?, phone = ?,
                                             linkedin = ?, other_social = ?, notes = ?, follow_up = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "ssssssssii", $form['name'], $form['job_title'], $form['company'], $form['email'],
                $form['phone'], $form['linkedin'], $form['other_social'], $form['notes'], $form['follow_up'], $id);
            mysqli_stmt_execute($stmt);
            done("event.php?id=" . $event_id . "#people", $form['name'] . " was updated.");
        }
        $stmt = mysqli_prepare($mysqli, "INSERT INTO people (event_id, name, job_title, company, email, phone, linkedin, other_social, notes, follow_up)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "issssssssi", $event_id, $form['name'], $form['job_title'], $form['company'], $form['email'],
            $form['phone'], $form['linkedin'], $form['other_social'], $form['notes'], $form['follow_up']);
        mysqli_stmt_execute($stmt);
        done("event.php?id=" . $event_id . "#people", $form['name'] . " was added.");
    }

    send_errors($errors); // Ajax: errors as JSON. Otherwise the form is shown again below.
}

// The person or their event doesn't exist (wrong id or deleted): 404 status
if (!$event || ($id > 0 && !$person)) {
    http_response_code(404);
}

render('person_form.twig', [
    'page'     => 'people',
    'id'       => $id,
    'event_id' => $event_id,
    'person'   => $person,
    'event'    => $event,
    'form'     => $form,
    'errors'   => $errors,
]);
