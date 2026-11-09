#!/usr/bin/env python3
"""
Generate an RTL *override* stylesheet for Vtiger (v7 layout) from its own compiled CSS.

  python3 tools/make_rtl_css.py tools/source_css/*.css > layouts/v7/modules/PersianCalendar/resources/rtl.generated.css

Only direction-sensitive declarations are emitted (left/right, float, clear, text-align, margin/padding/border
sides, 4-value shorthands, corner radii). Every selector is scoped to  body[data-language^="fa"]  so the file
is inert for non-Persian users and wins over the original rule by specificity. The side that the original
rule set is reset (0 / none / auto) unless it was already zero, so the original LTR value does not leak.
Hand-written fixes live in PersianCalendar.css (loaded after this file).
"""
import re
import sys

SCOPE = 'body[data-language^="fa"]'
ZERO = re.compile(r"^(0|0px|0em|0%|none|auto|initial|inherit|unset)(\s*!important)?$", re.I)


def strip_comments(css):
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def parse_blocks(css, i=0):
    """Yield ('rule', selector, body) / ('at', prelude, children-list|body-string) at nesting level."""
    out = []
    n = len(css)
    while i < n:
        j = css.find("{", i)
        k = css.find("}", i)
        if j == -1 or (k != -1 and k < j):
            return out, (k + 1 if k != -1 else n)
        prelude = css[i:j].strip()
        if prelude.startswith("@"):
            name = prelude.split()[0].lower()
            if name in ("@media", "@supports", "@document"):
                children, end = parse_blocks(css, j + 1)
                out.append(("at", prelude, children))
                i = end
                continue
            # @font-face, @keyframes, @page ...: skip the whole block (balanced braces)
            depth, p = 1, j + 1
            while p < n and depth:
                if css[p] == "{":
                    depth += 1
                elif css[p] == "}":
                    depth -= 1
                p += 1
            i = p
            continue
        end = css.find("}", j)
        out.append(("rule", prelude, css[j + 1:end]))
        i = end + 1
    return out, n


def decls(body):
    res = []
    for part in re.split(r";(?![^(]*\))", body):
        if ":" not in part:
            continue
        p, v = part.split(":", 1)
        p, v = p.strip().lower(), v.strip()
        if p and v:
            res.append((p, v))
    return res


def imp(v):
    return v.lower().endswith("!important")


def bare(v):
    return re.sub(r"\s*!important$", "", v, flags=re.I).strip()


def swap_lr_words(v):
    return re.sub(r"\b(left|right)\b", lambda m: "right" if m.group(1) == "left" else "left", v)


def four(v):
    """t r b l shorthand -> mirrored, or None if not 4 values"""
    b, important = bare(v), imp(v)
    parts = re.split(r"\s+(?![^(]*\))", b)
    if len(parts) == 4 and parts[1] != parts[3]:
        parts[1], parts[3] = parts[3], parts[1]
        return " ".join(parts) + (" !important" if important else "")
    return None


def radius(v):
    b, important = bare(v), imp(v)
    if "/" in b:
        return None
    parts = b.split()
    if len(parts) == 4:
        new = [parts[1], parts[0], parts[3], parts[2]]
    elif len(parts) == 3:
        new = [parts[1], parts[0], parts[1], parts[2]]
    elif len(parts) == 2:
        new = [parts[1], parts[0]]
    else:
        return None
    if new == parts:
        return None
    return " ".join(new) + (" !important" if important else "")


PAIRS = [
    ("left", "right", "auto"),
    ("margin-left", "margin-right", "0"),
    ("padding-left", "padding-right", "0"),
    ("border-left", "border-right", "none"),
    ("border-left-width", "border-right-width", "0"),
    ("border-left-color", "border-right-color", None),
    ("border-left-style", "border-right-style", "none"),
    ("border-top-left-radius", "border-top-right-radius", "0"),
    ("border-bottom-left-radius", "border-bottom-right-radius", "0"),
]


def flip(declist):
    d = {}
    for p, v in declist:
        d[p] = v                      # last wins
    out = []
    for a, b, reset in PAIRS:
        va, vb = d.get(a), d.get(b)
        if va is None and vb is None:
            continue
        if va is not None and vb is not None:
            if bare(va) != bare(vb):
                out += [(a, vb), (b, va)]
            continue
        src, dst, val = (a, b, va) if va is not None else (b, a, vb)
        out.append((dst, val))
        # positions: 0 is meaningful, so always release the original side; others only if non-zero
        if reset is not None and (a == "left" or not ZERO.match(val)):
            out.append((src, reset + (" !important" if imp(val) else "")))
    for p in ("float", "clear", "text-align"):
        v = d.get(p)
        if v and re.search(r"\b(left|right)\b", v):
            out.append((p, swap_lr_words(v)))
    for p in ("margin", "padding", "border-width", "border-color", "border-style"):
        if p in d:
            f = four(d[p])
            if f:
                out.append((p, f))
    if "border-radius" in d:
        r = radius(d["border-radius"])
        if r:
            out.append(("border-radius", r))
    if "background-position" in d and re.search(r"\b(left|right)\b", d["background-position"]):
        out.append(("background-position", swap_lr_words(d["background-position"])))
    return out


def scope_selector(sel):
    res = []
    for s in re.split(r",(?![^(]*\))", sel):
        s = s.strip()
        if not s:
            continue
        m = re.match(r"^(html\s*)?body\b", s)
        if m:
            rest = s[m.end():]
            res.append((("html " if m.group(1) else "") + SCOPE + rest).strip())
        elif s.startswith("html"):
            res.append("html " + SCOPE + s[4:])
        elif s in (":root",):
            continue
        else:
            res.append(SCOPE + " " + s)
    return ",\n".join(res)


def emit(blocks, indent=""):
    lines = []
    for b in blocks:
        if b[0] == "rule":
            fl = flip(decls(b[2]))
            if not fl:
                continue
            sel = scope_selector(b[1])
            if not sel:
                continue
            body = "; ".join(f"{p}: {v}" for p, v in fl)
            lines.append(f"{indent}{sel} {{ {body}; }}")
        else:
            inner = emit(b[2], indent + "  ")
            if inner:
                lines.append(f"{indent}{b[1]} {{")
                lines += inner
                lines.append(f"{indent}}}")
    return lines


def main(paths):
    print("/* GENERATED by tools/make_rtl_css.py from Vtiger's stylesheets – do not edit. RTL overrides for fa_* users. */")
    total = 0
    for path in paths:
        css = strip_comments(open(path, encoding="utf-8", errors="replace").read())
        blocks, _ = parse_blocks(css)
        lines = emit(blocks)
        total += len(lines)
        print(f"\n/* ---- from {path.split('/')[-1]} ---- */")
        print("\n".join(lines))
    print(f"/* {total} lines */", file=sys.stderr)


if __name__ == "__main__":
    main(sys.argv[1:])
