#!/usr/bin/env python3
"""Check that PHP ArabicNormalizer and SQL normalize_ar() give identical results,
and that both match the expected output. Usage: python3 tests/normalizer_parity.py <db>"""
import json
import os
import subprocess
import sys
from pathlib import Path

def psql_cmd(db):
    """psql over TCP when PGHOST is set (CI), else as the local postgres user."""
    if os.environ.get("PGHOST"):
        return ["psql", "-v", "ON_ERROR_STOP=1", "-d", db, "-At"]
    return ["su", "postgres", "-c", f"psql -v ON_ERROR_STOP=1 -d {db} -At"]


DB = sys.argv[1]
ROOT = Path(__file__).resolve().parent.parent

# Shared with the backend Pest suite (tests/Feature/Schema/SearchNormalizationTest.php).
CASES = [(c["input"], c["expected"]) for c in json.loads((ROOT / "tests/normalizer_cases.json").read_text(encoding="utf-8"))]

php = f"""
require '{ROOT.parent}/backend/app/Support/ArabicNormalizer.php';
$in = json_decode(stream_get_contents(STDIN), true);
echo json_encode(array_map(fn($s) => \\App\\Support\\ArabicNormalizer::normalize($s), $in), JSON_UNESCAPED_UNICODE);
"""
inputs = [c[0] for c in CASES]
php_out = json.loads(subprocess.run(["php", "-r", php], input=json.dumps(inputs, ensure_ascii=False),
                                    capture_output=True, text=True, check=True).stdout)

sql = "SELECT json_agg(normalize_ar(x) ORDER BY o) FROM json_array_elements_text(%s::json) WITH ORDINALITY t(x, o);"
literal = "'" + json.dumps(inputs, ensure_ascii=False).replace("'", "''") + "'"
sql_out = json.loads(subprocess.run(psql_cmd(DB),
                                    input=sql % literal, capture_output=True, text=True, check=True).stdout)

failed = 0
for (src, expected), p, s in zip(CASES, php_out, sql_out):
    ok = p == s == expected
    failed += not ok
    print(f"{'PASS' if ok else 'FAIL'}  {src!r:<34} php={p!r} sql={s!r}" + ("" if ok else f" expected={expected!r}"))
print(f"\n{len(CASES) - failed}/{len(CASES)} identical and correct")
sys.exit(1 if failed else 0)
