# moodle-local-ai-assistant

> ШІ-асистент для викладачів — плагін для Moodle 4.4+

Локальний плагін Moodle, який інтегрує Claude AI прямо на сторінку створення курсу.  
Викладач описує курс українською — асистент генерує структуру, модулі та результати навчання.

---

## Вимоги

- Moodle 4.4 або новіший
- PHP 8.1+
- Ключ API Anthropic ([console.anthropic.com](https://console.anthropic.com))

---

## Встановлення

```bash
# 1. Клонуйте репозиторій в директорію Moodle
git clone https://github.com/YOUR_ORG/moodle-local-ai-assistant \
    /path/to/moodle/local/ai_assistant

# 2. Відкрийте Moodle як адміністратор — система автоматично запропонує встановити плагін

# 3. Після встановлення перейдіть до:
#    Адміністрування сайту → Плагіни → ШІ-асистент
#    та введіть ключ API Anthropic
```

---

## Використання

1. Увійдіть як викладач або адміністратор
2. Перейдіть до **Адміністрування сайту → Курси → Додати новий курс**
3. У правому куті з'явиться панель **ШІ-асистент**
4. Опишіть курс у полі та натисніть **Згенерувати**
5. Скопіюйте результат у поля форми курсу

---

## Налаштування

| Параметр | Опис |
|---|---|
| `Ключ API Anthropic` | Ваш секретний ключ з console.anthropic.com |
| `Модель Claude` | Рекомендовано: Claude Sonnet 4 |

---

## Структура плагіну

```
local/ai_assistant/
├── classes/
│   ├── external/ask.php          ← AJAX-ендпоінт → Claude API
│   └── hook/course_edit_inject.php ← Інжектує панель у сторінку
├── amd/src/panel.js             ← Логіка UI панелі
├── templates/panel.mustache     ← HTML + CSS панелі
├── lang/uk/                     ← Рядки українською
├── db/
│   ├── access.php               ← Права доступу
│   ├── hooks.php                ← Реєстрація хуків
│   └── services.php             ← Веб-сервіси
├── settings.php                 ← Сторінка налаштувань
└── version.php
```

---

## Розробка

```bash
# Запустити Moodle через moodle-docker
export MOODLE_DOCKER_WWWROOT=~/moodle-dev/moodle
export MOODLE_DOCKER_DB=pgsql
export MOODLE_DOCKER_WEB_PORT=1234
export COMPOSE_PROJECT_NAME=moodle34

cd ~/moodle-dev/moodle-docker
bin/moodle-docker-compose up -d

# Очистити кеш після змін у PHP
bin/moodle-docker-compose exec webserver php admin/cli/purge_caches.php

# Перекомпілювати AMD JS після змін у panel.js
bin/moodle-docker-compose exec webserver \
    php admin/cli/build_js.php --component=local_ai_assistant
```

---

## Ліцензія

GNU GPL v3
