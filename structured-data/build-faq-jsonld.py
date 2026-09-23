#!/usr/bin/env python3
"""Regenerate faq.jsonld from compendium/06-faq.md.

Parses lines of the form:
    **Question text?**
    Answer paragraph (one or more lines, until a blank line).

Run after editing the FAQ:  python3 structured-data/build-faq-jsonld.py
"""
import json, re, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC  = ROOT / "compendium" / "06-faq.md"
OUT  = ROOT / "structured-data" / "faq.jsonld"

lines = SRC.read_text(encoding="utf-8").splitlines()
qas, q, buf = [], None, []

def flush():
    if q and buf:
        a = " ".join(buf).strip()
        a = re.sub(r"\*\*(.+?)\*\*", r"\1", a)      # bold
        a = re.sub(r"\*(.+?)\*", r"\1", a)          # italic
        a = re.sub(r"`([^`]+)`", r"\1", a)          # code
        a = re.sub(r"\s+", " ", a).strip()
        if a:
            qas.append((q, a))

for ln in lines:
    s = ln.strip()
    m = re.fullmatch(r"\*\*(.+\?)\*\*", s)
    if m:
        flush()
        q, buf = m.group(1), []
    elif q is not None:
        if not s or s.startswith(("#", "---", "|", "*Last verified")):
            flush(); q, buf = None, []
        else:
            buf.append(s)
flush()

graph = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "@id": "https://localpower.ie/#faq",
    "name": "Local Power — Frequently Asked Questions",
    "inLanguage": "en-IE",
    "publisher": {"@id": "https://localpower.ie/#organization"},
    "mainEntity": [
        {"@type": "Question", "name": qq,
         "acceptedAnswer": {"@type": "Answer", "text": aa}}
        for qq, aa in qas
    ],
}
OUT.write_text(json.dumps(graph, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
print(f"Wrote {OUT.relative_to(ROOT)} with {len(qas)} Q&A pairs")
