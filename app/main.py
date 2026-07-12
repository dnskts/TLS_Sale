"""Entry point for the TLS Sale Dash dashboard."""

from __future__ import annotations

import sys
from pathlib import Path

from dash import Dash

from app.layout import build_layout
from parser.settings_loader import get_settings

_PROJECT_ROOT = Path(__file__).resolve().parent.parent
_ASSETS_FOLDER = _PROJECT_ROOT / "assets"


def create_app() -> Dash:
    settings = get_settings()
    app_cfg = settings.get("app", {})
    title = app_cfg.get("title", "Дашборд продаж РС ТЛС")

    dash_app = Dash(
        __name__,
        title=title,
        suppress_callback_exceptions=True,
        external_stylesheets=[],
        assets_folder=str(_ASSETS_FOLDER),
    )
    dash_app.layout = build_layout
    import app.callbacks  # noqa: F401 — register @callback handlers
    import app.callbacks_settings  # noqa: F401 — settings tab callbacks

    return dash_app


def main() -> int:
    settings = get_settings()
    app_cfg = settings.get("app", {})
    host = app_cfg.get("host", "0.0.0.0")
    port = int(app_cfg.get("port", 8050))
    debug = app_cfg.get("debug", False)

    dash_app = create_app()
    print(f"Запуск дашборда: http://127.0.0.1:{port}")
    dash_app.run(host=host, port=port, debug=debug)
    return 0


if __name__ == "__main__":
    sys.exit(main())
