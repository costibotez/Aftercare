# Security Policy

Aftercare runs inside WordPress sites that belong to real businesses, so security reports are taken seriously. Thank you for helping keep those sites safe.

## Supported Versions

Only the latest release of Aftercare receives security fixes. Please update to the newest version from WordPress.org before you report a problem.

| Version | Supported |
| ------- | --------- |
| 1.1.x   | Yes       |
| < 1.1   | No        |

## Reporting a Vulnerability

**Please do not report security vulnerabilities in public GitHub issues, discussions or pull requests.**

Report them privately through GitHub's [private vulnerability reporting form](https://github.com/costibotez/Aftercare/security/advisories/new). If you cannot use GitHub, contact the maintainer through [nomad-developer.co.uk](https://www.nomad-developer.co.uk/) and ask for a private channel. Do not include vulnerability details in that first message.

You can also report through the [Patchstack](https://patchstack.com/database/report) or [Wordfence](https://www.wordfence.com/threat-intel/vulnerabilities/submit) disclosure programs, which notify the maintainer and coordinate with the WordPress.org plugin team.

### What to include

- The Aftercare version and the WordPress and PHP versions you tested on
- The type of issue (for example cross site scripting, SQL injection, missing authorization or data exposure)
- The file, screen or REST route involved
- Step by step instructions to reproduce the issue
- A proof of concept if you have one
- The impact you believe the issue has and who could exploit it (an anonymous visitor, a subscriber, an editor and so on)

### What to expect

- An acknowledgement of your report within 5 working days
- An initial assessment within 10 working days
- A fix released as quickly as possible for confirmed issues, depending on severity
- Credit in the changelog when the fix ships, unless you would rather stay anonymous

Aftercare is maintained by one developer, so these are goals and not guarantees. There is no paid bug bounty.

Please give us a reasonable chance to release a fix before you disclose the issue publicly.

## Scope

### In scope

- The Aftercare plugin code in this repository, including the free and Pro features
- The RUM REST endpoint and the front end beacon script
- Admin screens, settings, exports and email templates shipped with the plugin

### Out of scope

- Vulnerabilities in WordPress core, other plugins, themes or the hosting stack
- Reports that need an administrator account, since administrators can already run any code on a WordPress site
- Missing security headers or other server configuration choices
- Denial of service through high traffic volumes
- Results from automated scanners without a working proof of concept
- Social engineering of the maintainer or of site owners

## Secrets in this Repository

This repository must never contain passwords, API keys, SVN credentials or licence keys. Release credentials live only in GitHub Actions secrets. If you spot a secret in the code or in the history, report it privately using the steps above.
