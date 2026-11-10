#!/usr/bin/env python3
"""Combine audit screenshots into labelled contact sheets: contact_sheet.py in_dir out_dir [per_sheet] [width]"""
import glob, os, sys
from PIL import Image, ImageDraw
src, dst = sys.argv[1], sys.argv[2]
per, W = int(sys.argv[3]) if len(sys.argv) > 3 else 4, int(sys.argv[4]) if len(sys.argv) > 4 else 720
os.makedirs(dst, exist_ok=True)
files = sorted(glob.glob(os.path.join(src, "*.png")))
for k in range(0, len(files), per):
    ims = []
    for f in files[k:k + per]:
        im = Image.open(f).convert("RGB")
        w = W if im.width > 600 else W // 2
        im = im.resize((w, round(im.height * w / im.width)))
        lab = Image.new("RGB", (im.width, 22), (30, 30, 30))
        ImageDraw.Draw(lab).text((6, 4), os.path.basename(f), fill=(255, 255, 0))
        col = Image.new("RGB", (im.width, im.height + 22), "white"); col.paste(lab, (0, 0)); col.paste(im, (0, 22))
        ims.append(col)
    cols = 2
    rows = [ims[i:i + cols] for i in range(0, len(ims), cols)]
    sw = max(sum(c.width for c in r) for r in rows) + 10
    sh = sum(max(c.height for c in r) for r in rows) + 10 * len(rows)
    sheet = Image.new("RGB", (sw, sh), (200, 200, 200)); y = 0
    for r in rows:
        x = 0
        for c in r:
            sheet.paste(c, (x, y)); x += c.width + 10
        y += max(c.height for c in r) + 10
    sheet.save(os.path.join(dst, f"sheet_{k // per:02d}.jpg"), quality=82)
print(len(files), "images ->", dst)
