# Attendance Mobile API (v1)

> Merged into the ticket system: one database (`ticket_db`), one `users` table,
> the same logins as the web app. Tables it needs are created by
> `install/hrms_merge.php`.

REST/JSON API for the mobile app. The PHP server keeps all the rules — the app
only sends and renders data.

**Base URL:** `http://<your-server>/ticket-system/api/v1`

Every endpoint works two ways, so the app runs with or without `mod_rewrite`:

```
GET /ticket-system/api/v1/attendance/today
GET /ticket-system/api/v1/index.php?route=attendance/today
```

`GET /ticket-system/api/v1/` returns the live endpoint index.

---

## Response envelope

Success:

```json
{ "success": true, "meta": { "page": 1, "per_page": 50, "total": 120, "total_pages": 3 }, "data": { } }
```

Failure — the HTTP status carries the class of problem, `code` is stable for
the app to branch on, `message` is safe to show to the user:

```json
{ "success": false, "code": "already_punched_in", "message": "You are already punched in at 09:14:02. Please punch out first." }
```

| Status | Meaning |
|---|---|
| 401 | `unauthenticated`, `invalid_token`, `token_expired`, `invalid_credentials` → send the user to the login screen |
| 403 | `forbidden`, `account_inactive`, `outside_geofence` |
| 404 | `not_found` |
| 409 | conflict: `already_punched_in`, `not_punched_in`, `overlapping_leave`, `duplicate` |
| 422 | `validation_error`, `selfie_error`, `weak_password` |
| 500 | `db_error`, `server_error` |

## Authentication

`POST auth/login` returns a 64-character token stored in `auth_tokens`. Send it
on every other call:

```
Authorization: Bearer <token>
```

Clients that cannot set headers may pass `token=<token>` in the body or query
instead. Tokens last 30 days and the expiry slides forward on each use, so an
active user is never signed out mid-shift. Only `auth/login` and `meta/health`
are open.

```bash
curl -X POST http://localhost/ticket-system/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"phone":"9812345670","password":"secret","device_info":"Pixel 8 / Android 14"}'
```

`phone` also accepts the employee code or email address. `must_set_password` in
the response is `true` for accounts that still have an admin-assigned password —
send the user to the change-password screen.

---

## Endpoints

### auth
| Endpoint | Body / query |
|---|---|
| `POST auth/login` | `phone`, `password`, `device_info?` |
| `POST auth/logout` | `all=1` to sign out every device |
| `GET  auth/me` | current user + today's punch state |
| `GET  auth/devices` | active sessions |
| `POST auth/change_password` | `current_password`, `new_password` |

### profile
| Endpoint | Body / query |
|---|---|
| `GET  profile` | full profile, bank details, tracking settings |
| `POST profile/update` | `email`, `alternate_number`, `address`, `family_member_name` |
| `POST profile/photo` | `image` (base64 or data URI) |
| `GET  profile/salary` | salary structure |

Profile editing is deliberately narrow — role, department, salary, geofence flag
and employment status are admin-only.

### attendance
| Endpoint | Body / query |
|---|---|
| `GET  attendance/today` | punches, `is_punched_in`, `can_punch_in`, `can_punch_out` |
| `POST attendance/punch_in` | `selfie_image`, `latitude`, `longitude`, `accuracy` (metres, ≤ 50) |
| `POST attendance/punch_out` | `selfie_image`, `latitude`, `longitude`, `accuracy` (metres, ≤ 50) |
| `GET  attendance/history` | `month=YYYY-MM` or `from`/`to`, `page`, `per_page` |
| `GET  attendance/summary` | day-by-day calendar + month stats |
| `POST attendance/track` | `latitude`, `longitude`, `accuracy?`, `address?` |
| `GET  attendance/route` | `punch_in_id` → tracked points + distance |

```bash
curl -X POST http://localhost/ticket-system/api/v1/attendance/punch_in \
     -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"selfie_image":"data:image/jpeg;base64,/9j/4AAQ...","latitude":22.5507,"longitude":88.3992,"accuracy":12.5}'
```

`attendance/summary` classifies each day as `Present`, `Absent`, `Leave`, `OD`,
`Comp Off`, `Holiday`, `Week Off` or `Upcoming`, and returns month totals
including `payable_days`.

