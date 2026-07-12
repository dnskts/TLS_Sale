# Аудит дашборда РС ТЛС

**Дата:** 2026-07-11  
**Ревьюер:** QA / static + dynamic analysis  
**Версия кодовой базы:** локальная (после этапов 1–8, вкладка «Настройки», fix `app/main.py`)

---

## 1. Резюме

Проект **функционально близок к production на LAN**: ETL на реальных выгрузках отрабатывает (7206 + 1166 = 8372 строк unified), справочник 125 агентов валиден, Dash-приложение импортируется и слушает порт 8050 после исправления коллизии имён в `app/main.py`.

**Оценка готовности: ~85%** — **вердикт: GO WITH FIXES**.

Критичные бизнес-инварианты (1С+Б24 без дедупа, `profit_ex_vat`, Успех в unified, даты, agent mapping из settings, воронка по всем deals) **реализованы в коде и подтверждены прогоном БД**. Остаются точечные проблемы качества данных (unknown-агенты с полным ФИО+отчество), отсутствие агрегированных warnings по `date_fallback`, UX «фильтры только по кнопке», и операционные риски (save agents без pipeline → старые привязки в SQLite).

**Топ-5 действий до LAN-продакшена:**
1. Добавить алиас `Демидова Елена Николаевна` → `demidova_elena` (или объединить дубликаты Демидовых).
2. Прописать в pipeline warning при высокой доле `date_fallback_used` по Битрикс.
3. Smoke-test UI: KPI MTD, воронка, сохранение agents + backup + «Применить к данным».
4. Документировать для пользователей: «Сохранить» ≠ «Применить»; после save нужен pipeline.
5. Убрать дубликаты xlsx из корня проекта (оставить только `input/`).

---

## 2. Инвентаризация

### 2.1 Дерево проекта (ключевые файлы)

```
TLS_Sale/
├── index.php
├── settings.json
├── requirements.txt
├── README.md
├── format_spec.txt
├── app/
│   ├── main.py
│   ├── layout.py
│   ├── callbacks.py
│   ├── callbacks_settings.py
│   ├── settings_io.py
│   ├── data_access.py
│   ├── filters_logic.py
│   ├── metrics.py
│   ├── charts.py
│   ├── charts_funnel.py
│   └── components/
│       ├── filters.py
│       ├── kpi_cards.py
│       ├── charts_grid.py
│       └── settings_agents.py
├── parser/
│   ├── pipeline.py
│   ├── load_1c.py
│   ├── load_bitrix.py
│   ├── build_unified.py
│   ├── settings_loader.py
│   ├── cli_test_1c.py
│   └── cli_test_bitrix.py
├── assets/styles.css
├── data/
│   ├── dashboard.db
│   └── last_load.json
├── input/
│   ├── 1C.xlsx
│   └── Битрикс.xlsx
├── docs/
│   ├── format_spec.txt
│   └── generate_spec.py
└── scripts/
    ├── acceptance_check.py
    └── audit_run.py
```

*(Исключены `.venv/`, `__pycache__`. В корне также лежат копии xlsx и `справочник агентов.xlsx` — в runtime не используются.)*

### 2.2 settings.json

| Параметр | Значение |
|----------|----------|
| Верхний уровень | `app`, `paths`, `sheets`, `metrics`, `defaults`, `department_map`, `agents` |
| host / port | `0.0.0.0` / `8050` |
| dash_url | `http://127.0.0.1:8050` |
| paths | `input/`, `1C.xlsx`, `Битрикс.xlsx`, `data/dashboard.db`, `data/last_load.json` |
| metrics | `profit_ex_vat`, `bitrix_success_value: Успех`, даты 1С/Б24 |
| len(agents) | **125** |
| is_active true | **88** |
| is_active false | **37** |
| dup agent_key | **0** |
| конфликты имён (1c/bitrix) | **0** |

