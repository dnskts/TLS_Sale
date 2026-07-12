"""Load project settings from settings.json at the repository root."""

from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
from typing import Any

SETTINGS_FILENAME = "settings.json"


def project_root() -> Path:
    """Return the repository root (parent of the parser package)."""
    return Path(__file__).resolve().parent.parent


def settings_path() -> Path:
    return project_root() / SETTINGS_FILENAME


def load_settings(*, path: Path | None = None) -> dict[str, Any]:
    """
    Load and parse settings.json.

    Raises FileNotFoundError if the file is missing.
    Raises json.JSONDecodeError on invalid JSON.
    """
    cfg_path = path or settings_path()
    with cfg_path.open(encoding="utf-8") as handle:
        return json.load(handle)


@lru_cache(maxsize=1)
def get_settings() -> dict[str, Any]:
    """Cached settings for repeated access within one process."""
    return load_settings()


def clear_settings_cache() -> None:
    """Invalidate cached settings after settings.json changes."""
    get_settings.cache_clear()
