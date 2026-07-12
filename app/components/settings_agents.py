"""Layout for the Settings tab — agents dictionary editor."""

from __future__ import annotations

from dash import dash_table, dcc, html

_SETTINGS_TABLE = {
    "overflowX": "auto",
    "maxHeight": "520px",
    "overflowY": "auto",
}

_SETTINGS_CELL = {
    "padding": "8px 10px",
    "fontFamily": "Helvetica Neue, Helvetica, Arial, sans-serif",
    "fontSize": "13px",
    "color": "#535c69",
    "textAlign": "left",
    "minWidth": "80px",
}

_SETTINGS_HEADER = {
    "fontWeight": "bold",
    "backgroundColor": "#eef2f4",
    "color": "#535c69",
    "fontSize": "12px",
    "textTransform": "uppercase",
}


def tab_settings() -> html.Div:
    return html.Div(
        className="tab-content settings-tab",
        children=[
            html.H2("Настройки"),
            html.Section(
                className="settings-section",
                children=[
                    html.H3("Агенты"),
                    html.P(
                        "Справочник соответствия имён 1С и Битрикс. "
                        "Активность и команду можно менять в таблице или массово для выбранных строк. "
                        "«Сохранить изменения» пишет в settings.json (резервная копия в data/backups/). "
                        "«Применить к данным» пересчитывает привязку продаж в отчётах.",
                        className="tab-note",
                    ),
                    html.Div(
                        className="settings-alert settings-alert-warning settings-save-hint",
                        children=(
                            "⚠️ Сохранение справочника обновляет только файл конфигурации. "
                            "Чтобы привязать новые алиасы к уже загруженным продажам в базе данных, "
                            'обязательно нажмите кнопку «Применить к данным» после сохранения!'
                        ),
                    ),
                    html.Div(id="settings-dirty-indicator", className="settings-dirty hidden"),
                    html.Div(id="settings-message", className="settings-message"),
                    html.Div(id="settings-validation", className="settings-validation"),
                    html.Div(
                        className="settings-toolbar",
                        children=[
                            dcc.Input(
                                id="settings-search",
                                type="text",
                                placeholder="Поиск: имя, agent_key, алиасы…",
                                className="settings-search-input",
                                debounce=True,
                            ),
                            dcc.RadioItems(
                                id="settings-active-filter",
                                options=[
                                    {"label": " Все", "value": "all"},
                                    {"label": " Активные", "value": "active"},
                                    {"label": " Неактивные", "value": "inactive"},
                                ],
                                value="all",
                                inline=True,
                                className="settings-filter-radio",
                            ),
                        ],
                    ),
                    html.Div(
                        className="settings-actions",
                        children=[
                            html.Button("Добавить агента", id="btn-settings-add", className="btn-secondary"),
                            html.Button(
                                "Редактировать алиасы выбранного",
                                id="btn-settings-edit-selected",
                                className="btn-secondary",
                            ),
                            html.Button("Перечитать с диска", id="btn-settings-reload", className="btn-secondary"),
                            html.Button(
                                "Сохранить изменения",
                                id="btn-settings-save",
                                className="btn-primary settings-btn-save",
                            ),
                            html.Button(
                                "Применить к данным (пересобрать БД)",
                                id="btn-settings-apply",
                                className="btn-secondary",
                            ),
                        ],
                    ),
                    html.Div(
                        className="settings-bulk-panel",
                        children=[
                            html.Div(
                                className="settings-bulk-header",
                                children=[
                                    html.Strong("Массовые действия"),
                                    html.Span(id="settings-selected-count", className="settings-selected-count"),
                                ],
                            ),
                            html.Div(
                                className="settings-bulk-grid",
                                children=[
                                    html.Div(
                                        className="settings-bulk-field",
                                        children=[
                                            html.Label("Активен", className="filter-label"),
                                            dcc.RadioItems(
                                                id="bulk-active-action",
                                                options=[
                                                    {"label": " Не менять", "value": "keep"},
                                                    {"label": " Активировать", "value": "activate"},
                                                    {"label": " Деактивировать", "value": "deactivate"},
                                                ],
                                                value="keep",
                                                inline=True,
                                                className="settings-bulk-radio",
                                            ),
                                        ],
                                    ),
                                    html.Div(
                                        className="settings-bulk-field",
                                        children=[
                                            html.Label("Команда", className="filter-label"),
                                            dcc.Dropdown(
                                                id="bulk-team",
                                                options=[],
                                                placeholder="Не менять",
                                                searchable=True,
                                                clearable=True,
                                            ),
                                        ],
                                    ),
                                    html.Div(
                                        className="settings-bulk-actions",
                                        children=[
                                            html.Button(
                                                "Применить к выбранным",
                                                id="btn-bulk-apply",
                                                className="btn-primary",
                                            ),
                                            html.Button(
                                                "Объединить",
                                                id="btn-bulk-merge",
                                                className="btn-secondary",
                                            ),
                                            html.Button(
                                                "Удалить выбранные",
                                                id="btn-bulk-delete",
                                                className="btn-danger",
                                            ),
                                        ],
                                    ),
                                ],
                            ),
                        ],
                    ),
                    html.P(
                        "Отметьте строки галками. Для алиасов выберите ровно одну строку; "
                        "для массовых операций — одну или несколько. Объединение — минимум 2 профиля.",
                        className="tab-note",
                    ),
                    html.Div(
                        className="settings-datatable",
                        children=[
                            dash_table.DataTable(
                                id="settings-agents-datatable",
                                columns=[
                                    {"name": "Активен", "id": "is_active", "type": "boolean", "editable": True},
                                    {"name": "Отображаемое имя", "id": "name_display", "editable": False},
                                    {
                                        "name": "Команда",
                                        "id": "team",
                                        "editable": True,
                                        "presentation": "dropdown",
                                    },
                                    {"name": "Алиасы 1С", "id": "names_1c_preview", "editable": False},
                                    {"name": "Алиасы Битрикс", "id": "names_bitrix_preview", "editable": False},
                                    {"name": "agent_key", "id": "agent_key", "editable": False},
                                ],
                                data=[],
                                editable=True,
                                row_selectable="multi",
                                selected_rows=[],
                                page_size=20,
                                sort_action="native",
                                filter_action="native",
                                style_table=_SETTINGS_TABLE,
                                style_cell=_SETTINGS_CELL,
                                style_header=_SETTINGS_HEADER,
                                style_data_conditional=[
                                    {
                                        "if": {"filter_query": "{is_active} = false", "column_id": "name_display"},
                                        "color": "#828b95",
                                    },
                                ],
                                css=[
                                    {
                                        "selector": ".dash-spreadsheet-container .dash-spreadsheet-inner "
                                        "table tbody tr:hover",
                                        "rule": "background-color: #eaf5fc !important;",
                                    },
                                ],
                            ),
                        ],
                    ),
                    html.P(id="settings-agents-count", className="tab-note"),
                ],
            ),
            html.Section(
                className="settings-section settings-section-muted",
                children=[
                    html.H3("Общие"),
                    html.P("Текущие значения приложения:", className="tab-note"),
                    html.Div(id="settings-general-info", className="settings-general-info"),
                ],
            ),
        ],
    )


