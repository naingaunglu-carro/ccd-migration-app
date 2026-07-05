"""Extract Malaysian owner Name + NRIC from a PDF using RapidOCR (PP-OCRv5 Latin)."""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass, field
from enum import Enum
from pathlib import Path
from time import perf_counter
from typing import Iterable, List, Optional, Sequence, Tuple

import fitz
import numpy as np


# ----------------------------------------------------------------------------
# RapidOCR engine (lazy singleton)
# ----------------------------------------------------------------------------

_ENGINE = None


def get_engine():
    """Process-wide RapidOCR engine using PP-OCRv5 LATIN mobile (offline)."""
    global _ENGINE
    if _ENGINE is None:
        import logging

        from rapidocr import RapidOCR, LangRec, ModelType, OCRVersion

        # Silence RapidOCR's per-init INFO logs (model paths, engine choice).
        _rapid_log = logging.getLogger("RapidOCR")
        _rapid_log.setLevel(logging.WARNING)
        for _h in _rapid_log.handlers:
            _h.setLevel(logging.WARNING)
        _rapid_log.propagate = False

        _ENGINE = RapidOCR(
            params={
                "Rec.lang_type": LangRec.LATIN,
                "Rec.ocr_version": OCRVersion.PPOCRV5,
                "Rec.model_type": ModelType.MOBILE,
                "Global.text_score": 0.5,
                "Global.use_cls": True,
            }
        )
    return _ENGINE


def warm_engine() -> None:
    get_engine()


# ----------------------------------------------------------------------------
# PDF rendering 
# ----------------------------------------------------------------------------

_RENDER_DPI = 200
_MAX_LONG_EDGE = 2400
_NATIVE_TEXT_MIN_CHARS = 300  # below this, treat the PDF as scan


def _render_page(page: fitz.Page, dpi: int = _RENDER_DPI) -> np.ndarray:
    pix = page.get_pixmap(dpi=dpi)
    img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.h, pix.w, pix.n)
    if pix.n == 4:
        img = img[:, :, :3]
    long_edge = max(img.shape[:2])
    if long_edge > _MAX_LONG_EDGE:
        scale = _MAX_LONG_EDGE / long_edge
        new_w = int(img.shape[1] * scale)
        new_h = int(img.shape[0] * scale)
        import cv2

        img = cv2.resize(img, (new_w, new_h), interpolation=cv2.INTER_AREA)
    return img


def _pdf_native_text(doc: fitz.Document) -> str:
    return "\n".join(page.get_text() for page in doc)


# ----------------------------------------------------------------------------
# OCR types
# ----------------------------------------------------------------------------

@dataclass
class OcrLine:
    text: str
    score: float
    box: Optional[Sequence[Sequence[float]]] = None


def ocr_image(img: np.ndarray) -> List[OcrLine]:
    result = get_engine()(img)
    if result is None or not result.txts:
        return []
    out: List[OcrLine] = []
    boxes = result.boxes if result.boxes is not None else [None] * len(result.txts)
    for txt, score, box in zip(result.txts, result.scores, boxes):
        if not txt:
            continue
        out.append(OcrLine(text=str(txt).strip(), score=float(score), box=box))
    return out


# ----------------------------------------------------------------------------
# Normalization (from Latin OCR confusion research)
# ----------------------------------------------------------------------------

# Letters that commonly OCR as digits inside Latin-script text.
_LETTER_TO_DIGIT = {
    "O": "0", "o": "0", "Q": "0", "D": "0",
    "I": "1", "l": "1", "|": "1", "L": "1",
    "Z": "2", "z": "2",
    "E": "3",
    "A": "4",
    "S": "5", "s": "5",
    "G": "6", "b": "6",
    "T": "7",
    "B": "8",
    "g": "9", "q": "9",
}

# Digits that commonly OCR for letters inside Latin-script names.
_DIGIT_TO_LETTER = {
    "0": "O",
    "1": "I",   # default; "L" is rarer in Malay name corpora
    "5": "S",
    "8": "B",
    "6": "G",
    "7": "T",
    "2": "Z",
    "3": "E",
    "4": "A",
    "9": "G",
}

# Repair common OCR variants of Malay patronymic particles.
_PARTICLE_FIXES = [
    (r"\bB1N\b", "BIN"),
    (r"\bB1NTI\b", "BINTI"),
    (r"\bBINT1\b", "BINTI"),
    (r"\bBlNTI\b", "BINTI"),
    (r"\bBlN\b", "BIN"),
    (r"\bBN\b", "BIN"),
    (r"\bBINTE\b", "BINTI"),
    # A/L (anak lelaki) and A/P (anak perempuan) — Indian-Malaysian patronyms.
    # Slash often OCRs as V, |, \, 1, I, T, 7. Match A?P / A?L where ?
    # is any single non-letter or those slash look-alikes.
    (r"\bA\s*[\/\\|VIT17]\s*L\b", "A/L"),
    (r"\bA\s*[\/\\|VIT17]\s*P\b", "A/P"),
    (r"\bA\s+L\b", "A/L"),
    (r"\bA\s+P\b", "A/P"),
    # S/O (son of) and D/O (daughter of) — used in PUSPAKOM and JPJ docs.
    (r"\bS\s*[\/\\|VIT17]\s*O\b", "S/O"),
    (r"\bD\s*[\/\\|VIT17]\s*O\b", "D/O"),
]


