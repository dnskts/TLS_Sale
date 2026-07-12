"""Read dashboard data from SQLite with in-memory cache."""

from __future__ import annotations

import json
import sqlite3
from datetime import date, datetime
from functools import lru_cache
from pathlib import Path
from typing import Any

import pandas as pd

from parser.settings_loader import get_settings, project_root


def _db_path() -> Path:
    settings = get_settings()
    relative = settings.get("paths", {}).get("sqlite", "data/dashboard.db")
    return project_root() / relative


def _meta_path() -> Path:
    settings = get_settings()
    relative = settings.get("paths", {}).get("last_load_meta", "data/last_load.json")
    return project_root() / relative


def database_exists() -> bool:
    return _db_path().is_file()


def clear_cache() -> None:
    """Invalidate cached database reads and settings."""
    from parser.settings_loader import clear_settings_cache

    load_table.cache_clear()
    load_last_load_report.cache_clear()
    get_status_info.cache_clear()
    clear_settings_cache()


@lru_cache(maxsize=16)
def load_table(table_name: str) -> pd.DataFrame:
    path = _db_path()
    if not path.is_file():
        return pd.DataFrame()

    with sqlite3.connect(path) as connection:
        try:
            return pd.read_sql_query(f"SELECT * FROM {table_name}", connection)
        except (pd.errors.DatabaseError, sqlite3.OperationalError):
            return pd.DataFrame()


@lru_cache(maxsize=1)
def load_last_load_report() -> dict[str, Any]:
    path = _meta_path()
    if not path.is_file():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


@lru_cache(maxsize=1)
def get_status_info() -> dict[str, Any]:
    report = load_last_load_report()
    counts = report.get("counts", {})
    loaded_at = report.get("loaded_at")
    loaded_display = loaded_at
    if loaded_at:
        try:
            parsed = datetime.fromisoformat(loaded_at.replace("Z", "+00:00"))
            loaded_display = parsed.strftime("%d.%m.%Y %H:%M")
        except ValueError:
            loaded_display = loaded_at

    return {
        "database_exists": database_exists(),
        "loaded_at": loaded_display,
        "rows_1c": counts.get("operations_1c", 0),
        "rows_bitrix": counts.get("deals_bitrix", 0),
        "rows_unified": counts.get("sales_unified", 0),
        "warnings_count": len(report.get("warnings") or []),
    }


def load_sales_unified() -> pd.DataFrame:
    frame = load_table("sales_unified")
    if frame.empty:
        return frame
    if "date" in frame.columns:
        frame["date"] = pd.to_datetime(frame["date"], errors="coerce")
    return frame


def enrich_deals_bitrix(frame: pd.DataFrame) -> pd.DataFrame:
    """Attach agent_key / agent_display / agent_team from settings.json."""
    if frame.empty:
        return frame
    from parser.load_bitrix import resolve_agent_bitrix

    settings = get_settings()
    result = frame.copy()

    def _resolve(raw_name: Any) -> pd.Series:
        resolved = resolve_agent_bitrix(raw_name, settings)
        return pd.Series(
            {
                "agent_key": resolved["agent_key"],
                "agent_display": resolved.get("name_display") or resolved["agent_key"],
                "agent_team": resolved.get("team") or "Без команды",
            }
        )

    if "responsible_person" in result.columns:
        agent_fields = result["responsible_person"].apply(_resolve)
        result[["agent_key", "agent_display", "agent_team"]] = agent_fields
    return result


def load_deals_bitrix() -> pd.DataFrame:
    frame = load_table("deals_bitrix")
    if frame.empty:
        return frame
    for column in ("deal_created_at", "client_paid_at", "date_for_sales", "service_date"):
        if column in frame.columns:
            frame[column] = pd.to_datetime(frame[column], errors="coerce")
    return enrich_deals_bitrix(frame)


def load_agents_dim() -> pd.DataFrame:
    return load_table("agents_dim")


