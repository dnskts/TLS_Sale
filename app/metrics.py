"""Metric helpers and Russian currency formatting."""

from __future__ import annotations

from typing import Any

import pandas as pd


def format_rub(value: Any) -> str:
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return "—"
    try:
        number = float(value)
    except (TypeError, ValueError):
        return "—"
    if number != number:
        return "—"
    negative = number < 0
    number = abs(number)
    if float(number).is_integer():
        body = f"{int(round(number)):,}".replace(",", " ")
    else:
        body = f"{number:,.2f}".replace(",", " ").replace(".", ",")
    prefix = "−" if negative else ""
    return f"{prefix}{body} ₽"


def format_margin(sales: Any, profit: Any) -> str:
    if sales is None or profit is None:
        return "—"
    try:
        sales_f = float(sales)
        profit_f = float(profit)
    except (TypeError, ValueError):
        return "—"
    if sales_f <= 0:
        return "—"
    return f"{profit_f / sales_f * 100:.1f} %".replace(".", ",")


def format_count(value: Any) -> str:
    if value is None:
        return "—"
    try:
        return f"{int(value):,}".replace(",", " ")
    except (TypeError, ValueError):
        return "—"


def summarize_sales(frame: pd.DataFrame) -> dict[str, Any]:
    if frame.empty:
        return {
            "sales_total": 0.0,
            "profit_total": 0.0,
            "margin_pct": None,
            "row_count": 0,
            "sales_1c": 0.0,
            "sales_bitrix": 0.0,
            "share_1c_pct": None,
            "share_bitrix_pct": None,
            "count_1c": 0,
            "count_bitrix": 0,
            "refund_sum_1c": 0.0,
            "refund_count_1c": 0,
        }

    sales_total = float(frame["sales_amount"].fillna(0).sum())
    profit_total = float(frame["profit_ex_vat"].fillna(0).sum())
    row_count = int(len(frame))

    one_c = frame[frame["source"] == "1c"]
    bitrix = frame[frame["source"] == "bitrix"]
    sales_1c = float(one_c["sales_amount"].fillna(0).sum())
    sales_bitrix = float(bitrix["sales_amount"].fillna(0).sum())

    refunds = one_c[one_c["sales_amount"].fillna(0) < 0]

    share_1c = sales_1c / sales_total * 100 if sales_total else None
    share_bitrix = sales_bitrix / sales_total * 100 if sales_total else None

    return {
        "sales_total": sales_total,
        "profit_total": profit_total,
        "margin_pct": profit_total / sales_total * 100 if sales_total else None,
        "row_count": row_count,
        "sales_1c": sales_1c,
        "sales_bitrix": sales_bitrix,
        "share_1c_pct": share_1c,
        "share_bitrix_pct": share_bitrix,
        "count_1c": int(len(one_c)),
        "count_bitrix": int(len(bitrix)),
        "refund_sum_1c": float(refunds["sales_amount"].fillna(0).sum()),
        "refund_count_1c": int(len(refunds)),
    }


def add_margin_column(frame: pd.DataFrame, sales_col: str = "sales_amount", profit_col: str = "profit_ex_vat") -> pd.DataFrame:
    result = frame.copy()
    sales = result[sales_col].fillna(0)
    profit = result[profit_col].fillna(0)
    result["margin_pct"] = profit / sales.replace(0, pd.NA) * 100
    return result