# Substrings that appear in document chrome / labels / headers but never in
# a real person's name. Detection is case-insensitive on the normalized form.
# Tuned to catch OCR drift too (e.g. KAD PENG matches KAD PENGENIA).
_CHROME_SUBSTRINGS = (
    "KAD PENG", "KAR PENG", "PENGENAL", "MYKAD", "MYTUK",
    "MALAYS", "MALAYSL",
    "IDENT", "CARD", "WARGA", "LELAKI", "PEREMPU",
    "JABATAN", "PENGANGKUT", "JALAN MALAYSIA",
    "SIJIL", "SIUIL", "PEMILIK", "PEMUNYA", "BERDAFTA",
    "PEMBAWA",  # PUSPAKOM "bearer/driver" prefix (distinct from owner)
    "RUJUKAN", "TUKAR HAK", "HAK MILIK", "VEHICLE OWNERSHIP",
    "JPJ", "CARRO", "PUSPAKOM", "POTONG SAMBUNG",
    "KEPUTUSAN", "PERKARA", "CATATAN", "MEMATUHI", "LULUS",
    "PEMERIKSA", "LAPORAN", "PERTUKARAN",
    "SUBMISSION", "RESPONSE", "STATUS",
    "KETUA PENGARAH", "PENDAFTARAN", "NEGARA",
    "ALAMAT", "EMAIL", "TOUCH NGO", "TOUCH'NGO",
    "USE ONLY",
    # Summon-slip / business form chrome
    "USED CAR", "DEALER", "COMPANY", "VEHICLE NO",
    "CONTACT", "BLACKLISTED", "CONDITION", "BHD", "SDN",
    # Generic Malay vehicle-form field labels (appear on PUSPAKOM, VOC,
    # JPJ forms regardless of owner). Each substring is intentionally a
    # phrase rather than a single common word, so real names like
    # "MOHAMMAD" or "KOD" embedded in a longer name aren't accidentally
    # rejected.
    "BAHAN BAKAR", "TAHUN DIPERBUAT", "KEUPAYAAN ENJIN",
    "MUATAN TEMPAT", "JENIS PEMERIKSA", "JENIS BADAN",
    "KATEGORI KENDERAAN", "STATUS PEMUNYA", "STATUS ASAL",
    "KOD PUSAT", "KOD PEMERIKSA", "KOD PELULUS",
    "TEMPOH SAH", "TARIKH PEMERIKSA", "TARIKH PENDAFTAR",
    "KADAR LKM", "NO. PENDAFTAR", "NO PENDAFTAR",
    "NO. ENJIN", "NO ENJIN", "NO. CASIS", "NO CASIS",
    "NO. CHASIS", "NO CHASIS", "NOMBOR ENJIN", "NOMBOR CASIS",
    "BUATAN /", "/ NAMA MODEL",
    "KELAS KEGUNAAN", "SYARAT PENDAFTARAN",
    "KEADAAN CERMIN", "PEMASANGAN", "NOMBOR CASIS", "BACAAN CERMIN",
    # Vehicle classification values that often appear next to labels and
    # would otherwise be mistaken for owner names.
    "PERSENDIRIAN", "MOTOKAR", "MOTOSIKAL", "INDIVIDU",
    "TEMPATAN", "PEMASANGAN TEMPATAN",
    # Citizenship / religion / gender values (Malay).
    "ISLAM", "KRISTIAN", "BUDDHA", "HINDU",
    # International passport boilerplate (ICAO 9303 / common page text).
    "PASSPORT", "MINISTRY OF FOREIGN", "FOREIGN AFFAIRS",
    "AUTHORITIES", "AUTHORIT", "FOREIGN COUNTRIES", "BEARER OF THIS",
    "REPUBLIC OF", "PEOPLE'S REPUBLIC", "DATE OF BIRTH",
    "DATE OF ISSUE", "DATE OF EXPIRY", "PLACE OF BIRTH",
    "PLACE OF ISSUE", "COUNTRY CODE", "NATIONALITY",
    "EXIT", "ENTRY ADMINISTRATION",
    "AND CIVIL", "BUREAU",
)


