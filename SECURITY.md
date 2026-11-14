# Security policy

## Supported versions

No version of this plugin has been released. This policy applies to the design documents now and to released code later.

## Reporting a vulnerability

Report vulnerabilities privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Do not open a public issue. If GitHub's private reporting is unavailable, contact Thomas Vincent at thomasvincent@gmail.com and do not include exploit details in the first message.

Include what is affected, how to reproduce it, and the versions of Cacti, PHP, the GPU vendor tool, and the driver involved. You will get an acknowledgement, and fixes are prepared privately until a release is available.

Vulnerabilities in Cacti core, in another Cacti plugin, or in a vendor's GPU driver or tools belong to that project's own disclosure process, not this repository.

## Design-level security

The security boundaries this design relies on, including command execution on GPU nodes and the privileges each collection path needs, are described in [docs/PLAN.md](docs/PLAN.md#security). Weaknesses found in the design itself, before any code exists, may be reported publicly as issues with the `area: security` label.
