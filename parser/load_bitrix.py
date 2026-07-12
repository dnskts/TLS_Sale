"""Parse Bitrix CRM sales export (xlsx) into a normalized DataFrame."""

from __future__ import annotations

import difflib
import logging
import re
import warnings
from datetime import date, datetime
from typing import Any

import pandas as pd

logger = logging.getLogger(__name__)

# Excel header (as in format_spec.txt) -> normalized column alias
BITRIX_HEADER_ALIASES: dict[str, str] = {
    "Номер сделки": "deal_no",
    "Название сделки": "deal_title",
    "% участия агента в продаже*": "agent_sale_participation",
    "Дата создания сделки": "deal_created_at",
    "Статус сделки": "deal_status",
    "Ответственное лицо": "responsible_person",
    "Тип клиента": "client_type",
    "Клиент": "client",
    "Менеджер": "manager",
    "ID клиента": "id_client",
    "Дата создания фин.карты": "fin_card_created_at",
    "Создатель карты": "card_creator",
    "Дата оплаты Клиентом": "client_paid_at",
    "Тип карты": "card_type",
    "Дата отмены операции (возврат)": "cancel_operation_date",
    "Дата возврата": "return_date",
    "Партнер": "partner",
    "Количество броней (1)": "booking_count",
    "Тип брони": "booking_type",
    "Страна": "country",
    "Город": "city",
    "Гостиница": "hotel",
    "Ресторан": "restaurant",
    "Полное наименование организации": "org_full_name",
    "Цепочка": "chain",
    "Дата заезда": "checkin_date",
    "Дата выезда": "checkout_date",
    "Количество ночей": "nights_count",
    "Общее количество ночей": "total_nights",
    "Категория": "category",
    "Канал связи": "channel",
    "Маркетинговый канал": "marketing_channel",
    "Итого оплачено клиентом": "total_paid_by_client",
    "Сумма продажи": "sales_amount",
    "Оплата поставщику": "supplier_payment",
    "Статус карты возврата": "refund_card_status",
    "Сумма возврата поставщиком": "supplier_refund_amount",
    "Штраф от поставщика": "supplier_penalty",
    "Сбор поставщика на возврат": "supplier_refund_fee",
    "Продукты за сбор возврата": "refund_fee_products",
    "Штраф клиенту РС ТЛС": "client_penalty_rstls",
    "Возврат сбора РС ТЛС": "fee_refund_rstls",
    "Остаток сбора РС ТЛС": "fee_remainder_rstls",
    "Прибыль РС ТЛС с учетом возврата": "profit_rstls_after_refund",
    "Удержал поставщик": "supplier_retained",
    "Сумма возврата клиенту": "client_refund_amount",
    "Прибыль": "profit",
    "Прибыль без НДС": "profit_ex_vat",
    "Сумма прибыли с учетом возврата без НДС": "profit_after_refund_ex_vat",
    "Комиссия": "commission",
    "Комиссия без НДС": "commission_ex_vat",
    "Дополнительная выгода": "additional_benefit",
    "Дополнительная выгода без НДС": "additional_benefit_ex_vat",
    "Сервисный сбор": "service_fee",
    "Сервисный сбор без НДС": "service_fee_ex_vat",
    "Нетто в Валюте поставщика": "net_supplier_currency",
    "Брутто в Валюте поставщика": "gross_supplier_currency",
    "Комиссия поставщика в Валюте": "supplier_commission_currency",
    "Нетто в рублях": "net_rub",
    "Валюта сделки": "deal_currency",
    "Название валюты сделки": "deal_currency_name",
    "Курс оплаты": "payment_rate",
    "Курс оплаты ЦБ": "cbr_payment_rate",
    "Путешественник": "traveler",
    "Сумма НДС": "vat_amount",
    "Сумма TID": "tid",
    "SR": "sr",
    "LR": "lr",
    "Тип запроса": "request_type",
    "Связанные сделки": "related_deals",
    "Лид": "lead_id",
    "Тур": "tour",
    "Номер счёта": "invoice_no",
    "Тип оплаты": "payment_type",
    "Дата оказания услуги": "service_date",
    "Сумма продажи после возврата": "sales_amount_after_refund",
    "Депозит": "deposit",
    "Баллы AX": "points_ax",
    "Баллы MR": "points_mr",
    "Баллы IMP": "points_imp",
    "безнал": "cashless",
    "Карта": "card",
    "Сертификат": "certificate",
    "Убыток на компанию": "loss_company",
    "Убыток на сотрудника": "loss_employee",
    "Код FHR": "fhr_code",
    "Класс": "travel_class",
    "Пассажир": "passenger",
    "Дата вылета": "departure_date",
    "Дата прилета": "arrival_date",
    "Авиакомпания": "airline",
    "Страна прилета (Конечная точка)": "arrival_country",
    "Город прилета (Конечная точка)": "arrival_city",
    "Привилегии": "privileges",
    "Наличие договора": "has_contract",
    "Результат сделки": "deal_result",
    "Причина стадии Сделка проиграна": "lost_deal_reason",
    "Количество сегментов": "segments_count",
    "Дата оплаты партнеру (поставщику)": "partner_paid_at",
    "Комментарий Тимлидеру": "teamlead_comment",
    "Кросс-продажа": "cross_sell",
    "Кросс-продажа причина": "cross_sell_reason",
    "Схема финансовой карты": "fin_card_scheme",
    "Дата отложенной оплаты": "deferred_payment_date",
    "Валюта отложенной оплаты": "deferred_payment_currency_type",
    "Сумма отложенной оплаты, руб": "deferred_payment_rub",
    "Сумма отложенной оплаты, валюта": "deferred_payment_currency",
    "Количество номеров": "rooms_count",
    "Дата начала": "start_date",
    "Дата окончания": "end_date",
    "Дата завершения": "completion_date",
    "Средний курс для возврата": "avg_refund_rate",
    "Сбор поставщика": "supplier_fee",
}

