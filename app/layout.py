"""Main Dash application layout."""

from __future__ import annotations

from dash import dcc, html

from app.components.charts_grid import (
    tab_agents,
    tab_details,
    tab_funnel,
    tab_overview,
    tab_structure,
)
from app.components.settings_agents import build_merge_modal, build_settings_modal, tab_settings
from app.components.filters import build_filters_panel
from app.components.kpi_cards import overview_kpi_placeholders
from app.data_access import default_date_range, get_status_info
from parser.settings_loader import get_settings


def status_banner_content() -> tuple[list, str]:
    status = get_status_info()
    if not status.get("database_exists"):
        return (
            [
                html.Strong("Данные не загружены"),
                html.Br(),
                html.Span("Выполните python -m parser.pipeline"),
            ],
            "status-banner status-empty",
        )
    return (
        [
            html.Div(f"Загрузка: {status.get('loaded_at') or '—'}"),
            html.Div(
                [
                    html.Span(f"1С: {status.get('rows_1c', 0)}"),
                    html.Span(" · "),
                    html.Span(f"Битрикс: {status.get('rows_bitrix', 0)}"),
                    html.Span(" · "),
                    html.Span(f"Unified: {status.get('rows_unified', 0)}"),
                ]
            ),
        ],
        "status-banner status-ok",
    )


def _status_block() -> html.Div:
    children, class_name = status_banner_content()
    return html.Div(id="status-banner", className=class_name, children=children)


def build_layout() -> html.Div:
    settings = get_settings()
    title = settings.get("app", {}).get("title", "Дашборд РС ТЛС")
    date_from, date_to = default_date_range()

    initial_filters = {
        "date_from": date_from,
        "date_to": date_to,
        "source": "all",
        "teams": [],
        "agents": [],
        "client": None,
        "partner": None,
        "categories": [],
        "channels": [],
        "card_types": [],
        "client_types": [],
        "request_types": [],
        "show_inactive_agents": False,
        "show_unknown_agents": False,
    }

    return html.Div(
        className="app-container",
        children=[
            dcc.Store(id="store-filters", data=initial_filters),
            dcc.Store(id="store-filter-options"),
            dcc.Store(id="store-agents-draft"),
            dcc.Store(id="store-agents-loaded"),
            dcc.Store(id="store-settings-modal-mode"),
            dcc.Store(id="store-settings-modal-key"),
            dcc.Store(id="store-merge-keys"),
            dcc.ConfirmDialog(
                id="confirm-discard-settings",
                message="Отменить несохранённые изменения и перечитать settings.json с диска?",
            ),
            dcc.ConfirmDialog(
                id="confirm-delete-agent",
                message="Удалить агента из справочника? Строки продаж останутся, но станут «Не в справочнике» до перепривязки.",
            ),
            dcc.ConfirmDialog(
                id="confirm-bulk-delete",
                message="Удалить выбранных агентов из справочника?",
            ),
            build_settings_modal(),
            build_merge_modal(),
            html.Aside(
                className="sidebar",
                children=[
                    html.H1(title, className="sidebar-title"),
                    html.Nav(
                        className="sidebar-nav",
                        children=[
                            dcc.Tabs(
                                id="main-tabs",
                                value="tab-overview",
                                vertical=True,
                                className="vertical-tabs",
                                children=[
                                    dcc.Tab(label="Обзор", value="tab-overview"),
                                    dcc.Tab(label="Агенты и команды", value="tab-agents"),
                                    dcc.Tab(label="Структура продаж", value="tab-structure"),
                                    dcc.Tab(label="Воронка Битрикс", value="tab-funnel"),
                                    dcc.Tab(label="Детализация", value="tab-details"),
                                    dcc.Tab(label="Настройки", value="tab-settings"),
                                ],
                            ),
                        ],
                    ),
                    html.Div(
                        className="sidebar-footer",
                        children=[
                            _status_block(),
                            html.Div(
                                className="sidebar-actions",
                                children=[
                                    html.Button(
                                        "Обновить данные",
                                        id="btn-refresh-data",
                                        className="btn-secondary",
                                        n_clicks=0,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ],
            ),
            html.Div(
                className="main-content",
                children=[
                    build_filters_panel(),
                    overview_kpi_placeholders(),
                    html.Div(id="tab-content", className="tab-panel", children=tab_overview()),
                    html.Div(
                        id="debug-filters",
                        className="debug-panel",
                        children="Выберите фильтры и нажмите «Применить фильтры».",
                    ),
                ],
            ),
        ],
    )