Ключевые склейки: `isaykina_olga`, `makarova_tatyana`, `bagina_maria` — **FOUND** с ожидаемыми aliases.

### 2.3 Наличие артеfactов

| Артефакт | Статус |
|----------|--------|
| format_spec.txt | ✅ |
| index.php | ✅ |
| requirements.txt | ✅ |
| README.md | ✅ |
| input/1C.xlsx | ✅ (pipeline читает) |
| input/Битрикс.xlsx | ✅ |
| data/dashboard.db | ✅ |
| parser/* | ✅ 8 модулей |
| app/* | ✅ 16 файлов |
| data/backups/ | ⚠️ каталог не создан (save из UI не прогонялся в аудите) |

### 2.4 Entrypoints

| Команда | Назначение |
|---------|------------|
| `python -m parser.pipeline` | Загрузка `input/*.xlsx` → normalize → `build_sales_unified` → SQLite + `last_load.json` |
| `python -m app.main` | Dash-сервер: `create_app()` → callbacks → `dash_app.run(host, port)` |

---

## 3. Матрица инвариантов

| # | Статус | Доказательство |
|---|--------|----------------|
| 1 | **OK** | `build_unified.py:119-188` — все строки 1С + фильтр Успех Б24; `sales_unified` = 7206+1166; dedup не вызывается |
| 2 | **OK** | `build_unified.py:142,179` — `profit_ex_vat`; KPI/charts через `summarize_sales` / `profit_ex_vat` |
| 3 | **OK** | `build_unified.py:156-158` — `bitrix_sales = deals_bitrix[deal_result == success_value]` |
| 4 | **PARTIAL** | Дата: `load_bitrix.py:494-498` fallback + `date_fallback_used`; флаг в unified `build_unified.py:183-185`. **Warning в last_load.json пуст** при 631/1166 fallback (54%) |
| 5 | **OK** | `build_unified.py:121-125` — `resolve_agent(record.get("agent"))`; `issuing_agent` только в колонках 1С, не в метриках |
| 6 | **OK** | `build_unified.py:162` — `resolve_agent_bitrix(record.get("responsible_person"))` |
| 7 | **OK** | `load_1c.py:223-225`, `load_bitrix.py:408-410` — exact match в index; `unknown:{raw}`. *Примечание:* `difflib` только для **заголовков** Excel (`load_bitrix.py:340`), не для агентов |
| 8 | **OK** | `filters_logic.py:57-67` — при пустом списке агентов mask только `is_active` keys из settings |
| 9 | **OK** | `load_1c.py:255-256` — `frame.columns = ONE_C_COLUMNS` по позиции; col0/col1 = date_operation/datetime_operation |
| 10 | **OK** | UI русский; `main.py:22` `external_stylesheets=[]`; CDN в коде проекта не найден |
| 11 | **OK** | Воронка: `data_access.get_filtered_deals_bitrix` → все deals; фильтр по `deal_created_at` |
| 12 | **OK** | `load_1c.py:248`, `build_unified.py:144` — `is_refund` при sales_amount < 0; DB: 665 возвратов 1С |
| 13 | **OK** | `load_1c.py:248` docstring + отсутствие `drop_duplicates` в parser/ |
| 14 | **OK** | `settings_io.py:153-178` — validate, backup, atomic `os.replace`; UI в `callbacks_settings.py` |

---

## 4. Динамические прогоны

### 4.1 settings.json

```
json.load: OK
agents: 125, active: 88, inactive: 37
dup_keys: []
name_conflicts: 0
isaykina_olga, makarova_tatyana, bagina_maria: FOUND
```

### 4.2 Pipeline

```bash
python -m parser.pipeline
# exit code: 0
```

| Таблица | Count |
|---------|------:|
| operations_1c | 7206 |
| deals_bitrix | 1891 |
| sales_unified | 8372 |
| sales_unified 1c | 7206 |
| sales_unified bitrix (Успех) | 1166 |
| agents_dim | 127 |

### 4.3 SQLite (data/dashboard.db)

| Метрика | Значение |
|---------|----------|
| source counts | 1c: 7206, bitrix: 1166 |
| date range | 2026-01-01 … 2026-07-10 |
| sum sales_amount | 863 938 338,97 |
| sum profit_ex_vat | 47 383 795,28 |
| unknown top | `unknown:Демидова Елена Николаевна` (16), `unknown:` (1) |
| refunds 1c (count / sum) | 665 / −101 788 115,01 |
| bitrix date_fallback | 631 / 1166 (54,1%) |

### 4.4 last_load.json

- `loaded_at`: 2026-07-11T19:34:14Z (обновлён при повторном pipeline в аудите)
- `warnings`: **[]** (пусто)
- `top_unknown_agents`: 2 записи (см. выше)

### 4.5 Import / server

```bash
python -c "from app.main import create_app; create_app()"  # OK
python -m app.main  # Dash is running on http://0.0.0.0:8050/
```

*(Ранее был P0: `AttributeError: module 'app' has no attribute 'run'` — исправлено переименованием в `dash_app`.)*

---

## 5. UI-чеклист

| Пункт | Статус | Комментарий |
|-------|--------|-------------|
| Период по умолчанию MTD | **OK** | `default_date_range()` → 1-е число месяца … сегодня; в `store-filters` при загрузке |
| KPI: 6 карточек | **OK** | `kpi_cards.py`, `update_kpi_cards` |
| Формат ₽ пробел тысяч | **OK** | `metrics.format_rub`: `replace(",", " ")` |
| Агенты и команды | **OK** | ranking + таблицы teams/agents |
| Структура продаж | **OK** | 6 dimension bar charts |
| Воронка | **OK** | result/status/lost/trend; период по deal_created_at |
| Детализация + статус | **OK** | DataTable + `load-diagnostics` из last_load.json |
| Обновить данные | **OK** | `btn-refresh-data` → `run_pipeline()` |
| Настройки CRUD | **OK** | draft Store, modal, save, apply pipeline |
| Empty states RU | **OK** | `empty_figure("Нет данных…")`, funnel-specific messages |
| Неактивные / unknown | **OK** | чекбоксы в `filters.py`, логика в `filters_logic.py` |
| Фильтры → KPI без кнопки | **PARTIAL** | Начальные фильтры из Store работают; **смена sidebar требует «Применить фильтры»** |

---

## 6. Баги и долги (P0 → P2)

### P0 (блокеры)

| # | Симптом | Причина | Файл | Исправление |
|---|---------|---------|------|-------------|
| ~~1~~ | ~~Connection refused / сервер не стартует~~ | ~~`import app.callbacks` перезаписывал `app` Dash-объект~~ | ~~`app/main.py`~~ | **ИСПРАВЛЕНО** (`dash_app`) |

*Активных P0 на момент аудита нет.*

### P1 (важно до prod)

| # | Симптом | Причина | Файл | Исправление |
|---|---------|---------|------|-------------|
| 1 | 16 сделок Б24 в unknown | В settings есть `Демидова Елена`, но в CRM `Демидова Елена Николаевна` | `settings.json` → `demidova_elena.names_bitrix` | Добавить alias через «Настройки» → Save → Apply |
| 2 | 54% Битрикс-продаж без client_paid_at — нет warning | Pipeline не агрегирует date_fallback в `last_load.json.warnings` | `parser/pipeline.py` | Добавить warning если fallback > порога |
| 3 | После Save agents цифры не меняются | SQLite не пересчитывается до pipeline | by design | UI уже поясняет; усилить banner после save |
| 4 | Backup settings не проверен | `data/backups/` не создан | `app/settings_io.py` | Один smoke-test save из UI |

### P2 (можно отложить)

| # | Симптом | Причина | Файл | Исправление |
|---|---------|---------|------|-------------|
| 1 | Фильтры не live-update | Callback только на `btn-apply-filters` | `app/callbacks.py` | Auto-apply или hint «нажмите Применить» |
| 2 | Дубли xlsx в корне + `справочник агентов.xlsx` | Ручные копии | корень проекта | Оставить только `input/` |
| 3 | Два `load_settings()` | `parser/settings_loader` + `app/settings_io` | оба модуля | Допустимо; можно унифицировать позже |
| 4 | LRU cache SQLite | `load_table` кэширует до `clear_cache()` | `app/data_access.py` | OK после pipeline; документировать |
| 5 | `validate_agents`: `bool("false")` → True | `normalize_agent` использует `bool()` | `settings_io.py` | Строгая проверка JSON bool |
| 6 | KPI-блок на вкладке «Настройки» | Общий layout | `app/layout.py` | Скрывать KPI на settings (косметика) |

---

## 7. Unknown agents / качество справочника

**После заполнения 125 agents (этап 8):**

| agent_key | source | count | Проблема |
|-----------|--------|------:|----------|
| unknown:Демидова Елена Николаевна | bitrix | 16 | Полное ФИО с отчеством не в `names_bitrix` у `demidova_elena` |
| unknown: | 1c | 1 | Пустой agent в выгрузке 1С |

**Рекомендации:**
- Добавить `Демидова Елена Николаевна` в aliases `demidova_elena` (не создавать вторую карточку).
- Проверить, не путаются ли `demidova_elena` (RM) и `demidova_ekaterina` (Marketing) в отчётах — разные agent_key, OK.

---

## 8. Рекомендуемый порядок hotfix-промптов

1. **Alias Демидова** — UI Settings или правка JSON + pipeline.
2. **Warning date_fallback** — pipeline: `if fallback_pct > 0.3: warnings.append(...)`.
3. **Smoke-test Settings save** — save 1 agent → проверить `data/backups/settings_*.json`.
4. **Фильтры UX** — banner «Изменения фильтров не применены» или auto-apply debounce.
5. **Cleanup data** — удалить xlsx из корня, оставить `input/`.

---

## 9. Что НЕ проверялось / ограничения среды

- Ручной браузерный проход всех вкладок и графиков (visual QA).
- Долгая работа сервера под нагрузкой / несколько пользователей LAN.
- PHP `index.php` на реальном веб-сервере (только code review).
- Plotly/Dash offline в браузере без интернета (зависит от serve_locally Dash 4.x).
- Автотесты pytest (отсутствуют).
- Безопасность LAN (по ТЗ auth не требуется — не pentest).
- Сводный отчет.xlsx — вне scope.

---

## Приложение A. Статический анализ (детали A–J)

| Пункт | Статус | Комментарий |
|-------|--------|-------------|
| A load_1c | OK | Индекс колонок, regex case/order, id str, source=1c |
| B load_bitrix | OK | NBSP trim, все deals, Успех только в unified |
| C build_unified | OK | Поля unified, is_refund, raw_id, agent_key/team |
| D resolve_agent | OK | unknown: prefix; fuzzy только headers Bitrix |
| E Dash tabs | OK | Обзор, Агенты, Структура, Воронка, Детализация, Настройки |
| F Filter chain | OK | store-filters → get_filtered_sales → KPI/charts/tables |
| G Settings UI | OK | draft, validate, atomic save, backup, apply=pipeline, dirty |
| H index.php | OK | port/url из settings, fsockopen → redirect |
| I Auth | N/A | Нет auth (соответствует ТЗ) |
| J CDN | OK | `external_stylesheets=[]`; внешних URL в app/assets нет |

---

## Приложение B. Соответствие README

| README | Реальность |
|--------|------------|
| `python -m parser.pipeline` | ✅ |
| `python -m app.main` | ✅ (после fix main.py) |
| Settings UI | ✅ |
| Excel-справочник не используется | ✅ (файл в корне есть, код не читает) |

---

*Конец отчёта.*
