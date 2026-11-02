#!/usr/bin/env python3
"""Extract the Saudi MOJ Parquet dataset into searchable UTF-8 JSON."""
from __future__ import annotations
import json
from pathlib import Path
import pyarrow.parquet as pq

SOURCE = Path("library/data/saudi-legal-moj.parquet")
OUT = Path("library/data/saudi-legal-moj.json")
META = Path("library/data/saudi-legal-moj-meta.json")

def clean(value):
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    if isinstance(value, dict):
        return {str(k): clean(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [clean(v) for v in value]
    if hasattr(value, "isoformat"):
        return value.isoformat()
    return str(value)

def main():
    if not SOURCE.exists():
        raise SystemExit(f"Missing source dataset: {SOURCE}")
    table = pq.read_table(SOURCE)
    rows = table.to_pylist()
    records = [{str(k): clean(v) for k, v in row.items()} for row in rows]
    OUT.write_text(json.dumps(records, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    columns = []
    for field in table.schema:
        values = [row.get(field.name) for row in rows if row.get(field.name) is not None]
        sample = next((v for v in values if isinstance(v, (str, int, float, bool))), None)
        columns.append({"name": field.name, "type": str(field.type), "non_null": len(values), "sample": str(sample)[:240] if sample is not None else None})
    META.write_text(json.dumps({
        "dataset": "WafaaFraih/saudi-legal-moj",
        "source": "https://huggingface.co/datasets/WafaaFraih/saudi-legal-moj",
        "source_file": SOURCE.name,
        "records": len(records),
        "columns": columns,
        "generated_at_utc": __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat()
    }, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Extracted {len(records)} records; columns: {', '.join(f.name for f in table.schema)}")
    print(f"JSON size: {OUT.stat().st_size} bytes")

if __name__ == "__main__":
    main()
