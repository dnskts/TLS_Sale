"""Callbacks for the Settings tab (agents dictionary editor)."""

from __future__ import annotations

import json
import traceback
from typing import Any

from dash import Input, Output, State, callback, ctx, html, no_update

from app.data_access import clear_cache
from app.layout import status_banner_content
from app.settings_io import (
    apply_bulk_agent_updates,
    delete_agents_by_keys,
    load_settings,
    merge_agent_profiles,
    normalize_agents,
    save_agents,
    validate_agents,
)
from parser.pipeline import run_pipeline


def _agents_snapshot(agents: list[dict[str, Any]] | None) -> str:
    if agents is None:
        return ""
    return json.dumps(normalize_agents(agents), ensure_ascii=False, sort_keys=True)


def _is_dirty(draft: list | None, loaded: list | None) -> bool:
    return _agents_snapshot(draft) != _agents_snapshot(loaded)


def _parse_lines(text: str | None) -> list[str]:
    if not text:
        return []
    return [line.strip() for line in text.splitlines() if line.strip()]


def _lines_from_list(values: list[str] | None) -> str:
    return "\n".join(values or [])


def _team_options(agents: list[dict[str, Any]] | None) -> list[dict[str, str]]:
    settings = load_settings()
    teams: set[str] = set()
    for key in settings.get("department_map", {}):
        if key:
            teams.add(str(key))
    for value in settings.get("department_map", {}).values():
        if value:
            teams.add(str(value))
    for agent in agents or []:
        team = agent.get("team")
        if team:
            teams.add(str(team))
    return [{"label": team, "value": team} for team in sorted(teams)]


def _filter_agents(
    agents: list[dict[str, Any]] | None,
    search: str | None,
    active_filter: str | None,
) -> list[dict[str, Any]]:
    if not agents:
        return []
    result = list(agents)
    active_filter = active_filter or "all"
    if active_filter == "active":
        result = [agent for agent in result if agent.get("is_active")]
    elif active_filter == "inactive":
        result = [agent for agent in result if not agent.get("is_active")]

    query = (search or "").strip().lower()
    if not query:
        return result

    filtered: list[dict[str, Any]] = []
    for agent in result:
        haystack = " ".join(
            [
                str(agent.get("agent_key", "")),
                str(agent.get("name_display", "")),
                str(agent.get("team", "")),
                " ".join(agent.get("names_1c") or []),
                " ".join(agent.get("names_bitrix") or []),
            ]
        ).lower()
        if query in haystack:
            filtered.append(agent)
    return filtered


def _aliases_preview(names: list[str] | None, limit: int = 2) -> str:
    if not names:
        return "—"
    if len(names) <= limit:
        return ", ".join(names)
    return ", ".join(names[:limit]) + f" (+{len(names) - limit})"


def _agents_to_table_rows(agents: list[dict[str, Any]]) -> list[dict[str, Any]]:
    rows = []
    for agent in sorted(agents, key=lambda item: (item.get("name_display") or item.get("agent_key") or "").lower()):
        rows.append(
            {
                "is_active": bool(agent.get("is_active", True)),
                "name_display": agent.get("name_display") or "—",
                "team": agent.get("team") or "",
                "names_1c_preview": _aliases_preview(agent.get("names_1c")),
                "names_bitrix_preview": _aliases_preview(agent.get("names_bitrix")),
                "agent_key": agent.get("agent_key", ""),
            }
        )
    return rows


def _merge_table_into_draft(
    table_data: list[dict[str, Any]] | None,
    draft: list[dict[str, Any]] | None,
) -> list[dict[str, Any]] | None:
    if not table_data or not draft:
        return None
    updated = [dict(item) for item in draft]
    by_key = {item.get("agent_key"): index for index, item in enumerate(updated)}
    changed = False
    for row in table_data:
        key = row.get("agent_key")
        if key not in by_key:
            continue
        index = by_key[key]
        new_active = bool(row.get("is_active"))
        new_team = str(row.get("team") or "").strip()
        if updated[index].get("is_active") != new_active:
            updated[index]["is_active"] = new_active
            changed = True
        if (updated[index].get("team") or "") != new_team:
            updated[index]["team"] = new_team
            changed = True
    return updated if changed else None


def _validation_nodes(errors: list[str], warnings: list[str]) -> list:
    nodes = [html.Div(error, className="validation-error") for error in errors]
    nodes.extend(html.Div(warn, className="validation-warning") for warn in warnings)
    return nodes


