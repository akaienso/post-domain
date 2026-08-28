# CteSubtreeAdapter — capability evidence

`CteSubtreeAdapter` is **disabled**. It is enabled only by defining
`PD_ENABLE_CTE_SUBTREE` in `wp-config.php`, and that constant must not be
defined for an environment until this document records all three items below
for that environment.

Recursive common table expressions require **MySQL 8.0** or **MariaDB 10.2.2**
at minimum. Those floors are nominal: they are the published minimums, not
evidence about any environment this plugin actually runs on.

## Required evidence, per target environment

| Item | How to obtain it |
|---|---|
| 1. Environment identity | Hosting provider and plan, or "self-hosted" plus the server identity |
| 2. Database version string | `SELECT VERSION();` run against that site's database |
| 3. Product | MySQL or MariaDB — the version string alone is ambiguous between them |

## Recorded environments

_None yet. No target environment has been established for this plugin, so no
row can honestly be filled in. Until at least one row exists, the enumeration
provider is the only scope provider in use._

| Environment | `SELECT VERSION()` | Product | Probe result | Enabled |
|---|---|---|---|---|
| | | | | |
