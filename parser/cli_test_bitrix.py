"""CLI smoke test for the Bitrix parser."""

from __future__ import annotations

import sys
from pathlib import Path

from parser.load_bitrix import load_bitrix
from parser.settings_loader import get_settings, project_root


def _input_bitrix_path(settings: dict) -> Path:
    paths = settings.get("paths", {})
    input_dir = paths.get("input_dir", "input")
    file_bitrix = paths.get("file_bitrix", "Битрикс.xlsx")
    return project_root() / input_dir / file_bitrix


def main() -> int:
    settings = get_settings()
    sheet_name = settings.get("sheets", {}).get("bitrix", "Битрикс")
    success_value = settings.get("metrics", {}).get("bitrix_success_value", "Успех")
    file_path = _input_bitrix_path(settings)

    if not file_path.is_file():
        print(f"Файл не найден: {file_path}", file=sys.stderr)
        print("Поместите выгрузку Битрикс.xlsx в каталог input/.", file=sys.stderr)
        return 1

    print(f"Loading: {file_path}")
    print(f"Sheet:   {sheet_name}")
    print()

    frame = load_bitrix(file_path, sheet_name)

    print("=== shape ===")
    print(frame.shape)
    print()

    if "deal_result" in frame.columns:
        print("=== deal_result value_counts ===")
        print(frame["deal_result"].value_counts(dropna=False))
        print()

        success_mask = frame["deal_result"] == success_value
        success_count = int(success_mask.sum())
        print(f"=== deal_result == '{success_value}': {success_count} ===")

        if "client_paid_at" in frame.columns:
            empty_paid_among_success = int(
                frame.loc[success_mask, "client_paid_at"].isna().sum()
            )
            print(
                f"=== client_paid_at пустой среди '{success_value}': "
                f"{empty_paid_among_success} ==="
            )
    else:
        print("Колонка deal_result отсутствует.", file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())