### leave
| Endpoint | Body / query |
|---|---|
| `GET  leave/types` | leave types + yearly allowance |
| `GET  leave/list` | `status?`, `page` |
| `POST leave/apply` | `leave_type`, `start_date`, `end_date`, `reason` |
| `POST leave/cancel` | `id` (pending only) |
| `GET  leave/balance` | `year?` → allowed / used / balance |
| `GET  leave/od` | OD records for a range |
| `GET  leave/comp_off` | comp-off records for a range |

### employees & master data
| Endpoint | Body / query |
|---|---|
| `GET employees` | `search`, `department`, `company`, `location`, `status`, `page` |
| `GET employees/show` | `id` |
| `GET employees/on_duty` | who is punched in right now |
| `GET master` | every reference list in one call — cache this at login |
| `GET master/departments` \| `companies` \| `locations` \| `shifts` | individual lists |
| `GET master/office` | geofence centre, radius, and whether this user is restricted |
| `GET master/holidays` | `year?`, `project?` |
| `GET master/policy` | half-day / full-day thresholds |

### admin
Read endpoints allow `admin`, `superadmin` and `hr`; every write is
`admin` / `superadmin` only. (Roles are the ticket system's: the old `suparadmin`
is now `superadmin`.)

| Endpoint | Body / query |
|---|---|
| `GET  admin/dashboard` | headline counts + per-department breakdown |
| `GET  admin/today` | per-employee status board (`In` / `Out` / `OD` / `Leave` / `Absent`) |
| `GET  admin/attendance` | `from`/`to`, `department`, `search`, `incomplete=1`, `page` |
| `GET  admin/employee_summary` | per-employee monthly totals |
| `GET  admin/leaves` | approval queue, `status?` |
| `POST admin/leave_action` | `id`, `action=approve\|reject`, `notes?` |
| `POST admin/manual_attendance` | `user_id`, `date`, `punch_in`, `punch_out?`, `status?` |
| `POST admin/mark_od` | `user_id`, `date` |
| `POST admin/mark_comp_off` | `user_id`, `comp_off_date`, `earned_date` |
| `POST admin/reset_password` | `user_id`, `new_password` (revokes that user's tokens) |

Admins may add `user_id=` to `attendance/*`, `leave/*` and `profile` to read
another employee's data; employees requesting anyone but themselves get a 403.

---

## Rules the server enforces

- **Punch time is server time.** A device clock can never set a punch time.
- **The attendance day rolls over at 06:00.** A punch at 01:30 belongs to the
  previous date — the same rule the web app uses.
- **One open punch at a time.** Punching in twice returns 409 with
  `open_punch_id`; multiple in/out pairs per day are allowed.
- **GPS must be precise.** Both punches need `latitude`, `longitude` and the
  device's `accuracy` in metres, no worse than `ATT_MAX_ACCURACY_M` (50, in
  `includes/config.php`; also returned as `max_accuracy_meters` by
  `master/office`). Otherwise 422 `location_required` / `location_imprecise`.
  Use high-accuracy GPS and keep the best reading from a few seconds of updates.
- **Geofencing** applies only to users flagged `geo_restricted`, measured
  against `office_settings` with a Haversine distance.
- **Selfies are mandatory** on both punches, validated as real images (max 8 MB)
  and written to the private `uploads/hrms/selfies/Y/m/d/`. Responses return
  signed `files/get` links that work without the bearer header and expire after
  24 hours - fetch fresh data rather than caching the URL.
- **Route distance** is computed from `location_tracking` points on punch out
  and stored in `route_summary`.

## Deployment notes

- `.htaccess` needs `mod_rewrite` for the clean paths, and forwards the
  `Authorization` header (CGI/FastCGI strips it). Without it, use
  `index.php?route=...` — no server change required.
- There is no `RewriteBase`, so the folder works wherever the ticket system is
  deployed.
- CORS is open (`*`) for app clients. Restrict the origin if you also call this
  from a browser front-end.
- Selfies arrive as base64, so keep `post_max_size` at 12 MB or more.
- **Serve this over HTTPS in production** — tokens travel in a header.
