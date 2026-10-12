#!/usr/bin/env python3
"""Render every module diagram with Mermaid in headless Chromium; fail on any error.
Usage: python3 tools/check_erd_render.py <erd.json> <mermaid.min.js> <screenshot_dir>
"""
import json
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

erd = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
mermaid_js = Path(sys.argv[2]).read_text(encoding="utf-8")
shots = Path(sys.argv[3])
shots.mkdir(parents=True, exist_ok=True)

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1600, "height": 1200})
    page.set_content("<html><body><div id='out'></div></body></html>")
    page.add_script_tag(content=mermaid_js)
    page.evaluate("mermaid.initialize({startOnLoad:false, securityLevel:'strict', er:{useMaxWidth:false}})")
    failures = 0
    for i, m in enumerate(erd["modules"]):
        res = page.evaluate(
            """async ([id, src]) => {
                 try { const r = await mermaid.render(id, src);
                       document.getElementById('out').innerHTML = r.svg;
                       const s = document.querySelector('#out svg');
                       return {ok:true, w:s.getBBox().width, h:s.getBBox().height}; }
                 catch (e) { return {ok:false, err:String(e)}; } }""",
            [f"d{i}", m["mermaid"]])
        if res["ok"]:
            print(f"OK   {m['key']:<11} {int(res['w'])}x{int(res['h'])}")
            if m["key"] in ("content", "booking"):
                page.locator("#out svg").screenshot(path=str(shots / f"{m['key']}.png"))
        else:
            failures += 1
            print(f"FAIL {m['key']}: {res['err'][:300]}")
    browser.close()
    sys.exit(1 if failures else 0)
