# Website SOP — proposed amendments for v2.1

Written after auditing the Get It Framed NI build against v2.0, then running the
same checks against three other sites. Every amendment below comes from
something that actually went wrong or was actually missed, not from theory.

Numbered so they can be argued with one at a time.

---

## 1. Phase 3, Step 5 — make the stack a decision, not an instruction

**Now:** "Install WordPress + Astra theme + Elementor (free). Set up Header
Footer Elementor (HFE)."

**Problem:** it reads as the only permitted build, so anything else scores as
non-compliant on rows that cannot apply to it. A custom-theme build fails
"set Elementor typography on every widget" for the excellent reason that there
are no widgets.

**Proposed:** two named tracks, chosen at Step 5 and recorded in the project.

> **Track A — Astra + Elementor free + HFE.** The default. Use when the client
> will build or restructure pages themselves, when the site will grow (blog,
> shop, monthly content), or when the site may be handed to another developer.
>
> **Track B — custom theme, no builder.** Use when converting a design that has
> already been signed off as HTML, or when the client will only ever edit text
> and images inside existing layouts. Content still lives in normal WordPress
> post types so the client edits it in the normal editor.
>
> Tie-break: our existing rule. *Does the client publish new content, or do they
> just need to be found and phoned?* Publishes → Track A. Found and phoned →
> Track B is available.

Everything in Phases 4 to 9 applies to both tracks unchanged.

---

## 2. Split the approved plugin list into universal and stack-dependent

**Problem:** the current table implies eight plugins on every site. Four of them
exist to supply something a custom theme already has, and installing them anyway
means two systems writing the same meta tags.

**Proposed:**

**Universal — every site, both tracks, no exceptions:**

| Need | Plugin | Why it is universal |
|---|---|---|
| Backup | UpdraftPlus | Nothing in a theme replaces an off-site copy |
| Security | Wordfence | Firewall and malware scan are outside the theme's reach |
| Caching | LiteSpeed Cache | Both servers run LiteSpeed |
| SMTP | FluentSMTP | Authenticated delivery is a server concern, not a theme one |

**Stack-dependent — Track A needs them, Track B usually does not:**

| Need | Plugin | Skip it when |
|---|---|---|
| SEO | Rank Math | The theme outputs titles, descriptions, OG and JSON-LD itself |
| Forms | Contact Form 7 | The theme has its own hardened handler |
| reCAPTCHA | Simple Google reCAPTCHA | The form already has honeypot + timing + rate limiting |
| Page builder | Elementor free | Track B |

Rule: never run two things that write the same tag. One SEO source, one form
system, one cache, one backup.

---

## 3. Phase 5 — add the four hardening items v2.0 misses

Step 14 covers passwords, nulled plugins and file manager. It does not cover the
things a scanner finds in the first ten seconds. Add:

- **Username enumeration.** `/wp-json/wp/v2/users` and `/?author=1` both hand
  over every username on the site to anyone who asks, and a username is half of
  a brute-force attempt. Close both.
- **`readme.html` and `license.txt`.** `readme.html` states the exact WordPress
  version. Deny them.
- **Directory indexes.** `Options -Indexes`.
- **Version string and login errors.** Remove the generator meta tag; make login
  failures generic so they do not confirm which half was right.

All four are in `mu-plugins/smile-security-headers.php` in this kit, so the step
becomes "drop the mu-plugin in" rather than four manual edits.

---

## 4. Phase 4, Step 10 — add canonical tags, and say "including archives"

Not currently in the SEO list at all. Archives are where it goes wrong: WordPress
does not output a canonical for a post-type archive or a taxonomy term, so
`/services/` shipped without one on a build that had it everywhere else. It was
found by the checker, not by eye.

---

## 5. Phase 2 — add "produce a favicon" to the visual identity step

The QC checklist says "Favicon displays in browser tab", but nothing earlier in
the SOP says to make one, so it is discovered at QC with nothing to install.
Add to Step 4: a square mark that is legible at 16px. A wide logo shrunk into a
square never is — usually it needs a simplified mark in the brand colour.

---

## 6. Phase 7 — run the checker first, then do the five things it cannot

The QC checklist is 60-odd items and takes half an hour to do honestly, which is
why in practice it gets skimmed. Most of it is mechanical.

**Proposed:** Phase 7 becomes:

1. `python3 qc/sop-qc.py https://thesite.co.uk` — must report zero failures.
2. Then, by hand, the five it cannot judge:
   - submit every form and **open the destination inbox** (submitted is not
     delivered — this is the Logan's Removals rule and it stays first)
   - read the homepage cold for 30 seconds: does it read as machine-made?
   - are the photographs the client's own?
   - is the About origin story factually true?
   - PageSpeed Insights on mobile, 80+

The checker covers titles, descriptions, H1s, alt text, canonicals, schema,
favicon, robots, sitemap, llms.txt, security headers, xmlrpc, user enumeration,
directory browsing, broken internal links and `href="#"` placeholders.

---

## 7. Add a backup gate to Phase 7

UpdraftPlus is in the plugin list but appears nowhere in the QC checklist, so a
site can pass QC with no backup at all. Add:

> - [ ] UpdraftPlus configured with remote storage, has completed **one real
>       backup**, and that backup has been **restored once** somewhere else.
>       An untested backup is not a backup.

---

## 8. Phase 0 — the admin email standard

New policy as of August 2026: every install uses `wpadmin@smilecreative.agency`
as the administration email, not a personal address. Add to the Technical Brief:

> - Set the administration email to `wpadmin@smilecreative.agency`. Also change
>   **your own user account's email** on the site — the admin email catches
>   update and error notices, but post and comment notifications follow the user
>   account, so changing only one leaves half the noise in place.
> - Drop in `mu-plugins/smile-wp-admin.php`, which pins the address and mutes
>   the "updated successfully" notices while keeping failures.

---

## 9. Cookie consent — make it conditional

Step 19 says implement cookie consent. On a site with no analytics, no embedded
video and no reCAPTCHA, there is nothing to consent to, and the bar is pure
friction that costs conversions.

**Proposed:** "Required as soon as the site sets any non-essential cookie —
analytics, reCAPTCHA, embedded video, remarketing pixels. If it sets none, skip
it and note that in the project."

---

## 10. Note the WebP rule properly

Step 13 says convert everything to WebP and add rewrite rules. Worth saying
explicitly: the rewrite rules only matter if there are JPGs and PNGs on disk to
rewrite *from*. If the build ships WebP directly, the rules are redundant — but
they should still go in before handover, because the moment the client uploads a
photograph from their phone it will be a JPEG.
