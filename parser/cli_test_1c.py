"""CLI smoke test for the 1C parser."""

from __future__ import annotations

import sys
from pathlib import Path

import pandas as pd

from parser.load_1c import load_1c
from parser.settings_loader import get_settings, project_root

KEY_FIELDS = [
    "date_operation",
    "datetime_operation",
    "agent",
    "client",
    "department",
    "sales_amount",
    "profit_ex_vat",
    "case_id",
    "order_no",
    "id_crm",
]


def _input_1c_path(settings: dict) -> Path:
    paths = settings.get("paths", {})
    input_dir = paths.get("input_dir", "input")
    file_1c = paths.get("file_1c", "1C.xlsx")
    return project_root() / input_dir / file_1c


def main() -> int:
    settings = get_settings()
    sheet_name = settings.get("sheets", {}).get("1c", "TDSheet")
    file_path = _input_1c_path(settings)

    if not file_path.is_file():
        print(f"Файл не найден: {file_path}", file=sys.stderr)
        print("Поместите выгрузку 1C.xlsx в каталог input/.", file=sys.stderr)
        return 1

    print(f"Loading: {file_path}")
    print(f"Sheet:   {sheet_name}")
    print()

    frame = load_1c(file_path, sheet_name)

    print("=== shape ===")
    print(frame.shape)
    print()

    print("=== head(5) ===")
    with pd.option_context("display.max_columns", 20, "display.width", 200):
        print(frame.head(5))
    print()

    print("=== dtypes ===")
    print(frame.dtypes)
    print()

    print("=== null counts (key fields) ===")
    for field in KEY_FIELDS:
        if field in frame.columns:
            nulls = int(frame[field].isna().sum())
            print(f"  {field}: {nulls}")
    print()

    negative_sales = int((frame["sales_amount"] < 0).sum()) if "sales_amount" in frame.columns else 0
    print(f"=== sales_amount < 0: {negative_sales} ===")

    return 0


if __name__ == "__main__":
    sys.exit(main())
