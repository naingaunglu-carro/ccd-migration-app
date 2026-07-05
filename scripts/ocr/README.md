# Malaysia Owner/NRIC OCR

Extracts the **Name** and **NRIC / passport number** from Malaysian owner
documents — MyKad scans, JPJ transfer forms, vehicle ownership certificates
(VOC), Puspakom inspection reports, JPJ checking slips, Carro bill-of-sale
PDFs, and international passports — using RapidOCR (PP-OCRv5 Latin, ONNX).

## Setup (one time)

```bash
cd /Users/dev/MobileProjects/malay_ocr

# Create and activate a virtual environment
python3 -m venv .venv
source .venv/bin/activate

# Install dependencies
pip install -r requirements-rapid.txt
```

That installs `rapidocr`, `onnxruntime`, `PyMuPDF`, `opencv-python-headless`,
and `numpy`. The OCR models download automatically on first run, then
everything works offline.

## Run single OCR (one PDF)

```bash
python single_rapid_ocr.py path/to/document.pdf
```

It prints the doc type, extracted ID (NRIC/passport), name, and elapsed time.
Exit code is 0 if something was detected, 1 if not.

## Run batch OCR (a folder of PDFs)

```bash
# Uses the ./format folder by default
python batch_rapid_ocr.py

# Or point it at any folder
python batch_rapid_ocr.py sample_owners
```

Useful options:

```bash
python batch_rapid_ocr.py sample_owners --limit 5   # first 5 files only
```

It processes every PDF, prints per-file results, and ends with a summary
(detected count, total/average time, breakdown by doc type). Exit code 0
means all files detected, 2 means some missed.

## Python API

```python
from single_rapid_ocr import extract, warm_engine

warm_engine()                          # one-time model load (optional)
result = extract("format/Owner-IC-3.pdf")

print(result.doc_type.value)           # "mykad"
print(result.id_number)                # "870326-02-6075"
print(result.name)                     # "MOHD SOFFIAN BIN ZAINUN"
print(result.elapsed_seconds)          # 0.9
print(result.used_ocr)                 # True (False for text-PDFs)
```