# Address tokens — a name line should not contain these.
_ADDRESS_TOKENS = frozenset(
    {
        "JALAN", "LORONG", "LRG", "TAMAN", "BANDAR", "KAMPUNG", "KG",
        "BLOK", "APARTMENT", "APT", "TINGKAT", "TKT", "PERSIARAN",
        "LEBUH", "SEKSYEN", "SEC", "LOT", "FLAT", "TOWER", "FLOOR",
        "PRIMA", "AVENUE", "AVE", "STREET", "ST",
        # Malaysian states
        "SELANGOR", "JOHOR", "MELAKA", "PENANG", "KEDAH", "PERAK",
        "PAHANG", "TERENGGANU", "KELANTAN", "PERLIS", "SABAH",
        "SARAWAK", "LABUAN", "PUTRAJAYA", "NEGERI", "SEMBILAN",
        # Big cities
        "CYBERJAYA", "KAJANG", "SHAH", "ALAM", "MASAI", "SEREMBAN",
        "PASIR", "GUDANG", "KUALA", "LUMPUR", "KL", "KLANG",
        "KOTA", "BHARU", "JOHOR BAHRU", "BAHRU",
    }
)


# Tokens that the chrome filter passes but that aren't real names either.
_STANDALONE_NOISE = frozenset(
    {
        "MAL", "JR", "MYKAD", "MYTUK", "NOVD", "NOID", "NO", "ID",
        "USE", "ONLY", "BHD", "SDN", "TECHNOLOGY",
    }
)


def normalize_name(text: str) -> str:
    """Normalize an OCR'd uppercase Malay name line.

    Steps:
      1. Uppercase + whitespace collapse.
      2. Digit -> letter substitutions ONLY when the digit sits between letters
         (i.e. clearly inside a name token, not a standalone digit token).
      3. Particle fixes (BIN/BINTI/A/L/A/P/S/O/D/O variants).
      4. Strip leading / trailing punctuation noise.
    """
    if not text:
        return text
    s = text.upper().strip()
    s = re.sub(r"\s+", " ", s)

    def _swap(m: re.Match) -> str:
        return _DIGIT_TO_LETTER.get(m.group(0), m.group(0))

    # Digit between two letters: definitely an OCR slip inside a name.
    s = re.sub(r"(?<=[A-Z])[0-9](?=[A-Z])", _swap, s)
    # Digit at start of a multi-letter token (e.g. "0MAR" -> "OMAR").
    s = re.sub(r"(?<![A-Z0-9])[0-9](?=[A-Z]{2,})", _swap, s)
    # Digit at end of a multi-letter token (e.g. "OMA8" -> "OMAB").
    s = re.sub(r"(?<=[A-Z]{2})[0-9](?![A-Z0-9])", _swap, s)

    for pat, repl in _PARTICLE_FIXES:
        s = re.sub(pat, repl, s)

    # Strip commas (passport "SURNAME, GIVENNAMES" -> "SURNAME GIVENNAMES").
    s = s.replace(",", " ")
    s = re.sub(r"^[^A-Z]+", "", s)
    s = re.sub(r"[^A-Z')]+$", "", s)
    s = re.sub(r"\s+", " ", s)
    return s.strip()


def _is_chrome(line_upper: str) -> bool:
    for sub in _CHROME_SUBSTRINGS:
        if sub in line_upper:
            return True
    return False


def _looks_like_address(line_upper: str) -> bool:
    if re.search(r"\b\d{5}\b", line_upper):  # postcode
        return True
    tokens = set(re.findall(r"[A-Z]+", line_upper))
    return bool(tokens & _ADDRESS_TOKENS)


def _is_plausible_name(normalized: str) -> bool:
    if not normalized:
        return False
    if len(normalized) < 4 or len(normalized) > 80:
        return False
    up = normalized.upper()
    if _is_chrome(up):
        return False
    if _looks_like_address(up):
        return False
    tokens = [t for t in re.split(r"\s+", up) if t]
    if not tokens:
        return False
    # Reject single-token short names ("IDEN", "NOVD", "JR", etc.).
    if len(tokens) == 1 and len(tokens[0]) < 6:
        return False
    if all(t in _STANDALONE_NOISE for t in tokens):
        return False
    # Reject label-shaped lines: ends with NAME / NAMA / NRIC / NUMBER /
    # PASSPORT / ID. These are field labels, not person names.
    if tokens[-1] in {"NAME", "NAMA", "NRIC", "NUMBER", "PASSPORT", "ID", "NO"}:
        return False
    # Every token must be either alphabetic (with allowed punct), or one of
    # the canonical Malay particles (A/L, A/P, S/O, D/O).
    PARTICLES = {"A/L", "A/P", "S/O", "D/O", "BIN", "BINTI", "BT", "BTE"}
    for t in tokens:
        if t in PARTICLES:
            continue
        if not re.fullmatch(r"[A-Z][A-Z'.\-]*[A-Z]|[A-Z]", t):
            return False
    # At least one substantive token (>= 3 alphabetic chars).
    if not any(len(t) >= 3 and t.isalpha() for t in tokens):
        return False
    return True


