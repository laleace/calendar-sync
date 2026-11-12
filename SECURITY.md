# Security policy

calendar-sync stores the credentials of the calendar accounts it syncs: CalDAV and iCloud app-specific passwords, and Google OAuth tokens. Security reports are welcome and taken seriously.

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private reporting instead: open the repository's **Security** tab and choose **Report a vulnerability**.

Include, where possible:
- the affected version or commit;
- the steps to reproduce, or a proof of concept;
- the impact you expect, for example credential disclosure or access to the panel without login.

You should get a first answer within 7 days. Once a fix is released, the advisory is published with credit to you, unless you prefer to stay anonymous.

## Supported versions

Only the latest release receives security fixes.

## How the app protects credentials

- Account credentials are encrypted at rest with `APP_KEY` (AES-256) and never sent back to the browser.
- Errors and logs go through a redactor that masks `Authorization` and `Cookie` headers, bearer and basic tokens, and the account's known secrets.
- CalDAV credentials are only sent to the configured host or, for iCloud, to `*.icloud.com`.
- The panel has a single administrator: no public registration and no password reset.

## Recommendations for your installation

- Keep the panel off the public Internet: reach it from your LAN or through a VPN. The provided nginx configuration only accepts private networks.
- Run with `APP_DEBUG=false` and HTTPS, and keep `.env` readable only by the app user.
- Back up `APP_KEY` somewhere safe: without it the stored credentials cannot be decrypted.
- Run `php artisan calsync:doctor` after every update.
