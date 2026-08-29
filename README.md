# HelpDesk — PHP Ticketing System

Plain PHP 8 + MySQL (PDO) + Tailwind CSS. No Composer, no build step — drop it in `htdocs` or a cPanel `public_html` and it runs.

## Install (XAMPP)

1. Copy this folder into `C:\xampp\htdocs\ticket-system`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open <http://localhost/ticket-system/install/index.php>, fill in the Super Admin name / user ID / email / password, run the installer.
   It creates the `ticket_db` database, all tables, four default departments and your Super Admin account.
4. **Delete the `install/` folder.**
5. Sign in at <http://localhost/ticket-system/login.php>.

If you prefer phpMyAdmin: create a database called `ticket_db` and import `install/schema.sql`, then run the installer to seed the
Super Admin.

**Already running an older copy?** Open <http://localhost/ticket-system/install/migrate.php> once. It adds the workflow columns
(`assigned_at`, `completed_at`, `acknowledged_at`, `reopen_count`) and the `ticket_work_logs` table, backfills your existing
tickets, and is safe to run more than once. Delete `install/` afterwards.

## Moving to cPanel later

Edit `includes/config.php` only:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpaneluser_ticket_db');
define('DB_USER', 'cpaneluser_ticket');
define('DB_PASS', 'your-password');
define('BASE_URL', '');           // '' if it sits at the domain root
ini_set('display_errors', '0');   // turn errors off on live
```

Then upload the files, import `install/schema.sql` through phpMyAdmin, and make sure `uploads/` is writable (755).

## Roles

Accounts are **never self-registered** — the Super Admin creates every one under `users.php`.
Sign in with the **user ID** (not the email) issued to you.

Each account also belongs to a **department** (set on creation, or changed inline from the Users table). Departments group
people in the daily-task reports, so the Super Admin can read a whole team's sheet at once.

| Role | Can do |
|---|---|
| **Super Admin** | Everything: create Admin / IT / Employee accounts, set their department, reset passwords, assign tickets to IT staff, manage departments, delete tickets, raise tickets. **Keeps no task sheet** — reads everyone's in Reports |
| **Admin** | Read-only reports across every employee — tickets raised / assigned, daily task entries and hours, filtered by department and person. No editing anywhere |
| **Employee** | Raise tickets, acknowledge (or reject) completed work on their own tickets, fill the daily task sheet |
| **IT** | See tickets assigned to them, move the status along, reply, add internal notes, log the work they did, fill the daily task sheet |

Only the Super Admin assigns a ticket, and the assignee list contains **IT accounts only**.

## Ticket lifecycle

```
Employee raises a ticket           status: Open      (no priority - they cannot set one)
        v
Super Admin / Admin see it in "Waiting to be assigned" on the dashboard
        v
Super Admin assigns it — straight from the dashboard queue, or inside the ticket — AND sets the priority
        v
IT person sees it on their dashboard, works it, logs their work
        v
IT hits "Resolve" on the ticket list and writes the resolution
                                 status: Completed - the resolution is posted to the
                                 thread, and the requester is asked to acknowledge
        v
   +-----------------------------+---------------------------------+
   | Requester: Acknowledge      | Requester: "Raise again"        |
   v                             v
status: Closed                   status: Open, UNASSIGNED, reopen_count + 1
(a closed ticket can still be    -> back in the Super Admin's "Waiting to be
 raised again by its requester)     assigned" queue, to be handed out afresh