def _clicked(value) -> bool:
    return bool(value and value > 0)


def _selected_keys(selected_rows: list[int] | None, table_data: list[dict[str, Any]] | None) -> list[str]:
    if not selected_rows or not table_data:
        return []
    keys: list[str] = []
    for index in selected_rows:
        if 0 <= index < len(table_data):
            key = table_data[index].get("agent_key")
            if key:
                keys.append(str(key))
    return keys


def _merge_preview_block(draft: list[dict[str, Any]], keys: list[str], target_key: str | None) -> html.Div:
    if not keys or len(keys) < 2:
        return html.Div("Выберите минимум 2 профиля для объединения.", className="tab-note")
    selected = [agent for agent in draft if agent.get("agent_key") in keys]
    names_1c: list[str] = []
    names_bitrix: list[str] = []
    for agent in selected:
        names_1c.extend(agent.get("names_1c") or [])
        names_bitrix.extend(agent.get("names_bitrix") or [])
    names_1c = _normalize_name_list_for_preview(names_1c)
    names_bitrix = _normalize_name_list_for_preview(names_bitrix)
    target_label = target_key or "—"
    target_agent = next((agent for agent in selected if agent.get("agent_key") == target_key), None)
    if target_agent:
        target_label = target_agent.get("name_display") or target_key
    return html.Div(
        [
            html.P(f"Объединяется профилей: {len(keys)}"),
            html.P(f"Целевой профиль: {target_label}"),
            html.P(f"Итого алиасов 1С: {len(names_1c)}, Битрикс: {len(names_bitrix)}"),
        ],
        className="merge-preview-inner",
    )


def _normalize_name_list_for_preview(values: list[str]) -> list[str]:
    seen: set[str] = set()
    result: list[str] = []
    for item in values:
        trimmed = str(item).strip()
        if trimmed and trimmed not in seen:
            seen.add(trimmed)
            result.append(trimmed)
    return result


@callback(
    Output("store-agents-draft", "data"),
    Output("store-agents-loaded", "data"),
    Input("main-tabs", "value"),
    State("store-agents-loaded", "data"),
    prevent_initial_call=False,
)
def init_agents_stores(active_tab: str, loaded: list | None):
    if active_tab != "tab-settings":
        return no_update, no_update
    if loaded is not None:
        return no_update, no_update
    settings = load_settings()
    agents = settings.get("agents") or []
    return agents, agents


@callback(
    Output("settings-modal-overlay", "className", allow_duplicate=True),
    Output("merge-modal-overlay", "className", allow_duplicate=True),
    Input("main-tabs", "value"),
    prevent_initial_call=True,
)
def hide_modals_on_tab_leave(active_tab: str):
    if active_tab != "tab-settings":
        return "modal-overlay hidden", "modal-overlay hidden"
    return no_update, no_update


@callback(
    Output("settings-dirty-indicator", "children"),
    Output("settings-dirty-indicator", "className"),
    Input("store-agents-draft", "data"),
    State("store-agents-loaded", "data"),
)
def update_dirty_indicator(draft, loaded):
    if _is_dirty(draft, loaded):
        return "Есть несохранённые изменения", "settings-dirty"
    return "", "settings-dirty hidden"


