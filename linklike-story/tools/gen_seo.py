#!/usr/bin/env python3
"""
gen_seo.py — generate static, SEO-friendly pages for the Link! Like! story archive.

Reads ../summaries.json and emits:
  story/<key>.html        one page per episode / dream interlude (text baked into HTML)
  story/term-<N>.html     per-term index pages
  story/index.html        master index of all terms
 ../../robots.txt         at repo root (github.io root)
  ../../sitemap.xml       at repo root

Static text (not JS-fetched) so Googlebot can read & index every episode.
Run:  python tools/gen_seo.py
"""

import json
import os
import html
import datetime

BASE_URL = "https://tybantarnusa.github.io/linklike-story"
HERE = os.path.dirname(os.path.abspath(__file__))
LL_DIR = os.path.abspath(os.path.join(HERE, ".."))
REPO_ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
STORY_DIR = os.path.join(LL_DIR, "story")

TERM_META = {
    "102": {"ord": "102nd", "kind": "Voiced Novel (Side Story)", "range": (1, 8)},
    "103": {"ord": "103rd", "kind": "Main Story", "range": (1, 18)},
    "104": {"ord": "104th", "kind": "Main Story", "range": (1, 13)},
    "105": {"ord": "105th", "kind": "Main Story", "range": (1, 12)},
}

SITE_CSS = """
:root{--bg:#eef4fb;--card:#fff;--card-hover:#e3edfb;--accent:#93c5f5;--accent-strong:#978df0;--text:#2d3748;--muted:#5b6b82;--border:#c9dcf0}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);line-height:1.7}
.site-header{background:linear-gradient(90deg,#4f93d6,#6d4fc4);color:#fff;padding:18px 20px}
.site-header a{color:#fff;text-decoration:none}
.site-header h1{margin:0;font-size:1.35rem}
.wrap{max-width:820px;margin:0 auto;padding:24px 18px 60px}
.breadcrumb{font-size:.9rem;color:var(--muted);margin:18px 0 6px}
.breadcrumb a{color:var(--accent-strong);text-decoration:none}
.breadcrumb a:hover{text-decoration:underline}
h2.term-title{font-size:1.6rem;margin:.2em 0}
.subtitle{color:var(--muted);margin:.2em 0 1.4em}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:14px 0;transition:background .2s}
.card:hover{background:var(--card-hover)}
.card a{color:var(--text);text-decoration:none;font-weight:600;font-size:1.05rem}
.card .meta{color:var(--muted);font-size:.85rem;margin-top:4px}
.story-body{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px 26px;margin:18px 0;font-size:1.02rem}
.watch-btn{display:inline-block;margin-top:14px;background:linear-gradient(90deg,var(--accent),var(--accent-strong));color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:700}
.ep-nav{display:flex;justify-content:space-between;gap:10px;margin:22px 0}
.ep-nav a{flex:1;text-align:center;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px;text-decoration:none;color:var(--accent-strong);font-weight:600}
.ep-nav a.disabled{opacity:.4;pointer-events:none}
footer{color:var(--muted);font-size:.82rem;text-align:center;margin-top:40px}
.redirect-note{color:var(--accent-strong);font-size:.9rem;margin:6px 0 0}
"""

SCRIPT_REDIRECT = """  <script>
    setTimeout(function(){ window.location.replace('../'); }, 3000);
  </script>"""

def load_summaries():
    with open(os.path.join(LL_DIR, "summaries.json"), encoding="utf-8") as f:
        return json.load(f)

def parse_key(key):
    if key.startswith("dream-"):
        return ("dream", int(key.split("-")[1]))
    term, ep = key.split("-")
    return (term, int(ep))

def descr_of(text, n=158):
    # first sentence-ish trim
    t = " ".join(text.split())
    if len(t) <= n:
        return t
    cut = t[:n]
    # try to end at a space
    if " " in cut:
        cut = cut[: cut.rfind(" ")]
    return cut.strip() + "…"

def episode_title(key, term_meta, ep):
    if term_meta is None:
        return f"Hasunosora Dream Interlude {ep}"
    return f"Hasunosora {term_meta['ord']} Term — Episode {ep}"

def build_head(title, desc, url, og_type="website"):
    # Canonical + auto-redirect ALL point at the interactive front-end.
    # User wants Google (and people) to land on the real app, not the
    # read-only static archive. The static pages still carry the story
    # text so Googlebot can read keywords, but they bounce to the front
    # page after 3s (and instantly if JS runs).
    canonical = BASE_URL + "/"
    return f"""  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="refresh" content="3; url={canonical}" />
  <title>{html.escape(title)} | Link! Like! Activity Record</title>
  <meta name="description" content="{html.escape(desc)}" />
  <link rel="canonical" href="{canonical}" />
  <meta property="og:title" content="{html.escape(title)}" />
  <meta property="og:description" content="{html.escape(desc)}" />
  <meta property="og:url" content="{url}" />
  <meta property="og:type" content="{og_type}" />
  <meta property="og:site_name" content="Link! Like! Activity Record" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="{html.escape(title)}" />
  <meta name="twitter:description" content="{html.escape(desc)}" />"""