# ----------------------------------------------------------------------------
# NRIC extraction (conservative)
# ----------------------------------------------------------------------------

_NRIC_SHAPED = re.compile(r"(?<!\d)(\d{6})\s*[-.\s]\s*(\d{2})\s*[-.\s]\s*(\d{4})(?!\d)")
_NRIC_BARE12 = re.compile(r"(?<!\d)(\d{12})(?!\d)")


def _looks_like_valid_nric(d12: str) -> bool:
    if len(d12) != 12 or not d12.isdigit():
        return False
    yy, mm, dd = d12[0:2], d12[2:4], d12[4:6]
    try:
        m, day = int(mm), int(dd)
    except ValueError:
        return False
    return 1 <= m <= 12 and 1 <= day <= 31


def extract_nric_from_lines(lines: Sequence[OcrLine]) -> Optional[str]:
    """Return canonical NRIC string DDDDDD-DD-DDDD or None.

    Strict: only accepts lines whose digits already form an NRIC (shaped or
    bare-12). Lightweight letter->digit repair is applied per line first, but
    only on text that mostly resembles a number (>=50% digits + lookalikes).
    """
    from collections import Counter

    counter: Counter[str] = Counter()
    for line in lines:
        text = line.text
        for nric in _scan_line_for_nric(text):
            counter[nric] += 1
    if not counter:
        return None
    best, _ = counter.most_common(1)[0]
    return best


def _scan_line_for_nric(text: str) -> List[str]:
    out: List[str] = []
    if not text:
        return out
    # Direct hits.
    for m in _NRIC_SHAPED.finditer(text):
        cand = m.group(1) + m.group(2) + m.group(3)
        if _looks_like_valid_nric(cand):
            out.append(f"{cand[:6]}-{cand[6:8]}-{cand[8:]}")
    for m in _NRIC_BARE12.finditer(text):
        cand = m.group(1)
        if _looks_like_valid_nric(cand):
            out.append(f"{cand[:6]}-{cand[6:8]}-{cand[8:]}")
    if out:
        return out

    # Conservative letter->digit repair: only on lines that already look
    # primarily like an ID (mostly digits + a couple of letters).
    if _looks_like_numeric_id(text):
        repaired = "".join(
            _LETTER_TO_DIGIT[c] if c in _LETTER_TO_DIGIT else c for c in text
        )
        for m in _NRIC_SHAPED.finditer(repaired):
            cand = m.group(1) + m.group(2) + m.group(3)
            if _looks_like_valid_nric(cand):
                out.append(f"{cand[:6]}-{cand[6:8]}-{cand[8:]}")
        for m in _NRIC_BARE12.finditer(repaired):
            cand = m.group(1)
            if _looks_like_valid_nric(cand):
                out.append(f"{cand[:6]}-{cand[6:8]}-{cand[8:]}")
    return out


# Passport number patterns. Designed to match common passport-number shapes
# without false-positiving on chassis/engine numbers (which is why we only
# invoke this extractor when DocType == PASSPORT).
# Covers:
#   - Malaysian (A + 8 digits)
#   - Chinese ordinary (E/G/D/S/P + 8 digits)
#   - Chinese special (e.g. EJ + 7 digits)
#   - Many EU formats (1-2 alpha + 6-9 digits) 
# Find runs of 6-9 digits — the digit core of a passport number.
_DIGIT_RUN = re.compile(r"(?<!\d)(\d{6,9})(?!\d)")


def _passport_candidates_from_text(text: str) -> List[str]:
    """Find passport-number candidates of shape [A-Z]{1,2}\\d{6,9} in `text`.

    Robust to OCR concatenation: locates a digit-run, then walks back up to
    2 uppercase letters. This catches `EC6235260` inside an OCR-merged
    string like `OPLEC6235260` (where a `\\b...\\b` regex would miss).
    """
    up = text.upper()
    out: List[str] = []
    for m in _DIGIT_RUN.finditer(up):
        digits = m.group(1)
        start = m.start()
        # Take the largest 1-2-letter uppercase prefix before the digits.
        prefix = ""
        for k in (2, 1):
            if start - k >= 0:
                chunk = up[start - k : start]
                if chunk.isalpha() and chunk.isupper():
                    prefix = chunk
                    break
        if prefix:
            out.append(prefix + digits)
        else:
            # Bare 8-9 digit numeric ID — uncommon for passports but accept.
            if 8 <= len(digits) <= 9:
                out.append(digits)
    return out


