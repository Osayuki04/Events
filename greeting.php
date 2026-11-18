<?php
// Greeting for the dashboard, based on the time of day in the UK.
// Used in index.php with: include("greeting.php");  then  greeting()

function greeting(): string
{
    // UK time (XAMPP's default timezone may be different)
    $now = new DateTime("now", new DateTimeZone("Europe/London"));
    $hour = (int) $now->format("G"); // 0 to 23

    if ($hour >= 5 && $hour < 12) {
        return "Good morning";
    } elseif ($hour >= 12 && $hour < 18) {
        return "Good afternoon";
    } else {
        return "Good evening";
    }
}

// How long ago a date was, e.g. "Today", "Yesterday", "16 days ago", "3 weeks ago", "2 months ago"
function time_ago(string $date): string
{
    $today = new DateTime("today", new DateTimeZone("Europe/London"));
    $then = new DateTime(substr($date, 0, 10), new DateTimeZone("Europe/London"));
    $days = (int) $then->diff($today)->format("%r%a"); // negative if the date is in the future

    if ($days <= 0) {
        return "Today";
    } elseif ($days == 1) {
        return "Yesterday";
    } elseif ($days < 21) {
        return "$days days ago";
    } elseif ($days < 60) {
        return floor($days / 7) . " weeks ago";
    } elseif ($days < 365) {
        return floor($days / 30) . " months ago";
    }
    $years = floor($days / 365);
    return $years == 1 ? "A year ago" : "$years years ago";
}

// Today's date in the UK, e.g. "Wednesday 18 November"
function today_text(): string
{
    $now = new DateTime("now", new DateTimeZone("Europe/London"));
    return $now->format("l j F");
}
