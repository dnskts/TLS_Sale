"""Apply sidebar filters to sales_unified."""

from __future__ import annotations

from typing import Any

import pandas as pd

from parser.settings_loader import get_settings


def _agent_keys_by_activity(settings: dict[str, Any]) -> tuple[set[str], set[str]]:
    active: set[str] = set()
    inactive: set[str] = set()
    for agent in settings.get("agents", []):
        key = agent.get("agent_key")
        if not key:
            continue
        if agent.get("is_active", True):
            active.add(key)
        else:
            inactive.add(key)
    return active, inactive


def apply_sales_filters(frame: pd.DataFrame, filters: dict[str, Any] | None) -> pd.DataFrame:
    """Filter sales_unified according to store-filters payload."""
    if frame.empty:
        return frame

    filters = filters or {}
    settings = get_settings()
    result = frame.copy()

    if "date" in result.columns:
        result["date"] = pd.to_datetime(result["date"], errors="coerce")

    date_from = filters.get("date_from")
    date_to = filters.get("date_to")
    if date_from:
        result = result[result["date"] >= pd.to_datetime(date_from)]
    if date_to:
        result = result[result["date"] <= pd.to_datetime(date_to)]

    source = filters.get("source", "all")
    if source and source != "all":
        result = result[result["source"] == source]

    teams = filters.get("teams") or []
    if teams:
        result = result[result["agent_team"].isin(teams)]

    selected_agents = filters.get("agents") or []
    if selected_agents:
        result = result[result["agent_key"].isin(selected_agents)]
    else:
        active_keys, inactive_keys = _agent_keys_by_activity(settings)
        allowed = set(active_keys)
        if filters.get("show_inactive_agents"):
            allowed.update(inactive_keys)
        mask = result["agent_key"].isin(allowed)
        if filters.get("show_unknown_agents"):
            unknown_mask = result["agent_key"].astype(str).str.startswith("unknown:")
            mask = mask | unknown_mask
        else:
            mask = mask & ~result["agent_key"].astype(str).str.startswith("unknown:")
        result = result[mask]

    client = filters.get("client")
    if client:
        result = result[result["client"] == client]

    partner = filters.get("partner")
    if partner:
        result = result[result["partner_or_supplier"] == partner]

    for filter_key, column in [
        ("categories", "category"),
        ("channels", "channel"),
        ("card_types", "card_type"),
        ("client_types", "client_type"),
        ("request_types", "request_type"),
    ]:
        values = filters.get(filter_key) or []
        if values and column in result.columns:
            result = result[result[column].isin(values)]

    return result


def apply_deals_bitrix_filters(frame: pd.DataFrame, filters: dict[str, Any] | None) -> pd.DataFrame:
    """Filter deals_bitrix for funnel tab (period by deal_created_at, Bitrix dimensions only)."""
    if frame.empty:
        return frame

    filters = filters or {}
    settings = get_settings()
    result = frame.copy()

    if "deal_created_at" in result.columns:
        result["deal_created_at"] = pd.to_datetime(result["deal_created_at"], errors="coerce")

    date_from = filters.get("date_from")
    date_to = filters.get("date_to")
    if date_from:
        result = result[result["deal_created_at"] >= pd.to_datetime(date_from)]
    if date_to:
        result = result[result["deal_created_at"] <= pd.to_datetime(date_to)]

    teams = filters.get("teams") or []
    if teams and "agent_team" in result.columns:
        result = result[result["agent_team"].isin(teams)]

    selected_agents = filters.get("agents") or []
    if selected_agents and "agent_key" in result.columns:
        result = result[result["agent_key"].isin(selected_agents)]
    elif "agent_key" in result.columns:
        active_keys, inactive_keys = _agent_keys_by_activity(settings)
        allowed = set(active_keys)
        if filters.get("show_inactive_agents"):
            allowed.update(inactive_keys)
        mask = result["agent_key"].isin(allowed)
        if filters.get("show_unknown_agents"):
            unknown_mask = result["agent_key"].astype(str).str.startswith("unknown:")
            mask = mask | unknown_mask
        else:
            mask = mask & ~result["agent_key"].astype(str).str.startswith("unknown:")
        result = result[mask]

    for filter_key, column in [
        ("categories", "category"),
        ("channels", "channel"),
        ("request_types", "request_type"),
    ]:
        values = filters.get(filter_key) or []
        if values and column in result.columns:
            result = result[result[column].isin(values)]

    return result