KPI_ALIASES = {
    "deal_no",
    "deal_title",
    "agent_sale_participation",
    "deal_created_at",
    "deal_status",
    "responsible_person",
    "client_type",
    "client",
    "manager",
    "id_client",
    "fin_card_created_at",
    "card_creator",
    "client_paid_at",
    "card_type",
    "partner",
    "category",
    "channel",
    "sales_amount",
    "profit",
    "profit_ex_vat",
    "service_fee",
    "request_type",
    "lead_id",
    "invoice_no",
    "deal_result",
    "lost_deal_reason",
    "service_date",
}

STRING_COLUMNS = {
    "deal_title",
    "agent_sale_participation",
    "deal_status",
    "responsible_person",
    "client_type",
    "client",
    "manager",
    "card_creator",
    "card_type",
    "partner",
    "booking_type",
    "country",
    "city",
    "hotel",
    "restaurant",
    "org_full_name",
    "chain",
    "category",
    "channel",
    "marketing_channel",
    "refund_card_status",
    "deal_currency",
    "deal_currency_name",
    "traveler",
    "request_type",
    "tour",
    "invoice_no",
    "payment_type",
    "privileges",
    "has_contract",
    "deal_result",
    "lost_deal_reason",
    "teamlead_comment",
    "cross_sell",
    "cross_sell_reason",
    "fin_card_scheme",
    "agent_participant_name",
}

NUMERIC_COLUMNS = {
    "deal_no",
    "booking_count",
    "nights_count",
    "total_nights",
    "total_paid_by_client",
    "sales_amount",
    "supplier_payment",
    "supplier_refund_amount",
    "supplier_penalty",
    "supplier_refund_fee",
    "refund_fee_products",
    "client_penalty_rstls",
    "fee_refund_rstls",
    "fee_remainder_rstls",
    "profit_rstls_after_refund",
    "supplier_retained",
    "client_refund_amount",
    "profit",
    "profit_ex_vat",
    "profit_after_refund_ex_vat",
    "commission",
    "commission_ex_vat",
    "additional_benefit",
    "additional_benefit_ex_vat",
    "service_fee",
    "service_fee_ex_vat",
    "net_supplier_currency",
    "gross_supplier_currency",
    "supplier_commission_currency",
    "net_rub",
    "payment_rate",
    "cbr_payment_rate",
    "vat_amount",
    "tid",
    "sr",
    "lr",
    "lead_id",
    "sales_amount_after_refund",
    "deposit",
    "points_ax",
    "points_mr",
    "points_imp",
    "cashless",
    "card",
    "certificate",
    "loss_company",
    "loss_employee",
    "fhr_code",
    "travel_class",
    "passenger",
    "segments_count",
    "deferred_payment_date",
    "deferred_payment_currency_type",
    "deferred_payment_rub",
    "deferred_payment_currency",
    "rooms_count",
    "avg_refund_rate",
    "supplier_fee",
    "agent_participation_amount",
    "related_deals",
}

