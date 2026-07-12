"""Plotly figures for Bitrix funnel tab (deals_bitrix)."""

from __future__ import annotations

import pandas as pd
import plotly.express as px
import plotly.graph_objects as go

from app.charts import BITRIX_BLUE, BITRIX_GREEN, BITRIX_RED, BITRIX_TEXT, CHART_LAYOUT, _period_series, empty_figure

RESULT_COLORS = {
    "Успех": BITRIX_GREEN,
    "Проиграна": BITRIX_RED,
    "В процессе": BITRIX_BLUE,
    "Не указан": "#828b95",
}

RESULT_ORDER = ["Успех", "Проиграна", "В процессе", "Не указан"]


def _normalize_deal_result(series: pd.Series) -> pd.Series:
    normalized = series.fillna("Не указан").astype(str).str.strip()
    normalized = normalized.replace({"nan": "Не указан", "": "Не указан"})
    return normalized


def _result_counts(frame: pd.DataFrame) -> pd.DataFrame:
    if frame.empty:
        return pd.DataFrame(columns=["deal_result", "count"])
    data = frame.copy()
    data["deal_result"] = _normalize_deal_result(data.get("deal_result", pd.Series(dtype=object)))
    grouped = (
        data.groupby("deal_result", as_index=False)
        .size()
        .rename(columns={"size": "count"})
        .sort_values("count", ascending=False)
    )
    order_map = {label: index for index, label in enumerate(RESULT_ORDER)}
    grouped["sort_key"] = grouped["deal_result"].map(order_map).fillna(99)
    return grouped.sort_values("sort_key")


def figure_funnel_result_pie(frame: pd.DataFrame) -> go.Figure:
    grouped = _result_counts(frame)
    if grouped.empty:
        return empty_figure("Нет сделок за выбранный период создания")
    fig = px.pie(
        grouped,
        names="deal_result",
        values="count",
        color="deal_result",
        color_discrete_map=RESULT_COLORS,
        category_orders={"deal_result": RESULT_ORDER},
        labels={"deal_result": "Результат", "count": "Сделок"},
    )
    fig.update_traces(textposition="inside", textinfo="percent+label")
    fig.update_layout(**CHART_LAYOUT, height=340, showlegend=False)
    return fig


def figure_funnel_result_bar(frame: pd.DataFrame) -> go.Figure:
    grouped = _result_counts(frame)
    if grouped.empty:
        return empty_figure("Нет сделок за выбранный период создания")
    total = int(grouped["count"].sum())
    success = int(grouped.loc[grouped["deal_result"] == "Успех", "count"].sum())
    conversion = success / total * 100 if total else 0

    fig = px.bar(
        grouped,
        x="deal_result",
        y="count",
        color="deal_result",
        color_discrete_map=RESULT_COLORS,
        category_orders={"deal_result": RESULT_ORDER},
        labels={"deal_result": "Результат", "count": "Сделок"},
    )
    fig.update_layout(
        **CHART_LAYOUT,
        height=340,
        showlegend=False,
        title=dict(
            text=f"Конверсия в «Успех»: {conversion:.1f} %".replace(".", ","),
            x=0,
            xanchor="left",
            font=dict(size=13, color=BITRIX_TEXT),
        ),
    )
    return fig


def figure_funnel_status_bar(frame: pd.DataFrame, top_n: int = 15) -> go.Figure:
    if frame.empty or "deal_status" not in frame.columns:
        return empty_figure("Нет сделок за выбранный период создания")
    grouped = (
        frame.assign(deal_status=frame["deal_status"].fillna("Не указан").astype(str).str.strip())
        .groupby("deal_status", as_index=False)
        .size()
        .rename(columns={"size": "count"})
        .sort_values("count", ascending=False)
        .head(top_n)
    )
    if grouped.empty:
        return empty_figure()
    fig = px.bar(
        grouped,
        x="count",
        y="deal_status",
        orientation="h",
        labels={"count": "Сделок", "deal_status": "Статус"},
        color="count",
        color_continuous_scale=[[0, "#eaf5fc"], [1, BITRIX_BLUE]],
    )
    fig.update_layout(**CHART_LAYOUT, height=380, showlegend=False, coloraxis_showscale=False)
    fig.update_yaxes(categoryorder="total ascending")
    return fig


def figure_funnel_lost_reason(frame: pd.DataFrame, top_n: int = 12) -> go.Figure:
    if frame.empty:
        return empty_figure("Нет проигранных сделок за выбранный период")
    lost = frame[_normalize_deal_result(frame.get("deal_result", pd.Series(dtype=object))) == "Проиграна"]
    if lost.empty:
        return empty_figure("Нет проигранных сделок за выбранный период")
    if "lost_deal_reason" not in lost.columns:
        return empty_figure("Причины проигрыша не заполнены в выгрузке")

    grouped = (
        lost.assign(
            lost_deal_reason=lost["lost_deal_reason"].fillna("Не указана").astype(str).str.strip().replace({"": "Не указана"})
        )
        .groupby("lost_deal_reason", as_index=False)
        .size()
        .rename(columns={"size": "count"})
        .sort_values("count", ascending=False)
        .head(top_n)
    )
    if grouped.empty:
        return empty_figure("Причины проигрыша не заполнены")
    fig = px.bar(
        grouped,
        x="count",
        y="lost_deal_reason",
        orientation="h",
        labels={"count": "Сделок", "lost_deal_reason": "Причина"},
        color="count",
        color_continuous_scale=[[0, "#fff0f0"], [1, BITRIX_RED]],
    )
    fig.update_layout(**CHART_LAYOUT, height=360, showlegend=False, coloraxis_showscale=False)
    fig.update_yaxes(categoryorder="total ascending")
    return fig


def figure_funnel_created_vs_paid(frame: pd.DataFrame, granularity: str = "month") -> go.Figure:
    if frame.empty:
        return empty_figure("Нет сделок за выбранный период создания")
    created = frame.dropna(subset=["deal_created_at"]).copy()
    if created.empty:
        return empty_figure("Нет дат создания сделок в выбранном периоде")

    created["period"] = _period_series(created["deal_created_at"], granularity)
    created_counts = (
        created.groupby("period", as_index=False)
        .size()
        .rename(columns={"size": "created"})
        .sort_values("period")
    )

    paid = frame.dropna(subset=["client_paid_at"]).copy()
    paid_counts = pd.DataFrame(columns=["period", "paid"])
    if not paid.empty:
        paid["period"] = _period_series(paid["client_paid_at"], granularity)
        paid_counts = (
            paid.groupby("period", as_index=False)
            .size()
            .rename(columns={"size": "paid"})
            .sort_values("period")
        )

    periods = sorted(set(created_counts["period"]).union(set(paid_counts.get("period", []))))
    trend = pd.DataFrame({"period": periods})
    trend = trend.merge(created_counts, on="period", how="left").merge(paid_counts, on="period", how="left")
    trend["created"] = trend["created"].fillna(0).astype(int)
    trend["paid"] = trend["paid"].fillna(0).astype(int)

    fig = go.Figure()
    fig.add_trace(
        go.Bar(
            x=trend["period"],
            y=trend["created"],
            name="Созданы",
            marker_color=BITRIX_BLUE,
        )
    )
    fig.add_trace(
        go.Bar(
            x=trend["period"],
            y=trend["paid"],
            name="Оплачены клиентом",
            marker_color=BITRIX_GREEN,
        )
    )
    fig.update_layout(
        **CHART_LAYOUT,
        height=360,
        barmode="group",
        yaxis=dict(title="Количество сделок"),
        xaxis=dict(title="Период"),
    )
    return fig
