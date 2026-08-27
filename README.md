# TPMS v1.10.0 — Modular + ALS Multi-CLC Build

Teacher Profiling Management System for DepEd Aurora. This build combines the
v1.10.0 modular/security structure with the ALS multi-CLC school and teacher
workflow while preserving existing public PHP URLs.

## Before running the system

1. Copy `config/local.example.php` to `config/local.php`.
2. Enter the database host, database name, user, and password in
   `config/local.php`.
3. Replace `encryption_key` with a stable random secret of at least 32
   characters. Do not change this key after records or verification links are
   in use.
4. Make sure Apache and MySQL are running.
5. For a new database, remove the included `.setup.lock`, open `setup.php` once,
   and remove that public wrapper after setup succeeds.
6. For an existing live database, keep `.setup.lock`, configure this build to
   connect to a staging copy, and run `database/migrations/001_baseline.sql`
   through `005_als_teacher_clc_assignments.sql` in numeric order. Do not run
   `setup.php` or re-import `database.sql` over live data.

Production credentials are intentionally not included in this archive.
Environment variables can be used instead of `config/local.php`:

```text
TPMS_DB_HOST
TPMS_DB_NAME
TPMS_DB_USER
TPMS_DB_PASS
TPMS_DB_CHARSET
TPMS_APP_URL
TPMS_ENCRYPT_KEY
```

Environment variables take priority over values in `config/local.php`.

## Modular structure

| Path | Purpose |
| --- | --- |
| `app/bootstrap.php` | Loads configuration, database access, authentication, and shared helpers once |
| `app/Core/` | Core infrastructure such as the PDO database connection |
| `app/Support/` | Authentication and general helper functions |
| `app/Views/layouts/` | Shared header and footer layouts |
| `modules/<feature>/pages/` | Feature screens grouped by domain |
| `modules/<feature>/actions/` | POST, export, upload, and AJAX handlers for that feature |
| `actions/` | Thin compatibility entry files that preserve existing form and AJAX URLs |
| `includes/` | Thin compatibility includes for older page code |
| `config/` | Safe defaults, example local configuration, and ignored local secrets |
| `database/` | Database migrations and retained legacy SQL |
| `tools/` | CLI-only maintenance/diagnostic tools and archived legacy pages |

The root PHP files are intentionally small. They preserve URLs such as
`teachers.php`, `schools.php`, and `dashboard.php`, then load the appropriate
feature module. Direct web access to internal folders is blocked by `.htaccess`
and by the central bootstrap guard.

See `MODULAR_STRUCTURE.md` for the complete route map and instructions for
adding features.

## Roles

| Role | Access |
| --- | --- |
| `admin` | Full access, user management, and activity logs |
| `hr` | Teacher/school management, uploads, and exports |
| `school_head` | Assigned read/export access |
| `psds`, `unit_head` | Role- and district-scoped access |
| `sdc` | Assigned-district read-only access and exports |
| `eps_vr` | Division-wide read-only access and exports |
| `viewer` | Read-only access |

`viewer` is a valid read-only role in this integrated build. Only accounts with
an empty or `NULL` role are sent to role selection.

## Integrated ALS and school workflow

- A school can offer Formal Education, ALS, or both.
- Formal and ALS curricular offerings are stored independently.
- One teacher keeps one official or plantilla station in `teachers.school_id`.
- The same teacher can serve several ALS CLCs for a school year through
  `teacher_clc_assignments` without duplicate teacher records.
- One selected CLC can be marked primary for that school year.
- Removing a CLC from a teacher marks the assignment inactive, preserving its
  history.
- ALS assignments are recognized by Teachers, teacher profiles, ALS Centers,
  Reports, Retirement Watch, exports, and Requirement Planning.

See `docs/ALS_TEACHER_CLC_ASSIGNMENTS.md` and
`docs/SCHOOL_WORKFLOW_UPDATE.md` for the detailed workflows.
Use `ALS_MULTI_CLC_TEST_CHECKLIST.txt` for the staging acceptance test.

## Existing database upgrade

Back up both the files and database, then test the upgrade on staging. Import
these additive migrations into the database selected in phpMyAdmin or your
MySQL client:

1. `database/migrations/001_baseline.sql`
2. `database/migrations/002_schema_sync.sql`
3. `database/migrations/003_school_profile_workflow.sql`
4. `database/migrations/004_formal_als_programs.sql`
5. `database/migrations/005_als_teacher_clc_assignments.sql`

The migrations intentionally do not contain a hard-coded `USE tpms` statement,
so they run against the database you explicitly select. Never import them
without first confirming the selected staging/live database and taking a
backup.

## Bulk upload

Teacher template: `assets/templates/upload_template.csv`

School template: `assets/templates/school_upload_template.csv`

Minimum teacher columns are Employee Number, Last Name, and First Name. The
upload handler continues to support the existing alternative column labels.

## Deployment notes

- Back up the live files and database before replacing anything.
- Preserve `config/local.php` outside source control and verify it before
  switching traffic to this build.
- Do not upload the `.git`, `tools/legacy`, or documentation files if your
  hosting process only needs runtime files.
- Keep `assets/uploads/photos/` when upgrading an existing installation.
- Live database records and uploaded personnel photos are not bundled in this
  source archive. Keep those on the server and back them up separately.
- The bundled `.htaccess` no longer hard-codes `/tpms/`, so the project can run
  from another subfolder or domain root.
- PHP 8.0+ and MySQL 5.7+/MariaDB 10.3+ are required.
