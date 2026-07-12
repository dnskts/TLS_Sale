"""Dash callbacks for dashboard tabs and data refresh."""

from __future__ import annotations

import json
import traceback

from dash import Input, Output, State, callback, dash_table, html, no_update

from app.charts import (
    empty_figure,
    figure_agent_ranking,
    figure_dimension_bar,
    figure_source_stacked,
    figure_top_dimension,
    figure_trend,
)
from app.components.charts_grid import (
    tab_agents,
    tab_details,
    tab_funnel,
    tab_overview,
    tab_structure,
)
from app.components.settings_agents import tab_settings
from app.charts_funnel import (
    figure_funnel_created_vs_paid,
    figure_funnel_lost_reason,
    figure_funnel_result_bar,
    figure_funnel_result_pie,
    figure_funnel_status_bar,
)
from app.data_access import (
    clear_cache,
    database_exists,
    get_filtered_deals_bitrix,
    get_filtered_sales,
    get_filter_options,
    get_load_diagnostics,
    get_status_info,
    summarize_funnel_payment_stats,
)
from app.layout import status_banner_content
from app.metrics import format_count, format_margin, format_rub, summarize_sales
from parser.pipeline import run_pipeline

TAB_RENDERERS = {
    "tab-overview": tab_overview,
    "tab-agents": tab_agents,
    "tab-structure": tab_structure,
    "tab-funnel": tab_funnel,
    "tab-details": tab_details,
    "tab-settings": tab_settings,
}


def _filtered_or_empty(filters: dict | None):
    if not database_exists():
        return get_filtered_sales(filters)
    return get_filtered_sales(filters)


def _pct_text(value) -> str:
    if value is None:
        return "—"
    return f"{value:.1f} %".replace(".", ",")


@callback(
    Output("tab-content", "children"),
    Input("main-tabs", "value"),
)
def render_tab(active_tab: str):
    renderer = TAB_RENDERERS.get(active_tab, tab_overview)
    return renderer()


@callback(
    Output("store-filters", "data"),
    Output("debug-filters", "children"),
    Input("btn-apply-filters", "n_clicks"),
    State("filter-date-range", "start_date"),
    State("filter-date-range", "end_date"),
    State("filter-source", "value"),
    State("filter-team", "value"),
    State("filter-agent", "value"),
    State("filter-agent-flags", "value"),
    State("filter-client", "value"),
    State("filter-partner", "value"),
    State("filter-category", "value"),
    State("filter-channel", "value"),
    State("filter-card-type", "value"),
    State("filter-request-type", "value"),
    State("filter-client-type", "value"),
    prevent_initial_call=True,
)
def apply_filters(
    _n_clicks,
    date_from,
    date_to,
    source,
    teams,
    agents,
    agent_flags,
    client,
    partner,
    categories,
    channels,
    card_types,
    request_types,
    client_types,
):
    from parser.settings_loader import clear_settings_cache

    clear_settings_cache()
    flags = agent_flags or []
    payload = {
        "date_from": date_from,
        "date_to": date_to,
        "source": source or "all",
        "teams": teams or [],
        "agents": agents or [],
        "client": client,
        "partner": partner,
        "categories": categories or [],
        "channels": channels or [],
        "card_types": card_types or [],
        "client_types": client_types or [],
        "request_types": request_types or [],
        "show_inactive_agents": "inactive" in flags,
        "show_unknown_agents": "unknown" in flags,
    }
    debug_text = f"Фильтры применены: {json.dumps(payload, ensure_ascii=False)}"
    return payload, debug_text


@callback(
    Output("filter-agent", "options"),
    Input("filter-agent-flags", "value"),
)
def update_agent_options(agent_flags):
    flags = agent_flags or []
    options = get_filter_options()
    result = list(options.get("agents_active", []))
    if "inactive" in flags:
        result.extend(options.get("agents_inactive", []))
    if "unknown" in flags:
        result.extend(options.get("agents_unknown", []))
    result.sort(key=lambda item: item.get("label", ""))
    return result


