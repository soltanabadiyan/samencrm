#!/usr/bin/env python3
"""
Prepare the scans for OCR on another machine: one small JPEG per unique image and per page (first 3) of every
scanned PDF, named by content hash; text PDFs are read here (pdftotext) and need no OCR.

  python3 prep_flat.py <documents_dir> <out_dir>      -> <out_dir>/flat/<md5>.jpg, <md5>_p<n>.jpg
                                                         <out_dir>/pdftext/<md5>.txt, <out_dir>/hashes.json
"""
import json, os, subprocess, sys, tempfile
from multiprocessing import Pool

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ocr_worker import IMG, NOISE, MAX_SIDE, md5

ROOT, OUT = sys.argv[1], sys.argv[2]


def small(src, dst):
    from PIL import Image, ImageOps
    im = ImageOps.exif_transpose(Image.open(src)).convert("RGB")
    size = im.size
    im.thumbnail((MAX_SIDE, MAX_SIDE))
    im.save(dst + ".tmp", "JPEG", quality=80)
    os.replace(dst + ".tmp", dst)
    return size


def one(path):
    h = md5(path)
    info = {"md5": h}
    try:
        if path.lower().endswith(".pdf"):
            tp = os.path.join(OUT, "pdftext", h + ".txt")
            if not os.path.exists(tp):
                txt = subprocess.run(["pdftotext", "-layout", "-l", "5", path, "-"], capture_output=True, text=True, timeout=90).stdout
                open(tp, "w", encoding="utf-8").write(txt)
            txt = open(tp, encoding="utf-8").read()
            if len(txt.strip()) < 40 and not os.path.exists(os.path.join(OUT, "flat", h + "_p0.jpg")):
                with tempfile.TemporaryDirectory() as tmp:
                    subprocess.run(["pdftoppm", "-r", "110", "-l", "3", "-jpeg", path, os.path.join(tmp, "p")], timeout=180, capture_output=True)
                    for n, pg in enumerate(sorted(os.listdir(tmp))):
                        small(os.path.join(tmp, pg), os.path.join(OUT, "flat", f"{h}_p{n}.jpg"))
        else:
            dst = os.path.join(OUT, "flat", h + ".jpg")
            if not os.path.exists(dst):
                info["size"] = small(path, dst)
    except Exception as e:
        info["error"] = str(e)[:200]
    return path, info


def main():
    for d in ("flat", "pdftext"):
        os.makedirs(os.path.join(OUT, d), exist_ok=True)
    paths = []
    for d, _, files in os.walk(ROOT):
        if any(n in d for n in NOISE):
            continue
        paths += [os.path.join(d, f) for f in files if f.lower().endswith(IMG + (".pdf",))]
    res = {}
    with Pool(4) as pool:
        for i, (p, info) in enumerate(pool.imap_unordered(one, paths, chunksize=8)):
            res[os.path.relpath(p, ROOT)] = info
            if i % 500 == 0:
                print(i, "of", len(paths), flush=True)
    json.dump(res, open(os.path.join(OUT, "hashes.json"), "w", encoding="utf-8"), ensure_ascii=False)
    print("files", len(res), "errors", sum(1 for v in res.values() if "error" in v))


if __name__ == "__main__":
    main()
