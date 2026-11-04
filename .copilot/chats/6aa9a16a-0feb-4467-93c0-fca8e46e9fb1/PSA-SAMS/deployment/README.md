# Deployment handover checklist

This is an operations checklist, not deployment approval. The system owner, ICT, information security, records management, and finance/procurement stakeholders must approve the actual host, retention rules, roles, and workflows before real records are entered.

## Database permissions

1. A DBA creates the database and imports `database.sql` during initial installation.
2. The PHP runtime uses a dedicated account with table-specific grants. It receives `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on operational tables, but only `SELECT` and `INSERT` on `audit_logs`.
3. Keep schema changes and SQL imports under a separately controlled DBA account. The application does not need `CREATE`, `ALTER`, `DROP`, `FILE`, `PROCESS`, or global privileges.
4. `db-grants.sql.example` is a template only. Replace the sample account, host, and secret according to the host's secret policy; do not commit a real credential.

## Application host

- Use a supported PHP release with `pdo_mysql`, a supported patched Apache/PHP handler, and a supported MariaDB/MySQL version.
- Restrict the host and database to approved internal networks; do not expose XAMPP, phpMyAdmin, or MySQL publicly.
- Terminate TLS with organization-managed certificates. If a trusted reverse proxy terminates TLS, set `PSA_COOKIE_SECURE=1` in the protected PHP service environment. Never derive that setting from a client-supplied forwarding header.
- Set database variables (`PSA_DB_HOST`, `PSA_DB_PORT`, `PSA_DB_NAME`, `PSA_DB_USER`, `PSA_DB_PASSWORD`) in protected service configuration, not in the repository.
- Set `PSA_APP_BASE_URL` to the application path. Ensure Apache honors the included access restrictions or reproduce them in the virtual-host configuration.
- Keep `uploads/` non-public. It is denied by `.htaccess`; future file upload/download features need a separately reviewed, authenticated storage service and malware/content validation.
- Restrict file ownership and write permissions. PHP should not be able to modify source code, SQL, or Apache configuration.
- Configure centralized logs, alerting, time synchronization, patching, backup retention, and a documented incident process.
- Take encrypted backups and regularly test full restore, including attachment storage when implemented.

## Release checks

- Import into a new test database and create the initial administrator using the documented CLI bootstrap.
- Verify forced first password change, each role's access boundaries, transfer separation of duties, stock concurrency, reports, and account revocation.
- Run organization-approved security, privacy, accessibility, and user acceptance testing.
- Approve production access and data-retention policies before onboarding users.
- Do not treat application audit rows as tamper-evident records. If formal audit evidence is required, send events to an independently administered, append-only log service.
- The runtime database account must have no `UPDATE` or `DELETE` privilege on `audit_logs`. The example grants access table by table so broader database-level privileges cannot bypass the audit restriction.