# ID-field label patterns (Malay + English forms). Used as a fallback when
# the bare NRIC extractor finds nothing — for VOC / Bill of Sale / JPJ forms
# whose owner is a foreign national (passport number rather than a 12-digit
# Malaysian NRIC).
_ID_LABEL_PATTERNS = [
    re.compile(r"\bNo\.?\s+ID\b", re.I),
    re.compile(r"\bNo\.?\s+1D\b", re.I),   # OCR drift (I -> 1)
    re.compile(r"\bID\s+Pemunya\b", re.I),
    re.compile(r"\bID\s+Pemilik\b", re.I),
    re.compile(r"\bNo\.?\s+KP\b", re.I),
    re.compile(r"\bNo\.?\s+K\.P\.?\b", re.I),
    re.compile(r"\bMyKad\s+No\b", re.I),
    re.compile(r"\bNRIC\b", re.I),
]


def _parse_id_value(text: str) -> Optional[str]:
    """Parse a single value cell as either an NRIC or a passport number."""
    if not text:
        return None
    s = text.strip(": \t")
    # Try NRIC patterns first (covers shaped and bare-12).
    nrics = _scan_line_for_nric(s)
    if nrics:
        return nrics[0]
    # Try passport-number shape.
    cands = _passport_candidates_from_text(s)
    if cands:
        # If multiple, prefer the longest (more specific).
        return max(cands, key=len)
    return None


def extract_id_via_label(lines: Sequence[OcrLine]) -> Optional[str]:
    """Find an ID value adjacent to an ID-label line. Accepts NRIC or passport.

    For Malaysian VOC / JPJ / Bill-of-Sale forms whose owner is a foreign
    national, the "No. ID" field holds a passport number — which the bare
    NRIC extractor (12-digit) won't match.
    """
    for i, line in enumerate(lines):
        if not any(pat.search(line.text) for pat in _ID_LABEL_PATTERNS):
            continue
        # Same-line "Label: value"
        if ":" in line.text:
            after = line.text.split(":", 1)[1].strip()
            if after:
                val = _parse_id_value(after)
                if val:
                    return val
        # Next-line value
        for j in range(i + 1, min(i + 4, len(lines))):
            nxt = lines[j].text.strip()
            if not nxt or nxt in {":", "-"}:
                continue
            val = _parse_id_value(nxt)
            if val:
                return val
            break  # don't walk past unrelated content
    return None


# Passport-number label patterns. Human-readable text is far more reliable
# than the OCR-garbled MRZ — a clear "Passport No." label with a value
# beside/below it should always win over MRZ-derived candidates.
_PASSPORT_NO_LABEL = [
    re.compile(r"\bPASSPORT\s+No\.?\b", re.I),
    re.compile(r"\bPASSPORT\s+NUMBER\b", re.I),
    re.compile(r"\bPASSPORT\s+No\b", re.I),
    re.compile(r"^\s*PP\s+No\.?\s*$", re.I | re.M),
]


def _passport_no_via_label(lines: Sequence[OcrLine]) -> Optional[str]:
    """Find a passport number by following a 'Passport No.' label.

    Passport pages are usually column-laid-out, so RapidOCR may emit a few
    intervening short field-value lines (e.g. 'PJ' for Type, 'MMR' for
    Country code) between the label and the actual passport number. We
    scan up to 6 following lines and return the first that contains a
    passport-shape token.
    """
    for i, line in enumerate(lines):
        if not any(p.search(line.text) for p in _PASSPORT_NO_LABEL):
            continue
        # Same-line "Label: value"
        if ":" in line.text:
            after = line.text.split(":", 1)[1].strip()
            cands = _passport_candidates_from_text(after)
            if cands:
                return max(cands, key=len)
        # Forward scan: skip short non-passport-shape lines (Type / country
        # code / etc.) and return the first passport-shape hit.
        for j in range(i + 1, min(i + 7, len(lines))):
            nxt = lines[j].text.strip()
            if not nxt:
                continue
            # Stop if we hit another labelled section.
            if any(p.search(nxt) for p in _PASSPORT_NO_LABEL):
                break
            cands = _passport_candidates_from_text(nxt)
            if cands:
                return max(cands, key=len)
    return None


def extract_passport_number(lines: Sequence[OcrLine]) -> Optional[str]:
    """Find the best passport-number candidate across OCR lines.

    Priority:
      1. Value beside a 'Passport No.' label (human-readable, most reliable).
      2. First 9 chars of an MRZ TD3 line 2 (ICAO 9303 fixed position).
      3. Heuristic across all candidates (count + Malaysian-A bias + length).
    """
    # 1. Label-based extraction wins outright.
    val = _passport_no_via_label(lines)
    if val:
        return val

    # 2. MRZ TD3 line 2 starts with the passport number (positions 0-8,
    # right-padded with '<'). We look for any line >= 30 chars containing
    # multiple filler characters — that signature uniquely identifies MRZ
    # line 2 even with OCR noise.
    for line in lines:
        text = line.text.strip()
        if len(text) < 30:
            continue
        if not re.search(r"[<>]{2,}", text):
            continue
        # Take the leading 9 chars, strip MRZ filler, then look for our
        # passport-shape pattern (letter walk-back from digit run).
        head = re.sub(r"[<>]+", "", text[:10])
        cands = _passport_candidates_from_text(head)
        if cands:
            return max(cands, key=len)

    # 3. Heuristic fallback.
    from collections import Counter

    cands: List[str] = []
    mrz_lines = {
        i for i, ln in enumerate(lines) if re.search(r"[<>]{8,}", ln.text)
    }
    for i, line in enumerate(lines):
        text = re.sub(r"[<>]+", " ", line.text.upper())
        for tok in _passport_candidates_from_text(text):
            cands.append(tok)
            if i in mrz_lines or any(j in mrz_lines for j in (i - 1, i + 1)):
                cands.append(tok)

    if not cands:
        return None
    counter = Counter(cands)

    def _key(item: Tuple[str, int]) -> Tuple[int, bool, int]:
        tok, count = item
        is_my = bool(re.fullmatch(r"A\d{8}", tok))
        return (count, is_my, len(tok))

    best, _ = max(counter.items(), key=_key)
    return best


