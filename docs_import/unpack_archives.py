#!/usr/bin/env python3
"""
Unpack the .rar / .zip archives of the documents folder next to the tree (they are mostly zipped student folders).

  python3 unpack_archives.py <documents_dir> <out_dir>   -> <out_dir>/unpacked/<archive path>/…  + unpacked.json
Archives that hold programs (exe / msi) or no documents are left alone.  extract.py reads the result as if each
archive's content lay in the folder of the archive.
"""
import hashlib, json, os, shutil, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import common as c


def main():
    root, out = sys.argv[1], sys.argv[2]
    base = os.path.join(out, "unpacked")
    done, seen = {}, set()
    for d, _, fs in os.walk(root):
        if any(n in d for n in c.NOISE):
            continue
        for fn in sorted(fs):
            if not fn.lower().endswith((".rar", ".zip")):
                continue
            path = os.path.join(d, fn)
            rel = os.path.relpath(path, root)
            h = hashlib.md5(open(path, "rb").read()).hexdigest()
            if h in seen:
                done[rel] = {"skip": "duplicate archive"}; continue
            seen.add(h)
            ls = subprocess.run(["7z", "l", "-ba", "-slt", "-p", path], capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
            members = [l[7:] for l in ls.split("\n") if l.startswith("Path = ")]
            exts = [os.path.splitext(m)[1].lower() for m in members]
            docs = sum(1 for e in exts if e in c.DOCS and e not in (".rar", ".zip"))
            if any(e in (".exe", ".msi", ".dll", ".cmd") for e in exts) or docs == 0:
                done[rel] = {"skip": "not documents"}; continue
            dst = os.path.join(base, rel)
            if not os.path.isdir(dst):
                os.makedirs(dst + ".tmp", exist_ok=True)
                r = subprocess.run(["7z", "x", "-y", "-p", "-o" + dst + ".tmp", path], capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=600)
                if r.returncode not in (0, 1) and fn.lower().endswith(".rar"):       # RAR5 methods: 7z cannot, unrar can
                    shutil.rmtree(dst + ".tmp", ignore_errors=True); os.makedirs(dst + ".tmp")
                    r = subprocess.run(["unrar", "x", "-y", "-p-", "-inul", path, dst + ".tmp/"], capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=900)
                if r.returncode not in (0, 1):
                    shutil.rmtree(dst + ".tmp", ignore_errors=True)
                    done[rel] = {"skip": "cannot unpack: " + r.stderr[-80:]}; continue
                os.rename(dst + ".tmp", dst)
            done[rel] = {"dir": os.path.relpath(dst, out), "files": docs}
    json.dump(done, open(os.path.join(out, "unpacked.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    import collections
    print("archives", len(done), collections.Counter(v.get("skip", "unpacked") for v in done.values()))
    print("document files unpacked", sum(v.get("files", 0) for v in done.values()))


if __name__ == "__main__":
    main()