def jsonld_article(title, desc, url, date=None):
    data = {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": title,
        "description": desc,
        "inLanguage": "en",
        "publisher": {"@type": "Organization", "name": "Link! Like! Activity Record"},
        "mainEntityOfPage": {"@type": "WebPage", "@id": url},
    }
    if date:
        data["dateModified"] = date
    return "<script type=\"application/ld+json\">" + json.dumps(data, ensure_ascii=False) + "</script>"

def nav_for(key, summaries):
    """Return (prev_url, next_url) relative links within story/."""
    keys = sorted(summaries.keys(), key=lambda k: (parse_key(k)[0] != "dream",) + parse_key(k))
    idx = keys.index(key)
    prev = keys[idx - 1] if idx > 0 else None
    nxt = keys[idx + 1] if idx < len(keys) - 1 else None
    return prev, nxt

def render_episode(key, summaries):
    term, ep = parse_key(key)
    text = summaries[key]
    is_dream = term == "dream"
    tm = TERM_META.get(term)
    title = episode_title(key, tm, ep)
    url = f"{BASE_URL}/story/{key}.html"
    desc = descr_of(text)
    rel_app = f"../#{key}"  # link to interactive player

    # breadcrumb
    if is_dream:
        crumb = '<a href="index.html">Archive</a> &rsaquo; <a href="term-dream.html">Dream Interlude</a>'
        h2 = f"Dream Interlude {ep}"
        sub = "Hasunosora side story — Dream Interlude"
    else:
        crumb = f'<a href="index.html">Archive</a> &rsaquo; <a href="term-{term}.html">{tm["ord"]} Term</a>'
        h2 = f"{tm['ord']} Term — Episode {ep}"
        sub = f"Hasunosora {tm['ord']} Term ({tm['kind']}) — Episode {ep} story summary & archive"

    prev, nxt = nav_for(key, summaries)
    prev_html = f'<a href="{prev}.html">&#8592; Prev</a>' if prev else '<a class="disabled">&#8592; Prev</a>'
    nxt_html = f'<a href="{nxt}.html">Next &#8594;</a>' if nxt else '<a class="disabled">Next &#8594;</a>'

    body = f"""<!DOCTYPE html>
<html lang="en">
<head>
{build_head(title, desc, url)}
  <style>{SITE_CSS}</style>
  {jsonld_article(title, desc, url)}
</head>
<body>
  <header class="site-header"><a href="../"><h1>Link! Like! Activity Record</h1></a></header>
  <div class="wrap">
    <div class="breadcrumb">{crumb}</div>
    <h2 class="term-title">{h2}</h2>
    <p class="subtitle">{sub}</p>
    <p><a class="watch-btn" href="{rel_app}">&#9654; Open the interactive archive</a></p>
    <p class="redirect-note">Redirecting you to the interactive archive in a few seconds&hellip;</p>
    <div class="story-body">
{html.escape(text)}
    </div>
    <p><a class="watch-btn" href="{rel_app}">&#9654; Watch / read on the interactive archive</a></p>
    <div class="ep-nav">{prev_html}{nxt_html}</div>
    <footer>Link! Like! Activity Record — fan archive of Link! Like! Love Live! Hasunosora school idol stories. Not affiliated with the official franchise.</footer>
  </div>
  {SCRIPT_REDIRECT}
</body>
</html>
"""
    return body

def render_term(term, summaries):
    if term == "dream":
        url = f"{BASE_URL}/story/term-dream.html"
        title = "Hasunosora Dream Interlude — Side Story Archive"
        desc = "Hasunosora Dream Interlude side-story episodes. Read English summaries & watch the interactive archive."
        h2 = "Dream Interlude"
        sub = "Hasunosora side story — Dream Interlude episodes"
        keys = sorted([k for k in summaries if k.startswith("dream-")], key=parse_key)
    else:
        tm = TERM_META[term]
        url = f"{BASE_URL}/story/term-{term}.html"
        title = f"Hasunosora {tm['ord']} Term — Story Archive"
        desc = f"Hasunosora {tm['ord']} Term ({tm['kind']}) — all {len([k for k in summaries if k.split('-')[0]==term])} episode summaries & archive. Read & watch."
        h2 = f"{tm['ord']} Term"
        sub = f"{tm['kind']} — episode list"
        keys = sorted([k for k in summaries if k.split("-")[0] == term and k != "dream-" and not k.startswith("dream")], key=lambda k: parse_key(k)[1])

    cards = []
    for k in keys:
        t = summaries[k]
        ep = parse_key(k)[1]
        label = f"Dream Interlude {ep}" if term == "dream" else f"Episode {ep}"
        cards.append(f'    <div class="card"><a href="{k}.html">{label}</a><div class="meta">{html.escape(descr_of(t, 110))}</div></div>')
    cards_html = "\n".join(cards)

    body = f"""<!DOCTYPE html>
<html lang="en">
<head>
{build_head(title, desc, url)}
  <style>{SITE_CSS}</style>
  {jsonld_article(title, desc, url)}
</head>
<body>
  <header class="site-header"><a href="../"><h1>Link! Like! Activity Record</h1></a></header>
  <div class="wrap">
    <div class="breadcrumb"><a href="index.html">Archive</a> &rsaquo; {h2}</div>
    <h2 class="term-title">{h2}</h2>
    <p class="subtitle">{sub}</p>
{cards_html}
    <p><a class="watch-btn" href="../">&#9654; Open the interactive archive</a></p>
    <p class="redirect-note">Redirecting you to the interactive archive in a few seconds&hellip;</p>
    <footer>Link! Like! Activity Record — fan archive of Link! Like! Love Live! Hasunosora school idol stories. Not affiliated with the official franchise.</footer>
  </div>
  {SCRIPT_REDIRECT}
</body>
</html>"""
    return body

