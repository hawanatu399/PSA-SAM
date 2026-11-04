# PSA-SAMS — Initial Operational Release

Public Service Academy Stores and Asset Management System (PSA-SAMS) is a PHP 8 and MySQL/MariaDB application foundation for tracking assets, consumable stores, allocations, maintenance, and audit activity.

For the Director of ICT demonstration, see the [ICT demo brief and walkthrough](./deployment/ICT_DEMO_BRIEF.md) and [integration assessment plan](./deployment/INTEGRATION_PLAN.md).
For a technical and operational system overview, see the [PSA-SAMS blueprint](./deployment/PSA_SAMS_BLUEPRINT.md) and [one-page infographic](./deployment/PSA_SAMS_INFOGRAPHIC.svg).

The sign-in and application header use the PSA logo and the public-facing tagline “An Excellent Center of Knowledge” from the [Public Service Academy website](https://portal.psa.gov.sl/). The application footer also reflects the site's published values: Knowledge, Integrity, and Service. These elements are included for this Academy project; confirm branding approval with the Academy before any wider distribution.

## Phase 1 scope

This release includes:

- A relational InnoDB database schema with separately optional, repeatable synthetic demonstration data.
- Sign-in, sign-out, CSRF protection, session hardening, password hashing, and five-role access checks.
- An operational dashboard with asset, transfer, stock, maintenance, and recent-activity summaries.
- Dashboard asset-condition charts and a role-filtered list of stock items needing attention.
- Live in-app notifications for low stock, pending approvals, and open maintenance work; alerts clear when the underlying condition is resolved.
- Asset-register search across identifying details, with department, category, condition, and verification filters.
- Asset registration and active assignment/return workflows.
- Department-based physical verification records with found/missing results, observed condition, verifier, timestamp, and notes. Verification history is append-only in application workflows; optional image evidence is stored separately.
- Configurable physical verification campaigns with date windows, selected departments, per-department completion counts, and campaign-linked checks.
- Printable Code 39 asset labels with scanner-keyboard lookup by internal asset ID.
- Batch printing for the asset labels currently shown in the asset-register list.
- Optional JPEG, PNG, or WebP evidence for physical checks, stored outside direct web access and served only through an authenticated route.
- In-app reminders for active verification campaigns approaching or past their end date.
- Asset record maintenance and role-restricted physical verification records that update the current verification status.
- Department transfer requests with separate-officer approval and a movement audit trail.
- Store item setup and atomic stock-in/stock-out movements that prevent negative stock.
- Store requisitions with separate requester, approver, and issuer controls; stock is deducted only when an approved requisition is issued.
- Maintenance reporting, status updates, repair costs, and reporting dates.
- Super-administrator user provisioning, account activation/deactivation, and password reset.
- Super-administrator management of department and asset-category reference data, with audited changes and no destructive delete action.
- Self-service password changes with account-wide session invalidation and forced password changes for new/reset accounts.
- CSV exports for assets, stock transactions, transfers, maintenance, and audit events, with spreadsheet-formula neutralization.
- CSV exports for requisitions, physical verification records, and campaign progress.
- Print-ready reports that use the browser print dialog or Save as PDF.
- Structured JSON details for audited asset, transfer, stock, maintenance, and account-status changes.
- Read-only operational summaries and audit history for permitted oversight roles.
- Searchable, filterable, paginated audit history with user, record type, and date-range filters.
- Responsive role-filtered navigation.

Notifications are derived from current operational records and do not require a notification table or read/unread state; campaign reminders use the verification campaign schema. The asset register searches the full matching record set and displays up to the 100 most recent matches at a time.

This is an initial operational baseline, not a fully certified deployment. The requisition and physical-check workflows and configurable verification campaigns are demonstration baselines, not Academy-approved financial delegation or verification policy. Photo evidence retention/privacy and formal sign-off requirements still require policy approval; the recorded verifier and timestamp are not a formal electronic signature. Reminders are in-app only, not scheduled email. Barcode scanning requires a compatible keyboard-wedge scanner; camera scanning and QR labels are not included. Audit events cover implemented actions only; they are application records, not tamper-proof regulatory audit evidence.

## Local setup (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\PSA-SAMS\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open phpMyAdmin and import `database.sql`. The schema is installed without user or operational sample records. For a local-only walkthrough, optionally import `demo_seed.sql` afterward; never import the demo seed into an operational environment.
4. Create the first active administrator from a trusted command prompt as described in **Initial administrator** below.
5. Visit `http://localhost/PSA-SAMS/` and sign in with that administrator account.

The local defaults expect MySQL at `127.0.0.1:3306`, database `psa_sams_db`, user `root`, and an empty password. Configure `PSA_DB_HOST`, `PSA_DB_PORT`, `PSA_DB_NAME`, `PSA_DB_USER`, and `PSA_DB_PASSWORD` as environment variables to override those settings. If the application is installed at a different URL path, set `PSA_APP_BASE_URL` (for example, `/my-psa-app`).
`database.sql` creates the initial schema in a new/empty database; it is not a migration or backup. `demo_seed.sql` adds synthetic local demonstration accounts and sample records and must not be used in an operational environment. For an existing installation, back up the database and apply the additive migrations `migrations/20261103_add_requisitions_and_verifications.sql`, `migrations/20261103_add_verification_campaigns.sql`, and `migrations/20261103_add_verification_evidence.sql` in order; never re-import the initial schema over operational records. Use reviewed, versioned migrations for future schema changes.

Run the read-only local readiness check from PowerShell after starting Apache/MySQL:

```powershell
& 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\PSA-SAMS\bin\check_readiness.php'
```

It checks the PHP/MySQL driver, required tables and transactional storage, administrator bootstrap status, HTTPS enforcement and secure-cookie configuration, and runtime database user. Local XAMPP is expected to warn that HTTPS enforcement is disabled and it uses the `root` database user. A passing result means the local application prerequisites were found; it is not Academy production approval.

## Demonstration accounts

The optional `demo_seed.sql` file includes five demonstration accounts and sample records. All demonstration accounts are **inactive** and cannot sign in. The shared seed password is for local demonstration only; do not activate or use these accounts with real data.

| Role | Email |
|---|---|
| Super administrator | `superadmin@psa.local` |
| Administration officer | `admin@psa.local` |
| Stores officer | `stores@psa.local` |
| Approving officer | `approver@psa.local` |
| Management (view only) | `management@psa.local` |

The demonstration password hash corresponds to `Password123!`. Demo accounts remain inactive and the seed script does not reset/reactivate existing accounts. If enabling one for a local demo, set a unique password and explicitly mark it active using an administrator-controlled method. Successful logins can update a seeded hash to the current PHP default password algorithm.

## Initial administrator

The first active super administrator must be created from the server command line; the SQL seed does not install an active account with a published password. Use a protected terminal on the database host:

1. Set `PSA_BOOTSTRAP_NAME` and `PSA_BOOTSTRAP_EMAIL` to the authorized operator's details.
2. Set `PSA_BOOTSTRAP_PASSWORD` using your organization's approved secret-handling method. Use a unique password of at least 14 characters and at most 72 bytes, with uppercase, lowercase, a number, and a symbol. The 72-byte limit avoids bcrypt's input truncation on common PHP installations.
3. From the project directory, run `C:\xampp\php\php.exe bin\create_admin.php` (adjust the PHP path for the managed server).
4. Remove the bootstrap password from the process environment and store it only in the organization's approved password manager.

On Windows PowerShell, you can prompt for the password without putting the value in command history:

```powershell
$env:PSA_BOOTSTRAP_NAME = 'Authorized Administrator'
$env:PSA_BOOTSTRAP_EMAIL = 'admin@example.org'
$securePassword = Read-Host 'Enter a unique bootstrap password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:PSA_BOOTSTRAP_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\PSA-SAMS\bin\create_admin.php'
} finally {
    Remove-Item Env:PSA_BOOTSTRAP_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    $securePassword.Dispose()
    Remove-Item Env:PSA_BOOTSTRAP_NAME, Env:PSA_BOOTSTRAP_EMAIL -ErrorAction SilentlyContinue
}
```

The command refuses to create another initial administrator after an active super administrator exists. The new administrator must change the bootstrap password at first sign-in. Account deactivation and password resets invalidate access on the next protected request. Sessions expire after 30 minutes of inactivity or 8 hours, whichever comes first. Do not expose bootstrap credentials in shell history, screenshots, support tickets, or source control.

## Academy deployment readiness

The project can be developed on local XAMPP and prepared for a later managed internal server. These are separate stages:

- **Local XAMPP:** development and demonstration only. Use sample data or records explicitly approved for local testing. Do not expose the machine, Apache, phpMyAdmin, or MySQL to the internet or an untrusted network.
- **Managed internal server:** a separate, ICT-approved environment. Do not copy the local root database credentials or local database into production. Follow the [deployment handover checklist](./deployment/README.md), use a dedicated least-privilege database account, and test a fresh install and restore before onboarding users.

The Principal presentation should be treated as a demonstration of the current application, not approval to enter operational Academy records. Before go-live, the Academy must approve department access, store requisition and financial approval rules, physical verification procedures, retention/privacy rules, and user roles. This release includes a separate requester/approver/issuer requisition baseline and dated found/missing physical checks, but not approved financial thresholds, a background scheduler or email reminders, or formally approved photo/sign-off evidence.

## Security and deployment note

Use XAMPP only for local development and review. Do not expose XAMPP directly to the internet or use this application with real Academy records until the organization has approved a deployment. Operational deployment requires a managed, patched PHP/Apache and MariaDB host on a private network, HTTPS, a dedicated least-privilege database account, secrets management, restricted administration access, tested backups and restores, monitoring, independent security review, and acceptance testing against Academy asset, finance, and approval policies. Set `PSA_REQUIRE_HTTPS=1` and `PSA_COOKIE_SECURE=1` in protected service configuration and configure HTTP-to-HTTPS redirection at the TLS endpoint. If a trusted reverse proxy terminates TLS, follow the conditions in the deployment checklist; the app intentionally does not trust client-supplied forwarding headers. The supplied Apache rules help prevent directory listings and direct access to SQL files and the configuration directory; verify that Apache allows `.htaccess` overrides in the target environment.
For TLS terminated by a trusted reverse proxy, set `PSA_COOKIE_SECURE=1` in the protected PHP service environment. Do not set it by reading untrusted request headers.

## Project layout

- `auth/` — authentication, session, and access-control helpers.
- `config/` — PDO database connection and Apache access restriction.
- `includes/` — shared application header, role-filtered sidebar, and footer.
- `modules/dashboard/` — Phase 1 operational dashboard.
- `modules/` — dashboard, asset/store workflows, requisitions, physical verification, allocations, maintenance, reports, reference-data administration, and account management.
- `assets/` — application styles, navigation behavior, and image placeholder.
- `uploads/evidence/` — protected local storage for optional verification images; direct web access is denied and the application serves images through an authenticated route.
- `database.sql` — application schema, authentication throttling support, and safe demonstration records.
- `migrations/` — additive schema migrations for existing installations.
