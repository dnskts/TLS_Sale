"""Chart and table components for dashboard tabs."""

from __future__ import annotations

from dash import dash_table, dcc, html

_TABLE_HEADER = {"fontWeight": "bold", "backgroundColor": "#eef2f4", "color": "#535c69", "fontSize": "12px"}
_TABLE_CELL = {"padding": "8px", "fontFamily": "Helvetica Neue, Helvetica, Arial, sans-serif", "color": "#535c69"}


def _graph_block(graph_id: str, title: str, controls: html.Div | None = None) -> html.Div:
    children = [html.H4(title, className="chart-slot-title")]
    if controls is not None:
        children.append(controls)
    children.append(dcc.Graph(id=graph_id, className="chart-graph", config={"displayModeBar": False}))
    return html.Div(className="chart-slot", children=children)


def tab_overview() -> html.Div:
    granularity = html.Div(
        className="chart-controls",
        children=[
            html.Label("Гранулярность:", className="control-label"),
            dcc.RadioItems(
                id="overview-granularity",
                options=[
                    {"label": " День", "value": "day"},
                    {"label": " Неделя", "value": "week"},
                    {"label": " Месяц", "value": "month"},
                ],
                value="day",
                inline=True,
            ),
        ],
    )
    return html.Div(
        className="tab-content",
        children=[
            html.H2("Обзор"),
            html.Div(
                className="charts-grid",
                children=[
                    _graph_block("chart-overview-trend", "Динамика продаж и прибыли", granularity),
                    _graph_block("chart-overview-source", "Структура 1С vs Битрикс"),
                    _graph_block("chart-overview-teams", "Топ-5 команд"),
                    _graph_block("chart-overview-agents", "Топ-5 агентов"),
                ],
            ),
        ],
    )


def tab_agents() -> html.Div:
    return html.Div(
        className="tab-content",
        children=[
            html.H2("Агенты и команды"),
            _graph_block("chart-agents-ranking", "Рейтинг агентов по прибыли"),
            html.Div(
                className="tables-grid",
                children=[
                    html.Div(
                        className="table-slot",
                        children=[
                            html.H4("Команды"),
                            dash_table.DataTable(
                                id="table-teams",
                                page_size=10,
                                sort_action="native",
                                style_table={"overflowX": "auto"},
                                style_cell=_TABLE_CELL,
                                style_header=_TABLE_HEADER,
                            ),
                        ],
                    ),
                    html.Div(
                        className="table-slot",
                        children=[
                            html.H4("Агенты"),
                            dash_table.DataTable(
                                id="table-agents",
                                page_size=15,
                                sort_action="native",
                                style_table={"overflowX": "auto"},
                                style_cell=_TABLE_CELL,
                                style_header=_TABLE_HEADER,
                            ),
                        ],
                    ),
                ],
            ),
        ],
    )


def tab_structure() -> html.Div:
    return html.Div(
        className="tab-content",
        children=[
            html.H2("Структура продаж"),
            html.Div(
                className="charts-grid",
                children=[
                    _graph_block("chart-structure-category", "По категориям"),
                    _graph_block("chart-structure-channel", "По каналам"),
                    _graph_block("chart-structure-client-type", "По типам клиентов"),
                    _graph_block("chart-structure-card-type", "По типам карт"),
                    _graph_block("chart-structure-request-type", "По типам запросов"),
                    _graph_block("chart-structure-partners", "Топ партнёров / поставщиков"),
                ],
            ),
        ],
    )


def tab_funnel() -> html.Div:
    granularity = html.Div(
        className="chart-controls",
        children=[
            html.Label("Гранулярность:", className="control-label"),
            dcc.RadioItems(
                id="funnel-granularity",
                options=[
                    {"label": " День", "value": "day"},
                    {"label": " Неделя", "value": "week"},
                    {"label": " Месяц", "value": "month"},
                ],
                value="month",
                inline=True,
            ),
        ],
    )
    return html.Div(
        className="tab-content",
        children=[
            html.H2("Воронка Битрикс"),
            html.P(
                "Все сделки из deals_bitrix. Период и фильтры агента/команды/категории/канала/типа запроса "
                "применяются по дате создания сделки (deal_created_at). Фильтр «Источник» не используется.",
                className="tab-note",
            ),
            html.Div(
                id="funnel-stats-row",
                className="funnel-stats-row",
                children=[
                    html.Div(className="funnel-stat-card", children=[html.Div("—", className="funnel-stat-value"), html.Div("Создано за период", className="funnel-stat-label")]),
                ],
            ),
            html.Div(
                className="charts-grid",
                children=[
                    _graph_block("chart-funnel-result-pie", "Результат сделки (доля)"),
                    _graph_block("chart-funnel-result-bar", "Результат сделки (количество)"),
                    _graph_block("chart-funnel-status", "Статус сделки"),
                    _graph_block("chart-funnel-lost-reason", "Причины проигрыша"),
                    _graph_block("chart-funnel-trend", "Созданные vs оплаченные", granularity),
                ],
            ),
            html.Div(
                className="table-slot funnel-lost-table",
                children=[
                    html.H4("Таблица причин проигрыша"),
                    dash_table.DataTable(
                        id="table-funnel-lost",
                        page_size=10,
                        sort_action="native",
                        style_table={"overflowX": "auto"},
                        style_cell=_TABLE_CELL,
                        style_header=_TABLE_HEADER,
                    ),
                ],
            ),
        ],
    )


def tab_details() -> html.Div:
    return html.Div(
        className="tab-content",
        children=[
            html.H2("Детализация"),
            html.P("Строки sales_unified с учётом текущих фильтров.", className="tab-note"),
            html.Div(id="load-diagnostics", className="load-diagnostics"),
            dash_table.DataTable(
                id="table-details",
                page_size=20,
                page_action="native",
                sort_action="native",
                filter_action="native",
                style_table={"overflowX": "auto"},
                style_cell={**_TABLE_CELL, "maxWidth": 260, "overflow": "hidden", "textOverflow": "ellipsis"},
                style_header=_TABLE_HEADER,
            ),
        ],
    )
