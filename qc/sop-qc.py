#!/usr/bin/env python3
"""
Smile Creative — automated pre-launch QC.

Runs the checkable half of the Website SOP and the QC checklist against a live
URL and prints a pass/fail table. It reads pages; it never writes anything, so
it is safe to point at a live client site.

    python3 sop-qc.py https://example.co.uk
    python3 sop-qc.py https://example.co.uk --pages /about/ /contact/ /services/
    python3 sop-qc.py https://example.co.uk --crawl 25

What it cannot check is judgement: whether the copy is any good, whether the
origin story is true, whether the photographs are the client's own. Those stay
human. What it does check is the thirty-odd things that are tedious to verify by
hand and therefore quietly skipped.

Exit code is 1 if anything FAILED, so it can gate a deploy.

Standard library only, on purpose — it has to run anywhere with no install.
"""

import argparse
import gzip
import json
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import OrderedDict

UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0 Safari/537.36")

PASS, FAIL, WARN, INFO = "PASS", "FAIL", "WARN", "INFO"

CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE


class Result:
    def __init__(self):
        self.rows = []

    def add(self, group, name, status, detail=""):
        self.rows.append((group, name, status, detail))

    def report(self):
        width = max(len(r[1]) for r in self.rows) + 2
        group = None
        counts = {PASS: 0, FAIL: 0, WARN: 0, INFO: 0}
        for g, name, status, detail in self.rows:
            if g != group:
                print(f"\n{g.upper()}")
                print("-" * (width + 46))
                group = g
            counts[status] += 1
            print(f"  {status:5}  {name:<{width}} {detail}")
        print()
        print(f"{counts[PASS]} passed, {counts[FAIL]} failed, "
              f"{counts[WARN]} warnings, {counts[INFO]} informational")
        return counts[FAIL]


def fetch(url, method="GET", timeout=30):
    """Return (status, headers, body). Never raises for HTTP errors."""
    req = urllib.request.Request(url, method=method, headers={
        "User-Agent": UA,
        "Accept": "text/html,application/xhtml+xml,*/*",
        "Accept-Encoding": "gzip",
    })
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=timeout) as r:
            body = r.read()
            if r.headers.get("Content-Encoding") == "gzip":
                try:
                    body = gzip.decompress(body)
                except Exception:
                    pass
            return r.status, dict(r.headers), body.decode("utf8", "replace")
    except urllib.error.HTTPError as e:
        body = b""
        try:
            body = e.read()
        except Exception:
            pass
        # An error response is gzipped too. Miss this and every 404 body reads
        # as 5KB of binary, so a branded 404 looks unstyled.
        if (e.headers or {}).get("Content-Encoding") == "gzip":
            try:
                body = gzip.decompress(body)
            except Exception:
                pass
        return e.code, dict(e.headers or {}), body.decode("utf8", "replace")
    except Exception as e:
        return 0, {}, f"__ERROR__ {type(e).__name__}: {e}"


def head_status(url):
    status, _, _ = fetch(url, method="GET")
    return status


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **kw):
        return None


def fetch_noredirect(url, timeout=20):
    """Fetch without following redirects, so a 301 can be seen as a 301."""
    opener = urllib.request.build_opener(NoRedirect,
                                         urllib.request.HTTPSHandler(context=CTX))
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with opener.open(req, timeout=timeout) as r:
            return r.status, dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers or {})
    except Exception:
        return 0, {}


def text_of(html):
    """Visible text only. Script and style blocks must go first, or their
    contents (CSS selectors like img[sizes="auto" i]) read as shortcodes."""
    html = re.sub(r"<script\b.*?</script>", " ", html, flags=re.S | re.I)
    html = re.sub(r"<style\b.*?</style>", " ", html, flags=re.S | re.I)
    html = re.sub(r"<!--.*?-->", " ", html, flags=re.S)
    return re.sub(r"<[^>]+>", " ", html)


# --------------------------------------------------------------------------
# security
# --------------------------------------------------------------------------

REQUIRED_HEADERS = OrderedDict([
    ("strict-transport-security", "HSTS"),
    ("x-content-type-options", "nosniff"),
    ("x-frame-options", "frame options"),
    ("referrer-policy", "referrer policy"),
    ("permissions-policy", "permissions policy"),
])


