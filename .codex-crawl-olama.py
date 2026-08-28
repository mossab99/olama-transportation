import argparse
import concurrent.futures
import csv
import hashlib
import json
import re
import time
import urllib.parse
import xml.etree.ElementTree as ET
from pathlib import Path

import requests


UAS = {
    "chrome_desktop": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0 Safari/537.36",
    "chrome_android": "Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0 Mobile Safari/537.36",
    "safari_iphone": "Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1",
    "googlebot": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
    "googlebot_smartphone": "Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
    "bingbot": "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)",
}

IOC_RE = re.compile(
    r"site-helper-1317e86c55e6|0x58460d0b3d4d6b03761c89120393c0c676676496|"
    r"runtime-sample\.js|(?:^|/)vendor\.min\.js|nochain_bootstrap|mainnet\.base\.org|"
    r"base\.publicnode\.com|base-rpc\.publicnode\.com|base\.drpc\.org|1rpc\.io/base|"
    r"nochain-demo-frame|id=[\"']nc-cover[\"']",
    re.I,
)

TITLE_RE = re.compile(r"<title[^>]*>(.*?)</title>", re.I | re.S)
CANON_RE = re.compile(r"<link[^>]+rel=[\"'][^\"']*canonical[^\"']*[\"'][^>]+href=[\"']([^\"']+)", re.I)
SCRIPT_RE = re.compile(r"<script[^>]+src=[\"']([^\"']+)", re.I)
IFRAME_RE = re.compile(r"<iframe[^>]+src=[\"']([^\"']+)", re.I)


def fetch(url, ua_name, referer):
    headers = {"User-Agent": UAS[ua_name], "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"}
    if referer:
        headers["Referer"] = "https://www.google.com/"
    started = time.time()
    try:
        response = requests.get(url, headers=headers, timeout=(10, 35), allow_redirects=True)
        body = response.text
        title_match = TITLE_RE.search(body)
        canonical_match = CANON_RE.search(body)
        scripts = sorted(set(SCRIPT_RE.findall(body)))
        iframes = sorted(set(IFRAME_RE.findall(body)))
        normalized = re.sub(r"\s+", " ", body)
        return {
            "url": url,
            "ua": ua_name,
            "google_referer": bool(referer),
            "status": response.status_code,
            "final_url": response.url,
            "bytes": len(response.content),
            "seconds": round(time.time() - started, 3),
            "title": re.sub(r"\s+", " ", title_match.group(1)).strip() if title_match else "",
            "canonical": canonical_match.group(1) if canonical_match else "",
            "sha256": hashlib.sha256(response.content).hexdigest(),
            "structural_sha256": hashlib.sha256(normalized.encode("utf-8", "replace")).hexdigest(),
            "ioc": bool(IOC_RE.search(body)),
            "ioc_matches": sorted(set(m.group(0) for m in IOC_RE.finditer(body))),
            "scripts": scripts,
            "iframes": iframes,
            "error": "",
        }
    except Exception as exc:
        return {
            "url": url,
            "ua": ua_name,
            "google_referer": bool(referer),
            "status": 0,
            "final_url": "",
            "bytes": 0,
            "seconds": round(time.time() - started, 3),
            "title": "",
            "canonical": "",
            "sha256": "",
            "structural_sha256": "",
            "ioc": False,
            "ioc_matches": [],
            "scripts": [],
            "iframes": [],
            "error": f"{type(exc).__name__}: {exc}",
        }


def sitemap_urls(index_url):
    pending = [index_url]
    seen_maps = set()
    urls = []
    while pending:
        url = pending.pop(0)
        if url in seen_maps:
            continue
        seen_maps.add(url)
        response = requests.get(url, headers={"User-Agent": UAS["chrome_desktop"]}, timeout=(10, 35), allow_redirects=True)
        response.raise_for_status()
        root = ET.fromstring(response.content)
        locations = [node.text.strip() for node in root.findall(".//{*}loc") if node.text]
        if root.tag.endswith("sitemapindex"):
            pending.extend(locations)
        else:
            urls.extend(locations)
    return list(dict.fromkeys(urls)), sorted(seen_maps)


