"""Load xlsx exports, build unified mart, persist to SQLite."""

from __future__ import annotations

import json
import sqlite3
import sys
import warnings
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import pandas as pd

from parser.build_unified import build_agents_dim, build_sales_unified
from parser.load_1c import load_1c
from parser.load_bitrix import load_bitrix
from parser.settings_loader import get_settings, project_root


def _input_path(settings: dict[str, Any], file_key: str) -> Path:
    paths = settings.get("paths", {})
    input_dir = paths.get("input_dir", "input")
    filename = paths.get(file_key)
    if not filename:
        raise ValueError(f"В settings.json не задан paths.{file_key}")
    return project_root() / input_dir / filename


def _sqlite_path(settings: dict[str, Any]) -> Path:
    paths = settings.get("paths", {})
    db_path = paths.get("sqlite", "data/dashboard.db")
    return project_root() / db_path


def _last_load_path(settings: dict[str, Any]) -> Path:
    paths = settings.get("paths", {})
    meta_path = paths.get("last_load_meta", "data/last_load.json")
    return project_root() / meta_path


def _file_mtime_info(path: Path) -> dict[str, Any]:
    stat = path.stat()
    return {
        "path": str(path),
        "mtime": datetime.fromtimestamp(stat.st_mtime, tz=timezone.utc).isoformat(),
        "size_bytes": stat.st_size,
    }


def _serialize_frame(frame: pd.DataFrame) -> pd.DataFrame:
    """Convert datetimes and nullable booleans for SQLite export."""
    export = frame.copy()
    for column in export.columns:
        if pd.api.types.is_datetime64_any_dtype(export[column]):
            export[column] = export[column].dt.strftime("%Y-%m-%d %H:%M:%S")
        elif export[column].dtype == "object":
            export[column] = export[column].apply(
                lambda value: None if value is pd.NA or (isinstance(value, float) and pd.isna(value)) else value
            )
    return export


def _write_sqlite(
    db_path: Path,
    operations_1c: pd.DataFrame,
    deals_bitrix: pd.DataFrame,
    sales_unified: pd.DataFrame,
    agents_dim: pd.DataFrame,
    load_meta: dict[str, Any],
) -> None:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    if db_path.exists():
        db_path.unlink()

    with sqlite3.connect(db_path) as connection:
        _serialize_frame(operations_1c).to_sql("operations_1c", connection, index=False)
        _serialize_frame(deals_bitrix).to_sql("deals_bitrix", connection, index=False)
        _serialize_frame(sales_unified).to_sql("sales_unified", connection, index=False)
        _serialize_frame(agents_dim).to_sql("agents_dim", connection, index=False)

        meta_frame = pd.DataFrame([load_meta])
        _serialize_frame(meta_frame).to_sql("load_meta", connection, index=False)


def _top_unknown_agents(sales_unified: pd.DataFrame, limit: int = 10) -> list[dict[str, Any]]:
    if sales_unified.empty:
        return []
    mask = sales_unified["agent_key"].astype(str).str.startswith("unknown:")
    unknown = sales_unified.loc[mask]
    if unknown.empty:
        return []

    grouped = (
        unknown.groupby(["agent_key", "agent_display", "source"], dropna=False)
        .size()
        .reset_index(name="count")
        .sort_values("count", ascending=False)
    )
    result: list[dict[str, Any]] = []
    for _, row in grouped.head(limit).iterrows():
        result.append(
            {
                "agent_key": row["agent_key"],
                "agent_display": row["agent_display"],
                "source": row["source"],
                "count": int(row["count"]),
            }
        )
    return result


def _date_fallback_warning(sales_unified: pd.DataFrame) -> str | None:
    """Return Russian warning if >30% of Bitrix success rows used deal_created_at fallback."""
    if sales_unified.empty or "source" not in sales_unified.columns:
        return None
    bitrix = sales_unified[sales_unified["source"] == "bitrix"]
    if bitrix.empty:
        return None
    total = len(bitrix)
    if "date_fallback_used" not in bitrix.columns:
        return None
    fallback_count = int(bitrix["date_fallback_used"].fillna(False).astype(bool).sum())
    fallback_pct = fallback_count / total * 100 if total else 0.0
    if fallback_pct <= 30:
        return None
    return (
        f"Внимание: у {fallback_pct:.1f}% успешных сделок Битрикс отсутствует дата оплаты "
        f"(client_paid_at). Использована дата создания сделки."
    )