def check_security(res, base):
    host = urllib.parse.urlparse(base).netloc

    status, headers, _ = fetch(base)
    lower = {k.lower(): v for k, v in headers.items()}

    missing = [label for key, label in REQUIRED_HEADERS.items() if key not in lower]
    if missing:
        res.add("security", "security headers", FAIL, "missing: " + ", ".join(missing))
    else:
        res.add("security", "security headers", PASS, "all five present")

    # http -> https
    plain = "http://" + host + "/"
    st, hd = fetch_noredirect(plain)
    loc = {k.lower(): v for k, v in hd.items()}.get("location", "")
    if st in (301, 308) and loc.startswith("https://"):
        res.add("security", "http redirects to https", PASS, f"{st}")
    elif st in (302, 307) and loc.startswith("https://"):
        res.add("security", "http redirects to https", WARN, "temporary redirect, should be 301")
    else:
        res.add("security", "http redirects to https", FAIL, f"status {st} -> {loc or 'no location'}")

    for path, label, want_blocked in [
        ("/xmlrpc.php", "xmlrpc blocked", True),
        ("/wp-config.php", "wp-config not served", True),
        ("/readme.html", "readme.html blocked", True),
        ("/wp-content/uploads/", "no directory browsing", True),
    ]:
        st = head_status(base + path)
        ok = st in (401, 403, 404)
        res.add("security", label, PASS if ok else FAIL, f"status {st}")

    # user enumeration
    st, _, body = fetch(base + "/wp-json/wp/v2/users")
    if st == 200 and '"slug"' in body:
        names = re.findall(r'"slug":"([^"]+)"', body)
        res.add("security", "REST user enumeration", FAIL, "exposes: " + ", ".join(names[:5]))
    else:
        res.add("security", "REST user enumeration", PASS, f"status {st}")

    st, hd = fetch_noredirect(base + "/?author=1")
    loc = {k.lower(): v for k, v in hd.items()}.get("location", "")
    if "/author/" in loc:
        res.add("security", "author archive enumeration", WARN, f"redirects to {loc}")
    else:
        res.add("security", "author archive enumeration", PASS, "")

    st, _, body = fetch(base)
    gen = re.search(r'name="generator" content="WordPress ([^"]+)"', body)
    if gen:
        res.add("security", "WordPress version hidden", WARN, f"advertises {gen.group(1)}")
    else:
        res.add("security", "WordPress version hidden", PASS, "")

    st = head_status(base + "/wp-login.php")
    if st == 200:
        res.add("security", "login URL hidden", WARN, "wp-login.php reachable")
    else:
        res.add("security", "login URL hidden", PASS, f"status {st}")


# --------------------------------------------------------------------------
# per-page SEO and content
# --------------------------------------------------------------------------

def check_page(res, base, path, seen_titles, seen_descs):
    url = base.rstrip("/") + path
    status, _, html = fetch(url)
    label = path

    if status != 200:
        res.add("pages", label, FAIL, f"status {status}")
        return None

    problems = []
    notes = []

    m = re.search(r"<title>(.*?)</title>", html, re.S)
    title = re.sub(r"\s+", " ", m.group(1)).strip() if m else ""
    title_len = len(unescape(title))
    if not title:
        problems.append("no title")
    elif title_len > 60:
        problems.append(f"title {title_len}ch")
    if title in seen_titles:
        problems.append("duplicate title")
    seen_titles.add(title)

    d = re.search(r'name="description"[^>]*content="(.*?)"', html, re.S)
    desc = d.group(1) if d else ""
    desc_len = len(unescape(desc))
    if not desc:
        problems.append("no meta description")
    elif desc_len > 155:
        problems.append(f"description {desc_len}ch")
    if desc and desc in seen_descs:
        problems.append("duplicate description")
    seen_descs.add(desc)

    h1s = re.findall(r"<h1[^>]*>(.*?)</h1>", html, re.S)
    if len(h1s) != 1:
        problems.append(f"{len(h1s)} h1")

    if not re.search(r'rel="canonical"', html):
        problems.append("no canonical")
    if not re.search(r'property="og:title"', html):
        problems.append("no og:title")

    imgs = re.findall(r"<img[^>]*>", html)
    noalt = [i for i in imgs if "alt=" not in i]
    if noalt:
        problems.append(f"{len(noalt)}/{len(imgs)} images missing alt")
    nonwebp = [i for i in imgs if re.search(r"\.(jpg|jpeg|png)\b", i, re.I)]
    if nonwebp:
        notes.append(f"{len(nonwebp)} non-webp images")

    body_text = text_of(html)
    shortcodes = re.findall(r"\[(?!/)[a-z][a-z0-9_-]{2,}[^\]]{0,80}\]", body_text)
    if shortcodes:
        problems.append("raw shortcode visible: " + shortcodes[0][:30])

    if "Powered by WordPress" in html:
        problems.append("'Powered by WordPress' visible")

    if problems:
        res.add("pages", label, FAIL, "; ".join(problems) + (
            "  [" + "; ".join(notes) + "]" if notes else ""))
    else:
        res.add("pages", label, PASS, f"title {title_len}ch, desc {desc_len}ch, "
                                      f"{len(imgs)} images" + (
            ", " + "; ".join(notes) if notes else ""))
    return html


