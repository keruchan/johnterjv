# CERTREEFY Agent Guide

## Project Identity

- Project: CERTREEFY, identified by the repository as a CENRO Sta. Cruz, Laguna web application under DENR.
- Stack: PHP 8, PDO with a MySQL-compatible database, HTML5, CSS3, Bootstrap 5, and JavaScript. The current XAMPP database server is MariaDB; do not assume MySQL-specific behavior without checking compatibility.
- Security model: PHP sessions with database-backed role and active-status checks on every restricted page.
- Current database roles: `superadmin`, `rps`, `community`, and `ems`.
- Current account statuses: `pending`, `active`, `suspended`, and `disabled`; the interface labels `disabled` accounts as Deactivated.
- Compatibility routing: CENRO Superadmin and RPS share `pages/cenro/`; EMS uses `pages/ems/` (migrated from the legacy `pages/greenhouse/` path). CENRO remains the official organization name. The `greenhouse` value survives only as a one-way legacy-role database migration (`greenhouse` -> `ems`); it is no longer an accepted role or an active route.
- User-management boundary: only CENRO Superadmin may enter `pages/cenro/user-management.php`; Superadmin accounts are view-only there, while `community`, `rps`, and `ems` accounts may be edited or have status changed.
- Unverified scope: coverage of Districts 3 and 4 of Laguna is an external requirement, not a repository-backed fact.

## Context Loading Policy

Before changing code:

1. Read this `AGENTS.md`.
2. Read `docs/ai/PROJECT_CONTEXT.md`.
3. Read `docs/ai/IMPLEMENTATION_STATUS.md` only for feature completeness, unfinished modules, limitations, or TODOs.
4. Identify the affected module.
5. Search only for relevant symbols, routes, pages, tables, functions, and components.
6. Open the minimum implementation files required.
7. Expand the search only when the context is incomplete, contradictory, or outdated.

Do not scan the entire repository by default. Skip dependencies, generated output, caches, logs, uploads, minified third-party assets, and image collections unless directly relevant.

## Existing Design Protection

- Preserve the existing visual identity, templates, layouts, and responsive behavior.
- Reuse existing headers, navigation, sidebars, cards, forms, alerts, buttons, badges, and Bootstrap patterns.
- Use `css/dashboard.css` for protected dashboard styling; do not create competing dashboard stylesheets.
- Derive new pages from the nearest existing page with a similar purpose.
- Check for an existing component before adding one; avoid duplicated CSS, JavaScript, layouts, and markup.
- Do not add a new CSS framework or replace working pages with generic generated designs.
- Do not redesign unless explicitly requested. Make the smallest visual change needed.

## Implementation Rules

- Preserve working architecture and avoid unrelated refactoring.
- Inspect existing code before adding database columns, tables, routes, functions, or status values.
- Follow existing naming and folder conventions and prefer extending reusable components.
- Use PDO prepared statements and transactions for multi-step workflows.
- Use `password_hash()` and `password_verify()` for passwords.
- Enforce authentication, role checks, record ownership, and sensitive-file access on the server.
- Escape user-controlled HTML with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Validate uploaded file type, size, ownership, and authorization.
- Preserve status history and audit records when those workflows are introduced.

## Validation Rules

- Run `C:\xampp\php\php.exe -l <file>` on each modified PHP file.
- Test the smallest relevant workflow, including success, validation, failure, and unauthorized-access paths.
- Check role permissions, database queries, transactions, and record ownership where applicable.
- Confirm that existing page design remains consistent.
- Report changed files and validation performed.

## Documentation Maintenance

Update the context only when a task changes architecture, entities or relationships, roles, routes, navigation, major workflows, reusable components, major status, limitations, or durable design decisions. Do not update it for minor styling, small bug fixes, local variable changes, temporary diagnostics, or chat history.
