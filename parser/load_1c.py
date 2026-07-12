"""Parse 1C sales export (xlsx) into a normalized DataFrame."""

from __future__ import annotations

import re
from datetime import date, datetime
from typing import Any

import pandas as pd

# Column order is fixed by position (0-based); header row in xlsx is ignored for naming.
ONE_C_COLUMNS: list[str] = [
    "date_operation",
    "datetime_operation",
    "agent",
    "issuing_agent",
    "supplier",
    "card_type",
    "case_raw",
    "channel",
    "category",
    "id_crm",
    "case_status_change_date",
    "client_from_case",
    "id_client_from_case",
    "related_company",
    "case_cost_codes",
    "client",
    "service_scheme",
    "order_raw",
    "department",
    "related_service_type",
    "product",
    "payment_date",
    "realization_date",
    "sales_amount",
    "profit",
    "profit_ex_vat",
    "supplier_commission",
    "vat_commission",
    "markup",
    "vat_markup",
    "service_fee",
    "vat_fee",
    "sr",
    "lr",
    "solid_bank_privilege",
    "rs_cashback_points",
    "points_ax",
    "points_imp",
    "cashless",
    "against_salary",
    "certificate",
    "loss_company",
    "loss_employee",
    "travelers",
]

STRING_COLUMNS = {
    "agent",
    "issuing_agent",
    "supplier",
    "card_type",
    "case_raw",
    "channel",
    "category",
    "id_crm",
    "case_status_change_date",
    "client_from_case",
    "id_client_from_case",
    "related_company",
    "case_cost_codes",
    "client",
    "service_scheme",
    "order_raw",
    "department",
    "related_service_type",
    "product",
    "payment_date",
    "realization_date",
    "certificate",
    "travelers",
}

NUMERIC_COLUMNS = {
    "sales_amount",
    "profit",
    "profit_ex_vat",
    "supplier_commission",
    "vat_commission",
    "markup",
    "vat_markup",
    "service_fee",
    "vat_fee",
    "sr",
    "lr",
    "solid_bank_privilege",
    "rs_cashback_points",
    "points_ax",
    "points_imp",
    "cashless",
    "against_salary",
    "loss_company",
    "loss_employee",
}

ID_STRING_COLUMNS = {"id_crm", "id_client_from_case"}

DATE_ONLY_COLUMNS = {"date_operation"}
DATETIME_COLUMNS = {"datetime_operation"}

CASE_ID_PATTERN = re.compile(r"000002(\d+)")
ORDER_NO_PATTERN = re.compile(r"(0000-\d+)")

DATE_FORMATS = ("%d.%m.%Y", "%d.%m.%Y %H:%M:%S")


def _trim_string(value: Any) -> Any:
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    if isinstance(value, str):
        cleaned = value.replace("\xa0", " ").strip()
        return cleaned if cleaned else pd.NA
    if isinstance(value, datetime):
        return value
    if isinstance(value, date):
        return value
    cleaned = str(value).replace("\xa0", " ").strip()
    return cleaned if cleaned else pd.NA


def _parse_date_value(value: Any) -> Any:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    text = str(value).replace("\xa0", " ").strip()
    if not text:
        return pd.NA
    for fmt in DATE_FORMATS:
        try:
            parsed = datetime.strptime(text, fmt)
            return parsed.date()
        except ValueError:
            continue
    return pd.NA


def _parse_datetime_value(value: Any) -> Any:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    if isinstance(value, datetime):
        return value
    if isinstance(value, date):
        return datetime(value.year, value.month, value.day)
    text = str(value).replace("\xa0", " ").strip()
    if not text:
        return pd.NA
    for fmt in reversed(DATE_FORMATS):
        try:
            return datetime.strptime(text, fmt)
        except ValueError:
            continue
    return pd.NA


def _extract_case_id(value: Any) -> Any:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    match = CASE_ID_PATTERN.search(str(value))
    return match.group(1) if match else pd.NA


def _extract_order_no(value: Any) -> Any:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    match = ORDER_NO_PATTERN.search(str(value))
    return match.group(1) if match else pd.NA


def _build_agent_index(settings: dict[str, Any]) -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for agent in settings.get("agents", []):
        record = {
            "agent_key": agent["agent_key"],
            "name_display": agent["name_display"],
            "team": agent.get("team"),
            "is_active": agent.get("is_active"),
        }
        for name in agent.get("names_1c", []):
            key = str(name).strip()
            if key:
                index[key] = record
    return index


def resolve_agent(
    raw_name: Any,
    settings: dict[str, Any],
    department: Any = None,
) -> dict[str, Any]:
    """
    Map a raw 1C agent name to settings.json agent record.

    Exact match (after trim) against names_1c only — no heuristics.
    """
    if raw_name is None or (isinstance(raw_name, float) and pd.isna(raw_name)):
        raw = ""
    else:
        raw = str(raw_name).replace("\xa0", " ").strip()

    if not raw:
        team = _department_team(department)
        return {
            "agent_key": "unknown:",
            "name_display": raw,
            "team": team,
            "is_active": None,
        }

    index = _build_agent_index(settings)
    if raw in index:
        return dict(index[raw])

    team = _department_team(department)
    return {
        "agent_key": f"unknown:{raw}",
        "name_display": raw,
        "team": team,
        "is_active": None,
    }


def _department_team(department: Any) -> str:
    if department is None or (isinstance(department, float) and pd.isna(department)):
        return "Без команды"
    text = str(department).replace("\xa0", " ").strip()
    return text if text else "Без команды"


def load_1c(path: str | pd.PathLike[str], sheet_name: str) -> pd.DataFrame:
    """
    Load 1C xlsx export into a normalized DataFrame.

    Header names in the file are ignored; columns are assigned by position.
    Rows are not deduplicated. Negative sales_amount values are kept.
    """
    raw = pd.read_excel(path, sheet_name=sheet_name, header=0, engine="openpyxl")
    if raw.empty:
        empty = pd.DataFrame(columns=[*ONE_C_COLUMNS, "case_id", "order_no", "source"])
        return empty

    frame = raw.iloc[:, : len(ONE_C_COLUMNS)].copy()
    frame.columns = ONE_C_COLUMNS

    for column in STRING_COLUMNS:
        frame[column] = frame[column].map(_trim_string)

    for column in ID_STRING_COLUMNS:
        frame[column] = frame[column].map(
            lambda value: pd.NA
            if value is pd.NA or value is None
            else str(value).replace("\xa0", " ").strip()
        )
        frame[column] = frame[column].replace("", pd.NA)

    for column in NUMERIC_COLUMNS:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")

    frame["date_operation"] = frame["date_operation"].map(_parse_date_value)
    frame["datetime_operation"] = frame["datetime_operation"].map(_parse_datetime_value)

    for column in ("case_status_change_date", "payment_date", "realization_date"):
        frame[column] = frame[column].map(_parse_date_value)

    frame["case_id"] = frame["case_raw"].map(_extract_case_id)
    frame["order_no"] = frame["order_raw"].map(_extract_order_no)
    frame["source"] = "1c"

    return frame
