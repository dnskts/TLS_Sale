"""Load, validate, backup and atomically save settings.json."""

from __future__ import annotations

import json
import os
import re
from datetime import datetime
from pathlib import Path
from typing import Any

from parser.settings_loader import clear_settings_cache, project_root, settings_path

BACKUP_DIR_NAME = "data/backups"
MAX_BACKUPS = 20
AGENT_KEY_PATTERN = re.compile(r"^[a-z0-9_]+$")


def _backup_dir() -> Path:
    path = project_root() / BACKUP_DIR_NAME
    path.mkdir(parents=True, exist_ok=True)
    return path


def load_settings() -> dict[str, Any]:
    """Read settings.json from project root."""
    path = settings_path()
    if not path.is_file():
        raise FileNotFoundError(f"Не найден файл настроек: {path}")
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def _trim_name(value: Any) -> str:
    if value is None:
        return ""
    return str(value).replace("\xa0", " ").strip()


def _normalize_name_list(values: Any) -> list[str]:
    if not values:
        return []
    result: list[str] = []
    seen: set[str] = set()
    for item in values:
        trimmed = _trim_name(item)
        if not trimmed or trimmed in seen:
            continue
        seen.add(trimmed)
        result.append(trimmed)
    return result


def _parse_is_active(value: Any, *, default: bool = True) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return default
    return str(value).lower() in ("true", "1", "yes")


def normalize_agent(agent: dict[str, Any]) -> dict[str, Any]:
    """Normalize a single agent record."""
    return {
        "agent_key": _trim_name(agent.get("agent_key")),
        "name_display": _trim_name(agent.get("name_display")),
        "names_1c": _normalize_name_list(agent.get("names_1c")),
        "names_bitrix": _normalize_name_list(agent.get("names_bitrix")),
        "team": _trim_name(agent.get("team")),
        "is_active": _parse_is_active(agent.get("is_active", True)),
    }


