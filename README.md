# Yukylo

A diary of the events you've attended. For each event you keep where you sat, a rating, a summary, the people you met (with their contact details, LinkedIn and other socials) and any jobs you saved from it.

PHP + MySQL, with the **Twig** template engine for all HTML. Styled only with Tailwind CSS (loaded from the jsDelivr CDN, no build step), white background with one red. Icons from Bootstrap Icons (icon font only).

## How it is built

Server-side logic and client-side markup are kept apart:

- **`*.php` pages = logic only.** Each one starts with `include("db.php");`, reads and checks the input, runs the queries, then calls `render('page.twig', [...data...])`. No HTML.
- **`templates/*.twig` = markup only.** `base.twig` is the layout every page extends (head, navigation, messages). Pages fill in its `content` block. `macros.twig` holds small reusable pieces (form field, stars, status label, avatar, date block, stat card).

| File | What it does |
|---|---|
| `db.php` | MySQL connection, session + form token, Twig setup, helpers (`render`, `is_ajax`, `send_json`, `check_token`, `done`, `send_errors`) and login helpers (`require_login`, `log_in`, `log_out`, `check_captcha`) |
| `login.php` + `templates/login.twig` | Log in: username, password and captcha |
| `register.php` + `templates/register.twig` | Create your account. Only open while there are no accounts (the first visit) |
| `logout.php` | Log out (POST + form token) |
| `captcha.php` | Draws the captcha picture (SVG) and keeps its code in the session |
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
| `templates/_event_cards.twig`, `_people_cards.twig`, `_job_cards.twig` | The result cards. Every card is drawn by Twig with its id; the Ajax live search only shows, hides and orders them |
| `suggest.php` | Ajax endpoint for autocomplete and form filling (JSON) |
| `tests/security_test.php` | Security tests: run from the command line, prints PASS / FAIL for 381 checks |
| `assets/js/app.js` | All JavaScript: menu, "Are you sure?" box, live search, Ajax forms, autocomplete |
| `vendor/` | Twig, installed with Composer (`composer.json`) |
| `sql/schema.sql` | The whole database: creates every table and inserts all the data (kept out of git) |
| `sql/users.sql` | Just the login table, to add to a database that already has the diary tables |

`templates/`, `vendor/` and the Composer files can't be opened from the browser (`.htaccess`).

## Ajax

Every search and every form uses Ajax. Requests send the header `X-Requested-With: fetch`; the PHP page answers with JSON.

| Where | What Ajax does |
|---|---|
| Search on Events, People, Jobs | Results update as you type (`?ajax=1` answers with only the ids of the matching cards, never HTML); suggestions drop down under the search box (`suggest.php`); the stat cards filter without reloading |
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
4. Open http://localhost/Events/ — the first time, it asks you to **create your account** (username + password). After that it shows the login page.

To run the security tests (Apache and MySQL must be running): `composer test`

## Deploy to mi-linux

1. Check the server's PHP version is **8.1 or newer** (Twig 3.30 needs it): upload a file containing `<?php phpinfo();` and open it, then delete it.
2. Upload the whole folder **including `vendor/`** (it is not in git, so upload it from your own computer).
3. In phpMyAdmin open your database, go to the **SQL** tab, paste `sql/schema.sql` and press **Go**. It creates the tables with all the data and removes the old ticketing tables.
4. **Straight away** open the site and create your account: until one exists, anyone opening the site could create it.

`db.php` uses the mi-linux login automatically when the site isn't on localhost.

## Where each requirement lives

| Requirement | Where |
|---|---|
| **Create** | `event_form.php`, `person_form.php`, `job_form.php` |
| **Read** | `index.php`, `events.php`, `event.php`, `people.php`, `jobs.php` |
| **Update** | Edit forms above, "Mark as followed up" on `people.php`, status dropdown on `jobs.php` |
| **Delete** | `event_delete.php`, Remove button on `person_form.php` and `job_form.php` |
| **(1) Input filtering** | Every value goes through `clean_text()` in `db.php` (removes HTML tags and control characters, refuses lists, limits length), then `(int)`, `in_array` against allowed values, `filter_var` for email and links, regex for dates, phone numbers and usernames. Passwords are only checked to be text of a sensible length (never changed) |
| **(2) Output escaping** | Twig `autoescape` escapes every `{{ value }}` with `htmlspecialchars` when shown; the `url_encode` filter for values put in links; app.js inserts text with `textContent`; the live search gets only ids (whole numbers) and shows or hides cards Twig already drew, so no HTML from the server is ever parsed (no DOM XSS); stat cards pick a dropdown option by position instead of copying text; and `goTo()` rebuilds every address from a fixed list of our pages plus whole numbers (no open redirect) |
| **(3) Sessions protect the pages** | Every diary page starts with `require_login()` (`db.php`): no login → sent to `login.php` (Ajax gets 401). The session id is changed when you log in and out (`session_regenerate_id`, stops session fixation), you are logged out after 30 minutes idle, the cookie is HttpOnly + SameSite (+ Secure on HTTPS) and strict mode refuses made-up session ids. Log out is a POST with the form token |
| **(4) Captcha** | `captcha.php` makes a random 5 character code (`random_int`), keeps it only in the session and draws it as an SVG picture built from small squares of a pixel font, tilted and with noise, so the code is never in the page as text. Needed on login and create account (`check_captcha()` in `db.php`): each code works once and for 10 minutes. A wrong try shows a new picture |
| **(5) Passwords encrypted** | `register.php` stores only `password_hash()` (bcrypt with a random salt), never the password. `login.php` checks it with `password_verify()` and upgrades old hashes with `password_needs_rehash()`. Same message for a wrong username or password; 5 wrong passwords lock the account for 5 minutes |
| **Multi-criteria search** | `events.php`: keyword + type + city + minimum rating + date range + sort, combined with AND |
| **XSS / HTML injection** | Tags are removed from input before saving (`clean_text`), Twig escapes every `{{ value }}` when shown (`autoescape`), app.js inserts text with `textContent`, and the Content-Security-Policy header blocks any script that isn't from this site or jsDelivr |
| **SQL injection** | mysqli prepared statements (`?` placeholders) for every query that uses input, including `suggest.php` |
| **Other security** | Form token on every POST form, checked with `hash_equals` (CSRF); session cookie is HttpOnly + SameSite; links must start with http(s):// so `javascript:` links can't be saved; PHP errors are never shown to visitors |
| **Security headers** | Sent by `db.php` on every page: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy (same-origin), Permissions-Policy, Cross-Origin-Opener-Policy + Resource-Policy + Embedder-Policy (cross-origin isolation), X-Robots-Tag (and HSTS on HTTPS). `.htaccess` adds them to every other response too (app.js, robots.txt, and Apache's own 403 / 404 pages), each sent once. `robots.txt` also keeps the private diary out of search engines |
| **Security tests** | `tests/security_test.php` attacks every form, search and filter with XSS and SQL injection payloads and checks the login, captcha, password hashing, sessions, headers, CSRF, broken input and private files (381 checks). Run: `C:\xampp\php\php.exe tests\security_test.php` |
| **Ajax** | See the Ajax table above |
| **Template engine** | Twig: `templates/` holds all markup, the `.php` files hold only logic |