def external_domains(rows):
    domains = set()
    for row in rows:
        for value in row["scripts"] + row["iframes"]:
            host = urllib.parse.urlparse(urllib.parse.urljoin(row["final_url"] or row["url"], value)).hostname
            if host and host not in {"olama.online", "www.olama.online"}:
                domains.add(host.lower())
    return sorted(domains)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--workers", type=int, default=4)
    args = parser.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    urls, maps = sitemap_urls("https://olama.online/sitemap.xml")
    if not urls:
        raise SystemExit("No sitemap URLs discovered")

    # Full corpus: normal visitor and the highest-risk cloaking context.
    jobs = [(url, "chrome_desktop", False) for url in urls]
    jobs += [(url, "googlebot_smartphone", True) for url in urls]

    # Broader device/bot/referer matrix on 20 evenly distributed URLs.
    sample_count = min(20, len(urls))
    sample_indexes = sorted({round(i * (len(urls) - 1) / max(1, sample_count - 1)) for i in range(sample_count)})
    sample_urls = [urls[i] for i in sample_indexes]
    for url in sample_urls:
        for ua_name in UAS:
            for referer in (False, True):
                job = (url, ua_name, referer)
                if job not in jobs:
                    jobs.append(job)

    rows = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [pool.submit(fetch, *job) for job in jobs]
        for index, future in enumerate(concurrent.futures.as_completed(futures), 1):
            rows.append(future.result())
            if index % 100 == 0:
                print(f"completed={index}/{len(jobs)}", flush=True)

    rows.sort(key=lambda r: (r["url"], r["ua"], r["google_referer"]))
    csv_fields = ["url", "ua", "google_referer", "status", "final_url", "bytes", "seconds", "title", "canonical", "sha256", "structural_sha256", "ioc", "error"]
    with (out / "responses.csv").open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=csv_fields)
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row[key] for key in csv_fields})

    full_pairs = {}
    for row in rows:
        if row["ua"] == "chrome_desktop" and not row["google_referer"]:
            full_pairs.setdefault(row["url"], {})["visitor"] = row
        if row["ua"] == "googlebot_smartphone" and row["google_referer"]:
            full_pairs.setdefault(row["url"], {})["bot"] = row
    material_differences = []
    for url, pair in full_pairs.items():
        if "visitor" not in pair or "bot" not in pair:
            material_differences.append({"url": url, "reason": "missing pair"})
            continue
        visitor, bot = pair["visitor"], pair["bot"]
        compared = ("status", "final_url", "title", "canonical", "scripts", "iframes", "ioc")
        differences = {key: [visitor[key], bot[key]] for key in compared if visitor[key] != bot[key]}
        if differences:
            material_differences.append({"url": url, "differences": differences})

    status_counts = {}
    for row in rows:
        key = str(row["status"])
        status_counts[key] = status_counts.get(key, 0) + 1
    summary = {
        "sitemap_index": "https://olama.online/sitemap.xml",
        "sitemap_files": maps,
        "sitemap_url_count": len(urls),
        "representative_url_count": len(sample_urls),
        "request_count": len(rows),
        "status_counts": status_counts,
        "error_count": sum(bool(row["error"]) for row in rows),
        "ioc_response_count": sum(bool(row["ioc"]) for row in rows),
        "ioc_rows": [{"url": row["url"], "ua": row["ua"], "referer": row["google_referer"], "matches": row["ioc_matches"]} for row in rows if row["ioc"]],
        "material_visitor_bot_difference_count": len(material_differences),
        "material_visitor_bot_differences": material_differences,
        "external_script_iframe_domains": external_domains(rows),
        "sample_urls": sample_urls,
    }
    (out / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    (out / "sitemap-urls.txt").write_text("\n".join(urls) + "\n", encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2), flush=True)


if __name__ == "__main__":
    main()