```

Who may do what, enforced on the server as well as in the UI:

| Step | Employee | IT | Admin | Super Admin |
|---|---|---|---|---|
| Set priority when raising | no | – | – | no |
| Assign / **re-assign** to IT | no | no | no | **yes** |
| Set priority | no | no | no | **yes** |
| Change status to Open / In Progress / Completed | no | **yes** | no | **yes** |
| Close the ticket | **by acknowledging** | no | no | no |
| Re-raise it ("not resolved") | **yes, their own** | no | no | – |
| Write the resolution | no | **yes, when assigned** | no | **yes** |
| Log work on a ticket | no | **yes, their own** | reads it | **yes** |
| Keep a daily task sheet | **yes** | **yes** | no | no |
| Export own sheet (CSV / PDF) | **yes** | **yes** | no | no |
| Read everyone's task sheets | no | no | **yes** | **yes** |

If the first IT person is unavailable, the Super Admin simply picks another name in the **Assignee** dropdown — the ticket moves
off the first person's dashboard and onto the new one, and the change is written to the activity trail.

## Features

- User-ID sign-in with hashed passwords, CSRF tokens on every form, session-fixation protection on login
- Four roles (Super Admin / Admin / IT / Employee) enforced per page and per query — no self-registration
- **Closing a ticket writes itself into the task sheet**: when the requester acknowledges, an entry is added for the
  assignee — ticket code and subject as the title, hours summed from the work they logged on that ticket in the current
  assignment round. Marked "From ticket" and linked back; no same-day duplicate if a ticket is closed twice
- **Export the task sheet** as Excel (CSV) or PDF for whatever date range is on screen; the range is printed inside both files
- Daily task sheet built around **one date picked at the top** and a **Quick Add** line — type the task, pick hours from a
  dropdown (0.25 to 4, or Custom), pick a status, Add. Details and category are optional behind "+ Add details", so the
  common case is three fields and Enter
- Added tasks appear immediately as **drafts** in the day's list, kept in the browser so a refresh does not lose them, and
  **Submit All Tasks** writes the batch in one request. The button is disabled until there is something to submit
- Compact **summary cards** (Completed / In Progress / Pending / Total Logged) and a running total that update live as tasks
  are added, edited or removed
- Each row is a single line — status dot, title, category, hours, a status dropdown that saves on change, and a ⋮ menu with
  Edit / Add Details / Duplicate / Delete. **Editing happens inline**, never on another page
- Sort the day by Latest, Oldest, Hours or Status; a friendly empty state when the day is blank
- **Export the selected day** straight from the Daily Tasks card as Excel (CSV) or PDF; the history section below keeps its own
  export for a whole date range
- Four task statuses — Completed, In Progress, Pending, Blocked — each with its own colour
- Reports cover everyone who keeps a sheet — **Employees and IT** — filtered by **department** and **person**, with tickets
  raised, tickets assigned, task entries and hours, an "All task entries" feed for the whole department, and a drill-down
  into any one person's sheet. Super Admin and Admin do not keep sheets of their own
- Tickets with auto codes (`TKT-26-0001`), four statuses, four priorities, departments, assignment
- Assign -> complete -> acknowledge workflow: only the Super Admin assigns and grades, only the requester closes
- **Resolve from the ticket list**: the assignee gets a Resolve button on each of their rows, opening a dialog for the
  resolution text; submitting posts it to the thread and sets the ticket to Completed
- Requesters acknowledge finished work, or send it straight back with a reason, both inline on the ticket list;
  a re-raised ticket is unassigned and returns to the Super Admin's queue to be handed out again
- Per-ticket work log — the assignee records what they did, with hours, kept out of the conversation the requester sees
- Threaded replies, staff-only internal notes, file attachments (5 MB, extension-allowlisted, served through `download.php` so uploads are never executed)
- Per-ticket activity trail (assignment, priority, status, completion, acknowledgement and re-raises)
- Role-aware dashboard queue: unassigned tickets for the Super Admin / Admin, your open work for IT, work awaiting your sign-off for employees
- **Assign without opening the ticket**: every row in the Super Admin's "Waiting to be assigned" queue carries a priority
  selector and a type-to-search IT picker (filter by name or user ID, arrow keys + Enter, Send). Send stays disabled until a
  real person is chosen, and the server re-checks that the target is an active IT account before it writes anything
- Search + filter + pagination on the ticket list; dashboard with counts and a status breakdown
- Light, responsive UI (slate + teal) with a collapsible sidebar, accessible focus rings and accent-coloured native controls

## Layout

```
includes/   config.php · db.php · functions.php · upload.php · pdf.php   (blocked from the web)
layout/     header.php (sidebar + nav) · footer.php
install/    index.php (installer) · migrate.php (upgrade) · schema.sql  (delete after installing)
uploads/    attachments — PHP execution disabled via .htaccess
*.php       the pages
```

## Notes

- Tailwind loads from the CDN, which is fine for XAMPP and small deployments. For production you can swap the
  `<script src="https://cdn.tailwindcss.com">` line in `layout/header.php` for a compiled stylesheet in `assets/`.
- The palette lives in the Tailwind utility classes themselves: surfaces are `bg-white` / `bg-slate-50` on `border-slate-200`,
  and the accent is `teal-600` (hover `teal-700`). To re-brand, swap `teal` for another Tailwind hue across the `.php` files;
  the page wash and scrollbar colours are in the `<style>` block of `layout/header.php`.
- Set `date_default_timezone_set()` in `includes/config.php` to your timezone (currently `Asia/Kathmandu`).
- PDF export is generated by `includes/pdf.php`, a small dependency-free writer (no Composer, nothing to install). It uses the
  Helvetica core font, which is Latin-1 only — non-Latin characters in a task title are transliterated to ASCII in the PDF.
  The CSV export is UTF-8 with a BOM and keeps them intact, so use CSV if you log tasks in a non-Latin script.
- Email notifications are not wired up — add `mail()` or PHPMailer calls in `ticket-new.php` and `ticket-view.php` where
  `log_activity()` is called. The natural hooks are the `assigned`, `completed` and `acknowledged` activity actions.
- A reply no longer re-opens a completed ticket on its own; the requester does that deliberately with the
  **Not resolved** button, so "completed" always reflects a real decision.
