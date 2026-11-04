# Deployment handover checklist

This is an operations checklist, not deployment approval. The system owner, ICT, information security, records management, and finance/procurement stakeholders must approve the actual host, retention rules, roles, and workflows before real records are entered.

## Stage 1: local XAMPP demonstration

- Keep XAMPP bound to the development machine or an explicitly trusted private lab network. Never port-forward it or expose it to the internet.
- Start Apache and MySQL, import `database.sql` only into a new/empty local database, optionally import `demo_seed.sql` for synthetic local walkthrough data, and create the initial administrator through the CLI bootstrap.
- Run `C:\xampp\php\php.exe C:\xampp\htdocs\PSA-SAMS\bin\check_readiness.php`. A root-user warning is expected with local XAMPP defaults.
- Use synthetic demonstration data unless the Academy has explicitly authorized the specific local machine and data set.
- Back up the local database before any development changes. Never use the initial-install SQL file as a restore or migration.

## Stage 2: managed internal deployment

1. Obtain written ICT/system-owner approval for the hosting environment, network boundary, TLS endpoint, service owner, database host, and operational support.
2. Obtain policy approval from stores, finance/procurement, asset custodians, records management, and information security for request/approval/issue separation, department visibility, physical verification cadence/evidence, privacy, and retention.
3. Provision a patched and supported PHP/Apache and MariaDB/MySQL service. Do not use a developer XAMPP machine, public phpMyAdmin, or the local demo database for production.
4. Create an empty production database, import the schema-only `database.sql` with a DBA account, and configure the application runtime with protected `PSA_DB_*` environment values and a dedicated least-privilege account. Do not import `demo_seed.sql` into an operational environment.
   For an existing deployment, take and verify a restorable backup before applying the reviewed additive SQL migrations in `migrations/`; never re-import `database.sql` over operational records.
5. Set `PSA_APP_BASE_URL` for the installed path; set `PSA_REQUIRE_HTTPS=1` and `PSA_COOKIE_SECURE=1` in the protected PHP service configuration; configure the web server to redirect HTTP to the approved HTTPS URL; restrict database/network access; and verify Apache honors the included access rules. The application rejects HTTP requests when HTTPS is required.
6. Run the readiness check as the application runtime environment. Resolve every failure and warning; confirm the runtime identity is not `root`.
7. Create the first administrator using the approved secure bootstrap procedure; then verify forced password change, role access, account revocation, transfers, inventory concurrency, audit exports, backup/restore, logging, and alerting.
8. Complete independent security/privacy review and organization-approved acceptance testing. Obtain formal go-live approval before loading real records.

## Database permissions

1. A DBA creates the database and imports `database.sql` during initial installation.
2. The PHP runtime uses a dedicated account with the table-specific grants in `db-grants.sql.example`. It receives only the operations currently used by the application; login-attempt records are the only table that requires `DELETE`.
3. Keep schema changes and SQL imports under a separately controlled DBA account. The application does not need `CREATE`, `ALTER`, `DROP`, `FILE`, `PROCESS`, or global privileges.
4. `db-grants.sql.example` is a template only. Replace the sample account, host, and secret according to the host's secret policy; do not commit a real credential.

## Application host

- Use a supported PHP release with `pdo_mysql`, a supported patched Apache/PHP handler, and a supported MariaDB/MySQL version.
- Restrict the host and database to approved internal networks; do not expose XAMPP, phpMyAdmin, or MySQL publicly.
- Terminate TLS with organization-managed certificates. If a trusted reverse proxy terminates TLS, set `PSA_COOKIE_SECURE=1` in the protected PHP service environment. Never derive that setting from a client-supplied forwarding header.
- Set `PSA_REQUIRE_HTTPS=1` and `PSA_COOKIE_SECURE=1` in protected service configuration for a managed deployment. Configure HTTP-to-HTTPS redirection at the TLS endpoint; the application rejects a request it detects as plain HTTP and sends HSTS after secure requests. For TLS terminated at a reverse proxy, set `PSA_HTTPS_TERMINATED_BY_TRUSTED_PROXY=1` only when ICT has verified that the proxy enforces HTTPS, strips untrusted forwarding headers, and the application origin is not directly reachable by clients. Configure the web server/PHP handler to provide a trusted HTTPS transport indicator; the app does not trust request-supplied `X-Forwarded-Proto`.
- Set database variables (`PSA_DB_HOST`, `PSA_DB_PORT`, `PSA_DB_NAME`, `PSA_DB_USER`, `PSA_DB_PASSWORD`) in protected service configuration, not in the repository.
- Set `PSA_APP_BASE_URL` to the application path. Ensure Apache honors the included access restrictions or reproduce them in the virtual-host configuration.
- Keep `uploads/` non-public. The root and evidence subdirectory are denied by `.htaccess`; future file types and download features need a separately reviewed, authenticated storage service and malware/content validation.
- Verification evidence images are stored under `uploads/evidence/`, which has its own deny-all Apache rule, and are served by an authenticated application route. The current demo accepts JPEG, PNG, and WebP files up to 4 MB and does not provide malware scanning, configurable retention, or evidence encryption at rest. Do not upload sensitive images until ICT approves storage, access, backup, and retention controls.
- Restrict file ownership and write permissions. PHP should not be able to modify source code, SQL, or Apache configuration.
- Configure centralized logs, alerting, time synchronization, patching, backup retention, and a documented incident process.
- Take encrypted backups and regularly test full restore, including verification evidence files.

## Release checks

- Import `database.sql` into a new test database and create the initial administrator using the documented CLI bootstrap. Use `demo_seed.sql` only in an isolated test environment that needs synthetic walkthrough data.
- Verify forced first password change, each role's access boundaries, transfer separation of duties, stock concurrency, reports, and account revocation.
- Run organization-approved security, privacy, accessibility, and user acceptance testing.
- Approve production access and data-retention policies before onboarding users.
- Do not treat application audit rows as tamper-evident records. If formal audit evidence is required, send events to an independently administered, append-only log service.
- The runtime database account must have no `UPDATE` or `DELETE` privilege on `audit_logs` or `asset_verifications`. The example grants access table by table so broader database-level privileges cannot bypass the audit restriction; verification history is append-only through the application.
- Grant `verification_evidence` only the `SELECT` and `INSERT` operations required by the app. Do not grant evidence updates or deletes unless an approved retention workflow is introduced.
- Grant the runtime account only the campaign operations required by the app: `SELECT`, `INSERT`, and `UPDATE` on `verification_campaigns`; `SELECT` and `INSERT` on `verification_campaign_departments`; and the established read/append privileges for `asset_verifications`. Do not grant campaign deletes or verification updates/deletes.
- The application has no automated database backup, restore, disaster-recovery, external monitoring, or central append-only audit integration. Provide and test these controls in the managed service before go-live.
- Campaign reminders are derived in-app notifications only. There is no email service or background scheduler.
- Store requisitions enforce separate requester/approver/issuer identities in the application, and physical checks record found/missing and observed condition. Configurable verification campaigns track selected departments and campaign completion; optional verification photos are stored outside direct web access and served through an authenticated route. These are demonstration baselines only: Academy financial delegation, department scope, verification cadence, evidence-retention rules, and any formal sign-off requirements still need written approval and acceptance testing. A recorded verifier and timestamp are not a formal electronic signature.
- For an existing installation, take and verify a database backup, then apply the reviewed migrations in order. Never import `database.sql` over operational records.