def run_pipeline(settings: dict[str, Any] | None = None) -> dict[str, Any]:
    """Execute full ETL pipeline and return summary metadata."""
    from parser.settings_loader import clear_settings_cache

    clear_settings_cache()
    settings = settings or get_settings()
    path_1c = _input_path(settings, "file_1c")
    path_bitrix = _input_path(settings, "file_bitrix")

    missing: list[str] = []
    if not path_1c.is_file():
        missing.append(str(path_1c))
    if not path_bitrix.is_file():
        missing.append(str(path_bitrix))
    if missing:
        raise FileNotFoundError(
            "Не найдены входные файлы:\n" + "\n".join(f"  - {item}" for item in missing)
        )

    sheet_1c = settings.get("sheets", {}).get("1c", "TDSheet")
    sheet_bitrix = settings.get("sheets", {}).get("bitrix", "Битрикс")

    captured_warnings: list[str] = []
    with warnings.catch_warnings(record=True) as caught:
        warnings.simplefilter("always")
        operations_1c = load_1c(path_1c, sheet_1c)
        deals_bitrix = load_bitrix(path_bitrix, sheet_bitrix)
        for item in caught:
            captured_warnings.append(str(item.message))

    sales_unified = build_sales_unified(operations_1c, deals_bitrix, settings)
    agents_dim = build_agents_dim(settings, sales_unified)

    fallback_warning = _date_fallback_warning(sales_unified)
    if fallback_warning:
        captured_warnings.append(fallback_warning)

    loaded_at = datetime.now(tz=timezone.utc).isoformat()
    files_mtime = {
        "1c": _file_mtime_info(path_1c),
        "bitrix": _file_mtime_info(path_bitrix),
    }

    load_meta = {
        "loaded_at": loaded_at,
        "rows_1c": int(len(operations_1c)),
        "rows_bitrix": int(len(deals_bitrix)),
        "rows_unified": int(len(sales_unified)),
        "rows_unified_1c": int((sales_unified["source"] == "1c").sum()) if not sales_unified.empty else 0,
        "rows_unified_bitrix": int((sales_unified["source"] == "bitrix").sum()) if not sales_unified.empty else 0,
        "files_mtime_json": json.dumps(files_mtime, ensure_ascii=False),
        "warnings_json": json.dumps(captured_warnings, ensure_ascii=False),
    }

    db_path = _sqlite_path(settings)
    _write_sqlite(db_path, operations_1c, deals_bitrix, sales_unified, agents_dim, load_meta)

    top_unknown = _top_unknown_agents(sales_unified)
    report = {
        "loaded_at": loaded_at,
        "database": str(db_path),
        "counts": {
            "operations_1c": load_meta["rows_1c"],
            "deals_bitrix": load_meta["rows_bitrix"],
            "sales_unified": load_meta["rows_unified"],
            "sales_unified_1c": load_meta["rows_unified_1c"],
            "sales_unified_bitrix": load_meta["rows_unified_bitrix"],
            "agents_dim": int(len(agents_dim)),
        },
        "files": files_mtime,
        "top_unknown_agents": top_unknown,
        "warnings": captured_warnings,
    }

    meta_path = _last_load_path(settings)
    meta_path.parent.mkdir(parents=True, exist_ok=True)
    meta_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    return report


def print_summary(report: dict[str, Any]) -> None:
    counts = report.get("counts", {})
    print("=== TLS Sale — загрузка завершена ===")
    print(f"Время:     {report.get('loaded_at')}")
    print(f"База:      {report.get('database')}")
    print()
    print("Строки:")
    print(f"  operations_1c:        {counts.get('operations_1c', 0)}")
    print(f"  deals_bitrix:         {counts.get('deals_bitrix', 0)}")
    print(f"  sales_unified:        {counts.get('sales_unified', 0)}")
    print(f"    из 1С:              {counts.get('sales_unified_1c', 0)}")
    print(f"    из Битрикс (Успех): {counts.get('sales_unified_bitrix', 0)}")
    print(f"  agents_dim:           {counts.get('agents_dim', 0)}")
    print()

    unknown = report.get("top_unknown_agents") or []
    if unknown:
        print("Топ неизвестных агентов (не в settings.json):")
        for item in unknown:
            print(
                f"  {item['agent_display']} [{item['source']}]: "
                f"{item['count']} ({item['agent_key']})"
            )
        print()

    warnings_list = report.get("warnings") or []
    if warnings_list:
        print(f"Предупреждения ({len(warnings_list)}):")
        for message in warnings_list[:10]:
            print(f"  - {message}")
        if len(warnings_list) > 10:
            print(f"  ... и ещё {len(warnings_list) - 10}")
        print()

    print(f"Отчёт: data/last_load.json")


def main() -> int:
    try:
        report = run_pipeline()
    except FileNotFoundError as error:
        print(str(error), file=sys.stderr)
        print("Поместите выгрузки 1C.xlsx и Битрикс.xlsx в каталог input/.", file=sys.stderr)
        return 1
    except Exception as error:
        print(f"Ошибка загрузки: {error}", file=sys.stderr)
        return 1

    print_summary(report)
    return 0


if __name__ == "__main__":
    sys.exit(main())
