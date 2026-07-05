#!/usr/bin/env python3
"""Download S3 objects listed in a manifest, using boto3.

The manifest has one object per line: "<key>\t<disk>" (the disk column is
optional and ignored here). Each object is downloaded from --bucket into
<dest>/<key>, mirroring the key path.

AWS credentials are read ONLY from the environment (AWS_ACCESS_KEY_ID,
AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN) via boto3's default chain — nothing
is read from or written to disk. Export them before running:

    export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_SESSION_TOKEN=...
    .venv/bin/python scripts/download_dealer_files.py --bucket carro.co \
        --dest storage/app/dealer-files --manifest manifest.tsv
"""

import argparse
import os
import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError, BotoCoreError

_RETRY = Config(retries={"max_attempts": 5, "mode": "standard"})


def parse_args():
    p = argparse.ArgumentParser(description="Download S3 objects from a manifest via boto3.")
    p.add_argument("--bucket", required=True, help="Source S3 bucket, e.g. carro.co")
    p.add_argument("--dest", required=True, help="Local directory to mirror keys into")
    p.add_argument("--manifest", default="-", help="Manifest file path ('-' for stdin)")
    p.add_argument("--region", default=os.environ.get("AWS_DEFAULT_REGION") or "ap-southeast-1")
    p.add_argument("--concurrency", type=int, default=8)
    p.add_argument("--overwrite", action="store_true", help="Re-download even if the local file exists")
    p.add_argument("--dry-run", action="store_true", help="Plan only; do not touch S3 or write files")
    return p.parse_args()


def read_keys(path):
    stream = sys.stdin if path == "-" else open(path, "r", encoding="utf-8")
    try:
        for line in stream:
            line = line.strip()
            if line:
                yield line.split("\t")[0]
    finally:
        if stream is not sys.stdin:
            stream.close()


def preflight_credentials(region):
    """Validate the AWS credentials up front via STS (needs no IAM permission),
    so we fail fast with a clear message instead of N cryptic per-object errors.
    Returns (ok, reason)."""
    try:
        boto3.client("sts", region_name=region, config=_RETRY).get_caller_identity()
        return True, None
    except ClientError as e:
        return False, e.response.get("Error", {}).get("Code", "") or "error"
    except Exception as e:  # noqa: BLE001 - report any failure to the caller
        return False, str(e)


def resolve_bucket_region(bucket, fallback):
    """Ask S3 for the bucket's actual region so SigV4 signs against the right
    endpoint (a mismatch is the most common cause of a 403). Falls back to the
    given region when GetBucketLocation isn't permitted."""
    try:
        probe = boto3.client("s3", region_name=fallback, config=_RETRY)
        loc = probe.get_bucket_location(Bucket=bucket).get("LocationConstraint")
        return loc or "us-east-1"  # us-east-1 is reported as empty
    except Exception:
        return fallback


def classify(error):
    """Map a ClientError to (status, code)."""
    code = error.response.get("Error", {}).get("Code", "") or ""
    http = error.response.get("ResponseMetadata", {}).get("HTTPStatusCode")

    if code in ("NoSuchKey", "404") or http == 404:
        return "missing", code or "404"
    if code in ("AccessDenied", "403", "InvalidAccessKeyId", "SignatureDoesNotMatch",
                "ExpiredToken", "InvalidToken", "TokenRefreshRequired") or http == 403:
        return "denied", code or "403"
    return "failed", code or "error"


def main():
    args = parse_args()

    keys = list(dict.fromkeys(read_keys(args.manifest)))  # de-dupe, preserve order
    total = len(keys)
    if total == 0:
        print("Manifest empty — nothing to download.", file=sys.stderr)
        return 0

    s3 = None
    region = args.region

    if not args.dry_run:
        if not os.environ.get("AWS_ACCESS_KEY_ID"):
            print("AWS_ACCESS_KEY_ID is not set — export your AWS credentials first.", file=sys.stderr)
            return 2

        ok, why = preflight_credentials(args.region)
        if not ok:
            expired = why in ("ExpiredToken", "ExpiredTokenException", "InvalidToken", "TokenRefreshRequired")
            print(
                f"AWS credentials rejected ({why}). "
                + ("Your temporary session token has expired — export a fresh "
                   "AWS_SESSION_TOKEN (and key/secret) and re-run."
                   if expired else "Check AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN."),
                file=sys.stderr,
            )
            return 2

        region = resolve_bucket_region(args.bucket, args.region)
        s3 = boto3.client("s3", region_name=region, config=_RETRY)

    print(
        f"{total} object(s)  s3://{args.bucket}  ->  {args.dest}  "
        f"(region {region}, concurrency {args.concurrency}"
        f"{', DRY RUN' if args.dry_run else ''})",
        file=sys.stderr,
    )

    lock = threading.Lock()
    stats = {"ok": 0, "skip": 0, "missing": 0, "denied": 0, "failed": 0}

    def worker(key):
        dest_path = os.path.join(args.dest, key)

        if not args.overwrite and os.path.exists(dest_path) and os.path.getsize(dest_path) > 0:
            return "skip", key, None
        if args.dry_run:
            return "ok", key, None

        os.makedirs(os.path.dirname(dest_path) or ".", exist_ok=True)
        tmp = dest_path + ".part"
        try:
            s3.download_file(args.bucket, key, tmp)
            os.replace(tmp, dest_path)  # atomic: no half-written file on crash
            return "ok", key, None
        except ClientError as e:
            _cleanup(tmp)
            status, code = classify(e)
            return status, key, code
        except (BotoCoreError, OSError) as e:
            _cleanup(tmp)
            return "failed", key, str(e)

    done = 0
    with ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as pool:
        futures = [pool.submit(worker, k) for k in keys]
        for fut in as_completed(futures):
            status, key, detail = fut.result()
            with lock:
                stats[status] += 1
                done += 1
                d = done
            if status in ("missing", "denied", "failed"):
                print(f"  [{status}] {key}" + (f"  ({detail})" if detail else ""), file=sys.stderr)
            if d % 50 == 0 or d == total:
                print(
                    f"  progress {d}/{total}  "
                    f"(ok {stats['ok']}, skip {stats['skip']}, missing {stats['missing']}, "
                    f"denied {stats['denied']}, failed {stats['failed']})",
                    file=sys.stderr,
                )

    print(
        f"Done: ok {stats['ok']}, skip {stats['skip']}, missing {stats['missing']}, "
        f"denied {stats['denied']}, failed {stats['failed']}.",
        file=sys.stderr,
    )

    if stats["denied"] and stats["ok"] == 0:
        print(
            "All objects returned 403/AccessDenied. Likely causes: expired or invalid "
            "session token, wrong --bucket, wrong --region, or the object keys don't "
            "exist (S3 answers 403 for a missing key when ListBucket is denied).",
            file=sys.stderr,
        )

    return 1 if (stats["failed"] or (stats["denied"] and stats["ok"] == 0)) else 0


def _cleanup(path):
    try:
        if os.path.exists(path):
            os.remove(path)
    except OSError:
        pass


if __name__ == "__main__":
    sys.exit(main())