def _looks_like_numeric_id(text: str) -> bool:
    """A line that already looks primarily like a numeric ID field.

    Requires: at least 10 digits, total length <= 20, no obvious labels."""
    if not text:
        return False
    digits = sum(c.isdigit() for c in text)
    if digits < 10:
        return False
    alnum_len = sum(c.isalnum() for c in text)
    if alnum_len == 0 or alnum_len > 20:
        return False
    if digits / alnum_len < 0.6:
        return False
    return True


# ----------------------------------------------------------------------------
# Document classification (fuzzy, OCR-tolerant)
# ----------------------------------------------------------------------------

class DocType(str, Enum):
    MYKAD = "mykad"
    VOC = "vehicle_ownership_certificate"
    JPJ_TRANSFER = "jpj_transfer"
    SUMMON_SLIP = "summon_slip"
    PUSPAKOM = "puspakom"
    BILL_OF_SALE = "bill_of_sale"
    PASSPORT = "passport"
    GENERIC = "generic"


def classify_lines(lines: Sequence[OcrLine]) -> DocType:
    return classify_text("\n".join(l.text for l in lines))


def classify_text(text: str) -> DocType:
    up = text.upper()
    # Order matters: more-specific Malaysian docs are checked first because
    # they sometimes contain the word "Passport" in a label phrase (e.g.
    # Carro's "NRIC No./Passport No./Reg No." field).
    # PUSPAKOM inspection report.
    if "PUSPAKOM" in up or "LAPORAN PEMERIKSAAN" in up:
        return DocType.PUSPAKOM
    # Carro Bill of Sale.
    if "BILL OF SALE" in up:
        return DocType.BILL_OF_SALE
    # Passport: MRZ filler-run is the cleanest signal; bare "PASSPORT"
    # keyword is also accepted but only after Malaysian-form checks.
    if re.search(r"[<>]{8,}", up) or "PASSPORT" in up:
        return DocType.PASSPORT
    # VOC (vehicle ownership certificate). Tolerate "SIUIL" OCR drift.
    if "PEMILIKAN KENDERAAN" in up or "VEHICLE OWNERSHIP" in up:
        return DocType.VOC
    if "PEMUNYA BERDAFTAR" in up and "TUKAR HAK" in up:
        return DocType.JPJ_TRANSFER
    if "PEMUNYA BERDAFTAR" in up:
        return DocType.VOC
    if "TUKAR HAK MILIK" in up or "RUJUKAN TUKAR" in up:
        return DocType.JPJ_TRANSFER
    if "JPJ CHECKING" in up or ("MYKAD NO" in up and "VEHICLE NO" in up):
        return DocType.SUMMON_SLIP
    if "SUBMISSION" in up and "JPJ" in up:
        return DocType.SUMMON_SLIP
    if (
        "PENGENAL" in up
        or "MYKAD" in up
        or "IDENTITY CARD" in up
        # No labels detected, but WARGANEGARA + LELAKI/PEREMPUAN is the
        # canonical MyKad reverse-side signature.
        or ("WARGANEGARA" in up and ("LELAKI" in up or "PEREMPUAN" in up))
    ):
        return DocType.MYKAD
    return DocType.GENERIC


# ----------------------------------------------------------------------------
# Name picker (unified, scoring-based)
# ----------------------------------------------------------------------------

# Labels that, when found, give a strong boost to nearby plausible names.
_NAME_LABEL_PATTERNS = [
    re.compile(r"NAMA\s+PEMUNYA\s+BERDAFTAR", re.I),
    re.compile(r"NAMA\s+PEMUNYA", re.I),
    re.compile(r"NAMA\s+PEMILIK", re.I),
    re.compile(r"\bPEMILIK\b\s*:", re.I),
    re.compile(r"\bFULL\s*NAME\b", re.I),
    re.compile(r"^\s*NAME\s*:", re.I | re.M),
    re.compile(r"\bNAMA\s*:", re.I),
    # Passport: bare "Name" or "/Name" label on its own line (no colon).
    re.compile(r"^\s*/?\s*NAME\s*$", re.I | re.M),
    re.compile(r"^\s*SURNAME\s*$", re.I | re.M),
    re.compile(r"^\s*GIVEN\s+NAMES?\s*$", re.I | re.M),
    # JPJ slip / eAuto layout: bare "Nama" label on its own line, with the
    # colon and value on the next two lines.
    re.compile(r"^\s*NAMA\s*$", re.I | re.M),
]


