#!/usr/bin/env python3
"""
OCR the prepared scans (prep_flat.py) – meant for a machine with many cores.

  python3 ocr_flat.py <flat_dir> <out_dir> <worker_index> <worker_count>
  -> <out_dir>/ocr/<md5>.json  {"w","h","boxes","pages","lines":[[text, conf, x, y(, page)], ...]}
Start one process per 2 cores with OMP_NUM_THREADS=1 OPENBLAS_NUM_THREADS=1 and the library's
config.yaml set to intra_op_num_threads: 2 – otherwise every worker starts a thread per core.  Resumable: existing results are skipped.
"""
import collections, json, os, sys, time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ocr_worker import ocr_image


def main():
    flat, out, idx, cnt = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
    os.makedirs(os.path.join(out, "ocr"), exist_ok=True)
    groups = collections.defaultdict(list)
    for f in sorted(os.listdir(flat)):
        if f.endswith(".jpg") and not f.startswith("."):
            groups[f[:32]].append(f)
    items = [h for i, h in enumerate(sorted(groups)) if i % cnt == idx]
    import cv2
    cv2.setNumThreads(1)                 # many workers side by side: no thread pools inside each
    from PIL import Image
    from rapidocr_onnxruntime import RapidOCR
    engine = RapidOCR()
    done, t0 = 0, time.time()
    for h in items:
        dst = os.path.join(out, "ocr", h + ".json")
        if os.path.exists(dst):
            continue
        rec = {"lines": [], "boxes": 0, "pages": len(groups[h])}
        for n, f in enumerate(sorted(groups[h])):
            try:
                im = Image.open(os.path.join(flat, f)).convert("RGB")
                rec.setdefault("w", im.size[0]); rec.setdefault("h", im.size[1])
                lines, nb = ocr_image(engine, im)
                rec["boxes"] += nb
                rec["lines"] += [l + [n] for l in lines] if len(groups[h]) > 1 or "_p" in f else lines
            except Exception as e:
                rec["error"] = str(e)[:200]
        with open(dst + ".tmp", "w", encoding="utf-8") as fh:
            json.dump(rec, fh, ensure_ascii=False)
        os.replace(dst + ".tmp", dst)
        done += 1
        if done % 50 == 0:
            print(f"worker {idx}: {done}/{len(items)} {(time.time() - t0) / done:.1f}s each", flush=True)
    print(f"worker {idx}: finished ({done} new of {len(items)})", flush=True)


if __name__ == "__main__":
    main()
