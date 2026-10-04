# HMS Cloud — Multi-Tenant SaaS Hospital Management System

Laravel 12 · Livewire 3 + Volt (class-based) · Spatie Permission (teams) · Herozi Bootstrap 5 theme · MySQL

One installation serves many hospitals. A **Super Admin** onboards hospitals, sells subscription plans and
collects payments; each **hospital** gets its own isolated workspace at `/h/{hospital-slug}` with role-based
access for its staff and a **patient portal**.

---

## Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Local setup (WAMP, `http://localhost/hms`)](#local-setup)
4. [Demo logins & public demo](#demo-logins)
5. [Deploying to the VPS (`http://23.230.253.206/hms`)](#deploying-to-the-vps)
6. [Scheduled jobs & workers](#scheduled-jobs--workers)
7. [Integrations](#integrations)
8. [Tests](#tests)
9. [Project layout](#project-layout)

---

## Features

| Area | Highlights |
|---|---|
| **Super Admin** | Hospitals (create, edit, suspend, archive, “open as hospital admin”), subscription plans with module toggles, renewal invoices, bank-transfer proof verification, platform revenue / MRR / storage analytics, global settings, platform audit log |
| **Hospital admin & RBAC** | Hospital profile & branding, users, **custom roles with per-module permission matrix** (limited to the plan), departments, insurance companies, audit log, subscription & payment proof upload |
| **Patients & EMR** | Quick (walk-in) & full registration with per-hospital **UHID**, duplicate detection, ID card PDF, vitals with trends & BMI, SOAP notes, ICD-10 diagnoses, allergies, medical history, documents |
| **Appointments & queue** | Doctor weekly schedules & leaves → free-slot booking, check-in → OPD token, live token board (`wire:poll`), public TV display |
| **Doctor portal / OPD** | Doctor workspace, consultation screen, **e-prescriptions routed to the pharmacy**, lab & imaging orders routed to diagnostics, automatic follow-up booking |
| **IPD** | Real-time bed matrix, admission, bed transfers, charges, nurse station with abnormal-vitals alerts, discharge with consolidated final bill & discharge summary PDF |
| **Pharmacy** | POS with barcode scan, **FEFO batch deduction**, prescription dispensing, IPD credit, returns, medicine master, batches/expiry, stock adjustments & movements, purchase orders + goods receiving, re-order alerts |
| **Laboratory** | Test catalog with gender-specific & critical ranges, sample collection with **barcode labels**, result entry with live H/L flags, approval, **PDF report with signature image + QR verification** (+ optional PKCS#12 digitally signed PDF), QC log with trend chart, analyzer **device API** |
| **Radiology** | Imaging catalog & worklist, scheduling, DICOM/image upload, radiologist reports, PACS viewer link (Study Instance UID) |
| **Billing & finance** | Unified invoices (OPD, IPD, pharmacy, lab, radiology, OT, blood bank, services), payments & refunds, TPA cover & **insurance claims**, expenses, financial dashboard, ageing, doctor revenue, **tax/GST report CSV** |
| **HR & payroll** | Staff directory (optional login creation), shifts & weekly roster, attendance, attendance-aware payroll, **doctor commission / fee splitting**, payslips |
| **OT & blood bank** | OT scheduling with room-conflict check, surgical team, WHO-style pre/post-op checklists, charges to IPD bill; donors (90-day rule), TTI screening, component inventory, ABO/Rh-aware **cross-match** & issue |
| **Telemedicine & portal** | Jitsi video rooms per appointment (Agora-ready), patient portal: appointments booking, reports & prescriptions download, history & vitals trend, bills with bank-transfer reporting, profile — login by **email/password or UHID/phone + OTP** |

All screens are Livewire/Volt components: tables, filters, modals, searchable dropdowns and pagination update
without page reloads, and navigation uses `wire:navigate` (SPA-style) with the Herozi header & sidebar persisted.

## Architecture

**Tenancy (single database).** Every tenant table has `hospital_id`. Models using
`App\Models\Concerns\BelongsToHospital` get a global scope (`HospitalScope`) and are force-stamped with the
active hospital on create. The scope **fails closed**: a web request without a tenant context returns no rows.

```
/hms/                       landing (find your hospital)
/hms/admin/...              Super Admin console           (middleware: auth, super_admin)
/hms/h/{slug}/...           hospital staff area           (middleware: tenant, auth, staff, module:*, can:*)
/hms/h/{slug}/portal/...    patient portal                (middleware: tenant, auth, patient)
/hms/template/{page}        original Herozi demo pages    (HMS_TEMPLATE_DEMO=true)
/hms/api/lab-devices/results  analyzer integration        (Bearer device token)
```

* `IdentifyHospital` resolves the slug, activates the tenant (`App\Support\Tenancy`), sets the Spatie team id,
  `URL::defaults(['hospital' => slug])`, the hospital timezone, and signs out users of other hospitals.
  It is registered as **Livewire persistent middleware**, so every component update is tenant-scoped too.
* `ResetTenancy` (global) clears tenant state at the start of each request.
* Validation of foreign ids uses `tenant_exists('table')` / `doctor_exists()` so ids from another hospital are rejected.
* **Plans → modules → permissions** (`config/hms.php`). A permission is only usable if its module is in the
  hospital’s plan; changing a plan re-syncs every role. Hospital Admin automatically has every permission in the plan.
* Business rules live in `app/Services` (Billing, OPD, IPD, Pharmacy, Diagnostics, BloodBank, Payroll,
  Subscription, HospitalProvisioner) and are shared by the UI, portal, API, scheduler and seeders.
* Uploads are stored privately under `storage/app/private/hospitals/{id}/…` and streamed by `FileController`
  after an ownership check.

## Local setup

Requirements: PHP 8.2+ (gd, intl, mbstring, openssl, pdo_mysql, zip), Composer 2, MySQL 8 / MariaDB 10.6+.
Node is **not** required — compiled theme assets are committed in `public/assets`.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set the database in `.env` (`DB_DATABASE=hms`, `DB_USERNAME`, `DB_PASSWORD`) and `APP_URL=http://localhost/hms`, then:

```bash
php artisan migrate --seed
```

**WAMP alias** (`C:\wamp64\alias\hms.conf`) — restart Apache afterwards:

```apache
Alias /hms "C:/path/to/project/public"
<Directory "C:/path/to/project/public">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require local
    RewriteEngine On
    RewriteBase /hms/
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</Directory>
```

Open `http://localhost/hms`. (Alternatively `php artisan serve` → `http://127.0.0.1:8000` with `APP_URL` set accordingly.)

Rebuilding theme SCSS is optional: `yarn install && yarn dev` (Laravel Mix; `mix.setResourceRoot('../../')`
keeps font URLs relative so the app works under `/hms`).

## Demo logins

The seeder creates a Super Admin, **City General Hospital** (`/h/city-hospital`, Enterprise plan, full demo data)
with one account per default role and a portal patient, and **Sunrise Clinic** (`/h/sunrise-clinic`, Basic plan) for
isolation testing. The e-mail addresses and the shared demo password are listed at the top of
`database/seeders/DatabaseSeeder.php`. **Change or remove all demo accounts before going live**
(production: `php artisan migrate --seed` is not needed — create the Super Admin with the seeder’s platform part or tinker).

### Public demo hospital

The landing page (`/`) describes the product and has **Try Demo Hospital as Admin** plus one button per role
(Doctor, Receptionist, Nurse, Pharmacist, Lab, Accounts, Patient). These sign visitors straight into City General
Hospital without a password (`POST /demo/{role}`, throttled); the staff and portal login pages show the same buttons.
Inside the demo a banner shows the current role, a role switcher and the next reset time.

So visitors cannot lock each other out, demo accounts keep their e-mail, password and status, and the hospital
profile, certificates, roles, user accounts and subscription are read-only. All clinical and billing work is open.
`php artisan hms:reset-demo` deletes the demo hospital with all its data and files and rebuilds it with fresh sample
data. The scheduler runs it every `HMS_DEMO_RESET_HOURS`, and other hospitals are not touched.

| `.env` | Default | Meaning |
|---|---|---|
| `HMS_DEMO_ENABLED` | `true` | landing demo buttons, `/demo/{role}` and the reset schedule; set `false` for a private installation |
| `HMS_DEMO_HOSPITAL` | `city-hospital` | slug of the hospital that is public and gets reset |
| `HMS_DEMO_RESET_HOURS` | `6` | reset interval |

`fakerphp/faker` is a production dependency because the reset generates sample data.

## Deploying to the VPS

Target: `http://23.230.253.206/hms` on Ubuntu + Nginx/Apache + PHP-FPM 8.2+.

```bash
cd /var/www && git clone https://github.com/ShehrrYarr/SaaS-HMS.git hms && cd hms
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# .env: APP_ENV=production, APP_DEBUG=false, APP_URL=http://23.230.253.206/hms, DB_*, MAIL_*, HMS_SMS_DRIVER
php artisan migrate --force
php artisan db:seed --force          # first install only (demo data) — or seed just plans + super admin
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

**Nginx (sub-path `/hms`)** — a starting point (not yet tested on the VPS); after deploying, check that `/hms/livewire/livewire.js` and `/hms/assets/css/app.min.css` return 200.

```nginx
location ^~ /hms {
    alias /var/www/hms/public;
    try_files $uri $uri/ @hms;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/hms/public/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
location @hms { rewrite ^/hms/(.*)$ /hms/index.php?/$1 last; }
client_max_body_size 110M;
```

**Apache**: use the same `Alias` block as the WAMP example (with `Require all granted`).

**PHP** (`php.ini`): `upload_max_filesize = 100M`, `post_max_size = 110M` (DICOM uploads), `memory_limit = 512M`.

The app handles the sub-path itself: Livewire’s script/update endpoints are prefixed with the request base path
(`App\Support\Livewire\SubdirectoryHandleRequests`), and assets use `asset_v()` (cache-busting `?v=`).

## Scheduled jobs & workers

```cron
* * * * * cd /var/www/hms && php artisan schedule:run >> /dev/null 2>&1
```

| Command | When | What |
|---|---|---|
| `hms:billing-run` | 01:00 | renewal invoices (7 days before expiry), suspend hospitals unpaid past the grace period |
| `hms:daily-maintenance` | 01:30 | no-show marking, blood unit expiry, pharmacy expiry/low-stock notifications, storage usage per hospital |
| `hms:reset-demo` | every `HMS_DEMO_RESET_HOURS` (when the demo is enabled) | wipe and re-seed the public demo hospital |

Optional queue worker (Supervisor): `php artisan queue:work --tries=3` (notifications are database-backed and synchronous by default).

## Integrations

* **SMS / OTP** — `HMS_SMS_DRIVER=log|twilio|http` (`App\Services\Sms\SmsManager`). With `log` + `APP_DEBUG=true`
  the OTP is also shown as a toast for testing.
* **Telemedicine** — `HMS_TELEMEDICINE_DRIVER=jitsi` (default `meet.jit.si`, set `HMS_JITSI_DOMAIN` for a self-hosted
  Jitsi). `agora` is scaffolded in `App\Services\Telemedicine\VideoRoom` (add App ID/certificate + token builder).
* **Lab analyzers** — Lab → Devices issues a token per analyzer/middleware:
  `POST /hms/api/lab-devices/results` with `Authorization: Bearer <token>` and
  `{"barcode": "CGH00000012", "results": {"HGB": 13.4}}` (parameter codes from the test catalog). Results still require approval.
* **PACS** — set *Settings → Hospital Profile → PACS viewer URL* (e.g. an OHIF link containing `{uid}`).
* **Digitally signed lab reports** — upload a `.p12/.pfx` under *Hospital Profile*; approved reports then offer a signed PDF.
* **Payments** — manual/bank-transfer only (by design): hospitals upload proof, the Super Admin verifies; patients report transfers from the portal.

## Tests

Tests run against a separate MySQL database `hms_testing` (see `phpunit.xml`) seeded with the demo data:

```bash
php artisan test
```

They cover tenant isolation (scoping, cross-tenant URL guessing, login binding, suspension), plan/role access
control, the main clinical & financial workflows, the device API, and a **smoke test that renders every hospital,
admin and portal screen and every PDF**.

## Project layout

```
app/Models                  65+ models (tenant models use BelongsToHospital, key ones Auditable)
app/Services                business logic (billing, OPD, IPD, pharmacy, diagnostics, payroll, SaaS billing…)
app/Http/Middleware         IdentifyHospital, ResetTenancy, EnsureStaff/Patient/SuperAdmin, EnsureModuleEnabled
app/Support                 Tenancy, Sequence (document numbers), Menu, Permissions, DocumentAssets
config/hms.php              modules, permissions, default roles, currencies
resources/views/livewire    Volt pages: admin/*, tenant/*, portal/*, auth/*
resources/views/components  modal, form inputs, search-select, table toolbar, stat cards…
resources/views/pdf         dompdf templates
resources/views/template    original Herozi demo pages (/template/*)
```