@callback(
    Output("kpi-sales-total", "children"),
    Output("kpi-profit-total", "children"),
    Output("kpi-margin-total", "children"),
    Output("kpi-row-count", "children"),
    Output("kpi-source-share", "children"),
    Output("kpi-refunds-1c", "children"),
    Input("store-filters", "data"),
)
def update_kpi_cards(filters):
    summary = summarize_sales(_filtered_or_empty(filters))
    share_text = "—"
    if summary["share_1c_pct"] is not None and summary["share_bitrix_pct"] is not None:
        share_text = f"{_pct_text(summary['share_1c_pct'])} / {_pct_text(summary['share_bitrix_pct'])}"
    refunds_text = f"{format_rub(summary['refund_sum_1c'])} · {format_count(summary['refund_count_1c'])} шт."
    return (
        format_rub(summary["sales_total"]),
        format_rub(summary["profit_total"]),
        format_margin(summary["sales_total"], summary["profit_total"]),
        format_count(summary["row_count"]),
        share_text,
        refunds_text,
    )


@callback(
    Output("chart-overview-trend", "figure"),
    Output("chart-overview-source", "figure"),
    Output("chart-overview-teams", "figure"),
    Output("chart-overview-agents", "figure"),
    Input("store-filters", "data"),
    Input("main-tabs", "value"),
    Input("overview-granularity", "value"),
)
def update_overview_charts(filters, active_tab, granularity):
    if active_tab != "tab-overview":
        return no_update, no_update, no_update, no_update
    frame = _filtered_or_empty(filters)
    gran = granularity or "day"
    return (
        figure_trend(frame, gran),
        figure_source_stacked(frame, gran),
        figure_top_dimension(frame, "agent_team", "Команда", top_n=5),
        figure_top_dimension(frame, "agent_display", "Агент", top_n=5),
    )