DATETIME_COLUMNS = {
    "deal_created_at",
    "fin_card_created_at",
    "client_paid_at",
    "cancel_operation_date",
    "return_date",
    "service_date",
    "partner_paid_at",
    "start_date",
    "end_date",
    "completion_date",
    "date_for_sales",
}

ID_STRING_COLUMNS = {"id_client", "invoice_no"}

AGENT_PARTICIPATION_PATTERN = re.compile(r"^(.+?)=(\d+(?:\.\d+)?)$")


def _normalize_header(value: Any) -> str:
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return ""
    return str(value).replace("\xa0", " ").replace("\n", " ").strip()


def _trim_string(value: Any) -> Any:
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    if isinstance(value, (datetime, date)):
        return value
    if isinstance(value, str):
        cleaned = value.replace("\xa0", " ").strip()
        return cleaned if cleaned else pd.NA
    cleaned = str(value).replace("\xa0", " ").strip()
    return cleaned if cleaned else pd.NA


def _to_datetime(value: Any) -> Any:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA
    if isinstance(value, datetime):
        return value
    if isinstance(value, date):
        return datetime(value.year, value.month, value.day)
    text = str(value).replace("\xa0", " ").strip()
    if not text:
        return pd.NA
    parsed = pd.to_datetime(text, dayfirst=True, errors="coerce")
    if pd.isna(parsed):
        return pd.NA
    return parsed.to_pydatetime()


def _map_headers(file_headers: list[str]) -> tuple[dict[str, str], list[str]]:
    """
    Map normalized aliases to original file column names.

    Returns {alias: original_header} and list of warning messages.
    """
    normalized_to_original: dict[str, str] = {}
    for header in file_headers:
        normalized = _normalize_header(header)
        if normalized:
            normalized_to_original[normalized] = header

    alias_to_column: dict[str, str] = {}
    warnings_out: list[str] = []
    available = set(normalized_to_original)

    for expected_header, alias in BITRIX_HEADER_ALIASES.items():
        if expected_header in available:
            alias_to_column[alias] = normalized_to_original[expected_header]
            continue

        close = difflib.get_close_matches(expected_header, list(available), n=1, cutoff=0.9)
        if close:
            matched = close[0]
            alias_to_column[alias] = normalized_to_original[matched]
            warnings_out.append(
                f"Колонка «{expected_header}» (alias `{alias}`) не найдена точно; "
                f"использована похожая «{matched}»."
            )
        else:
            warnings_out.append(
                f"Колонка «{expected_header}» (alias `{alias}`) отсутствует в файле."
            )

    return alias_to_column, warnings_out


def _parse_agent_participation(value: Any) -> tuple[Any, Any]:
    if value is None or value is pd.NA or (isinstance(value, float) and pd.isna(value)):
        return pd.NA, pd.NA
    text = str(value).replace("\xa0", " ").strip()
    if not text:
        return pd.NA, pd.NA
    match = AGENT_PARTICIPATION_PATTERN.match(text)
    if not match:
        return pd.NA, pd.NA
    name = match.group(1).strip()
    amount = float(match.group(2))
    return name if name else pd.NA, amount


def _build_agent_index_bitrix(settings: dict[str, Any]) -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for agent in settings.get("agents", []):
        record = {
            "agent_key": agent["agent_key"],
            "name_display": agent["name_display"],
            "team": agent.get("team"),
            "is_active": agent.get("is_active"),
        }
        for name in agent.get("names_bitrix", []):
            key = str(name).replace("\xa0", " ").strip()
            if key:
                index[key] = record
    return index