def render_index(summaries):
    url = f"{BASE_URL}/story/index.html"
    title = "Hasunosora Story Archive — All Terms & Dream Interlude"
    desc = "Read & watch every Link! Like! Love Live! Hasunosora school idol story: 102nd–105th terms and Dream Interlude. English summaries & interactive archive."
    sections = []
    for term in ["103", "104", "105", "102", "dream"]:
        if term == "dream":
            tm = None
            label = "Dream Interlude"
            sub = "Side story"
            cnt = len([k for k in summaries if k.startswith("dream-")])
            link = "term-dream.html"
        else:
            tm = TERM_META[term]
            label = f"{tm['ord']} Term"
            sub = tm["kind"]
            cnt = len([k for k in summaries if k.split("-")[0] == term])
            link = f"term-{term}.html"
        sections.append(f'    <div class="card"><a href="{link}">{label}</a><div class="meta">{sub} — {cnt} episodes</div></div>')
    sections_html = "\n".join(sections)

    body = f"""<!DOCTYPE html>
<html lang="en">
<head>
{build_head(title, desc, url)}
  <style>{SITE_CSS}</style>
  {jsonld_article(title, desc, url)}
</head>
<body>
  <header class="site-header"><a href="../"><h1>Link! Like! Activity Record</h1></a></header>
  <div class="wrap">
    <h2 class="term-title">Hasunosora Story Archive</h2>
    <p class="subtitle">Link! Like! Love Live! Hasunosora — every term &amp; Dream Interlude. Read English summaries, then watch on the interactive player.</p>
{sections_html}
    <p><a class="watch-btn" href="../">&#9654; Open the interactive archive</a></p>
    <p class="redirect-note">Redirecting you to the interactive archive in a few seconds&hellip;</p>
    <footer>Link! Like! Activity Record — fan archive of Link! Like! Love Live! Hasunosora school idol stories. Not affiliated with the official franchise.</footer>
  </div>
  {SCRIPT_REDIRECT}
</body>
</html>"""
    return body

def main():
    os.makedirs(STORY_DIR, exist_ok=True)
    summaries = load_summaries()
    today = datetime.date.today().isoformat()

    count = 0
    # per-episode pages
    for key in summaries:
        page = render_episode(key, summaries)
        with open(os.path.join(STORY_DIR, f"{key}.html"), "w", encoding="utf-8") as f:
            f.write(page)
        count += 1

    # term pages
    for term in ["102", "103", "104", "105", "dream"]:
        page = render_term(term, summaries)
        fname = "term-dream.html" if term == "dream" else f"term-{term}.html"
        with open(os.path.join(STORY_DIR, fname), "w", encoding="utf-8") as f:
            f.write(page)
        count += 1

    # index
    with open(os.path.join(STORY_DIR, "index.html"), "w", encoding="utf-8") as f:
        f.write(render_index(summaries))
    count += 1

    # sitemap
    urls = [f"{BASE_URL}/", f"{BASE_URL}/story/index.html"]
    for term in ["102", "103", "104", "105", "dream"]:
        urls.append(f"{BASE_URL}/story/{'term-dream' if term=='dream' else 'term-'+term}.html")
    for key in summaries:
        urls.append(f"{BASE_URL}/story/{key}.html")
    locs = "\n".join(f"  <url><loc>{u}</loc><lastmod>{today}</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>" for u in urls)
    sitemap = f"""<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{locs}
</urlset>"""
    with open(os.path.join(REPO_ROOT, "sitemap.xml"), "w", encoding="utf-8") as f:
        f.write(sitemap)

    # robots.txt
    robots = f"""User-agent: *
Allow: /

Sitemap: {BASE_URL.replace('/linklike-story','')}/sitemap.xml
"""
    with open(os.path.join(REPO_ROOT, "robots.txt"), "w", encoding="utf-8") as f:
        f.write(robots)

    print(f"Generated {count} pages.")
    print(f"  story/ episodes+terms+index")
    print(f"  {os.path.join(REPO_ROOT,'sitemap.xml')}")
    print(f"  {os.path.join(REPO_ROOT,'robots.txt')}")

if __name__ == "__main__":
    main()
