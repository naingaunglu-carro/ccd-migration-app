#!/usr/bin/env python3
"""Batch OCR wrapper for the transaction_file_ocr queue.

Reads a manifest of "<file_id>\t<local_path>" lines (from --manifest or stdin),
runs the RapidOCR extractor (single_rapid_ocr.extract) once per file, and emits
one JSON object per line to stdout:

    {"file_id": 306792014, "status": "done", "name": "...", "name_slug": "...",
     "national_id": "870326-02-6075", "passport_number": null, "nationality": "MY",
     "doc_type": "mykad", "message": "mykad", "elapsed": 0.9}

status is one of: done (name and/or id found), not_detected (nothing found),
error (file missing or the extractor threw). The engine is warmed once so the
ONNX models load a single time for the whole batch. Progress goes to stderr.
"""

import argparse
import json
import re
import sys
from pathlib import Path

# Make single_rapid_ocr importable regardless of the caller's cwd.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from single_rapid_ocr import extract, warm_engine  # noqa: E402

# A Malaysian NRIC is 12 digits, commonly written 6-2-4 with dashes.
_NRIC_RE = re.compile(r"^\d{6}-?\d{2}-?\d{4}$")


def slugify(value: str) -> str:
    value = re.sub(r"[^A-Za-z0-9]+", "-", value.strip().lower())
    return value.strip("-")


def classify_id(doc_type: str, id_number: str | None):
    """Split the extracted id into (national_id, passport_number, nationality)."""
    if not id_number:
        return None, None, None

    compact = id_number.replace(" ", "")
    is_nric = bool(_NRIC_RE.match(compact))

    if doc_type == "passport" and not is_nric:
        return None, id_number, None       # nationality unknown from a passport
    if is_nric:
        return id_number, None, "MY"       # 12-digit NRIC ⇒ Malaysian
    # Has letters or an odd shape → treat as a passport/other id.
    return (id_number, None, "MY") if compact.isdigit() else (None, id_number, None)


def read_manifest(path: str):
    stream = sys.stdin if path == "-" else open(path, "r", encoding="utf-8")
    try:
        for line in stream:
            line = line.rstrip("\n")
            if not line:
                continue
            parts = line.split("\t")
            if len(parts) >= 2:
                yield parts[0], parts[1]
    finally:
        if stream is not sys.stdin:
            stream.close()


def main():
    ap = argparse.ArgumentParser(description="Batch OCR the transaction_file_ocr queue.")
    ap.add_argument("--manifest", default="-", help="Manifest file ('-' for stdin)")
    args = ap.parse_args()

    items = list(read_manifest(args.manifest))
    total = len(items)
    if total == 0:
        print("Manifest empty — nothing to OCR.", file=sys.stderr)
        return 0

    print(f"Warming OCR engine for {total} file(s)…", file=sys.stderr)
    try:
        warm_engine()
    except Exception as e:  # noqa: BLE001
        print(f"Failed to load OCR engine: {e}", file=sys.stderr)
        return 3

    done = detected = 0
    for i, (file_id, path) in enumerate(items, 1):
        rec = {"file_id": file_id, "status": "error", "name": None, "name_slug": None,
               "national_id": None, "passport_number": None, "nationality": None,
               "doc_type": None, "message": None, "elapsed": None}
        try:
            if not Path(path).exists():
                rec["message"] = "file not found on disk"
            else:
                r = extract(path)
                nat_id, passport, nationality = classify_id(r.doc_type.value, r.id_number)
                rec.update(
                    status="done" if r.detected else "not_detected",
                    name=r.name,
                    name_slug=slugify(r.name) if r.name else None,
                    national_id=nat_id,
                    passport_number=passport,
                    nationality=nationality,
                    doc_type=r.doc_type.value,
                    message=r.doc_type.value if r.detected else "no name or id detected",
                    elapsed=round(r.elapsed_seconds, 2),
                )
                if r.detected:
                    detected += 1
        except Exception as e:  # noqa: BLE001 - one bad file must not kill the batch
            rec["message"] = f"{type(e).__name__}: {e}"

        sys.stdout.write(json.dumps(rec) + "\n")
        sys.stdout.flush()
        done += 1
        if done % 20 == 0 or done == total:
            print(f"  ocr {done}/{total} (detected {detected})", file=sys.stderr)

    print(f"Done: {done} processed, {detected} detected.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