def build_settings_modal() -> html.Div:
    """Modal dialog for add/edit agent (lives in root layout)."""
    return html.Div(
        id="settings-modal-overlay",
        className="modal-overlay hidden",
        children=[
            html.Div(
                className="modal-dialog",
                children=[
                    html.Div(
                        className="modal-header",
                        children=[
                            html.H3(id="settings-modal-title", children="Агент"),
                            html.Button("×", id="btn-modal-close", className="modal-close-btn", n_clicks=0),
                        ],
                    ),
                    html.Div(
                        className="modal-body settings-form",
                        children=[
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("agent_key", className="form-label"),
                                    dcc.Input(
                                        id="modal-agent-key",
                                        type="text",
                                        placeholder="ivanov_ivan",
                                        className="input-field",
                                    ),
                                    html.Small(
                                        "Уникальный ключ (snake_case). У существующего агента не меняется.",
                                        className="form-hint",
                                    ),
                                ],
                            ),
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("Отображаемое имя", className="form-label"),
                                    dcc.Input(id="modal-name-display", type="text", className="input-field"),
                                ],
                            ),
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("Команда", className="form-label"),
                                    dcc.Dropdown(
                                        id="modal-team",
                                        searchable=True,
                                        clearable=False,
                                        placeholder="Выберите или введите команду",
                                    ),
                                ],
                            ),
                            html.Div(
                                className="form-row form-row-inline",
                                children=[
                                    dcc.Checklist(
                                        id="modal-is-active",
                                        options=[
                                            {
                                                "label": " Активен (участвует в фильтрах по умолчанию)",
                                                "value": "active",
                                            }
                                        ],
                                        value=["active"],
                                        className="form-checklist",
                                    ),
                                ],
                            ),
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("Алиасы 1С", className="form-label"),
                                    dcc.Textarea(
                                        id="modal-names-1c",
                                        className="input-field",
                                        placeholder="По одному имени на строку, как в выгрузке 1С (латиница)",
                                    ),
                                ],
                            ),
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("Алиасы Битрикс", className="form-label"),
                                    dcc.Textarea(
                                        id="modal-names-bitrix",
                                        className="input-field",
                                        placeholder="По одному имени на строку, как «Ответственное лицо»",
                                    ),
                                ],
                            ),
                            html.Div(id="modal-validation", className="settings-validation"),
                        ],
                    ),
                    html.Div(
                        className="modal-footer",
                        children=[
                            html.Button("Удалить агента", id="btn-modal-delete", className="btn-danger"),
                            html.Button("Отмена", id="btn-modal-cancel", className="btn-secondary"),
                            html.Button("Записать в список", id="btn-modal-save", className="btn-primary"),
                        ],
                    ),
                ],
            ),
        ],
    )


def build_merge_modal() -> html.Div:
    """Modal dialog for merging selected agent profiles."""
    return html.Div(
        id="merge-modal-overlay",
        className="modal-overlay hidden",
        children=[
            html.Div(
                className="modal-dialog",
                children=[
                    html.Div(
                        className="modal-header",
                        children=[
                            html.H3("Объединение профилей"),
                            html.Button("×", id="btn-merge-close", className="modal-close-btn", n_clicks=0),
                        ],
                    ),
                    html.Div(
                        className="modal-body settings-form",
                        children=[
                            html.P(
                                "Выберите профиль, который останется основным. "
                                "Его agent_key, имя, команда и активность сохранятся; "
                                "алиасы 1С и Битрикс будут объединены из всех выбранных профилей.",
                                className="tab-note",
                            ),
                            html.Div(
                                className="form-row",
                                children=[
                                    html.Label("Целевой профиль", className="form-label"),
                                    dcc.Dropdown(
                                        id="merge-target-key",
                                        searchable=True,
                                        clearable=False,
                                        placeholder="Выберите профиль",
                                    ),
                                ],
                            ),
                            html.Div(id="merge-preview", className="merge-preview"),
                            html.Div(id="merge-validation", className="settings-validation"),
                        ],
                    ),
                    html.Div(
                        className="modal-footer",
                        children=[
                            html.Button("Отмена", id="btn-merge-cancel", className="btn-secondary"),
                            html.Button("Объединить", id="btn-merge-confirm", className="btn-primary"),
                        ],
                    ),
                ],
            ),
        ],
    )