def get_filter_options() -> dict[str, list[str]]:
    """Distinct filter values from loaded data and settings."""
    settings = get_settings()
    options: dict[str, list[str]] = {
        "teams": [],
        "agents_active": [],
        "agents_inactive": [],
        "agents_unknown": [],
        "clients": [],
        "partners": [],
        "categories": [],
        "channels": [],
        "card_types": [],
        "client_types": [],
        "request_types": [],
    }

    for agent in settings.get("agents", []):
        entry = {
            "label": agent.get("name_display", agent.get("agent_key", "")),
            "value": agent.get("agent_key", ""),
        }
        if agent.get("is_active", True):
            options["agents_active"].append(entry)
        else:
            options["agents_inactive"].append(entry)

    options["agents_active"].sort(key=lambda item: item["label"])
    options["agents_inactive"].sort(key=lambda item: item["label"])

    if not database_exists():
        team_values = sorted({agent.get("team") for agent in settings.get("agents", []) if agent.get("team")})
        options["teams"] = [{"label": team, "value": team} for team in team_values]
        return options

    sales = load_sales_unified()
    agents_dim = load_agents_dim()

    if not agents_dim.empty and "is_unknown" in agents_dim.columns:
        unknown = agents_dim[agents_dim["is_unknown"] == 1]
        for _, row in unknown.iterrows():
            options["agents_unknown"].append(
                {
                    "label": f"{row.get('name_display', row.get('agent_key'))} (не в справочнике)",
                    "value": row.get("agent_key"),
                }
            )

    if not sales.empty:
        team_set = set(sales["agent_team"].dropna().astype(str).tolist())
        team_set.update(agent.get("team") for agent in settings.get("agents", []) if agent.get("team"))
        options["teams"] = [{"label": value, "value": value} for value in sorted(team_set)]

        for key, column in [
            ("clients", "client"),
            ("partners", "partner_or_supplier"),
            ("categories", "category"),
            ("channels", "channel"),
            ("card_types", "card_type"),
            ("client_types", "client_type"),
            ("request_types", "request_type"),
        ]:
            if column not in sales.columns:
                continue
            values = sales[column].dropna().astype(str).str.strip()
            values = sorted({value for value in values if value and value.lower() != "nan"})
            options[key] = [{"label": value, "value": value} for value in values[:500]]

    deals = load_deals_bitrix()
    if not deals.empty:
        if "agent_team" in deals.columns:
            team_set = {item["value"] for item in options["teams"]}
            team_set.update(deals["agent_team"].dropna().astype(str).tolist())
            options["teams"] = [{"label": value, "value": value} for value in sorted(team_set)]

        for key, column in [
            ("categories", "category"),
            ("channels", "channel"),
            ("request_types", "request_type"),
        ]:
            if column not in deals.columns:
                continue
            existing = {item["value"] for item in options.get(key, [])}
            values = deals[column].dropna().astype(str).str.strip()
            values = sorted({value for value in values if value and value.lower() != "nan" and value not in existing})
            options[key] = (options.get(key) or []) + [{"label": value, "value": value} for value in values[:500]]
            options[key].sort(key=lambda item: item["label"])

    return options


def get_filtered_sales(filters: dict[str, Any] | None) -> pd.DataFrame:
    from app.filters_logic import apply_sales_filters

    return apply_sales_filters(load_sales_unified(), filters)


def get_filtered_deals_bitrix(filters: dict[str, Any] | None) -> pd.DataFrame:
    from app.filters_logic import apply_deals_bitrix_filters

    return apply_deals_bitrix_filters(load_deals_bitrix(), filters)


def summarize_funnel_payment_stats(frame: pd.DataFrame, filters: dict[str, Any] | None) -> dict[str, Any]:
    """Stats for deals filtered by deal_created_at plus paid-date breakdown."""
    filters = filters or {}
    total_created = int(len(frame))
    with_paid_date = int(frame["client_paid_at"].notna().sum()) if "client_paid_at" in frame.columns else 0
    without_paid_date = total_created - with_paid_date

    paid_in_period = 0
    date_from = filters.get("date_from")
    date_to = filters.get("date_to")
    if "client_paid_at" in frame.columns and not frame.empty:
        paid_mask = frame["client_paid_at"].notna()
        paid_frame = frame[paid_mask]
        if date_from:
            paid_frame = paid_frame[paid_frame["client_paid_at"] >= pd.to_datetime(date_from)]
        if date_to:
            paid_frame = paid_frame[paid_frame["client_paid_at"] <= pd.to_datetime(date_to)]
        paid_in_period = int(len(paid_frame))

    success = 0
    if "deal_result" in frame.columns and not frame.empty:
        success = int((frame["deal_result"].astype(str).str.strip() == "Успех").sum())

    conversion = success / total_created * 100 if total_created else None
    return {
        "total_created": total_created,
        "with_paid_date": with_paid_date,
        "without_paid_date": without_paid_date,
        "paid_in_period": paid_in_period,
        "success_count": success,
        "conversion_pct": conversion,
    }


def get_load_diagnostics() -> dict[str, Any]:
    """Warnings and unknown agents from last_load.json."""
    report = load_last_load_report()
    return {
        "warnings": report.get("warnings") or [],
        "top_unknown_agents": report.get("top_unknown_agents") or [],
        "loaded_at": report.get("loaded_at"),
        "counts": report.get("counts") or {},
    }


def default_date_range() -> tuple[str, str]:
    today = date.today()
    start = today.replace(day=1)
    return start.isoformat(), today.isoformat()
