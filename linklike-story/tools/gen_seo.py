#!/usr/bin/env python3
"""
gen_seo.py — generate minimal SEO landing pages that instantly redirect to the
interactive front-end and open the matching episode.

These pages carry NO story summary (user wanted the archive gone as a reading
page). They exist only so old/shared URLs stay valid and Google can follow the
canonical+redirect straight to the real app at #term/index.

Reads ../summaries.json for the key list (text is NOT rendered).
Outputs:
  story/<key>.html        one redirect page per episode / dream interlude
  story/term-<N>.html     per-term redirect (opens the term playlist)
  story/index.html        master index -> front page
  ../../linklike-story/sitemap.xml   (front page + all archive pages)
  ../../linklike-story/robots.txt    (sitemap pointer)

Run:  python tools/gen_seo.py
"""

import json
import os

BASE_URL = "https://tybantarnusa.github.io/linklike-story"
HERE = os.path.dirname(os.path.abspath(__file__))
LL_DIR = os.path.abspath(os.path.join(HERE, ".."))
REPO_ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
STORY_DIR = os.path.join(LL_DIR, "story")

TERM_META = {
    "102": {"ord": "102nd", "kind": "Voiced Novel (Side Story)"},
    "103": {"ord": "103rd", "kind": "Main Story"},
    "104": {"ord": "104th", "kind": "Main Story"},
    "105": {"ord": "105th", "kind": "Main Story"},
}

CSS = """
:root{--bg:#eef4fb;--accent:#93c5f5;--accent-strong:#978df0;--text:#2d3748}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;min-height:100vh;line-height:1.7}
.wrap{text-align:center;max-width:560px;padding:24px}
h1{font-size:1.3rem;margin:.2em 0}
p{color:#5b6b82}
.btn{display:inline-block;margin-top:14px;background:linear-gradient(90deg,var(--accent),var(--accent-strong));color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:700}
"""

def load_keys():
    with open(os.path.join(LL_DIR, "summaries.json"), encoding="utf-8") as f:
        return json.load(f)

def parse_key(key):
    if key.startswith("dream-"):
        return ("dream", int(key.split("-")[1]))
    term, ep = key.split("-")
    return (term, int(ep))

def ep_title(term, ep):
    if term == "dream":
        return f"Hasunosora Dream Interlude {ep}"
    return f"Hasunosora {TERM_META[term]['ord']} Term — Episode {ep}"

def redirect_html(title, target_hash, label):
    """Minimal page: meta-refresh 0s to front-end + open episode; canonical to same."""
    url = f"{BASE_URL}/{target_hash}"
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="refresh" content="0; url={url}" />
  <title>{title} | Link! Like! Activity Record</title>
  <link rel="canonical" href="{url}" />
  <meta name="robots" content="noindex,follow" />
  <style>{CSS}</style>
  <script>window.location.replace('{url}');</script>
</head>
<body>
  <div class="wrap">
    <h1>{title}</h1>
    <p>Opening the interactive archive&hellip;</p>
    <a class="btn" href="{url}">{label}</a>
  </div>
</body>
</html>"""

def main():
    os.makedirs(STORY_DIR, exist_ok=True)
    data = load_keys()

    for key in data:
        term, ep = parse_key(key)
        if term == "dream":
            target = f"#dream/{ep}"
            label = f"Open Dream Interlude {ep}"
        else:
            target = f"#{term}/{ep}"
            label = f"Open {TERM_META[term]['ord']} Term Episode {ep}"
        page = redirect_html(ep_title(term, ep), target, label)
        with open(os.path.join(STORY_DIR, f"{key}.html"), "w", encoding="utf-8") as f:
            f.write(page)

    # term redirect pages -> open the term playlist (episode 1)
    for term in ["102", "103", "104", "105", "dream"]:
        if term == "dream":
            target = "#dream/1"
            title = "Hasunosora Dream Interlude"
            label = "Open Dream Interlude"
        else:
            target = f"#{term}/1"
            title = f"Hasunosora {TERM_META[term]['ord']} Term"
            label = f"Open {TERM_META[term]['ord']} Term"
        page = redirect_html(title, target, label)
        fname = "term-dream.html" if term == "dream" else f"term-{term}.html"
        with open(os.path.join(STORY_DIR, fname), "w", encoding="utf-8") as f:
            f.write(page)

    # index -> front page
    idx = f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="refresh" content="0; url={BASE_URL}/" />
  <title>Hasunosora Story Archive | Link! Like! Activity Record</title>
  <link rel="canonical" href="{BASE_URL}/" />
  <meta name="robots" content="noindex,follow" />
  <style>{CSS}</style>
  <script>window.location.replace('{BASE_URL}/');</script>
</head>
<body>
  <div class="wrap">
    <h1>Hasunosora Story Archive</h1>
    <p>Opening the interactive archive&hellip;</p>
    <a class="btn" href="{BASE_URL}/">Open the archive</a>
  </div>
</body>
</html>"""
    with open(os.path.join(STORY_DIR, "index.html"), "w", encoding="utf-8") as f:
        f.write(idx)

    # sitemap: ONLY the front page. Archive pages are noindex+redirect, so they
    # must NOT be listed here (Google flags a sitemap full of noindex/redirect
    # URLs as "could not be read"). The front page is the only indexable target.
    urls = [BASE_URL + "/"]
    locs = "\n".join(f"  <url><loc>{u}</loc></url>" for u in urls)
    sitemap = f"""<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{locs}
</urlset>"""
    # sitemap + robots live INSIDE /linklike-story/ (matches Search Console URL prefix)
    with open(os.path.join(LL_DIR, "sitemap.xml"), "w", encoding="utf-8") as f:
        f.write(sitemap)
    robots = f"""User-agent: *
Allow: /

Sitemap: {BASE_URL}/sitemap.xml
"""
    with open(os.path.join(LL_DIR, "robots.txt"), "w", encoding="utf-8") as f:
        f.write(robots)

    print(f"Generated {len(data)} episode pages + 6 term/index + sitemap + robots (all redirect to front-end).")

if __name__ == "__main__":
    main()
