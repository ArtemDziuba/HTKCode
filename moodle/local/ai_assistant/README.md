# local_ai_assistant — Moodle AI Course Assistant

A local Moodle plugin (Moodle 4.3+) that intercepts the standard "Create new course"
flow and offers lecturers a choice between manual creation and AI-powered content generation
(powered by Google Gemini).

## Features

- Replaces the standard course-creation entry point with a two-option page
- **Manual** path → standard Moodle course editor (unchanged)
- **AI path** → generate a course outline, GIFT-format quiz questions, or a graded assignment
- Calls the Google Gemini API directly from PHP (no Python dependency)
- API key stored securely in Moodle's encrypted admin settings
- Fully bilingual: English + Ukrainian

## Installation

1. Copy the `local/ai_assistant` folder into `<moodle_root>/local/`.
2. Log in as admin and navigate to **Site Administration → Notifications** — Moodle will detect
   the new plugin and run the one-step install.
3. Go to **Site Administration → Plugins → AI Course Assistant** and paste your
   [Gemini API key](https://aistudio.google.com/app/apikey).

> **Docker shortcut:**
> ```bash
> bin/moodle-docker-compose exec webserver php admin/cli/upgrade.php --non-interactive
> ```

## File structure

```
local/ai_assistant/
├── version.php          Plugin metadata
├── lib.php              after_config() hook – intercepts course/edit.php
├── locallib.php         Gemini API wrapper
├── settings.php         Admin settings (API key)
├── create_course.php    Main page: choice cards + AI form
├── db/
│   └── access.php       Capabilities (currently empty)
└── lang/
    ├── en/local_ai_assistant.php
    └── uk/local_ai_assistant.php
```

## How the intercept works

`lib.php` registers a `local_ai_assistant_after_config()` callback. This runs on every
page load right after Moodle's config is set up. If the current URL is
`/course/edit.php?action=createcourse` **and** the `?direct=1` flag is absent, the user
is redirected to our choice page. Choosing "Manual" adds `?direct=1` so Moodle proceeds
normally.

## Removing the old block plugin

The `blocks/ai_assistant` block is superseded by this plugin. To remove it:

```bash
# Inside the container
bin/moodle-docker-compose exec webserver php admin/cli/uninstall_plugin.php \
    --plugins=block_ai_assistant --run
```

Then delete the `blocks/ai_assistant` folder from your Moodle source tree.
