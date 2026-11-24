#!/usr/bin/env python3
"""
OCR every scanned image / scanned PDF page of the documents folder once (resumable, cached by content hash).

  python3 ocr_worker.py <documents_dir> <out_dir> [worker_index worker_count]

Output: <out_dir>/ocr/<md5>.json  = {"w":..,"h":..,"boxes": text boxes found, "lines":[[text, confidence, x, y], ...]}
Engine: RapidOCR (ONNX, CPU; Latin text – passports' machine-readable zone, English certificates, forms).
Deps:   pip install rapidocr-onnxruntime pillow        (pdftoppm from poppler-utils for scanned PDFs)
"""
import hashlib, json, os, subprocess, sys, tempfile, time

IMG = (".jpg", ".jpeg", ".jfif", ".png")
NOISE = ("_files/", "v2rayN", "powerpoint-template", "imagecompressor")
MAX_SIDE = 1700


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def targets(root):
    seen = {}
    for d, _, files in os.walk(root):
        if any(n in d + "/" for n in NOISE):
            continue
        for f in sorted(files):
            if f.lower().endswith(IMG + (".pdf",)):
                p = os.path.join(d, f)
                seen.setdefault(md5(p), p)
    return sorted(seen.items())


def load(path):
    from PIL import Image, ImageOps
    im = ImageOps.exif_transpose(Image.open(path)).convert("RGB")
    w, h = im.size
    if max(w, h) > MAX_SIDE:
        im.thumbnail((MAX_SIDE, MAX_SIDE))
    return im, w, h


KEY = ("<<", "passport", "cv", "resume", "curriculum", "name", "email", "e-mail", "whatsapp", "mobile", "phone")


def ocr_image(engine, im):
    """Detect every text box (fast), then read only the boxes that identify a document: the widest ones (a passport's
    machine-readable lines, titles), the first and the last ones. Pages that look like a CV / form are read in full.
    Reading is the slow part (about 0.3 s a line), detection takes a second."""
    import numpy as np
    arr = np.ascontiguousarray(np.asarray(im)[:, :, ::-1])
    det = (getattr(engine, 'text_detector', None) or engine.text_det)(arr)[0]      # rapidocr 1.2 / 1.4 names
    boxes = []
    for b in (det if det is not None else []):
        xs = [p[0] for p in b]; ys = [p[1] for p in b]
        boxes.append((int(min(xs)), int(min(ys)), int(max(xs)), int(max(ys))))
    boxes.sort(key=lambda r: (r[1], r[0]))
    H, W = arr.shape[:2]

    def read_many(idx):
        crops, keep = [], []
        for i in idx:
            x0, y0, x1, y1 = boxes[i]
            crop = arr[max(0, y0 - 3):min(H, y1 + 3), max(0, x0 - 3):min(W, x1 + 3)]
            if crop.size == 0 or crop.shape[0] < 6 or crop.shape[1] < 6:
                continue
            if crop.shape[0] > crop.shape[1] * 1.5:            # vertical text (rotated page): turn it
                crop = np.rot90(crop, 3)
            crops.append(np.ascontiguousarray(crop)); keep.append(i)
        if not crops:
            return []
        res = (getattr(engine, 'text_recognizer', None) or engine.text_rec)(crops)[0]
        res = [(r[0], r[1]) for r in res]
        return [[t, round(float(c), 3), boxes[i][0], boxes[i][1]] for i, (t, c) in zip(keep, res) if t]

    wide = sorted(range(len(boxes)), key=lambda i: boxes[i][0] - boxes[i][2])[:10]
    pick = sorted(set(wide) | set(range(min(5, len(boxes)))) | set(range(max(0, len(boxes) - 3), len(boxes))))
    out = read_many(pick)
    text = " ".join(l[0] for l in out).lower()
    mrz = sum(1 for l in out if "<<" in l[0])
    if mrz < 2 and sum(1 for k in KEY[1:] if k in text) >= 2 and len(boxes) <= 90:      # CV / application form: all of it
        out += read_many([i for i in range(len(boxes)) if i not in set(pick)])
    out.sort(key=lambda l: (l[3], l[2]))
    return out, len(boxes)


def main():
    root, out = sys.argv[1], sys.argv[2]
    idx, cnt = (int(sys.argv[3]), int(sys.argv[4])) if len(sys.argv) > 4 else (0, 1)
    os.makedirs(os.path.join(out, "ocr"), exist_ok=True)
    items = [t for i, t in enumerate(targets(root)) if i % cnt == idx]
    from rapidocr_onnxruntime import RapidOCR
    engine = RapidOCR()
    done = 0
    t0 = time.time()
    for h, path in items:
        dst = os.path.join(out, "ocr", h + ".json")
        if os.path.exists(dst):
            continue
        rec = {"path": path, "lines": [], "pages": 1}
        try:
            if path.lower().endswith(".pdf"):
                txt = subprocess.run(["pdftotext", "-layout", "-l", "3", path, "-"], capture_output=True, text=True, timeout=60).stdout
                rec["pdftext"] = txt[:20000]
                if len(txt.strip()) < 40:                      # scanned PDF: OCR its first 3 pages
                    with tempfile.TemporaryDirectory() as tmp:
                        subprocess.run(["pdftoppm", "-r", "110", "-l", "3", "-jpeg", path, os.path.join(tmp, "p")], timeout=120)
                        pages = sorted(os.listdir(tmp))
                        rec["pages"] = len(pages)
                        for n, pg in enumerate(pages):
                            im, w, hh = load(os.path.join(tmp, pg))
                            rec.setdefault("w", w); rec.setdefault("h", hh)
                            lines, nb = ocr_image(engine, im)
                            rec["boxes"] = rec.get("boxes", 0) + nb
                            rec["lines"] += [l + [n] for l in lines]
            else:
                im, w, hh = load(path)
                rec["w"], rec["h"] = w, hh
                rec["lines"], rec["boxes"] = ocr_image(engine, im)
        except Exception as e:                                  # unreadable file: recorded, never fatal
            rec["error"] = str(e)[:200]
        with open(dst + ".tmp", "w", encoding="utf-8") as f:
            json.dump(rec, f, ensure_ascii=False)
        os.replace(dst + ".tmp", dst)
        done += 1
        if done % 25 == 0:
            print(f"worker {idx}: {done} done, {(time.time() - t0) / done:.1f}s each", flush=True)
    print(f"worker {idx}: finished ({done} new of {len(items)})", flush=True)


if __name__ == "__main__":
    main()
