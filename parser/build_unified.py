"""Build unified sales mart from 1C operations and Bitrix deals."""

from __future__ import annotations

from datetime import date, datetime
from typing import Any

import pandas as pd

from parser.load_1c import resolve_agent
from parser.load_bitrix import resolve_agent_bitrix

UNIFIED_COLUMNS = [
    "source",
    "date",
    "agent_key",
    "agent_display",
    "agent_team",
    "agent_is_active",
    "agent_raw",
    "client",
    "client_id",
    "partner_or_supplier",
    "category",
    "channel",
    "card_type",
    "client_type",
    "request_type",
    "sales_amount",
    "profit_ex_vat",
    "service_fee",
    "is_refund",
    "raw_id",
    "date_fallback_used",
]


def _is_na(value: Any) -> bool:
    return value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value))


def _as_str(value: Any) -> str | None:
    if _is_na(value):
        return None
    text = str(value).replace("\xa0", " ").strip()
    return text if text else None


def _as_date(value: Any) -> date | None:
    if _is_na(value):
        return None
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return None


def _as_float(value: Any) -> float | None:
    if _is_na(value):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _team_from_department(department: Any, department_map: dict[str, str]) -> str:
    if _is_na(department):
        return "Без команды"
    dept = str(department).replace("\xa0", " ").strip()
    if not dept:
        return "Без команды"
    for key, display in department_map.items():
        if display == dept:
            return key
    if dept in department_map:
        return dept
    return dept


def _pick_category(related_service_type: Any, category: Any) -> str | None:
    for value in (related_service_type, category):
        text = _as_str(value)
        if text:
            return text
    return None


def _apply_agent_fields(
    row: dict[str, Any],
    resolved: dict[str, Any],
    *,
    department: Any = None,
    department_map: dict[str, str] | None = None,
) -> None:
    row["agent_key"] = resolved["agent_key"]
    row["agent_display"] = resolved["name_display"] or resolved.get("agent_key")
    row["agent_is_active"] = resolved.get("is_active")

    team = resolved.get("team")
    if str(resolved["agent_key"]).startswith("unknown:") and department_map is not None:
        team = _team_from_department(department, department_map)
    row["agent_team"] = team if team else "Без команды"