@callback(
    Output("chart-agents-ranking", "figure"),
    Output("table-teams", "data"),
    Output("table-teams", "columns"),
    Output("table-agents", "data"),
    Output("table-agents", "columns"),
    Input("store-filters", "data"),
    Input("main-tabs", "value"),
)
def update_agents_tab(filters, active_tab):
    empty_cols = []
    if active_tab != "tab-agents":
        return no_update, no_update, no_update, no_update, no_update

    frame = _filtered_or_empty(filters)
    if frame.empty:
        return empty_figure(), [], empty_cols, [], empty_cols

    total_sales = float(frame["sales_amount"].fillna(0).sum())

    teams = (
        frame.groupby("agent_team", as_index=False)
        .agg(sales_amount=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"), count=("sales_amount", "count"))
        .sort_values("profit_ex_vat", ascending=False)
    )
    teams["agent_team"] = teams["agent_team"].fillna("Без команды")
    teams["margin_pct"] = teams.apply(
        lambda row: row["profit_ex_vat"] / row["sales_amount"] * 100 if row["sales_amount"] else None,
        axis=1,
    )
    teams["share_pct"] = teams["sales_amount"] / total_sales * 100 if total_sales else 0

    team_data = teams.to_dict("records")
    team_columns = [
        {"name": "Команда", "id": "agent_team"},
        {"name": "Продажи", "id": "sales_amount", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Прибыль", "id": "profit_ex_vat", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Маржа %", "id": "margin_pct", "type": "numeric", "format": {"specifier": ".1f"}},
        {"name": "Строк", "id": "count", "type": "numeric"},
        {"name": "Доля %", "id": "share_pct", "type": "numeric", "format": {"specifier": ".1f"}},
    ]

    one_c = frame[frame["source"] == "1c"].groupby(["agent_key", "agent_display", "agent_team"], as_index=False).agg(
        sales_1c=("sales_amount", "sum")
    )
    bitrix = frame[frame["source"] == "bitrix"].groupby(["agent_key", "agent_display", "agent_team"], as_index=False).agg(
        sales_bitrix=("sales_amount", "sum")
    )
    agents = (
        frame.groupby(["agent_key", "agent_display", "agent_team"], as_index=False)
        .agg(sales_total=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"), count=("sales_amount", "count"))
        .merge(one_c, on=["agent_key", "agent_display", "agent_team"], how="left")
        .merge(bitrix, on=["agent_key", "agent_display", "agent_team"], how="left")
        .sort_values("profit_ex_vat", ascending=False)
    )
    agents["sales_1c"] = agents["sales_1c"].fillna(0)
    agents["sales_bitrix"] = agents["sales_bitrix"].fillna(0)
    agents["margin_pct"] = agents.apply(
        lambda row: row["profit_ex_vat"] / row["sales_total"] * 100 if row["sales_total"] else None,
        axis=1,
    )

    agent_data = agents.to_dict("records")
    agent_columns = [
        {"name": "Агент", "id": "agent_display"},
        {"name": "Команда", "id": "agent_team"},
        {"name": "1С", "id": "sales_1c", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Битрикс", "id": "sales_bitrix", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Итого", "id": "sales_total", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Прибыль", "id": "profit_ex_vat", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Маржа %", "id": "margin_pct", "type": "numeric", "format": {"specifier": ".1f"}},
        {"name": "Строк", "id": "count", "type": "numeric"},
    ]

    return figure_agent_ranking(frame), team_data, team_columns, agent_data, agent_columns


@callback(
    Output("chart-structure-category", "figure"),
    Output("chart-structure-channel", "figure"),
    Output("chart-structure-client-type", "figure"),
    Output("chart-structure-card-type", "figure"),
    Output("chart-structure-request-type", "figure"),
    Output("chart-structure-partners", "figure"),
    Input("store-filters", "data"),
    Input("main-tabs", "value"),
)
def update_structure_tab(filters, active_tab):
    if active_tab != "tab-structure":
        return (no_update,) * 6
    frame = _filtered_or_empty(filters)
    return (
        figure_dimension_bar(frame, "category", "Категория"),
        figure_dimension_bar(frame, "channel", "Канал"),
        figure_dimension_bar(frame, "client_type", "Тип клиента"),
        figure_dimension_bar(frame, "card_type", "Тип карты"),
        figure_dimension_bar(frame, "request_type", "Тип запроса"),
        figure_dimension_bar(frame, "partner_or_supplier", "Партнёр / поставщик"),
    )


@callback(
    Output("table-details", "data"),
    Output("table-details", "columns"),
    Input("store-filters", "data"),
    Input("main-tabs", "value"),
)
def update_details_table(filters, active_tab):
    columns = [
        {"name": "Дата", "id": "date"},
        {"name": "Источник", "id": "source_label"},
        {"name": "Агент", "id": "agent_display"},
        {"name": "Команда", "id": "agent_team"},
        {"name": "Клиент", "id": "client"},
        {"name": "Категория", "id": "category"},
        {"name": "Канал", "id": "channel"},
        {"name": "Партнёр", "id": "partner_or_supplier"},
        {"name": "Продажи", "id": "sales_amount", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "Прибыль", "id": "profit_ex_vat", "type": "numeric", "format": {"specifier": ",.0f"}},
        {"name": "ID", "id": "raw_id"},
    ]
    if active_tab != "tab-details":
        return no_update, no_update

    frame = _filtered_or_empty(filters)
    if frame.empty:
        return [], columns

    display = frame.copy()
    display["date"] = display["date"].dt.strftime("%d.%m.%Y")
    display["source_label"] = display["source"].map({"1c": "1С", "bitrix": "Битрикс"}).fillna(display["source"])
    display = display[
        [
            "date",
            "source_label",
            "agent_display",
            "agent_team",
            "client",
            "category",
            "channel",
            "partner_or_supplier",
            "sales_amount",
            "profit_ex_vat",
            "raw_id",
        ]
    ].fillna("")
    return display.to_dict("records"), columns


def _funnel_stat_card(value: str, label: str) -> html.Div:
    return html.Div(
        className="funnel-stat-card",
        children=[
            html.Div(value, className="funnel-stat-value"),
            html.Div(label, className="funnel-stat-label"),
        ],
    )


def _build_load_diagnostics() -> html.Div:
    diagnostics = get_load_diagnostics()
    warnings = diagnostics.get("warnings") or []
    unknown = diagnostics.get("top_unknown_agents") or []

    children: list = []
    if warnings:
        children.append(html.H4("Предупреждения загрузки"))
        children.append(
            html.Ul(
                [html.Li(str(item)) for item in warnings],
                className="load-warning-list",
            )
        )
    else:
        children.append(html.P("Предупреждений парсинга нет.", className="tab-note"))

    if unknown:
        children.append(html.H4("Неизвестные агенты (не в settings.json)"))
        children.append(
            dash_table.DataTable(
                columns=[
                    {"name": "Агент", "id": "agent_display"},
                    {"name": "Источник", "id": "source"},
                    {"name": "Строк", "id": "count", "type": "numeric"},
                    {"name": "Ключ", "id": "agent_key"},
                ],
                data=unknown,
                page_size=10,
                sort_action="native",
                style_table={"overflowX": "auto", "marginBottom": "16px"},
                style_cell={"padding": "8px", "fontFamily": "Segoe UI, Arial, sans-serif"},
                style_header={"fontWeight": "700", "backgroundColor": "#fff4e5"},
            )
        )
        children.append(
            html.P(
                "Добавьте алиасы в settings.json → agents (names_1c / names_bitrix) и перезагрузите данные.",
                className="tab-note",
            )
        )
    return html.Div(className="load-diagnostics-inner", children=children)


@callback(
    Output("funnel-stats-row", "children"),
    Output("chart-funnel-result-pie", "figure"),
    Output("chart-funnel-result-bar", "figure"),
    Output("chart-funnel-status", "figure"),
    Output("chart-funnel-lost-reason", "figure"),
    Output("chart-funnel-trend", "figure"),
    Output("table-funnel-lost", "data"),
    Output("table-funnel-lost", "columns"),
    Input("store-filters", "data"),
    Input("main-tabs", "value"),
    Input("funnel-granularity", "value"),
)
def update_funnel_tab(filters, active_tab, granularity):
    empty_cols: list = []
    if active_tab != "tab-funnel":
        return (no_update,) * 8

    frame = get_filtered_deals_bitrix(filters)
    stats = summarize_funnel_payment_stats(frame, filters)

    stat_cards = [
        _funnel_stat_card(format_count(stats["total_created"]), "Создано за период"),
        _funnel_stat_card(format_count(stats["success_count"]), "Успех"),
        _funnel_stat_card(_pct_text(stats["conversion_pct"]), "Конверсия"),
        _funnel_stat_card(format_count(stats["paid_in_period"]), "Оплачено за период (client_paid_at)"),
        _funnel_stat_card(format_count(stats["with_paid_date"]), "С датой оплаты"),
        _funnel_stat_card(format_count(stats["without_paid_date"]), "Без даты оплаты"),
    ]

    lost_columns = [
        {"name": "Причина", "id": "lost_deal_reason"},
        {"name": "Сделок", "id": "count", "type": "numeric"},
        {"name": "Доля %", "id": "share_pct", "type": "numeric", "format": {"specifier": ".1f"}},
    ]
    lost_data: list = []
    if not frame.empty and "deal_result" in frame.columns:
        lost = frame[frame["deal_result"].astype(str).str.strip() == "Проиграна"]
        if not lost.empty and "lost_deal_reason" in lost.columns:
            grouped = (
                lost.assign(
                    lost_deal_reason=lost["lost_deal_reason"]
                    .fillna("Не указана")
                    .astype(str)
                    .str.strip()
                    .replace({"": "Не указана"})
                )
                .groupby("lost_deal_reason", as_index=False)
                .size()
                .rename(columns={"size": "count"})
                .sort_values("count", ascending=False)
            )
            total_lost = int(grouped["count"].sum())
            grouped["share_pct"] = grouped["count"] / total_lost * 100 if total_lost else 0
            lost_data = grouped.to_dict("records")

    gran = granularity or "month"
    return (
        stat_cards,
        figure_funnel_result_pie(frame),
        figure_funnel_result_bar(frame),
        figure_funnel_status_bar(frame),
        figure_funnel_lost_reason(frame),
        figure_funnel_created_vs_paid(frame, gran),
        lost_data,
        lost_columns,
    )


@callback(
    Output("load-diagnostics", "children"),
    Input("main-tabs", "value"),
    Input("btn-refresh-data", "n_clicks"),
)
def update_load_diagnostics(active_tab, _refresh_clicks):
    if active_tab != "tab-details":
        return no_update
    return _build_load_diagnostics()


@callback(
    Output("debug-filters", "children", allow_duplicate=True),
    Output("status-banner", "children"),
    Output("status-banner", "className"),
    Input("btn-refresh-data", "n_clicks"),
    prevent_initial_call=True,
)
def refresh_data(n_clicks):
    if not n_clicks:
        return no_update, no_update, no_update
    try:
        run_pipeline()
        clear_cache()
        status = get_status_info()
        message = html.Div(
            [
                html.Strong("Данные обновлены."),
                html.Span(
                    f" Unified: {status.get('rows_unified', 0)} строк, "
                    f"1С: {status.get('rows_1c', 0)}, Битрикс: {status.get('rows_bitrix', 0)}."
                ),
            ]
        )
        banner_children, banner_class = status_banner_content()
        return message, banner_children, banner_class
    except Exception:
        return (
            html.Div(
                [
                    html.Strong("Ошибка обновления данных"),
                    html.Pre(traceback.format_exc(), style={"whiteSpace": "pre-wrap", "fontSize": "12px"}),
                ],
                style={"color": "#9a3412"},
            ),
            no_update,
            no_update,
        )
