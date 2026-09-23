#!/usr/bin/env python3
"""
Rebuild llms-full.txt from the compendium source files.

llms-full.txt is GENERATED. Never edit it by hand — edit the files in
compendium/ and run this script (or just push; the GitHub Action runs it
for you).

Usage:  python3 build/build-llms-full.py
        python3 build/build-llms-full.py --check     (exit 1 if out of date)
"""
import io, glob, sys, datetime, re, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(ROOT, "llms-full.txt")
SRC  = sorted(glob.glob(os.path.join(ROOT, "compendium", "*.md")))

def build() -> str:
    existing = io.open(OUT, encoding="utf-8").read()
    header = existing.split("\n---\n", 1)[0].rstrip()
    header = re.sub(r"Generated: \d{4}-\d{2}-\d{2}",
                    "Generated: " + datetime.date.today().isoformat(), header)
    parts = [header] + [io.open(f, encoding="utf-8").read().strip() for f in SRC]
    return "\n\n---\n\n".join(parts) + "\n"

def main() -> int:
    if not SRC:
        print("ERROR: no files found in compendium/", file=sys.stderr)
        return 1

    new = build()

    if "--check" in sys.argv:
        current = io.open(OUT, encoding="utf-8").read()
        # ignore the Generated: date when comparing
        strip = lambda s: re.sub(r"Generated: \d{4}-\d{2}-\d{2}", "", s)
        if strip(current) == strip(new):
            print("llms-full.txt is up to date (%d source files)" % len(SRC))
            return 0
        print("OUT OF DATE: llms-full.txt does not match compendium/", file=sys.stderr)
        return 1

    io.open(OUT, "w", encoding="utf-8").write(new)
    print("Rebuilt llms-full.txt from %d source files (%d chars)" % (len(SRC), len(new)))

    # keep the plugin's bundled fallback copy in step
    bundled = os.path.join(ROOT, "deploy", "local-power-ai-files", "data", "llms-full.txt")
    if os.path.isdir(os.path.dirname(bundled)):
        io.open(bundled, "w", encoding="utf-8").write(new)
        print("Updated the plugin's bundled copy too")
    return 0

if __name__ == "__main__":
    sys.exit(main())
