# Contributing to Aftercare

Thank you for taking the time to help with Aftercare. Bug reports, translations, documentation fixes and code are all welcome. This guide explains how to get a change from your machine into a release.

Please note that sending a pull request does not guarantee it will be merged. Opening an issue first is the best way to avoid wasted work.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Before You Start](#before-you-start)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Features](#suggesting-features)
- [Setting Up a Development Site](#setting-up-a-development-site)
- [Project Layout](#project-layout)
- [Coding Standards](#coding-standards)
- [Free and Pro Code](#free-and-pro-code)
- [Testing Your Change](#testing-your-change)
- [Commit Messages](#commit-messages)
- [Submitting a Pull Request](#submitting-a-pull-request)
- [Translations](#translations)
- [License](#license)

## Code of Conduct

Everyone taking part in this project is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md). Please read it before you join a discussion.

## Before You Start

- **Open an issue first** for anything bigger than a typo or a one-line fix. Describe the problem and the change you have in mind so we can agree on the approach before you write code.
- **Search existing issues** to check that nobody else is already working on it.
- **Security problems are different.** Never report a vulnerability in a public issue. Follow the [Security Policy](SECURITY.md) instead.

## Reporting Bugs

A good bug report helps us fix the problem quickly. Please include:

- The Aftercare version, the WordPress version and the PHP version
- Whether the site runs Action Scheduler (for example through WooCommerce) or plain WP-Cron
- The steps that reproduce the problem
- What you expected to happen and what happened instead
- Any related lines from the **Aftercare → PHP Errors** screen or your server error log

Please remove domain names, email addresses and other private data before you post.

## Suggesting Features

Feature ideas are welcome. Explain the problem you are trying to solve for your site or your clients rather than only the solution. Check the [roadmap](README.md#roadmap-free) first, since your idea may already be planned.

## Setting Up a Development Site

Requirements:

- WordPress 6.4 or newer
- PHP 8.1 or newer
- A local WordPress site you can break safely (Local, wp-env, DDEV or any similar tool)

Steps:

1. Fork the repository on GitHub and clone your fork.
2. Link or copy the repository into `wp-content/plugins/aftercare` on your local site.
3. Activate **Aftercare** from the Plugins screen.
4. Add the upstream repository so you can stay in sync:

   ```bash
   git remote add upstream https://github.com/costibotez/Aftercare.git
   ```

There is no build step and no Composer or npm install. The plugin is plain PHP with a small PSR-4 autoloader in `aftercare.php`.

To force a full daily run while you work, open **Aftercare → Dashboard** and click **Run daily checks now**. Lower a budget below the current value first if you want to see an incident and its alert email.

## Project Layout

The [Development section of the README](README.md#development) lists every folder and the hooks the plugin exposes. In short:

- `src/` holds the plugin classes under the `Aftercare\` namespace
- `assets/` holds the admin CSS and JavaScript and the RUM beacon
- `templates/` holds email and report templates
- `bin/` holds the build and compatibility test scripts

## Coding Standards

- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP, JavaScript and CSS.
- Keep the code dependency free. Please do not add frameworks, Composer packages or front end build tools.
- Escape all output, sanitize all input and check nonces and capabilities on every action that changes data.
- Make every user facing string translatable with the `aftercare` text domain.
- Prefix hooks, options and database tables with `aftercare_`.
- Keep pages fast. Aftercare measures performance, so it must not add weight to the front end of a site.

## Free and Pro Code

This repository holds the full codebase. The free WordPress.org build is produced by `bin/build-zip.sh`, which leaves out the files listed in `.distignore`.

- Free code must never lock features behind a licence check. WordPress.org guidelines forbid it.
- Free code must keep working when the Pro classes are missing. Guard any Pro class with `class_exists()`.
- If your change adds a Pro only file, add it to `.distignore` in the same pull request.

## Testing Your Change

Before you open a pull request:

1. Build the free zip and make sure it succeeds:

   ```bash
   bash bin/build-zip.sh
   ```

2. Install that zip on a clean WordPress site with `WP_DEBUG` turned on and check that no notices, warnings or errors appear.
3. Run the compatibility suite if you can. It needs a WordPress checkout and an empty database:

   ```bash
   DB_NAME=wp DB_USER=wp DB_PASSWORD=wp bin/compat-test.sh /path/to/wordpress dist/aftercare.*.zip
   ```

   The same suite runs automatically on every pull request against the minimum supported WordPress, the latest release and the nightly build.

4. Test every admin screen you touched in a desktop browser and at a narrow mobile width.

## Commit Messages

- Write the summary line in the imperative mood, for example "Add a budget filter for single URLs".
- Keep the summary under 72 characters.
- Use the body to explain why the change is needed when it is not obvious.
- Keep each commit focused on one change.

## Submitting a Pull Request

1. Create a branch from `main` with a short descriptive name.
2. Make your change and test it as described above.
3. Update `readme.txt` and `README.md` when the change affects users or developers.
4. Push the branch to your fork and open a pull request against `main`.
5. Link the issue the pull request resolves and describe how you tested it.
6. Be ready to make changes after review. Small, focused pull requests are reviewed fastest.

Please do not bump the plugin version in your pull request. The maintainer handles versions and releases.

## Translations

Translations for the free plugin are managed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/aftercare/). Please contribute translations there rather than sending `.po` or `.mo` files in a pull request.

## License

By contributing to Aftercare you agree that your contributions will be licensed under the [GNU General Public License v2.0 or later](LICENSE), the same license that covers the project.