def unescape(s):
    return re.sub(r"&#?\w+;", "x", s)


# --------------------------------------------------------------------------
# site-wide
# --------------------------------------------------------------------------

def check_sitewide(res, base, home_html):
    # A site set to "discourage search engines" correctly has no sitemap and a
    # blocking robots.txt. Scoring that as a failure trains people to ignore
    # failures, so report it once and move on.
    noindex = bool(re.search(r'<meta name=.robots.[^>]*noindex', home_html, re.I))
    if noindex:
        res.add("site", "search engine visibility", INFO,
                "site is set to noindex — sitemap/robots checks skipped (staging?)")

    for path, label, required in [
        ("/robots.txt", "robots.txt", True),
        ("/llms.txt", "llms.txt", True),
        ("/favicon.ico", "favicon", False),
    ]:
        st = head_status(base + path)
        if st == 200:
            res.add("site", label, PASS, "")
        elif label == "favicon" and re.search(r'rel="[^"]*icon"', home_html):
            res.add("site", label, PASS, "declared in head")
        else:
            res.add("site", label, FAIL if required else WARN, f"status {st}")

    st, _, robots = fetch(base + "/robots.txt")
    if st == 200 and not noindex:
        if re.search(r"^\s*Disallow:\s*/\s*$", robots, re.M):
            res.add("site", "robots.txt not blocking site", WARN, "Disallow: / present (staging?)")
        elif "Sitemap:" in robots:
            res.add("site", "robots.txt references sitemap", PASS, "")
        else:
            res.add("site", "robots.txt references sitemap", FAIL, "no Sitemap: line")

    if not noindex:
        for path in ("/wp-sitemap.xml", "/sitemap_index.xml", "/sitemap.xml"):
            st = head_status(base + path)
            if st == 200:
                res.add("site", "xml sitemap", PASS, path)
                break
        else:
            res.add("site", "xml sitemap", FAIL, "none of wp-sitemap / sitemap_index / sitemap")

    types = schema_types(home_html)
    for want in ("LocalBusiness", "WebSite"):
        ok = any(want in t for t in types)
        res.add("site", f"{want} schema", PASS if ok else FAIL, ", ".join(types) if not ok else "")
    if not any("AggregateRating" in t for t in types):
        res.add("site", "AggregateRating schema", WARN, "no review stars in search results")

    if "Designed by" in home_html or "Smile Creative" in home_html:
        res.add("site", "agency credit in footer", PASS, "")
    else:
        res.add("site", "agency credit in footer", FAIL, "no 'Designed by Smile Creative'")

    st, _, body = fetch(base + "/this-page-does-not-exist-qc-check/")
    if st == 404:
        branded = len(body) > 3000 and "<footer" in body
        res.add("site", "404 page", PASS if branded else WARN,
                "branded" if branded else "returns 404 but looks unstyled")
    else:
        res.add("site", "404 page", FAIL, f"returns {st}, not 404")


