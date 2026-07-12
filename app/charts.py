"""Plotly figure builders for dashboard tabs."""

from __future__ import annotations

import pandas as pd
import plotly.express as px
import plotly.graph_objects as go

from app.metrics import format_rub

BITRIX_BLUE = "#00a2e8"
BITRIX_GREEN = "#9bcb56"
BITRIX_RED = "#ff5757"
BITRIX_TEXT = "#535c69"
BITRIX_DARK_BLUE = "#2067b0"

CHART_LAYOUT = dict(
    template="plotly_white",
    paper_bgcolor="rgba(0,0,0,0)",
    plot_bgcolor="rgba(0,0,0,0)",
    margin=dict(l=40, r=20, t=40, b=40),
    legend=dict(orientation="h", yanchor="bottom", y=1.02, x=0),
    font=dict(family="Helvetica Neue, Helvetica, Arial, sans-serif", color=BITRIX_TEXT),
)

SOURCE_LABELS = {"1c": "1С", "bitrix": "Битрикс"}


def empty_figure(message: str = "Нет данных для выбранных фильтров") -> go.Figure:
    fig = go.Figure()
    fig.add_annotation(
        text=message,
        xref="paper",
        yref="paper",
        x=0.5,
        y=0.5,
        showarrow=False,
        font=dict(size=14, color="#828b95"),
    )
    fig.update_layout(**CHART_LAYOUT, height=320)
    fig.update_xaxes(visible=False)
    fig.update_yaxes(visible=False)
    return fig


def _period_series(dates: pd.Series, granularity: str) -> pd.Series:
    if granularity == "month":
        return dates.dt.to_period("M").astype(str)
    if granularity == "week":
        return dates.dt.to_period("W").astype(str)
    return dates.dt.strftime("%Y-%m-%d")


def figure_trend(frame: pd.DataFrame, granularity: str = "day") -> go.Figure:
    if frame.empty:
        return empty_figure()
    data = frame.dropna(subset=["date"]).copy()
    if data.empty:
        return empty_figure()
    data["period"] = _period_series(data["date"], granularity)
    grouped = (
        data.groupby("period", as_index=False)
        .agg(sales_amount=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"))
        .sort_values("period")
    )
    fig = go.Figure()
    fig.add_trace(
        go.Bar(
            x=grouped["period"],
            y=grouped["sales_amount"],
            name="Продажи",
            marker_color=BITRIX_BLUE,
        )
    )
    fig.add_trace(
        go.Scatter(
            x=grouped["period"],
            y=grouped["profit_ex_vat"],
            name="Прибыль",
            mode="lines+markers",
            yaxis="y2",
            line=dict(color=BITRIX_GREEN, width=3),
        )
    )
    fig.update_layout(
        **CHART_LAYOUT,
        height=340,
        yaxis=dict(title="Продажи, ₽"),
        yaxis2=dict(title="Прибыль, ₽", overlaying="y", side="right"),
        barmode="group",
    )
    return fig


def figure_source_stacked(frame: pd.DataFrame, granularity: str = "day") -> go.Figure:
    if frame.empty:
        return empty_figure()
    data = frame.dropna(subset=["date"]).copy()
    if data.empty:
        return empty_figure()
    data["period"] = _period_series(data["date"], granularity)
    data["source_label"] = data["source"].map(SOURCE_LABELS).fillna(data["source"])
    grouped = (
        data.groupby(["period", "source_label"], as_index=False)["sales_amount"]
        .sum()
        .sort_values("period")
    )
    fig = px.bar(
        grouped,
        x="period",
        y="sales_amount",
        color="source_label",
        labels={"period": "Период", "sales_amount": "Продажи, ₽", "source_label": "Источник"},
        color_discrete_map={"1С": BITRIX_DARK_BLUE, "Битрикс": BITRIX_GREEN},
    )
    fig.update_layout(**CHART_LAYOUT, height=340, barmode="stack")
    return fig


def figure_top_dimension(frame: pd.DataFrame, dimension: str, title: str, top_n: int = 5) -> go.Figure:
    if frame.empty or dimension not in frame.columns:
        return empty_figure()
    grouped = (
        frame.groupby(dimension, as_index=False)
        .agg(sales_amount=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"))
        .sort_values("profit_ex_vat", ascending=False)
        .head(top_n)
    )
    grouped[dimension] = grouped[dimension].fillna("—")
    if grouped.empty:
        return empty_figure()
    fig = px.bar(
        grouped,
        x="profit_ex_vat",
        y=dimension,
        orientation="h",
        labels={"profit_ex_vat": "Прибыль, ₽", dimension: title},
        color="profit_ex_vat",
        color_continuous_scale=[[0, "#eaf5fc"], [1, BITRIX_BLUE]],
    )
    fig.update_layout(**CHART_LAYOUT, height=320, showlegend=False, coloraxis_showscale=False)
    fig.update_yaxes(categoryorder="total ascending")
    return fig


def figure_agent_ranking(frame: pd.DataFrame, top_n: int = 15) -> go.Figure:
    if frame.empty:
        return empty_figure()
    grouped = (
        frame.groupby(["agent_display", "agent_team"], as_index=False)
        .agg(sales_amount=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"))
        .sort_values("profit_ex_vat", ascending=False)
        .head(top_n)
    )
    if grouped.empty:
        return empty_figure()
    grouped["label"] = grouped["agent_display"].fillna("—") + " (" + grouped["agent_team"].fillna("—") + ")"
    fig = px.bar(
        grouped,
        x="profit_ex_vat",
        y="label",
        orientation="h",
        labels={"profit_ex_vat": "Прибыль, ₽", "label": "Агент"},
        color="profit_ex_vat",
        color_continuous_scale=[[0, "#eaf5fc"], [1, BITRIX_BLUE]],
    )
    fig.update_layout(**CHART_LAYOUT, height=360, showlegend=False, coloraxis_showscale=False)
    fig.update_yaxes(categoryorder="total ascending")
    return fig


def figure_dimension_bar(frame: pd.DataFrame, dimension: str, title: str, top_n: int = 12) -> go.Figure:
    if frame.empty or dimension not in frame.columns:
        return empty_figure()
    grouped = (
        frame.groupby(dimension, as_index=False)
        .agg(sales_amount=("sales_amount", "sum"), profit_ex_vat=("profit_ex_vat", "sum"))
        .sort_values("sales_amount", ascending=False)
        .head(top_n)
    )
    grouped[dimension] = grouped[dimension].fillna("—")
    if grouped.empty:
        return empty_figure()
    fig = px.bar(
        grouped,
        x=dimension,
        y="sales_amount",
        labels={"sales_amount": "Продажи, ₽", dimension: title},
        color="profit_ex_vat",
        color_continuous_scale=[[0, "#eaf5fc"], [1, BITRIX_BLUE]],
    )
    fig.update_layout(**CHART_LAYOUT, height=320, showlegend=False, coloraxis_showscale=False)
    return fig
