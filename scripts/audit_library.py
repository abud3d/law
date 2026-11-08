#!/usr/bin/env python3
"""Audit all imported legal records without modifying their original wording.
Usage: python3 scripts/audit_library.py
Output: library/data/legal-audit.json (generated locally; review before committing).
"""
import collections
import datetime
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOURCE = ROOT / "library/data/saudi-legal-moj.json"
OUTPUT = ROOT / "library/data/legal-audit.json"
def clean(value):
    return re.sub(r"[.،؛\s]+$", "", re.sub(r"\s+", " ", str(value or "").strip()))
def article_key(value):
    return clean(value).replace("أ", "ا").replace("إ", "ا").replace("آ", "ا")
def classify(name):
    if re.search(r"لائحة|اللائحة", name): return "لائحة"
    if "قواعد" in name: return "قواعد"
    if "ضوابط" in name: return "ضوابط"
    if "تنظيم" in name: return "تنظيم"
    if "نظام" in name: return "نظام"
    return "غير محدد"
def audit(rows):
    if not isinstance(rows, list):
        raise ValueError("Expected a JSON array")
    if not all(isinstance(row, dict) for row in rows):
        raise ValueError("All records must be JSON objects")
    return build_report(rows)

def build_report(rows):
    groups = collections.defaultdict(list)
    issues = []
    for idx, row in enumerate(rows):
        name = clean(row.get("law_name"))
        groups[name].append(idx)
        article = clean(row.get("article_number"))
        raw = row.get("text") or ""
        flags = []
        if not name: flags.append("missing_law_name")
        if not article: flags.append("missing_article_number")
        if not raw.strip(): flags.append("missing_text")
        if not row.get("source"): flags.append("missing_source")
        if not any(row.get(k) for k in ("url", "source_url", "document_url", "official_url", "link")):
            flags.append("missing_direct_url")
        if name and article and not re.search(re.escape(article_key(article).replace("ا", "[اأإآ]")), article_key(raw)):
            flags.append("article_label_not_found_in_text")
        if re.search(r"\(جزء\s*\d+\)", article): flags.append("article_fragment")
        headings = re.findall(r"(?:^|\n)\s*المادة\s+[^\n:]{1,45}", raw)
        if len(headings) > 1: flags.append("multiple_article_headings")
        if flags: issues.append({"row":idx+1,"law":name,"article":article,"flags":flags})
    names = []
    for name, indices in sorted(groups.items()):
        seen = collections.Counter(article_key(rows[i].get("article_number")) for i in indices)
        names.append({"name":name,"kind":classify(name),"records":len(indices),
                      "repeated_article_labels":sum(v-1 for k,v in seen.items() if k and v>1),
                      "source_authorities":sorted({str(rows[i].get("source") or "") for i in indices})})
    result = {
      "generated_at_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
      "total_records":len(rows),"legislation_names":len(names),
      "note":"Structural audit only. This does not verify the authenticity, completeness, or currency of any legal text.",
      "source_policy":"moj.gov.sa is an authority-level label, not a verified article URL.",
      "issue_counts":dict(collections.Counter(flag for issue in issues for flag in issue["flags"])),
      "legislation":names,"records_requiring_review":issues
    }
    return result

def main():
    rows = json.loads(SOURCE.read_text(encoding="utf-8"))
    result = audit(rows)
    OUTPUT.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print(f"Audited {len(rows)} records across {len(result['legislation'])} canonical names; report: {OUTPUT}")
if __name__ == "__main__":
    main()