@callback(
    Output("settings-agents-datatable", "data"),
    Output("settings-agents-datatable", "dropdown"),
    Output("settings-agents-count", "children"),
    Input("main-tabs", "value"),
    Input("store-agents-draft", "data"),
    Input("settings-search", "value"),
    Input("settings-active-filter", "value"),
)
def render_agents_datatable(active_tab, draft, search, active_filter):
    if active_tab != "tab-settings":
        return no_update, no_update, no_update
    filtered = _filter_agents(draft, search, active_filter)
    total = len(draft or [])
    shown = len(filtered)
    count_text = f"Показано {shown} из {total} агентов."
    if not filtered:
        return [], {"team": {"options": _team_options(draft)}}, count_text
    return (
        _agents_to_table_rows(filtered),
        {"team": {"options": _team_options(draft)}},
        count_text,
    )


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Input("settings-agents-datatable", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def sync_table_edits_to_draft(table_data, draft):
    merged = _merge_table_into_draft(table_data, draft)
    if merged is None:
        return no_update
    return merged


@callback(
    Output("settings-selected-count", "children"),
    Input("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
)
def update_selected_count(selected_rows, table_data):
    count = len(_selected_keys(selected_rows, table_data))
    if count == 0:
        return " — выбрано: 0"
    return f" — выбрано: {count}"


@callback(
    Output("bulk-team", "options"),
    Input("main-tabs", "value"),
    Input("store-agents-draft", "data"),
)
def bulk_team_options(active_tab, draft):
    if active_tab != "tab-settings":
        return no_update
    return _team_options(draft)


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("settings-agents-datatable", "selected_rows", allow_duplicate=True),
    Output("settings-message", "children", allow_duplicate=True),
    Input("btn-bulk-apply", "n_clicks"),
    State("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
    State("bulk-active-action", "value"),
    State("bulk-team", "value"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def bulk_apply_to_selected(n_clicks, selected_rows, table_data, active_action, bulk_team, draft):
    if not _clicked(n_clicks):
        return no_update, no_update, no_update

    keys = _selected_keys(selected_rows, table_data)
    if not keys:
        return (
            no_update,
            no_update,
            html.Div("Выберите хотя бы одного агента.", className="settings-alert settings-alert-warning"),
        )

    is_active = None
    if active_action == "activate":
        is_active = True
    elif active_action == "deactivate":
        is_active = False

    team = bulk_team if bulk_team else None
    if is_active is None and team is None:
        return (
            no_update,
            no_update,
            html.Div(
                "Укажите изменение активности или команды.",
                className="settings-alert settings-alert-warning",
            ),
        )

    updated = apply_bulk_agent_updates(draft or [], keys, is_active=is_active, team=team)
    return (
        updated,
        [],
        html.Div(
            f"Изменения применены к {len(keys)} агент(ам). Не забудьте сохранить.",
            className="settings-alert settings-alert-info",
        ),
    )


@callback(
    Output("confirm-bulk-delete", "message"),
    Output("confirm-bulk-delete", "displayed"),
    Output("settings-message", "children", allow_duplicate=True),
    Input("btn-bulk-delete", "n_clicks"),
    State("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
    prevent_initial_call=True,
)
def ask_bulk_delete(n_clicks, selected_rows, table_data):
    if not _clicked(n_clicks):
        return no_update, no_update, no_update

    keys = _selected_keys(selected_rows, table_data)
    if not keys:
        return (
            no_update,
            no_update,
            html.Div("Выберите агентов для удаления.", className="settings-alert settings-alert-warning"),
        )
    return (
        f"Удалить {len(keys)} агент(ов) из справочника? Строки продаж станут «Не в справочнике» до перепривязки.",
        True,
        no_update,
    )


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("settings-agents-datatable", "selected_rows", allow_duplicate=True),
    Output("settings-message", "children", allow_duplicate=True),
    Input("confirm-bulk-delete", "submit_n_clicks"),
    State("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def bulk_delete_confirmed(submit_clicks, selected_rows, table_data, draft):
    if not _clicked(submit_clicks):
        return no_update, no_update, no_update

    keys = _selected_keys(selected_rows, table_data)
    if not keys:
        return no_update, no_update, no_update

    updated = delete_agents_by_keys(draft or [], keys)
    return (
        updated,
        [],
        html.Div(
            f"Удалено из списка: {len(keys)}. Не забудьте сохранить.",
            className="settings-alert settings-alert-info",
        ),
    )


@callback(
    Output("merge-modal-overlay", "className"),
    Output("merge-target-key", "options"),
    Output("merge-target-key", "value"),
    Output("store-merge-keys", "data"),
    Output("settings-message", "children", allow_duplicate=True),
    Input("btn-bulk-merge", "n_clicks"),
    State("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def open_merge_modal(n_clicks, selected_rows, table_data, draft):
    if not _clicked(n_clicks):
        return (no_update,) * 5

    keys = _selected_keys(selected_rows, table_data)
    if len(keys) < 2:
        return (
            no_update,
            no_update,
            no_update,
            no_update,
            html.Div(
                "Для объединения выберите минимум 2 профиля.",
                className="settings-alert settings-alert-warning",
            ),
        )

    draft = draft or []
    options = []
    for key in keys:
        agent = next((item for item in draft if item.get("agent_key") == key), None)
        label = (agent.get("name_display") if agent else None) or key
        options.append({"label": f"{label} ({key})", "value": key})

    default_target = keys[0]
    return "modal-overlay", options, default_target, keys, no_update


@callback(
    Output("merge-preview", "children"),
    Input("merge-target-key", "value"),
    State("store-merge-keys", "data"),
    State("store-agents-draft", "data"),
)
def update_merge_preview(target_key, merge_keys, draft):
    if not merge_keys:
        return ""
    return _merge_preview_block(draft or [], merge_keys, target_key)


@callback(
    Output("merge-modal-overlay", "className", allow_duplicate=True),
    Input("btn-merge-close", "n_clicks"),
    Input("btn-merge-cancel", "n_clicks"),
    prevent_initial_call=True,
)
def close_merge_modal(close_clicks, cancel_clicks):
    triggered = ctx.triggered_id
    if triggered == "btn-merge-close" and _clicked(close_clicks):
        return "modal-overlay hidden"
    if triggered == "btn-merge-cancel" and _clicked(cancel_clicks):
        return "modal-overlay hidden"
    return no_update


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("merge-modal-overlay", "className", allow_duplicate=True),
    Output("settings-agents-datatable", "selected_rows", allow_duplicate=True),
    Output("merge-validation", "children"),
    Output("settings-message", "children", allow_duplicate=True),
    Input("btn-merge-confirm", "n_clicks"),
    State("merge-target-key", "value"),
    State("store-merge-keys", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def confirm_merge(n_clicks, target_key, merge_keys, draft):
    if not _clicked(n_clicks):
        return (no_update,) * 5

    if not merge_keys or len(merge_keys) < 2 or not target_key:
        return (
            no_update,
            no_update,
            no_update,
            [html.Div("Выберите минимум 2 профиля и целевой agent_key.", className="validation-error")],
            no_update,
        )

    try:
        updated = merge_agent_profiles(draft or [], merge_keys, target_key)
    except ValueError as exc:
        return no_update, no_update, no_update, [html.Div(str(exc), className="validation-error")], no_update

    errors, _warnings = validate_agents(updated)
    if errors:
        return (
            no_update,
            no_update,
            no_update,
            _validation_nodes(errors, []),
            no_update,
        )

    removed = len(merge_keys) - 1
    return (
        updated,
        "modal-overlay hidden",
        [],
        "",
        html.Div(
            f"Объединено {len(merge_keys)} профилей в «{target_key}». Удалено дубликатов: {removed}. Сохраните изменения.",
            className="settings-alert settings-alert-success",
        ),
    )


@callback(
    Output("settings-validation", "children", allow_duplicate=True),
    Input("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def show_validation_on_draft_change(draft):
    errors, warnings = validate_agents(draft or [])
    nodes = _validation_nodes(errors, warnings)
    if not nodes:
        return ""
    return html.Div(
        [html.Strong("Проверка справочника:"), html.Div(nodes)],
        className="settings-alert settings-alert-warning",
    )


@callback(
    Output("settings-general-info", "children"),
    Input("main-tabs", "value"),
)
def render_general_info(active_tab: str):
    if active_tab != "tab-settings":
        return no_update
    settings = load_settings()
    app_cfg = settings.get("app", {})
    return html.Ul(
        [
            html.Li(f"Заголовок: {app_cfg.get('title', '—')}"),
            html.Li(f"Host: {app_cfg.get('host', '—')}"),
            html.Li(f"Port: {app_cfg.get('port', '—')}"),
            html.Li(f"URL: {app_cfg.get('dash_url', '—')}"),
        ]
    )


def _modal_edit_payload(draft: list[dict[str, Any]], agent_key: str):
    agent = next((item for item in draft if item.get("agent_key") == agent_key), None)
    if not agent:
        return (no_update,) * 14
    return (
        "modal-overlay",
        f"Редактирование: {agent.get('name_display') or agent_key}",
        agent_key,
        True,
        agent.get("name_display", ""),
        agent.get("team"),
        _team_options(draft),
        ["active"] if agent.get("is_active", True) else [],
        _lines_from_list(agent.get("names_1c")),
        _lines_from_list(agent.get("names_bitrix")),
        {"display": "inline-block"},
        "edit",
        agent_key,
        "",
    )


@callback(
    Output("settings-modal-overlay", "className"),
    Output("settings-modal-title", "children"),
    Output("modal-agent-key", "value"),
    Output("modal-agent-key", "disabled"),
    Output("modal-name-display", "value"),
    Output("modal-team", "value"),
    Output("modal-team", "options"),
    Output("modal-is-active", "value"),
    Output("modal-names-1c", "value"),
    Output("modal-names-bitrix", "value"),
    Output("btn-modal-delete", "style"),
    Output("store-settings-modal-mode", "data"),
    Output("store-settings-modal-key", "data"),
    Output("modal-validation", "children"),
    Output("settings-message", "children", allow_duplicate=True),
    Input("btn-settings-add", "n_clicks"),
    Input("btn-settings-edit-selected", "n_clicks"),
    Input("btn-modal-close", "n_clicks"),
    Input("btn-modal-cancel", "n_clicks"),
    State("settings-agents-datatable", "selected_rows"),
    State("settings-agents-datatable", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def open_close_modal(add_clicks, edit_selected_clicks, close_clicks, cancel_clicks, selected_rows, table_data, draft):
    triggered = ctx.triggered_id
    draft = draft or []
    empty_msg = no_update

    if triggered in ("btn-modal-close", "btn-modal-cancel"):
        if not _clicked(close_clicks if triggered == "btn-modal-close" else cancel_clicks):
            return (no_update,) * 14 + (no_update,)
        return ("modal-overlay hidden",) + (no_update,) * 13 + (empty_msg,)

    if triggered == "btn-settings-add":
        if not _clicked(add_clicks):
            return (no_update,) * 14 + (no_update,)
        return (
            "modal-overlay",
            "Новый агент",
            "",
            False,
            "",
            None,
            _team_options(draft),
            ["active"],
            "",
            "",
            {"display": "none"},
            "add",
            None,
            "",
            empty_msg,
        )

    if triggered == "btn-settings-edit-selected":
        if not _clicked(edit_selected_clicks):
            return (no_update,) * 14 + (no_update,)
        if not selected_rows or not table_data or len(selected_rows) != 1:
            return (no_update,) * 14 + (
                html.Div(
                    "Выберите ровно одну строку для редактирования алиасов.",
                    className="settings-alert settings-alert-warning",
                ),
            )
        row = table_data[selected_rows[0]]
        return _modal_edit_payload(draft, row.get("agent_key", "")) + (empty_msg,)

    return (no_update,) * 14 + (no_update,)


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("settings-modal-overlay", "className", allow_duplicate=True),
    Output("modal-validation", "children", allow_duplicate=True),
    Input("btn-modal-save", "n_clicks"),
    State("store-settings-modal-mode", "data"),
    State("store-settings-modal-key", "data"),
    State("modal-agent-key", "value"),
    State("modal-name-display", "value"),
    State("modal-team", "value"),
    State("modal-is-active", "value"),
    State("modal-names-1c", "value"),
    State("modal-names-bitrix", "value"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def save_modal_to_draft(
    n_clicks,
    mode,
    original_key,
    agent_key,
    name_display,
    team,
    is_active,
    names_1c_text,
    names_bitrix_text,
    draft,
):
    if not _clicked(n_clicks):
        return no_update, no_update, no_update

    draft = list(draft or [])
    record = {
        "agent_key": (agent_key or "").strip(),
        "name_display": (name_display or "").strip(),
        "team": (team or "").strip(),
        "is_active": "active" in (is_active or []),
        "names_1c": _parse_lines(names_1c_text),
        "names_bitrix": _parse_lines(names_bitrix_text),
    }
    normalized = normalize_agents([record])[0]
    candidate = (
        [item for item in draft if item.get("agent_key") != original_key] + [normalized]
        if mode == "edit"
        else draft + [normalized]
    )
    errors, warnings = validate_agents(candidate)
    messages = _validation_nodes(errors, warnings)
    if errors:
        return no_update, no_update, messages

    if mode == "edit" and original_key:
        updated: list[dict[str, Any]] = []
        replaced = False
        for item in draft:
            if item.get("agent_key") == original_key:
                updated.append(normalized)
                replaced = True
            else:
                updated.append(item)
        if not replaced:
            updated.append(normalized)
        draft = updated
    else:
        if any(item.get("agent_key") == normalized["agent_key"] for item in draft):
            return (
                no_update,
                no_update,
                [html.Div(f"agent_key «{normalized['agent_key']}» уже существует.", className="validation-error")],
            )
        draft.append(normalized)

    return draft, "modal-overlay hidden", ""


@callback(
    Output("confirm-delete-agent", "displayed"),
    Input("btn-modal-delete", "n_clicks"),
    State("store-settings-modal-mode", "data"),
    prevent_initial_call=True,
)
def ask_delete_agent(n_clicks, mode):
    if _clicked(n_clicks) and mode == "edit":
        return True
    return no_update


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("settings-modal-overlay", "className", allow_duplicate=True),
    Input("confirm-delete-agent", "submit_n_clicks"),
    State("store-settings-modal-key", "data"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def delete_agent_from_draft(submit_clicks, agent_key, draft):
    if not _clicked(submit_clicks) or not agent_key:
        return no_update, no_update
    draft = [item for item in (draft or []) if item.get("agent_key") != agent_key]
    return draft, "modal-overlay hidden"


@callback(
    Output("confirm-discard-settings", "displayed"),
    Input("btn-settings-reload", "n_clicks"),
    State("store-agents-draft", "data"),
    State("store-agents-loaded", "data"),
    prevent_initial_call=True,
)
def ask_reload_if_dirty(n_clicks, draft, loaded):
    if _clicked(n_clicks) and _is_dirty(draft, loaded):
        return True
    return no_update


@callback(
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("store-agents-loaded", "data", allow_duplicate=True),
    Output("settings-message", "children"),
    Input("btn-settings-reload", "n_clicks"),
    Input("confirm-discard-settings", "submit_n_clicks"),
    State("store-agents-draft", "data"),
    State("store-agents-loaded", "data"),
    prevent_initial_call=True,
)
def reload_agents_from_disk(btn_reload, confirm_submit, draft, loaded):
    triggered = ctx.triggered_id
    if triggered == "btn-settings-reload" and _is_dirty(draft, loaded):
        return no_update, no_update, no_update
    if triggered == "btn-settings-reload" and not _clicked(btn_reload):
        return no_update, no_update, no_update
    if triggered == "confirm-discard-settings" and not _clicked(confirm_submit):
        return no_update, no_update, no_update

    settings = load_settings()
    agents = settings.get("agents") or []
    return agents, agents, html.Div("Справочник перечитан с диска.", className="settings-alert settings-alert-info")


@callback(
    Output("store-agents-loaded", "data", allow_duplicate=True),
    Output("store-agents-draft", "data", allow_duplicate=True),
    Output("settings-message", "children", allow_duplicate=True),
    Output("settings-validation", "children"),
    Input("btn-settings-save", "n_clicks"),
    State("store-agents-draft", "data"),
    prevent_initial_call=True,
)
def save_agents_to_disk(n_clicks, draft):
    if not _clicked(n_clicks):
        return no_update, no_update, no_update, no_update

    draft = draft or []
    errors, warnings = validate_agents(draft)
    validation_nodes = _validation_nodes(errors, warnings)
    if errors:
        return (
            no_update,
            no_update,
            html.Div("Исправьте ошибки перед сохранением.", className="settings-alert settings-alert-error"),
            html.Div(
                [html.Strong("Ошибки сохранения:"), html.Div(validation_nodes)],
                className="settings-alert settings-alert-error",
            ),
        )

    try:
        count = save_agents(draft)
    except (OSError, ValueError) as exc:
        return (
            no_update,
            no_update,
            html.Div(f"Ошибка сохранения: {exc}", className="settings-alert settings-alert-error"),
            validation_nodes,
        )

    message = html.Div(
        f"Сохранено в settings.json ({count} агентов). Резервная копия создана в data/backups/.",
        className="settings-alert settings-alert-success",
    )
    settings = load_settings()
    loaded = settings.get("agents") or []
    validation_block = ""
    if warnings:
        validation_block = html.Div(
            [html.Strong("Сохранено с предупреждениями:"), html.Div(validation_nodes)],
            className="settings-alert settings-alert-warning",
        )
    return loaded, loaded, message, validation_block


@callback(
    Output("settings-message", "children", allow_duplicate=True),
    Output("status-banner", "children"),
    Output("status-banner", "className"),
    Input("btn-settings-apply", "n_clicks"),
    prevent_initial_call=True,
)
def apply_settings_to_pipeline(n_clicks):
    if not _clicked(n_clicks):
        return no_update, no_update, no_update

    try:
        report = run_pipeline()
        clear_cache()
        counts = report.get("counts", {})
        message = html.Div(
            [
                html.Strong("Данные пересобраны. "),
                html.Span(
                    f"Unified: {counts.get('sales_unified', 0)}, "
                    f"1С: {counts.get('operations_1c', 0)}, "
                    f"Битрикс: {counts.get('deals_bitrix', 0)}."
                ),
            ],
            className="settings-alert settings-alert-success",
        )
        banner_children, banner_class = status_banner_content()
        return message, banner_children, banner_class
    except Exception:
        return (
            html.Div(
                [
                    html.Strong("Ошибка пересборки данных"),
                    html.Pre(traceback.format_exc(), style={"whiteSpace": "pre-wrap", "fontSize": "12px"}),
                ],
                className="settings-alert settings-alert-error",
            ),
            no_update,
            no_update,
        )
