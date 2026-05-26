# PW Platform — Frontend

A PHP/MySQL + vanilla JS web application for managing practical work (PW) session reservations in an academic setting. Three role-based dashboards share one CSS design system.

---

## File Structure

```
frontend/
├── pages/
│   ├── admin.html      — Admin dashboard
│   ├── teacher.html    — Teacher dashboard
│   └── student.html    — Student dashboard
├── css/
│   └── style.css       — Shared design system
└── _write.php          — PHP helper (backend bridge)
```

---

## Roles & Pages

### Admin (`pages/admin.html`)

Full control over the platform.

**Overview / Dashboard**
- Live stat cards: total students, teachers, PW sessions, reservations, pending approvals
- Horizontal accordion showing all users grouped by role (Students / Teachers / Admins / Pending), each group in its own collapsible column
- Pending accounts shown inline with Approve / Reject buttons

**Academic Levels** (`Management > Academic Levels`)
- Add new academic levels (e.g. L1, L2, Ing1, M1) with optional description
- List and delete existing levels

**Users** (`Management > Users`)
- Add Admin or Teacher accounts directly (bypasses registration)
- View all users in a sortable table — click Name, Role, or Level column headers to sort ascending/descending
- Toggle user active/inactive status
- Delete users with confirmation modal

**Pending Approvals** (`Management > Pending Approvals`)
- Live badge on sidebar link showing pending count
- Table of self-registered accounts awaiting approval
- Approve modal: assign role (Student or Teacher) and academic level, then confirm
- Reject (delete) with confirmation

**All PW Sessions** (`Management > All PW Sessions`)
- Horizontal accordion — one column per academic level
- Each column lists PW sessions for that level: title, teacher, slot count, reservation count, status
- Enable/disable or delete any PW session from here

---

### Teacher (`pages/teacher.html`)

Manage personal PW sessions and time slots.

**My PW Sessions**
- All own PW sessions displayed as interactive circles
- Circle shows: title, level, reserved/total slot ratio
- Inactive sessions appear greyed out
- Click a circle to open a detail modal (status, slots, dates, description)
- Modal actions: go to Manage Slots, or Delete PW

**Create New PW**
- Form fields: Title, Description, Academic Level (dropdown), Open From date, Open Until date
- On success, auto-redirects to My PW Sessions after 1.2 s

**Manage Slots** (reached from circle detail modal)
- Add 2-hour time slots: pick a date and a start time (08:00 / 10:00 / 12:00 / 14:00 / 16:00)
- Slots table: date, start time, end time, reserved-by student
- Free slots: Delete button
- Reserved slots: Force Free button (cancels student reservation)

---

### Student (`pages/student.html`)

Browse and reserve PW session time slots.

**Available PW Sessions**
- All active PW sessions for the student's academic level shown as circles
- Circle shows: title, teacher name, number of free slots
- Already-reserved sessions highlighted with a green border
- Click a circle to open slot selection modal

**Slot Reservation Modal**
- Lists all free slots for the selected PW session (date + time range)
- Click Reserve (or the row) and confirm to book a slot
- After booking, automatically checks access and redirects to Waiting Room or Access Granted

**Waiting Room**
- Shown when a reservation exists but the session time has not started yet
- Large countdown circle (HH:MM:SS) with animated pulse border
- Progress bar that fills as start time approaches
- Slot info box with exact date and time range
- Auto-redirects to Access Granted when countdown reaches zero

**Access Granted**
- Shown when it is currently the student's reserved time slot
- Green check icon, session end time display
- Live countdown (MM:SS) until the session ends
- Alert and return to Available PW when time expires

**My Reservations**
- Table of all past and upcoming reservations: PW title, date, time, status
- Status shows "Completed" for past active reservations
- Cancel button for future active reservations

---

## Design System (`css/style.css`)

Built with CSS custom properties (no Tailwind, no SCSS).

| Token | Value | Use |
|---|---|---|
| `--primary` | `#3b9ed9` | Buttons, links, active states |
| `--primary-light` | `#e8f4fc` | Backgrounds, hover fills |
| `--success` | `#4caf80` | Active badges, reserved circles |
| `--danger` | `#e05c5c` | Delete actions |
| `--warning` | `#e09a3b` | Pending badge |

**Components**
- Fixed top navbar with brand + user info + role badge + logout
- Fixed 220px left sidebar with section groups and active indicator
- `.card-custom` — white card with shadow and bottom margin
- `.stat-card` — large centered number above a label
- `.pw-circle` — 140×140 px circle for PW session display; hover scales up; `.inactive` greyed; `.my-reservation` / `.reserved` green border
- `.table-custom` — full-width, header highlighted in primary-light, row hover
- `.badge-admin/teacher/student/active/inactive` — pill badges
- `.btn-main` / `.btn-secondary-custom` / `.btn-danger-custom`
- `.modal-overlay` + `.modal-box` — centered popup with fade/slide-in transition
- `.countdown-circle` — pulsing circle for waiting room
- `.loading-spinner` — CSS border animation
- `.alert-success` / `.alert-error` / `.alert-info` — left-bordered alerts
- Responsive breakpoint at 768 px: sidebar hides, main content full-width, circles shrink to 110 px

---

## API Integration

All pages communicate with `../../backend/api/` via `fetch()`.

| Endpoint | Used by |
|---|---|
| `auth.php?action=check` | All pages — session/role guard on load |
| `auth.php?action=logout` | All pages — logout button |
| `admin.php?action=*` | Admin dashboard |
| `teacher.php?action=*` | Teacher dashboard |
| `student.php?action=*` | Student dashboard |

Requests use `Content-Type: application/json` POST bodies or query-string GET parameters. All responses return `{ success: bool, message?: string, ... }`.

---

## Tech Stack

- HTML5 / CSS3 (custom properties, flexbox, CSS animations)
- Vanilla JavaScript (no framework)
- Bootstrap 5.3 (CDN) — grid and utility classes only
- PHP sessions for authentication (handled server-side)
