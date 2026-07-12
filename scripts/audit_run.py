"""One-off audit dynamic checks."""
import json
import sqlite3
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

s = json.loads((ROOT / "settings.json").read_text(encoding="utf-8"))
ags = s.get("agents", [])
print("=== SETTINGS ===")
print("top_keys", sorted(s.keys()))
print("port", s["app"]["port"], "host", s["app"]["host"])
print("agents", len(ags))
print("active", sum(1 for a in ags if a.get("is_active")))
print("inactive", sum(1 for a in ags if not a.get("is_active")))
c = Counter(a.get("agent_key") for a in ags)
print("dup_keys", [k for k, v in c.items() if v > 1])
m = {}
conflicts = []
for a in ags:
    for src, names in [("1c", a.get("names_1c") or []), ("bitrix", a.get("names_bitrix") or [])]:
        for n in names:
            n = (n or "").strip()
            if not n:
                continue
            key = (src, n)
            if key in m and m[key] != a.get("agent_key"):
                conflicts.append((src, n, m[key], a.get("agent_key")))
            else:
                m[key] = a.get("agent_key")
print("name_conflicts", len(conflicts))
print("sample_conflicts", conflicts[:5])
for key in ["isaykina_olga", "makarova_tatyana", "bagina_maria"]:
    a = next((x for x in ags if x.get("agent_key") == key), None)
    print(key, "FOUND" if a else "MISSING", (a or {}).get("names_1c"), (a or {}).get("names_bitrix"))

db = ROOT / "data" / "dashboard.db"
if db.is_file():
    conn = sqlite3.connect(db)
    cur = conn.cursor()
    print("=== DB ===")
    tables = [r[0] for r in cur.execute("SELECT name FROM sqlite_master WHERE type='table'")]
    print("tables", tables)
    for t in ["operations_1c", "deals_bitrix", "sales_unified", "agents_dim"]:
        print(t, cur.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0])
    print("source counts", cur.execute("SELECT source, COUNT(*) FROM sales_unified GROUP BY source").fetchall())
    print("date range", cur.execute("SELECT MIN(date), MAX(date) FROM sales_unified").fetchone())
    print("sums", cur.execute("SELECT SUM(sales_amount), SUM(profit_ex_vat) FROM sales_unified").fetchone())
    print("bitrix unified", cur.execute("SELECT COUNT(*) FROM sales_unified WHERE source='bitrix'").fetchone()[0])
    print("deals total", cur.execute("SELECT COUNT(*) FROM deals_bitrix").fetchone()[0])
    print("unknown top", cur.execute(
        "SELECT agent_key, COUNT(*) c FROM sales_unified WHERE agent_key LIKE 'unknown:%' "
        "GROUP BY agent_key ORDER BY c DESC LIMIT 10"
    ).fetchall())
    print("refunds 1c", cur.execute(
        "SELECT COUNT(*), SUM(sales_amount) FROM sales_unified WHERE source='1c' AND sales_amount < 0"
    ).fetchone())
    print("date_fallback bitrix", cur.execute(
        "SELECT SUM(CASE WHEN date_fallback_used=1 THEN 1 ELSE 0 END), COUNT(*) "
        "FROM sales_unified WHERE source='bitrix'"
    ).fetchone())
    conn.close()
else:
    print("DB missing")

from app.main import create_app
create_app()
print("import app OK")
