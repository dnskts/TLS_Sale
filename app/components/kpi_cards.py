"""KPI cards for dashboard header."""

from __future__ import annotations

from dash import html


def kpi_card(title: str, card_id: str, subtitle: str = "—") -> html.Div:
    return html.Div(
        className="kpi-card",
        children=[
            html.Div(title, className="kpi-title"),
            html.Div("—", className="kpi-value", id=card_id),
            html.Div(subtitle, className="kpi-sub"),
        ],
    )


def overview_kpi_placeholders() -> html.Div:
    return html.Div(
        className="kpi-container",
        children=[
            kpi_card("Продажи", "kpi-sales-total", "1С + Битрикс"),
            kpi_card("Прибыль без НДС", "kpi-profit-total", "profit_ex_vat"),
            kpi_card("Маржа", "kpi-margin-total", "прибыль / продажи"),
            kpi_card("Строк", "kpi-row-count", "sales_unified"),
            kpi_card("Доля 1С / Битрикс", "kpi-source-share", "по сумме продаж"),
            kpi_card("Возвраты 1С", "kpi-refunds-1c", "отрицательные sales_amount"),
        ],
    )
