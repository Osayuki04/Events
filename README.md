# Yukylo

A diary of the events you've attended. For each event you keep where you sat, a rating, a summary, the people you met (with their contact details, LinkedIn and other socials) and any jobs you saved from it.

PHP + MySQL, with the **Twig** template engine for all HTML. Styled only with Tailwind CSS (loaded from the jsDelivr CDN, no build step), white background with one red. Icons from Bootstrap Icons (icon font only).

## How it is built

Server-side logic and client-side markup are kept apart:

- **`*.php` pages = logic only.** Each one starts with `include("db.php");`, reads and checks the input, runs the queries, then calls `render('page.twig', [...data...])`. No HTML.
- **`templates/*.twig` = markup only.** `base.twig` is the layout every page extends (head, navigation, messages). Pages fill in its `content` block. `macros.twig` holds small reusable pieces (form field, stars, status label, avatar, date block, stat card).

| File | What it does |
|---|---|
| `db.php` | MySQL connection, session + form token, Twig setup, helpers (`render`, `is_ajax`, `send_json`, `check_token`, `done`, `send_errors`) |
| `greeting.php` | Time helpers used in templates: `greeting()`, `today_text()`, the `time_ago` filter |
| `index.php` + `templates/index.twig` | Dashboard |
| `events.php` + `templates/events.twig` | All events, multi-criteria search |
| `event.php` + `templates/event.twig` | One event: summary, people met, jobs saved |
| `event_form.php` + `templates/event_form.twig` | Add / edit an event |
| `event_delete.php` | Deletes an event (and its people and jobs) |
| `people.php` + `templates/people.twig` | Everyone you've met, with "Mark as followed up" |
| `person_form.php` + `templates/person_form.twig` | Add / edit / remove someone you met |
| `jobs.php` + `templates/jobs.twig` | All saved jobs, change status from a card |
| `job_form.php` + `templates/job_form.twig` | Add / edit / remove a job |
| `templates/_event_cards.twig`, `_people_cards.twig`, `_job_cards.twig` | The result cards, used by the full pages **and** by the Ajax live search |
| `suggest.php` | Ajax endpoint for autocomplete and form filling (JSON) |
| `tests/security_test.php` | Security tests: run from the command line, prints PASS / FAIL for 307 checks |
| `assets/js/app.js` | All JavaScript: menu, "Are you sure?" box, live search, Ajax forms, autocomplete |
| `vendor/` | Twig, installed with Composer (`composer.json`) |
| `sql/schema.sql` | The whole database: creates every table and inserts all the data (kept out of git) |

`templates/`, `vendor/` and the Composer files can't be opened from the browser (`.htaccess`).

## Ajax

Every search and every form uses Ajax. Requests send the header `X-Requested-With: fetch`; the PHP page answers with JSON.

| Where | What Ajax does |
|---|---|
| Search on Events, People, Jobs | Results update as you type (`?ajax=1`); suggestions drop down under the search box (`suggest.php`); the stat cards filter without reloading |
| Event form | Typing a name suggests events you've been to before — picking one **fills in the type, venue and city**; venue and city also suggest past values |
| Person form | Warns if the person is **already in your diary** (opens their details); job title and company suggest past values |
| Job form | Job title, company and location suggest past values |
| All add / edit forms | Sent in the background: errors appear under each field without reloading; on success you go to the saved item |
| "Mark as followed up", job status dropdown | Saved in the background; the cards and the numbers on the stat cards update |
| Delete / remove buttons | After the "Are you sure?" box, sent in the background |

Without JavaScript every form and search still works as a normal page.

## Run on XAMPP

1. Put the folder in `C:\xampp\htdocs\Events` and run `composer install` in it (downloads Twig into `vendor/`)
2. Start Apache and MySQL in the XAMPP control panel
3. In phpMyAdmin create a database called `event_diary`, open its **SQL** tab, paste `sql/schema.sql` and press **Go**
4. Open http://localhost/Events/

## Deploy to mi-linux

1. Check the server's PHP version is **8.1 or newer** (Twig 3.30 needs it): upload a file containing `<?php phpinfo();` and open it, then delete it.
2. Upload the whole folder **including `vendor/`** (it is not in git, so upload it from your own computer).
3. In phpMyAdmin open your database, go to the **SQL** tab, paste `sql/schema.sql` and press **Go**. It creates the tables with all the data and removes the old ticketing tables.

`db.php` uses the mi-linux login automatically when the site isn't on localhost.

## Where each requirement lives

| Requirement | Where |
|---|---|
| **Create** | `event_form.php`, `person_form.php`, `job_form.php` |
| **Read** | `index.php`, `events.php`, `event.php`, `people.php`, `jobs.php` |
| **Update** | Edit forms above, "Mark as followed up" on `people.php`, status dropdown on `jobs.php` |
| **Delete** | `event_delete.php`, Remove button on `person_form.php` and `job_form.php` |
| **Multi-criteria search** | `events.php`: keyword + type + city + minimum rating + date range + sort, combined with AND |
| **Input filtering** | Every value goes through `clean_text()` in `db.php` (removes HTML tags and control characters, refuses lists, limits length), then `(int)`, `in_array` against allowed values, `filter_var` for email and links, regex for dates and phone numbers |
| **XSS / HTML injection** | Tags are removed from input before saving (`clean_text`), Twig escapes every `{{ value }}` when shown (`autoescape`), app.js inserts text with `textContent`, and the Content-Security-Policy header blocks any script that isn't from this site or jsDelivr |
| **SQL injection** | mysqli prepared statements (`?` placeholders) for every query that uses input, including `suggest.php` |
| **Other security** | Form token on every POST form, checked with `hash_equals` (CSRF); session cookie is HttpOnly + SameSite; links must start with http(s):// so `javascript:` links can't be saved; PHP errors are never shown to visitors |
| **Security headers** | Sent by `db.php` on every page: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy (and HSTS on HTTPS) |
| **Security tests** | `tests/security_test.php` attacks every form, search and filter with XSS and SQL injection payloads and checks headers, CSRF, broken input and private files (307 checks). Run: `C:
mpp\php\php.exe tests\security_test.php` |
| **Ajax** | See the Ajax table above |
| **Template engine** | Twig: `templates/` holds all markup, the `.php` files hold only logic |
