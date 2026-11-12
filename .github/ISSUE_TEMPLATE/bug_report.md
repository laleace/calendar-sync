---
name: Bug report
about: Something does not work as expected
labels: bug
---

**What happened**
<!-- What you did, what you expected, what you got. -->

**How to reproduce**
1.
2.

**Setup**
- calendar-sync version (or commit):
- Installed with: Docker / Debian server / local development
- Account types involved: iCloud / CalDAV (which server?) / Google

**Output**
<!--
The error shown in the run history, and the output of `php artisan calsync:doctor`
(with Docker: `docker compose exec web php artisan calsync:doctor`).
For CalDAV problems, `php artisan calsync:test-account {id} --debug` shows the requests.
Credentials are masked, but check for anything personal (calendar names, titles, addresses) before pasting.
-->
