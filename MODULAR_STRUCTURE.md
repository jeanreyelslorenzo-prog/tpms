# TPMS Modular Structure

## Request flow

```text
Browser request
  -> root page or actions compatibility entry
  -> app/bootstrap.php
  -> feature module page/action
  -> shared layout and helpers
  -> database
```

The compatibility layer lets the modular build replace the previous version
without changing bookmarks, forms, JavaScript endpoints, or rewrite rules.

## Public page map

| Existing URL | Module implementation |
| --- | --- |
| `dashboard.php` | `modules/dashboard/pages/index.php` |
| `teachers.php` | `modules/teachers/pages/index.php` |
| `add_teacher.php` | `modules/teachers/pages/create.php` |
| `edit_teacher.php` | `modules/teachers/pages/edit.php` |
| `view_teacher.php` | `modules/teachers/pages/show.php` |
| `upload.php` | `modules/teachers/pages/upload.php` |
| `schools.php` | `modules/schools/pages/index.php` |
| `districts.php` | `modules/districts/pages/index.php` |
| `als.php` | `modules/als/pages/index.php` |
| `reports.php` | `modules/reports/pages/index.php` |
| `requirement_planning.php` | `modules/planning/pages/index.php` |
| `retirement_watch.php` | `modules/retirement/pages/index.php` |
| `users.php` | `modules/users/pages/index.php` |
| `logs.php`, `my_activity.php` | `modules/activity/pages/` |
| `chatbot.php` | `modules/chatbot/pages/index.php` |
| `appearance.php` | `modules/appearance/pages/index.php` |
| `profile.php` | `modules/profile/pages/index.php` |
| `updates.php` | `modules/updates/pages/index.php` |
| Login, role, district, onboarding, setup | `modules/auth/pages/` |

All existing files under `actions/` also remain valid. Each one delegates to
the corresponding feature action under `modules/`.

The integrated ALS workflow also adds compatibility actions for teacher and
school creation/update, district management, appearance/profile/user saves,
planning saves, the two-step school setup, and dashboard tour completion.

## Adding a page to an existing module

1. Add the implementation to `modules/<feature>/pages/`.
2. At the top of an authenticated page, load the shared header using:

   ```php
   require_once dirname(__DIR__, 3) . '/includes/header.php';
   ```

3. Add a thin public root entry that defines `TPMS_PUBLIC_ENTRY` and requires
   the module page.
4. Use `APP_URL` for browser URLs and `BASE_PATH` for filesystem paths.
5. Apply the appropriate `requireRole()` or `requireRoleSelection()` check.

## Adding an action

1. Put the handler in `modules/<feature>/actions/`.
2. Load `app/bootstrap.php` from the handler.
3. Start the secure session and enforce login/role/CSRF checks before reading
   request data.
4. Add a thin compatibility file in `actions/` so existing JavaScript and forms
   have a stable endpoint.

## Boundaries

- Feature-specific queries and form behavior belong in that feature module.
- Cross-feature helpers belong in `app/Support/functions.php` only when at least
  two modules use them.
- Authentication/session behavior belongs in `app/Support/auth.php`.
- Database connection behavior belongs in `app/Core/Database.php`.
- Shared navigation and page chrome belong in `app/Views/layouts/`.
- Database changes belong in `database/migrations/`, never in a public debug
  page.
- Diagnostic and one-off maintenance scripts belong in `tools/` and are CLI
  only.

## Backward compatibility rules

- Do not rename a public wrapper without updating every form, JavaScript fetch,
  export link, and redirect that uses it.
- Do not expose `modules/`, `app/`, `config/`, `database/`, or `tools/` directly.
- Preserve the current database schema unless a versioned migration is supplied.
- Preserve `config/local.php` and uploaded photos during an upgrade.
