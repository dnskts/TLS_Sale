"""Top filters panel shared across dashboard tabs."""

from __future__ import annotations

from dash import dcc, html

from app.data_access import default_date_range, get_filter_options


def _filter_item(label: str, control) -> html.Div:
    return html.Div(
        className="filter-item",
        children=[
            html.Label(label, className="filter-label"),
            control,
        ],
    )


def _dropdown(dropdown_id: str, options: list[dict], *, multi: bool = True) -> dcc.Dropdown:
    return dcc.Dropdown(
        id=dropdown_id,
        options=options,
        value=[] if multi else None,
        multi=multi,
        placeholder="Все",
        clearable=True,
    )


def build_filters_panel() -> html.Div:
    date_from, date_to = default_date_range()
    options = get_filter_options()

    return html.Div(
        id="filters-panel",
        className="filters-panel",
        children=[
            html.H3("Фильтры", className="filters-title"),
            html.Div(
                className="filters-grid",
                children=[
                    _filter_item(
                        "Период",
                        dcc.DatePickerRange(
                            id="filter-date-range",
                            start_date=date_from,
                            end_date=date_to,
                            display_format="DD.MM.YYYY",
                            minimum_nights=0,
                        ),
                    ),
                    _filter_item(
                        "Источник",
                        dcc.Dropdown(
                            id="filter-source",
                            options=[
                                {"label": "Все", "value": "all"},
                                {"label": "1С", "value": "1c"},
                                {"label": "Битрикс", "value": "bitrix"},
                            ],
                            value="all",
                            clearable=False,
                        ),
                    ),
                    _filter_item("Команда", _dropdown("filter-team", options.get("teams", []))),
                    _filter_item("Агент", _dropdown("filter-agent", options.get("agents_active", []))),
                    _filter_item(
                        "Клиент",
                        dcc.Dropdown(
                            id="filter-client",
                            options=options.get("clients", []),
                            value=None,
                            placeholder="Поиск клиента",
                            searchable=True,
                            clearable=True,
                        ),
                    ),
                    _filter_item(
                        "Партнёр / поставщик",
                        _dropdown("filter-partner", options.get("partners", []), multi=False),
                    ),
                    _filter_item("Категория", _dropdown("filter-category", options.get("categories", []))),
                    _filter_item("Канал", _dropdown("filter-channel", options.get("channels", []))),
                    _filter_item("Тип карты", _dropdown("filter-card-type", options.get("card_types", []))),
                    _filter_item("Тип клиента", _dropdown("filter-client-type", options.get("client_types", []))),
                    _filter_item("Тип запроса", _dropdown("filter-request-type", options.get("request_types", []))),
                    html.Div(
                        className="filter-checks",
                        children=[
                            dcc.Checklist(
                                id="filter-agent-flags",
                                options=[
                                    {"label": " Показать неактивных", "value": "inactive"},
                                    {"label": " Не в справочнике", "value": "unknown"},
                                ],
                                value=[],
                                className="filter-checklist",
                            ),
                        ],
                    ),
                    html.Div(
                        className="filters-actions",
                        children=[
                            html.Button("Применить фильтры", id="btn-apply-filters", className="btn-primary"),
                        ],
                    ),
                ],
            ),
        ],
    )