def schema_types(html):
    out = []
    for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try:
            obj = json.loads(block)
        except Exception:
            out.append("PARSE-FAIL")
            continue
        for item in (obj if isinstance(obj, list) else [obj]):
            if not isinstance(item, dict):
                continue
            if "@graph" in item:
                out += [str(g.get("@type")) for g in item["@graph"]]
            elif "@type" in item:
                out.append(str(item["@type"]))
    return out


# --------------------------------------------------------------------------
# links
# --------------------------------------------------------------------------

def check_links(res, base, pages_html, limit):
    host = urllib.parse.urlparse(base).netloc
    found = set()
    for html in pages_html:
        for href in re.findall(r'href="([^"#?]+)', html):
            if href.startswith("mailto:") or href.startswith("tel:"):
                continue
            full = urllib.parse.urljoin(base, href)
            if urllib.parse.urlparse(full).netloc != host:
                continue
            if re.search(r"\.(css|js|png|jpe?g|webp|svg|ico|woff2?|xml|txt)$", full, re.I):
                continue
            # machine endpoints, not pages a visitor can reach
            if "/wp-json" in full or "/feed" in full or "/xmlrpc" in full:
                continue
            found.add(full.rstrip("/") or base)

    checked, broken, placeholder = 0, [], 0
    for html in pages_html:
        placeholder += len(re.findall(r'href="#"', html))

    for url in sorted(found)[:limit]:
        st = head_status(url)
        checked += 1
        if st >= 400:
            broken.append(f"{urllib.parse.urlparse(url).path} ({st})")

    res.add("links", "internal links resolve", PASS if not broken else FAIL,
            f"{checked} checked" + ("" if not broken else "; broken: " + ", ".join(broken[:6])))
    res.add("links", "no href=\"#\" placeholders", PASS if not placeholder else FAIL,
            "" if not placeholder else f"{placeholder} found")
    if len(found) > limit:
        res.add("links", "link check coverage", INFO,
                f"{len(found)} unique links found, {limit} checked (raise with --crawl)")


# --------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(description="Smile Creative pre-launch QC")
    ap.add_argument("url")
    ap.add_argument("--pages", nargs="*", default=None,
                    help="extra paths to check, e.g. /about/ /contact/")
    ap.add_argument("--crawl", type=int, default=40,
                    help="how many internal links to verify (default 40)")
    args = ap.parse_args()

    base = args.url.rstrip("/")
    res = Result()

    print(f"Smile Creative QC — {base}")

    status, _, home = fetch(base + "/")
    if status != 200 or home.startswith("__ERROR__"):
        print(f"\nCould not fetch the homepage: {status} {home[:120]}")
        return 2

    paths = ["/"] + (args.pages or discover(base, home))
    seen_titles, seen_descs, pages_html = set(), set(), []
    for p in paths:
        html = check_page(res, base, p, seen_titles, seen_descs)
        if html:
            pages_html.append(html)

    check_sitewide(res, base, home)
    check_security(res, base)
    check_links(res, base, pages_html, args.crawl)

    failures = res.report()
    print("\nNot machine-checkable — still do these by hand:")
    print("  * submit every form and OPEN the destination inbox (submitted is not delivered)")
    print("  * read the homepage as a stranger for 30 seconds: does it read as machine-made?")
    print("  * confirm photographs are the client's own, not stock")
    print("  * confirm the About origin story is factually true")
    print("  * PageSpeed Insights on mobile, aim 80+")
    return 1 if failures else 0


def discover(base, home):
    """Pick a handful of real internal pages off the homepage nav."""
    host = urllib.parse.urlparse(base).netloc
    paths, seen = [], set()
    for href in re.findall(r'href="([^"#?]+)"', home):
        full = urllib.parse.urljoin(base, href)
        parsed = urllib.parse.urlparse(full)
        if parsed.netloc != host:
            continue
        path = parsed.path
        if path in ("", "/") or path in seen:
            continue
        if re.search(r"\.(css|js|png|jpe?g|webp|svg|ico|woff2?|xml|txt|php)$", path, re.I):
            continue
        if "/wp-" in path or "/feed" in path or "/wp-json" in path:
            continue
        seen.add(path)
        paths.append(path)
        if len(paths) >= 6:
            break
    return paths


if __name__ == "__main__":
    sys.exit(main())
