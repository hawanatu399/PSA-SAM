# PSA-SAMS | ICT demonstration brief

**Audience:** Director of ICT and Deputy Director  
**Demonstration environment:** Local XAMPP at `http://localhost/PSA-SAMS/`  
**Purpose:** Review the working application, agree the ICT assessment path, and identify approvals needed before any operational deployment.

The local sign-in and application header use the PSA logo and the public-facing tagline from the [Public Service Academy website](https://portal.psa.gov.sl/).

## Opening statement

> PSA-SAMS is a working local demonstration of asset, stores, requisition, maintenance, physical verification, and reporting workflows. I am asking ICT to assess the proposed managed internal hosting and security review—not to approve this local XAMPP instance for operational data or go-live today.

## What is available to demonstrate

- Role-based sign-in and a dashboard with asset, stores, maintenance, and approval indicators.
- Asset register search and filters; asset assignment and transfers.
- Store inventory movements and a requester/approver/issuer requisition workflow.
- Physical found/missing checks and configurable campaigns with department coverage.
- Audit history, CSV exports, and browser print/Save-as-PDF reports.
- Printable Code 39 asset labels and scan lookup with a compatible barcode scanner.
- Batch printing for the asset labels shown in the current asset-register results.
- Super-administrator management of department and asset-category reference data, with changes recorded in the audit history.
- Optional physical-check image evidence, served only to authorized signed-in users.
- In-app notices when an active verification campaign is nearing/past its due date.
- A CLI readiness check for local installation prerequisites.

## Suggested 8–10 minute walkthrough

1. **Start at the dashboard** — show the application is running locally and explain that the current setup uses XAMPP.
2. **Open Assets** — show the register, search/filter controls, and asset detail workflow.
3. **Open Stores & inventory and Store requisitions** — explain that inventory movements are tracked, requisitions require distinct requester, approver, and issuer identities, and stock is deducted only on issue.
4. **Open Physical verification** — show campaign scheduling, department coverage, and the check history. There are currently no campaigns or verification events in the local database. If you choose to create a campaign during the meeting, use only data approved for demonstration and label the campaign clearly as a demonstration.
5. **Open Reports & audit** — show audit filters, CSV exports, and the print report action.
6. **Close on deployment readiness** — show the successful local preflight and its root-user warning. Explain that ICT needs to review a managed internal environment before live use.

Do not enter personal, financial, or operational Academy information into the local demonstration unless explicitly authorized.

## Current local status (checked 3 November 2026)

- PHP 8.2, PDO MySQL, required tables, InnoDB storage, campaign linkage, duplicate-check constraint, and protected evidence storage pass the local readiness check.
- The readiness result is **PASS with one warning**: local XAMPP connects as MySQL `root`. That is for local development only and is not an acceptable managed-server runtime identity.
- The local database currently contains 3 departments, 3 assets, 3 stock items, and 1 active user. There are no campaigns or physical verification events.
- Because there is only one active user, this installation cannot demonstrate a complete end-to-end handoff between different people for requisition request, approval, and issue. Do not claim that multi-user acceptance testing has been completed.
- The web application responds at `http://localhost/PSA-SAMS/`. Keep XAMPP, phpMyAdmin, and MySQL restricted to the local demonstration machine; do not expose them to the internet.

## Decisions and support requested from ICT

1. Nominate the system owner and technical contact for an ICT assessment.
2. Agree whether the target should be an internally managed web/database service, and identify the approved host, network boundary, TLS, backups, logs, monitoring, and support arrangements.
3. Confirm the review path for application security, privacy, access roles, and database permissions.
4. Identify the business stakeholders who must approve department access, financial delegation, requisition roles, verification cadence/evidence, and records retention.
5. Agree a test environment and acceptance plan, including separate test accounts for requester, approver, issuer, and read-only management.

## Important limitations to state clearly

- This application is a demonstration baseline, not a certified or approved production system.
- XAMPP and its `root` database account are local-development defaults only. Production requires an ICT-managed host and a dedicated least-privilege database account.
- Application audit history is not a tamper-evident regulatory log. Central independently administered logging, backups, tested restoration, monitoring, and incident processes are not integrated.
- Requisition and campaign workflows are implemented baselines; Academy policy owners must approve the actual delegation, access, verification, privacy, and retention rules.
- Optional image evidence is implemented, but approved retention and privacy controls are not. Campaign reminders are generated in-app when the application is opened; there is no background scheduler or email service.
- The new evidence upload stores optional images locally; it has no malware scanning, configurable retention, or at-rest encryption. The recorded verifier and time are an application sign-off, not a formal electronic signature. Do not upload sensitive images before ICT approves controls.
- Barcode labels use Code 39 and encode the internal numeric asset ID; scan lookup expects a compatible keyboard-wedge scanner. QR labels and camera-based scanning are not included.
- Integration planning is documented, but there are no external system connectors or email reminders.

## Suggested close

> If ICT agrees, the next step is a scoped technical and policy review in a managed test environment, followed by role-based acceptance testing. No operational records should be loaded and no go-live should be declared until the required reviews and approvals are complete.

## Before the meeting

- Start Apache and MySQL in XAMPP and open the local sign-in page.
- Sign in using your assigned administrator account; keep the password private and do not put it in this document or the presentation.
- Run the read-only local check in PowerShell:

```powershell
& 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\PSA-SAMS\bin\check_readiness.php'
```

- Keep this brief open for the scope, limitations, and ICT decisions requested.
- For the managed deployment requirements, see [the deployment handover checklist](./README.md).
