#!/usr/bin/env python3
"""Sincroniza style.css, index.html (widget) y script.js a los nowdocs de backend.php."""
from __future__ import annotations

import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BACKEND = ROOT / "backend.php"
SNIPPET = ROOT / "wp-snippet-tutor-ia.php"
STYLE = ROOT / "style.css"
INDEX = ROOT / "index.html"
SCRIPT = ROOT / "script.js"

NOWDOCS = (
    ("ua_chat_widget_css", "UA_CHAT_CSS"),
    ("ua_chat_widget_html", "UA_CHAT_HTML"),
    ("ua_chat_widget_js", "UA_CHAT_JS"),
)


def marker_open(func: str, ident: str) -> str:
    return f"function {func}() {{\n\treturn <<<'{ident}'\n"


def marker_close(ident: str) -> str:
    return f"\n{ident};\n}}"


def replace_nowdoc(text: str, func: str, ident: str, content: str) -> str:
    open_m = marker_open(func, ident)
    close_m = marker_close(ident)
    start = text.find(open_m)
    if start == -1:
        raise ValueError(f"no se encontró apertura {ident}")
    content_start = start + len(open_m)
    end = text.find(close_m, content_start)
    if end == -1:
        raise ValueError(f"no se encontró cierre {ident}")
    return text[:content_start] + content.strip() + text[end:]


def extract_widget_html(html: str) -> str:
    match = re.search(
        r"(<div class=\"ua-chat-widget\".*?</div>)\s*\n\s*<script",
        html,
        re.DOTALL,
    )
    if not match:
        raise ValueError("no se encontró el widget en index.html")
    return match.group(1).strip()


def main() -> int:
    backend = BACKEND.read_text(encoding="utf-8")
    css = STYLE.read_text(encoding="utf-8").strip()
    widget_html = extract_widget_html(INDEX.read_text(encoding="utf-8"))
    js = SCRIPT.read_text(encoding="utf-8").strip()

    backend = replace_nowdoc(backend, "ua_chat_widget_css", "UA_CHAT_CSS", css)
    backend = replace_nowdoc(backend, "ua_chat_widget_html", "UA_CHAT_HTML", widget_html)
    backend = replace_nowdoc(backend, "ua_chat_widget_js", "UA_CHAT_JS", js)

    BACKEND.write_text(backend, encoding="utf-8", newline="\n")
    shutil.copy2(BACKEND, SNIPPET)

    text = BACKEND.read_text(encoding="utf-8")
    css_body = text.split(marker_open("ua_chat_widget_css", "UA_CHAT_CSS"), 1)[1].split(
        marker_close("UA_CHAT_CSS"), 1
    )[0]
    html_body = text.split(marker_open("ua_chat_widget_html", "UA_CHAT_HTML"), 1)[1].split(
        marker_close("UA_CHAT_HTML"), 1
    )[0]
    js_body = text.split(marker_open("ua_chat_widget_js", "UA_CHAT_JS"), 1)[1].split(
        marker_close("UA_CHAT_JS"), 1
    )[0]

    checks = [
        ("CSS botón azul", ".ua-chat-btn {\n  position: relative" in css_body and "background: #03035b;" in css_body.split(".ua-chat-btn {", 1)[1][:200]),
        ("CSS pulso", ".ua-animacion-pulso" in css_body and "@keyframes softPulse" in css_body),
        ("CSS tooltip", "#ua-chat-tooltip" in css_body and ".ua-tooltip-visible" in css_body),
        ("CSS quiz", ".ua-chat-en-quiz" in css_body),
        ("HTML tooltip", 'id="ua-chat-tooltip"' in html_body),
        ("HTML icono blanco FAB", 'stroke="#ffffff"' in html_body),
        ("JS $1 markdown", "'<span class=\"ua-chat-md-heading\">$1</span>'" in js_body),
        ("JS sin corrupción", "return <<<'UA_CHAT_JS'" not in js_body),
        ("una apertura por nowdoc", all(text.count(marker_open(f, i)) == 1 for f, i in NOWDOCS)),
        ("backend === snippet", BACKEND.read_bytes() == SNIPPET.read_bytes()),
    ]

    ok = True
    for name, passed in checks:
        print(f"{name}: {'OK' if passed else 'FAIL'}")
        ok = ok and passed

    return 0 if ok else 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