def _label_line_indices(lines: Sequence[OcrLine]) -> List[int]:
    out: List[int] = []
    for i, line in enumerate(lines):
        for pat in _NAME_LABEL_PATTERNS:
            if pat.search(line.text):
                out.append(i)
                break
    return out


def _nric_line_indices(lines: Sequence[OcrLine]) -> List[int]:
    out: List[int] = []
    for i, line in enumerate(lines):
        if _NRIC_SHAPED.search(line.text) or _NRIC_BARE12.search(line.text):
            out.append(i)
    return out


def pick_name(lines: Sequence[OcrLine], doc_type: DocType) -> Optional[str]:
    """Pick the best Name line from OCR output, regardless of doc_type ordering.

    Strategy:
      1. If a line is "<label>: <value>" with a name label, take the value
         directly. (Highest precedence, always trustworthy.)
      2. Otherwise, score every candidate line:
           + 2.0 * OCR confidence
           + 4.0 if contains a Malay patronymic particle (BIN/BINTI/A/L/A/P/S/O)
           + 3.0 if has a name label line within +-2 of its position
           + boost (0..3) inversely proportional to distance from NRIC line
           + 1.5 if 2..5 tokens
           - 1.0 if 1 token or > 6 tokens
      3. Reject candidates failing plausibility (chrome/address/short/etc.).
    """

    # Step 1: explicit-label match wins outright. Try same-line value first,
    # then fall through to the next 1-3 lines for the value.
    for i, line in enumerate(lines):
        if not any(pat.search(line.text) for pat in _NAME_LABEL_PATTERNS):
            continue
        # Same-line "Label: value"
        if ":" in line.text:
            after = line.text.split(":", 1)[1].strip()
            if after:
                normalized = normalize_name(after)
                if _is_plausible_name(normalized):
                    return normalized
        # Next-line value: scan up to 3 following non-empty lines.
        for j in range(i + 1, min(i + 4, len(lines))):
            nxt = lines[j].text.strip()
            if not nxt:
                continue
            # Skip an immediately-following bare ":" or label fragment.
            if nxt in {":", "-"}:
                continue
            normalized = normalize_name(nxt)
            if _is_plausible_name(normalized):
                return normalized
            # Only walk forward across blank/separator lines, not real content.
            break

    # Step 2: scored search.
    label_idx = _label_line_indices(lines)
    nric_idx = _nric_line_indices(lines)

    candidates: List[Tuple[float, int, str]] = []
    for i, line in enumerate(lines):
        norm = normalize_name(line.text)
        if not _is_plausible_name(norm):
            continue

        score = 0.0
        score += line.score * 2.0
        if re.search(r"\b(BIN|BINTI|BT|BTE|A/L|A/P|S/O|D/O)\b", norm):
            score += 4.0
        if any(abs(i - li) <= 2 for li in label_idx):
            score += 3.0
        if nric_idx:
            min_d = min(abs(i - n) for n in nric_idx)
            score += max(0.0, 3.0 - min_d * 0.5)
        tok_count = len(norm.split())
        if 2 <= tok_count <= 5:
            score += 1.5
        elif tok_count == 1 or tok_count > 6:
            score -= 1.0

        candidates.append((score, i, norm))

    if not candidates:
        return None
    candidates.sort(key=lambda x: (-x[0], x[1]))
    return candidates[0][2]


def _join_name_continuation(
    lines: Sequence[OcrLine], primary: str
) -> str:
    """Append a short continuation line if the chosen name ends with a Malay
    particle (BIN/BINTI/A/L/A/P/S/O/D/O) — the suffix is the patronymic and
    the *next* line is typically the father's name truncated by line-break.
    """
    primary = primary.strip()
    if not re.search(r"\b(BIN|BINTI|BT|BTE|A/L|A/P|S/O|D/O)$", primary):
        return primary
    for i, line in enumerate(lines):
        if normalize_name(line.text) == primary:
            for j in range(i + 1, min(i + 3, len(lines))):
                nxt = lines[j].text.strip()
                if not nxt:
                    continue
                if re.search(r"\d", nxt):
                    continue
                if _looks_like_address(nxt.upper()):
                    continue
                if _is_chrome(nxt.upper()):
                    continue
                # Only join short uppercase fragments (1-2 tokens) — anything
                # longer is probably a separate line, not a continuation.
                tokens = nxt.split()
                if 1 <= len(tokens) <= 2 and all(t.isalpha() for t in tokens):
                    return normalize_name(primary + " " + nxt)
            break
    return primary