def normalize_agents(agents: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Normalize all agents (trim, dedupe names, drop empty aliases)."""
    return [normalize_agent(agent) for agent in agents]


def validate_agents(agents: list[dict[str, Any]]) -> tuple[list[str], list[str]]:
    """
    Validate agents list.

    Returns (errors, warnings). Errors block save; warnings are informational.
    """
    errors: list[str] = []
    warnings: list[str] = []
    normalized = normalize_agents(agents)

    keys_seen: dict[str, int] = {}
    alias_owner: dict[tuple[str, str], str] = {}

    for index, agent in enumerate(normalized, start=1):
        label = agent.get("agent_key") or agent.get("name_display") or f"строка {index}"
        agent_key = agent.get("agent_key", "")

        if not agent_key:
            errors.append(f"{label}: поле agent_key обязательно.")
        elif agent_key in keys_seen:
            errors.append(f"agent_key «{agent_key}» дублируется (строки {keys_seen[agent_key]} и {index}).")
        else:
            keys_seen[agent_key] = index
            if not AGENT_KEY_PATTERN.match(agent_key):
                warnings.append(
                    f"«{agent_key}»: рекомендуется snake_case латиницей [a-z0-9_]."
                )

        if not agent.get("name_display"):
            errors.append(f"«{agent_key or label}»: отображаемое имя обязательно.")

        if not agent.get("team"):
            errors.append(f"«{agent_key or label}»: команда обязательна.")

        if not isinstance(agent.get("is_active"), bool):
            errors.append(f"«{agent_key or label}»: is_active должно быть true/false.")

        if not agent.get("names_1c"):
            warnings.append(f"«{agent_key or label}»: нет алиасов 1С — строки могут попасть в «Не в справочнике».")
        if not agent.get("names_bitrix"):
            warnings.append(f"«{agent_key or label}»: нет алиасов Битрикс.")

        for alias in agent.get("names_1c", []):
            key = ("1c", alias)
            if key in alias_owner and alias_owner[key] != agent_key:
                errors.append(
                    f"Алиас 1С «{alias}» уже у агента «{alias_owner[key]}», "
                    f"нельзя назначить «{agent_key}»."
                )
            else:
                alias_owner[key] = agent_key

        for alias in agent.get("names_bitrix", []):
            key = ("bitrix", alias)
            if key in alias_owner and alias_owner[key] != agent_key:
                errors.append(
                    f"Алиас Битрикс «{alias}» уже у агента «{alias_owner[key]}», "
                    f"нельзя назначить «{agent_key}»."
                )
            else:
                alias_owner[key] = agent_key

    return errors, warnings


def apply_bulk_agent_updates(
    agents: list[dict[str, Any]],
    keys: list[str],
    *,
    is_active: bool | None = None,
    team: str | None = None,
) -> list[dict[str, Any]]:
    """Apply is_active and/or team to agents whose agent_key is in keys."""
    if not keys:
        return list(agents)
    key_set = set(keys)
    team_value = _trim_name(team) if team is not None else None
    updated: list[dict[str, Any]] = []
    for agent in agents:
        record = dict(agent)
        if record.get("agent_key") not in key_set:
            updated.append(record)
            continue
        if is_active is not None:
            record["is_active"] = is_active
        if team_value is not None:
            record["team"] = team_value
        updated.append(record)
    return normalize_agents(updated)


def delete_agents_by_keys(agents: list[dict[str, Any]], keys: list[str]) -> list[dict[str, Any]]:
    """Remove agents with given agent_key values."""
    if not keys:
        return list(agents)
    key_set = set(keys)
    return normalize_agents([agent for agent in agents if agent.get("agent_key") not in key_set])


def merge_agent_profiles(
    agents: list[dict[str, Any]],
    keys: list[str],
    target_key: str,
) -> list[dict[str, Any]]:
    """
    Merge selected agent profiles into target_key.

    Target keeps agent_key, name_display, team, is_active.
    names_1c and names_bitrix are unioned (deduped) from all selected profiles.
    Other selected profiles are removed.
    """
    target_key = _trim_name(target_key)
    key_set = set(_trim_name(key) for key in keys if _trim_name(key))
    if not target_key or target_key not in key_set or len(key_set) < 2:
        raise ValueError("Для объединения нужно минимум 2 профиля и корректный целевой agent_key.")

    selected = [agent for agent in agents if agent.get("agent_key") in key_set]
    if len(selected) < 2:
        raise ValueError("Не найдено достаточно профилей для объединения.")

    target = next((agent for agent in selected if agent.get("agent_key") == target_key), None)
    if target is None:
        raise ValueError(f"Целевой профиль «{target_key}» не найден среди выбранных.")

    names_1c: list[str] = []
    names_bitrix: list[str] = []
    for agent in selected:
        names_1c.extend(agent.get("names_1c") or [])
        names_bitrix.extend(agent.get("names_bitrix") or [])

    merged_target = normalize_agent(
        {
            **target,
            "names_1c": names_1c,
            "names_bitrix": names_bitrix,
        }
    )

    remove_keys = key_set - {target_key}
    result: list[dict[str, Any]] = []
    for agent in agents:
        key = agent.get("agent_key")
        if key in remove_keys:
            continue
        if key == target_key:
            result.append(merged_target)
        else:
            result.append(dict(agent))
    return normalize_agents(result)


def backup_settings() -> Path:
    """Copy settings.json to data/backups/settings_YYYYMMDD_HHMMSS.json."""
    (project_root() / BACKUP_DIR_NAME).mkdir(parents=True, exist_ok=True)
    source = settings_path()
    if not source.is_file():
        raise FileNotFoundError(f"Не найден файл для резервной копии: {source}")

    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = _backup_dir() / f"settings_{stamp}.json"
    backup_path.write_bytes(source.read_bytes())

    backups = sorted(_backup_dir().glob("settings_*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    for old_backup in backups[MAX_BACKUPS:]:
        old_backup.unlink(missing_ok=True)

    return backup_path


def save_settings(data: dict[str, Any]) -> None:
    """Atomically write settings.json (UTF-8, indent=2)."""
    target = settings_path()
    temp_path = target.with_suffix(".json.tmp")
    payload = json.dumps(data, ensure_ascii=False, indent=2) + "\n"
    temp_path.write_text(payload, encoding="utf-8")
    os.replace(temp_path, target)
    clear_settings_cache()


def save_agents(agents: list[dict[str, Any]]) -> int:
    """
    Validate, backup and persist agents into settings.json.

    Returns number of saved agents.
    Raises ValueError on validation errors.
    """
    normalized = normalize_agents(agents)
    errors, _warnings = validate_agents(normalized)
    if errors:
        raise ValueError("\n".join(errors))

    backup_settings()
    data = load_settings()
    data["agents"] = normalized
    save_settings(data)
    return len(normalized)
