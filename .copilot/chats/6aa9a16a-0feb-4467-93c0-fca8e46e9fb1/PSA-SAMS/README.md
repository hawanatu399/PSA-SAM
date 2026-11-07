# PSA-SAMS — Initial Operational Release

Public Service Academy Stores and Asset Management System (PSA-SAMS) is a PHP 8 and MySQL/MariaDB application foundation for tracking assets, consumable stores, allocations, maintenance, and audit activity.

## Phase 1 scope

This release includes:g

- A relational InnoDB database schema and repeatable demonstration seed data.
- Sign-in, sign-out, CSRF protection, session hardening, password hashing, and five-role access checks.
- An operational dashboard with asset, transfer, stock, maintenance, and recent-activity summaries.
- Asset registration and active assignment/return workflows.
- Asset record maintenance and role-restricted verification status updates.
- Department transfer requests with separate-officer approval and a movement audit trail.
- Store item setup and atomic stock-in/stock-out movements that prevent negative stock.
- Maintenance reporting, status updates, repair costs, and reporting dates.
- Super-administrator user provisioning, account activation/deactivation, and password reset.
- Self-service password changes with account-wide session invalidation and forced password changes for new/reset accounts.
- CSV exports for assets, stock transactions, transfers, maintenance, and audit events, with spreadsheet-formula neutralization.
- Structured JSON details for audited asset, transfer, stock, maintenance, and account-status changes.
- Read-only operational summaries and audit history for permitted oversight roles.
- Responsive role-filtered navigation.

This is an initial operational baseline, not a fully certified deployment. Physical verification evidence (photos/sign-off), formal requisitions, organization-specific approval rules, and tamper-evident audit storage still require implementation and review. Audit events cover implemented actions only; they are application records, not tamper-proof regulatory audit evidence.

## Local setup (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\PSA-SAMS\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open phpMyAdmin and import `database.sql`. The script creates and selects `psa_sams_db`.
4. Create the first active administrator from a trusted command prompt as described in **Initial administrator** below.
5. Visit `http://localhost/PSA-SAMS/` and sign in with that administrator account.

The local defaults expect MySQL at `127.0.0.1:3306`, database `psa_sams_db`, user `root`, and an empty password. Configure `PSA_DB_HOST`, `PSA_DB_PORT`, `PSA_DB_NAME`, `PSA_DB_USER`, and `PSA_DB_PASSWORD` as environment variables to override those settings. If the application is installed at a different URL path, set `PSA_APP_BASE_URL` (for example, `/my-psa-app`).
`database.sql` is an initial-install script with demonstration records, not a production migration or backup. Import it only into a new/empty environment. Never re-import it over operational records; use reviewed, versioned migrations for future schema changes.

## Demonstration accounts

The SQL file includes five demonstration accounts and sample records. All demonstration accounts are **inactive** and cannot sign in. The shared seed password is for local demonstration only; do not activate or use these accounts with real data.

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

## Security and deployment note

Use XAMPP only for local development and review. Do not expose XAMPP directly to the internet or use this application with real Academy records until the organization has approved a deployment. Operational deployment requires a managed, patched PHP/Apache and MariaDB host on a private network, HTTPS, a dedicated least-privilege database account, secrets management, restricted administration access, tested backups and restores, monitoring, independent security review, and acceptance testing against Academy asset, finance, and approval policies. If a reverse proxy is used, configure authentication rate limits at the trusted edge; the app intentionally does not trust client-supplied forwarding headers. The supplied Apache rules help prevent directory listings and direct access to the SQL seed and configuration directory; verify that Apache allows `.htaccess` overrides in the target environment.
For TLS terminated by a trusted reverse proxy, set `PSA_COOKIE_SECURE=1` in the protected PHP service environment. Do not set it by reading untrusted request headers.

## Project layout

- `auth/` — authentication, session, and access-control helpers.
- `config/` — PDO database connection and Apache access restriction.
- `includes/` — shared application header, role-filtered sidebar, and footer.
- `modules/dashboard/` — Phase 1 operational dashboard.
- `modules/` — dashboard, asset/store workflows, allocations, maintenance, reports, and account management.
- `assets/` — application styles, navigation behavior, and image placeholder.
- `uploads/` — reserved for future verification photos; executable PHP files are denied by the included Apache rule.
- `database.sql` — ten core tables, authentication throttling support, and safe demonstration records.