# ----------------------------------------------------------------------------
# Native-text fast-path
# ----------------------------------------------------------------------------

def _native_text_to_lines(text: str) -> List[OcrLine]:
    """Wrap native PDF text as 'OcrLine's so the unified extractors can run."""
    out: List[OcrLine] = []
    for raw in text.splitlines():
        s = raw.strip()
        if s:
            out.append(OcrLine(text=s, score=1.0))
    return out


# ----------------------------------------------------------------------------
# Orchestrator
# ----------------------------------------------------------------------------

@dataclass
class OcrResult:
    path: Path
    doc_type: DocType
    id_number: Optional[str]
    name: Optional[str]
    elapsed_seconds: float
    pages_processed: int
    used_ocr: bool
    raw_lines: List[OcrLine] = field(default_factory=list)

    @property
    def detected(self) -> bool:
        return bool(self.id_number or self.name)


def extract(path: str | Path) -> OcrResult:
    p = Path(path).expanduser()
    if not p.exists():
        return OcrResult(p, DocType.GENERIC, None, None, 0.0, 0, False)

    t0 = perf_counter()
    doc = fitz.open(p)
    try:
        native = _pdf_native_text(doc)
        if len(native.strip()) >= _NATIVE_TEXT_MIN_CHARS:
            # Native-text fast path: skip OCR entirely.
            lines = _native_text_to_lines(native)
            doc_type = classify_lines(lines)
            if doc_type == DocType.PASSPORT:
                nric = extract_passport_number(lines)
            else:
                nric = extract_nric_from_lines(lines)
                if nric is None:
                    nric = extract_id_via_label(lines)
            name = pick_name(lines, doc_type)
            if name:
                name = _join_name_continuation(lines, name)
            return OcrResult(
                path=p,
                doc_type=doc_type,
                id_number=nric,
                name=name,
                elapsed_seconds=perf_counter() - t0,
                pages_processed=doc.page_count,
                used_ocr=False,
                raw_lines=lines,
            )

        # Image-scan path: OCR each page, short-circuit when both fields found.
        all_lines: List[OcrLine] = []
        doc_type: Optional[DocType] = None
        nric: Optional[str] = None
        name: Optional[str] = None
        pages_processed = 0

        for page in doc:
            pages_processed += 1
            img = _render_page(page)
            page_lines = ocr_image(img)
            all_lines.extend(page_lines)

            doc_type = classify_lines(all_lines)
            if nric is None:
                if doc_type == DocType.PASSPORT:
                    nric = extract_passport_number(all_lines)
                else:
                    nric = extract_nric_from_lines(all_lines)
                    if nric is None:
                        # Foreign owner on a Malaysian form: ID field may hold
                        # a passport number rather than a 12-digit NRIC.
                        nric = extract_id_via_label(all_lines)
            if name is None:
                name = pick_name(all_lines, doc_type)
            if nric and name:
                break

        if name:
            name = _join_name_continuation(all_lines, name)

        return OcrResult(
            path=p,
            doc_type=doc_type or DocType.GENERIC,
            id_number=nric,
            name=name,
            elapsed_seconds=perf_counter() - t0,
            pages_processed=pages_processed,
            used_ocr=True,
            raw_lines=all_lines,
        )
    finally:
        doc.close()


# ----------------------------------------------------------------------------
# CLI
# ----------------------------------------------------------------------------

_NATIVE_TEXT_SUPPRESS_TYPES = {DocType.PUSPAKOM, DocType.BILL_OF_SALE}


def _print_result(result: OcrResult, *, show_lines: bool = False) -> None:
    suffix = (
        " [native-text]"
        if not result.used_ocr and result.doc_type not in _NATIVE_TEXT_SUPPRESS_TYPES
        else ""
    )
    print()
    print(f"File Path: {result.path}")
    print(f"Doc Type:  {result.doc_type.value}{suffix}")
    print(f"Status:    {'detected' if result.detected else 'not detected'}")
    print(f"ID:        {result.id_number or ''}")
    print(f"Name:      {result.name or ''}")
    print(f"Time:      {result.elapsed_seconds:.2f}s")
    if show_lines:
        print("Raw lines:")
        for line in result.raw_lines:
            print(f"  [{line.score:.2f}] {line.text}")
    print(flush=True)


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Extract Malaysian Name + NRIC from a PDF using RapidOCR."
    )
    parser.add_argument("pdf", type=str)
    parser.add_argument(
        "--show-lines",
        action="store_true",
        help="Print every OCR / native text line with confidence",
    )
    args = parser.parse_args(argv)

    warm_engine()
    result = extract(args.pdf)
    _print_result(result, show_lines=args.show_lines)
    return 0 if result.detected else 1


if __name__ == "__main__":
    sys.exit(main())
