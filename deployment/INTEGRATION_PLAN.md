# PSA-SAMS integration assessment plan

This is a discovery and governance plan, not an implemented integration. No external Academy system, credentials, endpoints, or personal data are configured in PSA-SAMS.

## Candidate integration areas

| Priority | Candidate | Potential value | ICT/business decision required |
|---|---|---|---|
| 1 | Academy identity provider / single sign-on | Centralized authentication, MFA, and account lifecycle | Approved identity platform, OIDC/SAML method, account claims, role mapping, MFA/session rules |
| 2 | Finance or procurement system | Reduce duplicate entry for acquisition and purchasing references | System owner, authoritative fields, approved API/export, transaction reconciliation, financial controls |
| 3 | HR or staff directory | Keep staff and department assignments current | Source of truth, employee identifier, joiner/mover/leaver process, permitted attributes |
| 4 | Email or approved messaging service | Deliver actionable reminders and workflow notices | Approved relay/provider, sender identity, recipient rules, data classification, delivery/retention policy |
| 5 | Backup, central logging, and monitoring services | Operational resilience and independently managed event visibility | ICT platform, retention, alert ownership, restore objectives, access controls |

## Recommended integration sequence

1. Name a system owner, ICT technical owner, and business data owners.
2. Confirm target architecture, network boundaries, data classification, and whether the app may exchange data with each candidate system.
3. Identify each system's authoritative records and define a minimal field-level data contract.
4. Prefer standards-based, authenticated APIs with least privilege; avoid shared administrator credentials and direct cross-system database access.
5. Define reconciliation, error handling, retries, audit events, duplicate handling, and a manual fallback before building connectors.
6. Create a test environment and synthetic test records; obtain API credentials through protected configuration only.
7. Complete security/privacy review and user acceptance testing before enabling a connector in an operational environment.

## Proposed first technical discovery

Ask ICT whether the Academy has an approved identity provider and an internal email notification service. These are discovery questions only. The current application uses its own local accounts and in-app notifications; it does not connect to SSO or send email.

## Not included in this project build

- SSO, HR, finance/procurement, email, messaging, or other system connectors.
- API endpoints, external credentials, synchronization jobs, webhooks, or outbound network calls.
- Approved integration data contracts or operational support commitments.

No integration should be implemented until ICT identifies the actual systems, supported interface, accountable owner, and required access policy.