def resolve_agent_bitrix(
    raw_name: Any,
    settings: dict[str, Any],
) -> dict[str, Any]:
    """
    Map a raw Bitrix responsible_person to settings.json agent record.

    Exact match (after trim) against names_bitrix only — no heuristics.
    """
    if raw_name is None or (isinstance(raw_name, float) and pd.isna(raw_name)):
        raw = ""
    else:
        raw = str(raw_name).replace("\xa0", " ").strip()

    if not raw:
        return {
            "agent_key": "unknown:",
            "name_display": raw,
            "team": "Без команды",
            "is_active": None,
        }

    index = _build_agent_index_bitrix(settings)
    if raw in index:
        return dict(index[raw])

    return {
        "agent_key": f"unknown:{raw}",
        "name_display": raw,
        "team": "Без команды",
        "is_active": None,
    }


def load_bitrix(path: str | pd.PathLike[str], sheet_name: str) -> pd.DataFrame:
    """
    Load Bitrix xlsx export into a normalized DataFrame (all deals, no filtering).

    Header names are matched to aliases from format_spec; slight mismatches emit warnings.
    """
    raw = pd.read_excel(path, sheet_name=sheet_name, header=0, engine="openpyxl")
    if raw.empty:
        columns = sorted(set(BITRIX_HEADER_ALIASES.values()) | {
            "agent_participant_name",
            "agent_participation_amount",
            "source",
            "date_for_sales",
            "date_fallback_used",
        })
        return pd.DataFrame(columns=columns)

    raw.columns = [_normalize_header(col) for col in raw.columns]
    alias_to_column, header_warnings = _map_headers(list(raw.columns))
    for message in header_warnings:
        warnings.warn(message, stacklevel=2)
        logger.warning(message)

    missing_kpi = KPI_ALIASES - set(alias_to_column)
    if missing_kpi:
        warnings.warn(
            f"Отсутствуют KPI-поля: {', '.join(sorted(missing_kpi))}",
            stacklevel=2,
        )

    frame = pd.DataFrame()
    for alias, source_col in alias_to_column.items():
        if source_col in raw.columns:
            frame[alias] = raw[source_col]

    for column in STRING_COLUMNS:
        if column in frame.columns:
            frame[column] = frame[column].map(_trim_string)

    for column in ID_STRING_COLUMNS:
        if column in frame.columns:
            frame[column] = frame[column].map(
                lambda value: pd.NA
                if value is pd.NA or value is None
                else str(value).replace("\xa0", " ").strip()
            )
            frame[column] = frame[column].replace("", pd.NA)

    for column in NUMERIC_COLUMNS:
        if column in frame.columns:
            frame[column] = pd.to_numeric(frame[column], errors="coerce")

    for column in DATETIME_COLUMNS:
        if column in frame.columns:
            frame[column] = frame[column].map(_to_datetime)

    if "agent_sale_participation" in frame.columns:
        parsed = frame["agent_sale_participation"].map(_parse_agent_participation)
        frame["agent_participant_name"] = parsed.map(lambda item: item[0])
        frame["agent_participation_amount"] = parsed.map(lambda item: item[1])
    else:
        frame["agent_participant_name"] = pd.NA
        frame["agent_participation_amount"] = pd.NA

    if "client_paid_at" in frame.columns:
        frame["client_paid_at"] = frame["client_paid_at"].map(_to_datetime)
    else:
        frame["client_paid_at"] = pd.NA

    if "deal_created_at" in frame.columns:
        frame["deal_created_at"] = frame["deal_created_at"].map(_to_datetime)
    else:
        frame["deal_created_at"] = pd.NA

    frame["date_fallback_used"] = frame["client_paid_at"].isna()
    frame["date_for_sales"] = frame["client_paid_at"].where(
        ~frame["client_paid_at"].isna(),
        frame["deal_created_at"],
    )
    frame["source"] = "bitrix"

    return frame
