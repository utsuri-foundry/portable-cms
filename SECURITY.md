# Security policy

Portable CMS is pre-1.0 software. The current development line receives security
review and fixes; there is no supported older-release window, guaranteed fix
deadline or production support SLA yet. Run a reviewed current release and keep
PHP, MariaDB and the web server supported by their vendors. See the
[qualified hosting boundary](docs/development/conventional-host-probe.md).

Report suspected vulnerabilities privately to **admin@utsuri.io**, or use
GitHub's **Security → Report a vulnerability** when enabled on the public
repository. Do not put exploits, credentials, client data or private host details
in public issues. Include the affected version, boundary, impact, minimal
reproduction and a safe way to contact you. Remove real secrets and content.

We aim to acknowledge reports within five working days and provide an initial
assessment within ten working days. These are response targets, not a guaranteed
resolution time. If you hear nothing after five working days, follow up by email.
We coordinate disclosure with the reporter after assessing affected versions,
mitigation and a fix; please keep details private while that work proceeds.
Accepted issues receive a security advisory and release notes when a fix is
available. Urgent operator mitigations may precede a release.

The publication runbook requires a person to enable and verify GitHub private
reporting at public cutover. The email route remains available independently.
