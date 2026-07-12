"""Manual acceptance checklist runner."""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.data_access import (  # noqa: E402
    clear_cache,
    get_filtered_deals_bitrix,
    get_filtered_sales,
    get_load_diagnostics,
    summarize_funnel_payment_stats,
)
from app.metrics import summarize_sales  # noqa: E402
from parser.settings_loader import get_settings  # noqa: E402


def main() -> int:
    clear_cache()
    filters = {
        "date_from": "2020-01-01",
        "date_to": "2030-12-31",
        "source": "all",
        "teams": [],
        "agents": [],
        "show_inactive_agents": False,
        "show_unknown_agents": False,
    }

    sales = get_filtered_sales(filters)
    summary = summarize_sales(sales)
    print("KPI filtered (active agents):", summary["row_count"], "rows")
    assert summary["count_1c"] + summary["count_bitrix"] == summary["row_count"]

    db = ROOT / "data" / "dashboard.db"
    with sqlite3.connect(db) as conn:
        total_unified = conn.execute("SELECT COUNT(*) FROM sales_unified").fetchone()[0]
        total_1c = conn.execute("SELECT COUNT(*) FROM sales_unified WHERE source='1c'").fetchone()[0]
        total_bitrix = conn.execute("SELECT COUNT(*) FROM sales_unified WHERE source='bitrix'").fetchone()[0]
    assert total_unified == total_1c + total_bitrix == 7206 + 1166

    deals = get_filtered_deals_bitrix(filters)
    print("Funnel deals (active agents):", len(deals))
    assert len(deals) < 1891

    stats = summarize_funnel_payment_stats(deals, filters)
    print("Conversion:", stats["conversion_pct"])

    diag = get_load_diagnostics()
    assert len(diag["top_unknown_agents"]) >= 9
    print("Unknown agents in last_load.json:", len(diag["top_unknown_agents"]))

    settings = get_settings()
    inactive = [a["agent_key"] for a in settings["agents"] if not a.get("is_active", True)]
    if inactive:
        with_inactive = get_filtered_sales({**filters, "show_inactive_agents": True})
        assert len(with_inactive) >= len(sales)

    print("ALL CHECKS OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
