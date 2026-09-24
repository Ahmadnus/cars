# نظام إدارة مركز تدريب القيادة

### Driving Training Center Management System

A production-grade management platform for a driving training center: trainees,
trainers, lessons, scheduling, vehicles, payroll, trainer compensation, revenue,
expenses, cashbox, profit & loss, reporting, and a full audit trail.

The Arabic RTL admin dashboard is delivered. The Laravel backend and MySQL
schema were designed from the start to serve the Trainer and Trainee Flutter
apps through the same REST API and the same business rules — the API is built
and tested, and ships with this release.

---

## Contents

1. [Architecture](#architecture)
2. [Technology stack](#technology-stack)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Demo accounts](#demo-accounts)
6. [Development commands](#development-commands)
7. [Queues](#queues)
8. [Scheduler](#scheduler)
9. [Storage](#storage)
10. [Roles and permissions](#roles-and-permissions)
11. [Business rules that matter](#business-rules-that-matter)
12. [REST API](#rest-api)
13. [Flutter integration](#flutter-integration)
14. [Testing](#testing)
15. [Project structure](#project-structure)
16. [Production deployment](#production-deployment)
17. [Design decisions](#design-decisions)

---

## Architecture

```
                    ┌─────────────────────────┐
                    │   Laravel Blade Admin   │
                    │       Dashboard         │
                    └────────────┬────────────┘
                                 │
                                 ▼
                    ┌─────────────────────────┐
                    │    Laravel Backend      │
                    │                         │
                    │ Services (business      │
                    │   logic, transactions)  │
                    │ Policies + Gates        │
                    │ REST API /api/v1        │
                    │ Sanctum tokens          │
                    │ Notifications, Queues   │
                    │ Scheduler, Reports, PDF │
                    └────────────┬────────────┘
                                 │
                                 ▼
                    ┌─────────────────────────┐
                    │         MySQL           │
                    └─────────────────────────┘
                                 ▲
                  ┌──────────────┴──────────────┐
        ┌─────────┴─────────┐       ┌──────────┴──────────┐
        │ Flutter Trainer   │       │  Flutter Trainee    │
        │       App         │       │        App          │
        └───────────────────┘       └─────────────────────┘
```

**The Laravel backend is the single source of truth.** No financial or
business calculation happens in Blade, in JavaScript, or in Flutter. The
dashboard and the API call the same services.

Authorization is enforced in **three layers**, deliberately:

| Layer | Where | Guards |
|---|---|---|
| Route middleware | `permission:payments.void` | Can this user reach the endpoint at all? |
| Policy | `PaymentPolicy::void()` | May they act on *this specific record*, in *this branch*, in *this state*? |
| Service | `PaymentService::void()` | Is the operation valid as a business action? |

Blade's `@canDo` directive controls only what is *shown*. It is never the only
check.

---

## Technology stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.3+ |
| Admin dashboard | Laravel Blade (server-rendered), Blade components |
| Styling | Tailwind CSS v4 |
| Client interaction | Alpine.js (dropdowns, modals, tabs, toasts only) |
| Charts | Chart.js, mounted declaratively from server-rendered config |
| Database | MySQL 8 (Eloquent ORM, migrations, foreign keys, transactions) |
| Web auth | Laravel session authentication |
| API auth | Laravel Sanctum (bearer tokens) |
| API | Laravel REST API at `/api/v1` |
| PDF | mPDF (chosen for Arabic glyph shaping — see [Design decisions](#design-decisions)) |
| Excel / CSV | OpenSpout (streaming) |
| Mobile | Flutter / Dart (consumes `/api/v1`) |
| Testing | PHPUnit, running against MySQL |

There is no React, Vue, Inertia, or SPA layer. The dashboard is server-rendered
Blade.

---

## Requirements

- PHP **8.3+** with extensions: `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`,
  `bcmath`, `fileinfo`, `openssl`
- MySQL **8.0+** (or MariaDB 10.6+)
- Composer 2
- Node.js 20+ and npm

---

## Installation

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Create the database
mysql -u root -e "CREATE DATABASE driving_center CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

#    Then set DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env

# 4. Schema and data
php artisan migrate --seed

# 5. Storage symlink (avatars and other public files)
php artisan storage:link

# 6. Build the frontend
npm run build       # or: npm run dev  (hot reload during development)

# 7. Serve
php artisan serve
```

Open <http://localhost:8000> and sign in with one of the demo accounts below.

`php artisan migrate --seed` gives you a fully populated center: two branches,
20 trainees on real packages, 4 trainers with different compensation models, 4
vehicles, ~130 lessons across the last month and the next two weeks, payments,
three months of expenses, payroll, and trainer statements. Every one of those
records was created **through the application's own services**, so the seeded
database satisfies every invariant the app enforces.

### Seeding in production

`DatabaseSeeder` only seeds demo records outside production. A production
install runs the structural seeders (permissions, reference data,
organization/branches) and you create your own administrator:

```bash
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan db:seed --class=OrganizationSeeder --force
php artisan tinker    # then create your first admin user
```

---

## Demo accounts

> **Development only.** These are created by `UserSeeder` and are not seeded in
> production. Delete them before going live.

| Role | Email | Password |
|---|---|---|
| مدير النظام (System Administrator) | `admin@example.com` | `password123` |
| مدير المركز (Center Manager) | `manager@example.com` | `password123` |
| محاسب (Accountant) | `accountant@example.com` | `password123` |
| موظف استقبال (Receptionist) | `reception@example.com` | `password123` |
| مشرف تدريب (Training Supervisor) | `supervisor@example.com` | `password123` |
| مدرب (Trainer) | `trainer@example.com` | `password123` |

Sign in as the **receptionist** to see the permission model working: no profit,
no salaries, no cashbox, no trainer compensation — those blocks are not
rendered, and the underlying figures are never even computed.

---

## Development commands

```bash
php artisan serve                 # dev server
npm run dev                       # Vite with hot reload
php artisan queue:work            # process queued notifications and jobs
php artisan schedule:work         # run the scheduler locally
php artisan test                  # full test suite
php artisan migrate:fresh --seed  # rebuild the database from scratch
php artisan finance:verify        # check every balance against its ledger
```

---

## Queues

Notifications, emails and other slow work are queued so they never block a
request. The default connection is `database`.

```bash
php artisan queue:work --tries=3 --timeout=90
```

In production, run the worker under a process supervisor:

```ini
; /etc/supervisor/conf.d/driving-center-worker.conf
[program:driving-center-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/driving-center/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/driving-center/storage/logs/worker.log
stopwaitsecs=3600
```

---

## Scheduler

One cron entry drives everything:

```cron
* * * * * cd /var/www/driving-center && php artisan schedule:run >> /dev/null 2>&1
```

| Command | When | What it does |
|---|---|---|
| `lessons:send-reminders` | hourly | Reminds trainees and trainers about lessons inside the configured notice window |
| `expenses:generate-recurring` | 06:00 daily | Posts due recurring expenses (or flags them for review when `auto_post` is off) |
| `alerts:daily` | 07:00 daily | Low lesson balances, overdue utility bills, unpaid salaries after pay day |
| `finance:verify` | 02:00 daily | Verifies cashbox, lesson, advance and payment balances against their ledgers |

`finance:verify` **reports only — it never repairs.** A mismatch means a bug or
a manual database edit, and both deserve a person. It exits non-zero so it can
gate a deploy or page a monitor.

Check the schedule with `php artisan schedule:list`.

---

## Storage

Two disks, for a reason:

- **`public`** — profile photos. Served directly.
- **`private`** — identity documents, medical reports, invoices, receipts,
  vehicle papers. `serve` is **off**; the web server cannot reach these files.
  Every download goes through `DocumentController`, which checks the policy on
  the *owning record* first: if you may not see the trainee, you may not see
  their identity papers.

Uploads are validated on extension **and** sniffed MIME type, capped at 8 MB,
and stored under a random UUID filename so a guessed URL cannot reach a file
even if the disk were exposed.

For production, set `PRIVATE_FILESYSTEM_DISK=s3` and fill in the AWS variables.
No application code changes.

---

## Roles and permissions

Six system roles ship with the application, and custom roles can be created in
the UI. The permission catalogue lives in one place —
`app/Support/Permissions.php` — which the seeder, the roles screen and the
middleware all read from.

| Role | Sees money? | Notes |
|---|---|---|
| System Administrator | Everything | Bypasses all gates |
| Center Manager | Everything | Full operational and financial access |
| Accountant | Everything financial | No user or role management |
| Receptionist | **No** | Trainees, appointments, bookings only |
| Training Supervisor | No | Trainers, trainees, lessons, evaluations, vehicles |
| Trainer | No | Only their own assigned trainees and lessons |

Sensitive permissions (money, salaries, personal documents) are flagged in the
catalogue and called out in the roles UI.

**Per-user overrides** sit on top of role grants, for the one-off case where
someone needs slightly more or less than their role: grant or revoke a single
permission from the user edit screen.

### Multi-branch access

Every branch-scoped model uses the `BelongsToBranch` trait and its
`visibleTo()` scope. A user's reachable branches come from their own grants,
never from the request. The branch selector in the header narrows what is
already permitted — it cannot widen it, and `BranchContext::set()` rejects any
branch the user has no grant for.

---

## Business rules that matter

These are the invariants the system is built to protect. Each has tests.

**Lesson balances are a ledger, not a number.** `lesson_transactions` is
append-only with signed quantities; the remaining balance is always
`SUM(quantity)`. Nothing overwrites a balance. A debit takes a row lock on the
enrolment first, so two concurrent lesson completions cannot both pass the
"enough lessons left" check. Balances cannot go negative unless a center
explicitly opts in.

**Completing a lesson is one transaction.** Validate authorization → validate
state → validate balance → record completion → deduct one lesson → write the
ledger row → record the evaluation → roll skill levels forward → update trainee
progress → notify → audit. All of it, or none of it.

**The cashbox always equals its ledger.** Every posting locks the cashbox, reads
the balance, appends an immutable line carrying the resulting balance, and
writes the new balance back — inside the caller's transaction. Corrections are
posted as reversing rows; nothing is ever edited or deleted. `finance:verify`
checks this nightly.

**Financial records are voided, never deleted.** Voiding a payment reverses the
cash movement and the enrolment's paid amount while keeping the original row and
its receipt number. Cancelling an expense reverses its cash movement the same
way. Expenses generated by payroll or trainer payouts are marked
system-generated and can only be changed from the module that created them.

**Advances cannot be over-collected.** Each installment is capped by both the
advance's remaining balance and what is left of the salary. Recalculating a
payroll releases that month's collection first, so a second run cannot
double-deduct.

**Appointment conflicts are impossible.** Trainer, trainee and vehicle are each
checked independently for overlap, plus branch working hours and the trainer's
own schedule. The UI pre-checks availability for a nicer experience; the server
re-runs every check on write and does not trust the client.

**Compensation rules are versioned, not edited.** Changing a trainer's pay model
closes the old rule and opens a new one with an effective date, so a historical
statement always reproduces from the terms that actually applied.

**Package terms are copied at enrolment.** Later catalogue price changes never
rewrite a signed contract, and a price change is separately audited.

**Profit comes from transactions.** `ProfitService` sums completed payments and
recorded expenses directly. It never reads a figure displayed elsewhere. The
same method backs the screen, the API and the PDF, so the three cannot
disagree.

---

## REST API

Base URL: `/api/v1` — 60 endpoints. Every response uses the same envelope:

```json
{
  "success": true,
  "message": "تم تنفيذ العملية بنجاح",
  "data": {},
  "meta": {}
}
```

```json
{
  "success": false,
  "message": "لا يمكن تنفيذ العملية",
  "errors": { "amount": ["قيمة الدفعة أكبر من المبلغ المتبقي."] }
}
```

Error responses are built centrally in `bootstrap/app.php`, including a
catch-all for `abort()`, so *every* failure carries this shape. Stack traces,
SQL and internal details are never exposed.

### Authentication

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "trainer@example.com", "password": "password123", "device_name": "Pixel 8" }
```

Returns a bearer token plus the user and their effective permissions. Send it as
`Authorization: Bearer <token>` on every subsequent request.

Token abilities mirror the user's permissions, so a stolen trainer token cannot
reach an accountant's endpoints. Logging in again from the same `device_name`
replaces that device's token rather than accumulating stale ones.

### Endpoints

| Area | Endpoints |
|---|---|
| Auth | `POST auth/login`, `GET auth/me`, `POST auth/logout`, `POST auth/logout-all`, `POST auth/change-password` |
| Home | `GET dashboard`, `GET home/trainer`, `GET home/trainee` |
| Trainees | `GET/POST trainees`, `GET/PATCH trainees/{id}`, `GET trainees/{id}/sessions`, `GET trainees/{id}/packages`, `GET trainees/{id}/evaluations` |
| Trainers | `GET trainers`, `GET trainers/{id}`, `GET trainers/{id}/schedule`, `GET vehicles` |
| Lessons | `GET/POST appointments`, `GET appointments/today`, `GET appointments/available-slots`, `GET/PATCH appointments/{id}`, `POST appointments/{id}/complete`, `POST appointments/{id}/cancel`, `POST appointments/{id}/no-show` |
| Bookings | `GET/POST booking-requests`, `GET booking-requests/{id}`, `POST booking-requests/{id}/withdraw` |
| Finance | `GET/POST payments`, `POST payments/{id}/void`, `GET/POST expenses`, `GET payroll`, `GET cashbox` |
| Reports | `GET reports/revenue`, `GET reports/expenses`, `GET reports/profit` |
| Notifications | `GET notifications`, `POST notifications/{id}/read`, `POST notifications/read-all`, `POST notifications/device` |
| Skills | `GET training-skills` |

`/appointments` and `/training-sessions` are the **same resource** under two
prefixes — see [Design decisions](#design-decisions).

### API conventions

- **IDs are UUIDs.** Internal auto-increment keys are never exposed, so record
  counts do not leak.
- **Pagination** via `?page=` and `?per_page=` (capped at 100). Details are in
  `meta`: `current_page`, `last_page`, `per_page`, `total`, `has_more`.
- **Filtering and sorting** via query parameters, e.g.
  `?status=scheduled&from=2026-01-01&to=2026-01-31&sort=full_name&direction=asc`.
- **Rate limiting** on login (10/minute) and the default API throttle elsewhere.
- **Scoping is automatic.** A trainer's token only lists their own lessons and
  trainees; a trainee's token only lists their own lessons, payments and
  requests. This is applied server-side — the client cannot widen it.
- **Permission-aware payloads.** A field the caller may not see is not
  redacted, it is *absent*: `TraineeResource` omits the `financial` block
  entirely unless the caller holds `trainees.financial` or is that trainee.

---

## Flutter integration

The backend is ready for both apps. **Flutter must never connect to MySQL
directly** — the path is always `Flutter → /api/v1 → services → MySQL`.

### Trainer app

Home screen: `GET /api/v1/home/trainer` returns today's lessons, upcoming
lessons and month statistics in one call.

Then: `GET appointments/today`, `GET trainees/{id}`,
`GET trainees/{id}/evaluations`, `GET training-skills`, and the main write —
`POST appointments/{id}/complete` with the evaluation and per-skill ratings.
That single call consumes the lesson, records the evaluation, rolls the
trainee's skill levels forward, notifies and audits.

### Trainee app

Home screen: `GET /api/v1/home/trainee` returns the current package, lessons
remaining and completed, the assigned trainer, upcoming lessons, progress and
outstanding balance.

Then: `GET appointments`, `GET payments`, `GET trainees/{id}/evaluations`, and
`POST booking-requests` to request a booking, reschedule or cancellation. A
request never changes the schedule by itself — it lands in the dashboard for
staff to approve, which keeps conflict rules and the lesson ledger under
staff control.

Phone + OTP login is not yet implemented; the login endpoint already accepts
`phone` as an alternative to `email`, and `users.two_factor_confirmed_at`
exists for the OTP rollout.

### Client notes

- Store the token in secure storage (`flutter_secure_storage`), never in
  `SharedPreferences`.
- A `401` means the token was revoked or the account was disabled — clear
  local state and return to login.
- Drive the UI from the `permissions` array returned at login, but treat a
  `403` as authoritative: the server always has the final say.
- `POST /api/v1/notifications/device` accepts a push token today; delivery
  activates when an FCM provider is configured.

---

## Testing

```bash
php artisan test
```

**48 tests, 115 assertions, all passing.** The suite runs against MySQL, not
SQLite — the reporting and payroll queries use MySQL-specific SQL, so an
in-memory SQLite run would not exercise the real code path. Create the test
database once:

```bash
mysql -u root -e "CREATE DATABASE driving_center_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

| Suite | Covers |
|---|---|
| `LessonBalanceTest` | Package credit, consumption, negative-balance guard, double-completion, reopening and refund, ledger reconstruction, no-show policy |
| `AppointmentConflictTest` | Trainer / trainee / vehicle double-booking, partial overlap, back-to-back bookings, cancelled slots freeing up, branch hours, closed days, self-collision on reschedule, unavailable vehicles |
| `FinancialIntegrityTest` | Cash vs non-cash payments, overpayment guard, voiding and its reversal, cashbox-equals-ledger, running balances, daily closing differences, payroll calculation, advance installments and the over-collection guard, recalculation not double-collecting, salary overpayment guard, profit from real transactions, exclusion of voided and cancelled rows |
| `AuthorizationTest` | Guest redirects, receptionist locked out of financial screens, dashboard omitting financial data, cross-branch access, branch-selector tampering, super-admin bypass, deactivated users, per-user grants and revocations |
| `ApiTest` | Token lifecycle, account enumeration resistance, inactive accounts, response envelope, validation and 404 shapes, pagination metadata, trainer/trainee data scoping, booking requests and their ownership check |
| `RouteSmokeTest` | Walks **every** GET route in the dashboard with real data and asserts none errors |

---

## Project structure

```
app/
├── Console/Commands/      Scheduled maintenance and alert commands
├── Exceptions/            BusinessRuleException (Arabic, renders to web + API)
├── Http/
│   ├── Controllers/
│   │   ├── Admin/         Dashboard controllers (HTTP only)
│   │   ├── Api/V1/        REST API controllers
│   │   └── Auth/          Login, password reset
│   ├── Middleware/        EnsurePermission, EnsureUserIsActive, SetLocale
│   ├── Requests/          Form Requests: validation + authorization
│   └── Resources/         API resources (permission-aware serialization)
├── Models/
│   └── Concerns/          HasUuid, BelongsToBranch
├── Notifications/         SystemNotification (queued)
├── Policies/              Per-record authorization, all extending BasePolicy
├── Providers/             AppServiceProvider, AuthServiceProvider
├── Services/              ← all business logic lives here
│   ├── Notifications/     ChannelGateway seams for SMS / WhatsApp / push
│   └── Reports/           ReportDefinition + ReportRegistry
└── Support/               Permissions catalogue, BranchContext, helpers

database/
├── factories/             Model factories for tests
├── migrations/            7 migrations, ~40 tables
└── seeders/               Permissions, reference data, organization, users, demo

resources/
├── css/app.css            Tailwind theme and design tokens
├── js/                    Alpine bootstrap, chart mounting, toast bus
└── views/
    ├── admin/             Dashboard pages
    ├── auth/              Login, password reset
    ├── components/        Blade component library (ui, form, layout)
    ├── layouts/           App shell and guest shell
    └── pdf/               Arabic RTL PDF templates

routes/
├── web.php                Dashboard routes (163)
├── api.php → api_v1.php   Versioned API (60)
└── console.php            Scheduler definition
```

### Services

| Service | Responsibility |
|---|---|
| `TraineeBalanceService` | The only writer of the lesson ledger |
| `AppointmentService` | Booking, rescheduling, cancellation, conflict detection, availability |
| `TrainingSessionService` | Lesson completion, evaluation, absence, reopening |
| `PackageService` | Enrolment, extra lessons, discounts, catalogue pricing |
| `PaymentService` | Receiving and voiding trainee payments |
| `ExpenseService` | The expense ledger, recurring expenses, utility bills |
| `PayrollService` | Salaries, advances, installment collection, disbursement |
| `TrainerCompensationService` | Five pay models, statements, payouts, rule versioning |
| `CashboxService` | The only writer of the cash ledger |
| `ProfitService` | P&L computed from actual transactions |
| `ReportService` + `ReportRegistry` | Declarative reports with shared execution and export |
| `DashboardService` | Permission-aware dashboard assembly |
| `NotificationService` | Event routing and channel selection |
| `DocumentService` | Secure private file storage |
| `PdfService` | Server-side Arabic PDF generation |
| `AuditLogger` | The only writer of the audit trail |

---

## Production deployment

```bash
# Dependencies, production mode
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Environment
# APP_ENV=production
# APP_DEBUG=false
# SESSION_SECURE_COOKIE=true
# PRIVATE_FILESYSTEM_DISK=s3

php artisan migrate --force
php artisan storage:link

# Cache everything
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Then:

- Point the web root at `public/`, never the project root.
- Add the scheduler cron entry (see [Scheduler](#scheduler)).
- Run the queue worker under supervisor (see [Queues](#queues)).
- Serve over HTTPS. The app forces the HTTPS scheme when `APP_ENV=production`.
- Delete the demo accounts.

In production, password rules tighten automatically to a 10-character minimum
with letters, numbers, symbols, and a breach check against Have I Been Pwned.

### Security checklist

- [x] Passwords hashed; never returned by any endpoint
- [x] Session and token authentication, with per-account lockout after 5 failed
      attempts and per-IP rate limiting
- [x] CSRF protection on all dashboard forms
- [x] SQL injection prevented by Eloquent and parameter binding throughout
- [x] XSS prevented by Blade escaping; no `{!! !!}` on user input
- [x] Private documents behind authenticated, policy-checked routes
- [x] Uploads validated on extension *and* sniffed MIME type, size-capped,
      stored under random names
- [x] Permissions enforced in middleware, policies and services
- [x] Branch access enforced server-side, never by UI filtering
- [x] Financial data absent from payloads the caller may not see
- [x] Sensitive operations audited with before/after snapshots, actor, IP and
      user agent
- [x] No secrets in source control; everything through `.env`
- [x] Errors return safe Arabic messages; no stack traces or SQL exposed

---

## Design decisions

Where the brief left room for judgement, here is what was chosen and why.

**Appointments and training sessions are one table.** The brief listed both.
They describe the same real-world object at two lifecycle stages — a booked
lesson and a delivered one — and splitting them would have put the conflict
invariant across two sources of truth, with a synchronisation bug waiting in
between. There is one `training_sessions` table. The calendar and the
`/api/v1/appointments` endpoints are scheduling-focused projections of it, so
both vocabularies still work for the two apps.

**UUIDs alongside auto-increment keys.** Every public-facing model has both: a
bigint primary key for index locality and foreign keys, and an indexed UUID used
in URLs and API payloads. Full UUID primary keys would hurt MySQL index
performance on the tables that grow fastest; exposing sequential IDs would leak
record counts. This gets both.

**mPDF rather than dompdf.** dompdf renders Arabic as disconnected,
unshaped letters. mPDF handles Arabic glyph shaping and RTL page direction
natively. For an Arabic-first product that is a correctness requirement, not a
preference.

**Custom RBAC rather than a package.** The brief specified `role_permissions`
and `user_roles` tables and a granular permission list. A small first-party
implementation matches that schema exactly, adds per-user overrides cleanly, and
keeps the permission catalogue in one readable file.

**Expense categories map to fixed P&L buckets.** A center can rename or add its
own categories freely, but each maps to one of eight fixed profit buckets, so
the P&L keeps the same shape month to month and across branches.

**Reports are declared, not written.** A report is a `ReportDefinition` —
columns, the permission that guards it, and a query closure. `ReportService`
handles filtering, branch scoping, totals, pagination and PDF/Excel/CSV export
once. Adding a report means adding an entry to `ReportRegistry`.

**External notification channels are seams, not stubs.** In-app notifications
work now. SMS, WhatsApp and push sit behind the `ChannelGateway` interface with
a logging implementation, so the whole flow is exercised and testable today and
a real provider is a service-provider binding away. They fail soft by design: a
dead SMS provider must never roll back the business operation that raised the
notification.

**Tests run on MySQL.** The reporting and payroll queries use `DATE_FORMAT` and
boolean `SUM` aggregates. Running the suite on SQLite would test different SQL
than production runs.

---

## Status

**Delivered:** the complete Laravel backend, MySQL schema, Arabic RTL admin
dashboard, and the REST API with Sanctum authentication — Phases 1–7 of the
brief.

**Next:** Phase 8 (Trainer Flutter app) and Phase 9 (Trainee Flutter app). Both
consume the API documented above; no backend work is required to begin either.