def build_sales_unified(
    operations_1c: pd.DataFrame,
    deals_bitrix: pd.DataFrame,
    settings: dict[str, Any],
) -> pd.DataFrame:
    """Combine 1C (all rows) and Bitrix (success deals only) into sales_unified."""
    metrics = settings.get("metrics", {})
    success_value = metrics.get("bitrix_success_value", "Успех")
    department_map = settings.get("department_map", {})

    rows: list[dict[str, Any]] = []

    for _, record in operations_1c.iterrows():
        sales_amount = _as_float(record.get("sales_amount"))
        resolved = resolve_agent(
            record.get("agent"),
            settings,
            department=record.get("department"),
        )
        unified: dict[str, Any] = {
            "source": "1c",
            "date": _as_date(record.get("date_operation")),
            "agent_raw": _as_str(record.get("agent")),
            "client": _as_str(record.get("client")),
            "client_id": _as_str(record.get("id_crm")),
            "partner_or_supplier": _as_str(record.get("supplier")),
            "category": _pick_category(
                record.get("related_service_type"),
                record.get("category"),
            ),
            "channel": _as_str(record.get("channel")),
            "card_type": _as_str(record.get("card_type")),
            "client_type": None,
            "request_type": None,
            "sales_amount": sales_amount,
            "profit_ex_vat": _as_float(record.get("profit_ex_vat")),
            "service_fee": _as_float(record.get("service_fee")),
            "is_refund": bool(sales_amount is not None and sales_amount < 0),
            "raw_id": _as_str(record.get("order_no")),
            "date_fallback_used": None,
        }
        _apply_agent_fields(
            unified,
            resolved,
            department=record.get("department"),
            department_map=department_map,
        )
        rows.append(unified)

    bitrix_sales = deals_bitrix
    if "deal_result" in deals_bitrix.columns:
        bitrix_sales = deals_bitrix[deals_bitrix["deal_result"] == success_value]

    for _, record in bitrix_sales.iterrows():
        sales_amount = _as_float(record.get("sales_amount"))
        resolved = resolve_agent_bitrix(record.get("responsible_person"), settings)
        deal_no = record.get("deal_no")
        raw_id = None if _is_na(deal_no) else str(int(deal_no)) if float(deal_no).is_integer() else str(deal_no)

        unified = {
            "source": "bitrix",
            "date": _as_date(record.get("date_for_sales")),
            "agent_raw": _as_str(record.get("responsible_person")),
            "client": _as_str(record.get("client")),
            "client_id": _as_str(record.get("id_client")),
            "partner_or_supplier": _as_str(record.get("partner")),
            "category": _as_str(record.get("category")),
            "channel": _as_str(record.get("channel")),
            "card_type": _as_str(record.get("card_type")),
            "client_type": _as_str(record.get("client_type")),
            "request_type": _as_str(record.get("request_type")),
            "sales_amount": sales_amount,
            "profit_ex_vat": _as_float(record.get("profit_ex_vat")),
            "service_fee": _as_float(record.get("service_fee")),
            "is_refund": False,
            "raw_id": raw_id,
            "date_fallback_used": bool(record.get("date_fallback_used"))
            if not _is_na(record.get("date_fallback_used"))
            else None,
        }
        _apply_agent_fields(unified, resolved)
        rows.append(unified)

    if not rows:
        return pd.DataFrame(columns=UNIFIED_COLUMNS)

    frame = pd.DataFrame(rows)
    for column in UNIFIED_COLUMNS:
        if column not in frame.columns:
            frame[column] = None
    return frame[UNIFIED_COLUMNS]


def build_agents_dim(
    settings: dict[str, Any],
    sales_unified: pd.DataFrame,
) -> pd.DataFrame:
    """Settings agents plus unknown agent statistics from unified sales."""
    rows: list[dict[str, Any]] = []

    for agent in settings.get("agents", []):
        rows.append(
            {
                "agent_key": agent.get("agent_key"),
                "name_display": agent.get("name_display"),
                "names_1c": "|".join(agent.get("names_1c", [])),
                "names_bitrix": "|".join(agent.get("names_bitrix", [])),
                "team": agent.get("team"),
                "is_active": agent.get("is_active"),
                "is_unknown": False,
                "count_1c": 0,
                "count_bitrix": 0,
                "count_total": 0,
            }
        )

    known_keys = {row["agent_key"] for row in rows}
    if not sales_unified.empty and "agent_key" in sales_unified.columns:
        unknown = sales_unified[sales_unified["agent_key"].astype(str).str.startswith("unknown:")]
        if not unknown.empty:
            grouped = (
                unknown.groupby(["agent_key", "agent_display", "agent_team", "source"], dropna=False)
                .size()
                .reset_index(name="count")
            )
            stats: dict[str, dict[str, Any]] = {}
            for _, item in grouped.iterrows():
                key = item["agent_key"]
                stats.setdefault(
                    key,
                    {
                        "agent_key": key,
                        "name_display": item["agent_display"],
                        "names_1c": "",
                        "names_bitrix": "",
                        "team": item["agent_team"],
                        "is_active": None,
                        "is_unknown": True,
                        "count_1c": 0,
                        "count_bitrix": 0,
                        "count_total": 0,
                    },
                )
                if item["source"] == "1c":
                    stats[key]["count_1c"] += int(item["count"])
                elif item["source"] == "bitrix":
                    stats[key]["count_bitrix"] += int(item["count"])
                stats[key]["count_total"] += int(item["count"])

            for key, item in stats.items():
                if key not in known_keys:
                    rows.append(item)

    return pd.DataFrame(rows)
