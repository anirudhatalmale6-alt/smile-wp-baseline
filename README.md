# Smile Creative — WordPress baseline

The mechanical half of the Website SOP, packaged so it takes two minutes per
site instead of half an hour, and so it comes out the same every time.

Nothing here needs a licence, a subscription or an install. Two must-use
plugins, two config templates, and one script that runs the QC checklist for
you.

```
mu-plugins/
  smile-wp-admin.php          admin email policy + kills the update-success noise
  smile-security-headers.php  security headers, xmlrpc off, no username enumeration
templates/
  wp-config-additions.php     the standard defines
  htaccess-hardening.conf     the standard hardening block
qc/
  sop-qc.py                   runs the checkable part of the QC checklist
SOP-v2.1-proposed-amendments.md
```

## The checker

```
python3 qc/sop-qc.py https://thesite.co.uk
```

Standard library only — no pip install, runs anywhere with Python 3. It only
reads pages, never writes, so it is safe to point at a live client site. Exit
code is 1 if anything failed, so it can gate a deploy.

It checks, across the homepage and up to six inner pages it finds from the nav:

**Pages** — title present, unique, under 60 characters · meta description
present, unique, under 155 · exactly one H1 · canonical · og:title · images
missing alt text · non-WebP images · raw shortcodes showing as text · "Powered
by WordPress" left visible

**Site** — robots.txt · llms.txt · favicon · sitemap referenced and reachable ·
LocalBusiness and WebSite schema · AggregateRating · agency credit in the footer
· a branded 404 that actually returns 404

**Security** — the five response headers · http → https is a 301 · xmlrpc,
wp-config, readme.html and directory browsing all blocked · REST user
enumeration · author archive enumeration · WordPress version string · whether
the login URL is still at the default

**Links** — every internal link resolves · no `href="#"` placeholders left

It knows a noindexed site is probably staging and skips the sitemap checks
rather than failing them, because a checker that cries wolf gets ignored.

**LiteSpeed gotcha it caught:** a cached page is served without running PHP, so
security headers set by the mu-plugin appear on a cache miss and vanish on a
cache hit. That is why the headers are in `templates/htaccess-hardening.conf`
as well. Always test with and without a cache-busting query string.

### What it deliberately does not check

Judgement. Run these by hand, every time:

1. **Submit every form and open the destination inbox.** Submitted is not
   delivered. This is the Logan's Removals rule: the calculator saved every
   quote and showed a success message while the email code sat commented out,
   and two weeks of real leads went nowhere.
2. Read the homepage cold for 30 seconds — does it read as machine-made?
3. Are the photographs the client's own, or stock?
4. Is the About origin story factually true?
5. PageSpeed Insights on mobile, aim 80+.

## The mu-plugins

Drop both in `wp-content/mu-plugins/`. Must-use plugins load before everything
else and cannot be switched off from the dashboard, so the policy holds even if
a client changes a setting or a plugin tries to.

**smile-wp-admin.php** — pins the administration email to
`wpadmin@smilecreative.agency` (override with `SMILE_WP_ADMIN_EMAIL` in
wp-config), which also skips WordPress's confirm-by-email dance that makes
changing it across thirty sites painful. Mutes the "your site updated
successfully" emails; deliberately keeps update *failures*, fatal-error and
recovery-mode notices, password changes and new-user notifications.

Remember it is two settings per site: the administration email **and** your own
user account's email. Post and comment notifications follow the user account.

**smile-security-headers.php** — HSTS, nosniff, frame options, referrer policy,
permissions policy, cross-origin opener policy. Turns off XML-RPC, removes the
version string, makes login errors generic, and closes both username
enumeration routes (`/wp-json/wp/v2/users` and `/?author=1`). If a site needs
real author archives, define `SMILE_ALLOW_AUTHOR_ARCHIVES` true.

## Order of work on a new site

1. Create the account, database and subdomain. Pin the PHP version if the host
   defaults to something old.
2. Install WordPress. Paste in `templates/wp-config-additions.php`.
3. Paste `templates/htaccess-hardening.conf` above the WordPress block in
   `.htaccess`.
4. Copy both mu-plugins in.
5. Build the site.
6. `python3 qc/sop-qc.py https://...` until it reports zero failures.
7. The five human checks above.
8. Only then say it is ready.
