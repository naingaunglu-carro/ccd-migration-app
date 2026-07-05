"""Run RapidOCR-based Malay Name+NRIC extraction across a folder of PDFs.

Independent of batch_ocr.py. Shares a warm RapidOCR engine across the batch
so per-file latency stays low after the first call.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from time import perf_counter
from typing import Optional, Sequence

from single_rapid_ocr import OcrResult, _print_result, extract, warm_engine


DEFAULT_PDF_DIR = Path(__file__).resolve().parent / "format"


def batch_pdf_results(
    pdf_dir: str | Path = DEFAULT_PDF_DIR,
    *,
    pattern: str = "*.pdf",
    limit: Optional[int] = None,
    show_lines: bool = False,
) -> int:
    folder = Path(pdf_dir).expanduser()
    if not folder.exists():
        print(f"ERROR: folder not found: {folder}")
        return 1
    if not folder.is_dir():
        print(f"ERROR: not a folder: {folder}")
        return 1

    pdfs = sorted(folder.glob(pattern))
    if limit is not None:
        pdfs = pdfs[:limit]
    if not pdfs:
        print(f"ERROR: no PDFs found in: {folder} matching {pattern!r}")
        return 1

    print(
        f"Found {len(pdfs)} PDF(s) in {folder} matching {pattern!r}",
        flush=True,
    )

    warm_start = perf_counter()
    warm_engine()
    print(f"Engine ready in {perf_counter() - warm_start:.2f}s", flush=True)

    detected = 0
    total = 0
    total_time = 0.0
    results: list[OcrResult] = []

    for pdf in pdfs:
        result = extract(pdf)
        _print_result(result, show_lines=show_lines)
        total += 1
        total_time += result.elapsed_seconds
        if result.detected:
            detected += 1
        results.append(result)

    print()
    print("=" * 70)
    print(f"Summary: detected {detected}/{total} files")
    print(f"Total OCR time: {total_time:.2f}s "
          f"(avg {total_time / max(total, 1):.2f}s/file)")
    by_type: dict[str, int] = {}
    for r in results:
        by_type[r.doc_type.value] = by_type.get(r.doc_type.value, 0) + 1
    print("By doc type: " + ", ".join(f"{k}={v}" for k, v in sorted(by_type.items())))

    return 0 if detected == total else 2


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Batch RapidOCR Name+NRIC extraction over a folder of Malaysian "
            "owner documents (MyKad, vehicle_ownership_certificate, JPJ transfer, summon slip)."
        )
    )
    parser.add_argument(
        "pdf_dir",
        nargs="?",
        default=str(DEFAULT_PDF_DIR),
        help="Folder of PDFs (default: ./format)",
    )
    parser.add_argument("--pattern", default="*.pdf", help="Glob pattern (default *.pdf)")
    parser.add_argument("--limit", type=int, default=None, help="Process at most N files")
    parser.add_argument(
        "--show-lines",
        action="store_true",
        help="Print every OCR line per file",
    )
    args = parser.parse_args(argv)

    return batch_pdf_results(
        args.pdf_dir,
        pattern=args.pattern,
        limit=args.limit,
        show_lines=args.show_lines,
    )


if __name__ == "__main__":
    sys.exit(main())
