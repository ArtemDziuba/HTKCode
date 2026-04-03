<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// ── AI Generation ────────────────────────────────────────────────────────────

/**
 * Calls Gemini for content generation (outline, quiz, assignment, rewrite).
 *
 * @param  string $task     One of: outline, quiz, assignment, rewrite
 * @param  string $prompt   User-supplied text (may include extracted file content)
 * @param  string $apikey   Gemini API key
 * @return array  [string $result, string $error]
 */
function local_ai_assistant_call_gemini(string $task, string $prompt, string $apikey): array {
    $service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

    $body = json_encode(['task' => $task, 'prompt' => $prompt, 'api_key' => $apikey]);
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post(rtrim($service_url, '/') . '/generate', $body);

    if ($curl->get_errno()) {
        return ['', 'AI service error: ' . $curl->error];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['', 'Invalid response from AI service.'];
    }
    if (!empty($data['error'])) {
        return ['', $data['error']];
    }
    $result = $data['result'] ?? '';
    return $result !== '' ? [$result, ''] : ['', 'AI service returned an empty response.'];
}

// ── Chatbot Conversation ─────────────────────────────────────────────────────

/**
 * Drives one turn of the course-creation chatbot conversation.
 *
 * Sends the full conversation history to Gemini with a structured system
 * prompt. Gemini must reply ONLY with a JSON object:
 * {
 *   "message":     string   — what to say back to the user (Ukrainian),
 *   "ready":       bool     — true when enough info exists to create the course,
 *   "course_name": string   — extracted clean course title (or ""),
 *   "weeks":       [{"title": string, "topics": [string]}]  — course structure,
 *   "description": string   — one-sentence course description (or "")
 * }
 *
 * @param  array  $history   [{role:'user'|'assistant', content:string}, ...]
 * @param  string $apikey
 * @return array  Decoded JSON array, or ['message' => error_text, 'ready' => false]
 */
function local_ai_assistant_chat_turn(array $history, string $apikey, bool $has_syllabus = false): array {
    $service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

    $body = json_encode(['history' => $history, 'api_key' => $apikey, 'has_syllabus' => $has_syllabus]);
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post(rtrim($service_url, '/') . '/chat/turn', $body);

    if ($curl->get_errno()) {
        return ['message' => 'Помилка з\'єднання з AI-сервісом. Спробуйте ще раз.', 'ready' => false];
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return ['message' => 'Не вдалося розібрати відповідь AI-сервісу. Спробуйте ще раз.', 'ready' => false];
    }

    // Surface validation errors as the reply message so the user sees them
    if (!empty($parsed['validation_errors'])) {
        $parsed['message'] = implode("\n", $parsed['validation_errors']);
        $parsed['ready'] = false;
    }

    return $parsed;
}

// ── Course Name Extraction ───────────────────────────────────────────────────

/**
 * Asks Gemini to extract a clean, concise course title from a raw user prompt.
 * Falls back to the raw prompt if the call fails.
 *
 * @param  string $raw_prompt  What the user typed, e.g. "створи курс з лінійної алгебри"
 * @param  string $apikey
 * @return string  Clean title, e.g. "Лінійна Алгебра"
 */
function local_ai_assistant_extract_course_name(string $raw_prompt, string $apikey): string {
    $service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

    $body = json_encode(['prompt' => $raw_prompt, 'api_key' => $apikey]);
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post(rtrim($service_url, '/') . '/generate/course-name', $body);

    if ($curl->get_errno()) {
        return $raw_prompt;
    }

    $data = json_decode($raw, true);
    $name = trim($data['course_name'] ?? '');
    return ($name !== '' && mb_strlen($name) < 100) ? $name : $raw_prompt;
}

// ── Syllabus Cleanup ─────────────────────────────────────────────────────────

/**
 * Strips AI preamble and postamble from a raw Gemini outline response.
 * Keeps only lines from "Назва курсу:" / "Тиждень" onward, and drops
 * everything after the last bullet/week line.
 *
 * @param  string $text  Raw Gemini response
 * @return string  Cleaned syllabus text
 */
function local_ai_assistant_clean_syllabus_text(string $text): string {
    // Remove markdown bold (**text**) keeping the inner text
    $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
    // Remove markdown italic (*text* or _text_) but be careful not to strip bullets
    $text = preg_replace('/(?<![•\*])\*(?!\s)(.+?)(?<!\s)\*/', '$1', $text);
    // Remove leading/trailing ---
    $text = preg_replace('/^-{3,}\s*$/m', '', $text);

    $lines   = explode("\n", $text);
    $started = false;
    $kept    = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Start capturing from "Назва курсу:" or first "Тиждень N:"
        if (!$started) {
            if (preg_match('/^(назва курсу|тиждень\s*\d|week\s*\d)/iu', $trimmed)) {
                $started = true;
            } else {
                continue; // skip preamble
            }
        }

        $kept[] = $line;
    }

    // Strip trailing empty lines and postamble paragraphs (sentences after last bullet/week)
    $result = rtrim(implode("\n", $kept));

    // Remove trailing commentary: drop any non-bullet, non-week lines at the end
    $result_lines = explode("\n", $result);
    while (!empty($result_lines)) {
        $last = trim(end($result_lines));
        if ($last === '' || preg_match('/^[•*\-]|^тиждень|^week/iu', $last)) {
            break;
        }
        array_pop($result_lines);
    }

    return trim(implode("\n", $result_lines));
}

// ── Syllabus DOCX Builder ────────────────────────────────────────────────────

/**
 * Builds a formatted DOCX from cleaned syllabus text and attaches it to
 * section 0 of the given course as a mod_resource.
 *
 * Parsing rules:
 *   "Назва курсу: X"   → Heading 1
 *   "Тиждень N: X"     → Heading 2
 *   "• X" / "* X"      → bullet paragraph (indented)
 *   everything else    → normal paragraph
 *
 * @param  int    $course_id
 * @param  string $syllabus_text  Already-cleaned syllabus text
 * @return bool
 */
function local_ai_assistant_attach_syllabus_to_course(int $course_id, string $syllabus_text): bool {
    global $CFG, $DB, $USER;
    require_once($CFG->dirroot . '/course/lib.php');

    if (!class_exists('ZipArchive')) {
        return false;
    }

    // ── 1. Parse text into structured elements ────────────────────────────────
    $elements = []; // each: ['type' => 'h1'|'h2'|'bullet'|'normal', 'text' => string]

    foreach (explode("\n", $syllabus_text) as $raw_line) {
        $line = trim($raw_line);
        if ($line === '' || $line === '---') {
            continue;
        }

        if (preg_match('/^назва курсу\s*:\s*(.+)/iu', $line, $m)) {
            $elements[] = ['type' => 'h1', 'text' => trim($m[1])];
        } elseif (preg_match('/^(тиждень\s*\d+\s*[:\-\.]\s*.+)/iu', $line, $m)) {
            $elements[] = ['type' => 'h2', 'text' => trim($m[1])];
        } elseif (preg_match('/^[•*\-]\s+(.+)/', $line, $m)) {
            $elements[] = ['type' => 'bullet', 'text' => trim($m[1])];
        } else {
            $elements[] = ['type' => 'normal', 'text' => $line];
        }
    }

    if (empty($elements)) {
        return false;
    }

    // ── 2. Build DOCX XML ────────────────────────────────────────────────────
    $doc_xml = local_ai_assistant_build_docx_xml($elements);

    // Styles XML with Heading1, Heading2, ListBullet
    $styles_xml = local_ai_assistant_docx_styles_xml();

    // ── 3. Pack into a ZIP (DOCX) in Moodle's temp dir ──────────────────────
    $tmp_path = make_request_directory() . '/syllabus.docx';

    $zip = new ZipArchive();
    if ($zip->open($tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $zip->addFromString('[Content_Types].xml', local_ai_assistant_docx_content_types());
    $zip->addFromString('_rels/.rels',         local_ai_assistant_docx_rels());
    $zip->addFromString('word/document.xml',   $doc_xml);
    $zip->addFromString('word/styles.xml',     $styles_xml);
    $zip->addFromString('word/_rels/document.xml.rels', local_ai_assistant_docx_document_rels());
    $zip->addFromString('word/settings.xml',   local_ai_assistant_docx_settings());
    $zip->close();

    // ── 4. Attach the file to the course via Moodle file API ────────────────
    $module = $DB->get_record('modules', ['name' => 'resource'], 'id');
    if (!$module) {
        return false;
    }

    $draftitemid = file_get_unused_draft_itemid();
    $usercontext = context_user::instance($USER->id);

    $filerecord = [
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftitemid,
        'filepath'  => '/',
        'filename'  => 'Syllabus.docx',
    ];

    $fs = get_file_storage();
    try {
        $fs->create_file_from_pathname($filerecord, $tmp_path);
    } catch (Exception $e) {
        return false;
    }

    require_once($CFG->dirroot . '/course/modlib.php');
    if (!function_exists('add_moduleinfo')) {
        return false;
    }

    $course                           = get_course($course_id);
    $moduleinfo                       = new stdClass();
    $moduleinfo->modulename           = 'resource';
    $moduleinfo->module               = $module->id;
    $moduleinfo->course               = $course_id;
    $moduleinfo->section              = 0;
    $moduleinfo->visible              = 1;
    $moduleinfo->visibleoncoursepage  = 1;
    $moduleinfo->name                 = 'Силабус курсу';
    $moduleinfo->intro                = '';
    $moduleinfo->introformat          = FORMAT_HTML;
    $moduleinfo->printintro           = 0;
    $moduleinfo->files                = $draftitemid;
    $moduleinfo->display              = 0;
    $moduleinfo->showsize             = 1;
    $moduleinfo->showtype             = 1;
    $moduleinfo->showdate             = 0;
    $moduleinfo->cmidnumber           = '';
    $moduleinfo->groupmode            = 0;
    $moduleinfo->groupingid           = 0;
    $moduleinfo->availability         = null;
    $moduleinfo->completion           = 0;
    $moduleinfo->completionview       = 0;
    $moduleinfo->completionexpected   = 0;
    $moduleinfo->completionunlocked   = 1;
    $moduleinfo->tags                 = [];

    try {
        add_moduleinfo($moduleinfo, $course);
        return true;
    } catch (Exception $e) {
        debugging('local_ai_assistant: syllabus DOCX attach failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

// ── DOCX XML helpers ─────────────────────────────────────────────────────────

function local_ai_assistant_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function local_ai_assistant_build_docx_xml(array $elements): string {
    $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $paragraphs = '';

    foreach ($elements as $el) {
        $text = local_ai_assistant_xml_escape($el['text']);
        switch ($el['type']) {
            case 'h1':
                $paragraphs .= <<<XML
<w:p>
  <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
  <w:r><w:t xml:space="preserve">{$text}</w:t></w:r>
</w:p>
XML;
                break;
            case 'h2':
                $paragraphs .= <<<XML
<w:p>
  <w:pPr><w:pStyle w:val="Heading2"/></w:pPr>
  <w:r><w:t xml:space="preserve">{$text}</w:t></w:r>
</w:p>
XML;
                break;
            case 'bullet':
                $paragraphs .= <<<XML
<w:p>
  <w:pPr>
    <w:pStyle w:val="ListBullet"/>
    <w:ind w:left="720" w:hanging="360"/>
  </w:pPr>
  <w:r><w:t xml:space="preserve">{$text}</w:t></w:r>
</w:p>
XML;
                break;
            default:
                $paragraphs .= <<<XML
<w:p>
  <w:r><w:t xml:space="preserve">{$text}</w:t></w:r>
</w:p>
XML;
        }
    }

    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document
  xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
  xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
  xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
{$paragraphs}
    <w:sectPr>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>
    </w:sectPr>
  </w:body>
</w:document>
XML;
}

function local_ai_assistant_docx_styles_xml(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
        <w:sz w:val="24"/>
      </w:rPr>
    </w:rPrDefault>
  </w:docDefaults>
  <w:style w:type="paragraph" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:pPr><w:spacing w:after="160"/></w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:outlineLvl w:val="0"/>
      <w:spacing w:before="240" w:after="160"/>
    </w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:b/>
      <w:sz w:val="36"/>
      <w:color w:val="1F3864"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading2">
    <w:name w:val="heading 2"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:outlineLvl w:val="1"/>
      <w:spacing w:before="200" w:after="100"/>
    </w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:b/>
      <w:sz w:val="28"/>
      <w:color w:val="2E75B6"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="ListBullet">
    <w:name w:val="List Bullet"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:before="40" w:after="40"/>
      <w:ind w:left="720" w:hanging="360"/>
    </w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>
      <w:sz w:val="22"/>
    </w:rPr>
  </w:style>
</w:styles>
XML;
}

function local_ai_assistant_docx_content_types(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
</Types>
XML;
}

function local_ai_assistant_docx_rels(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>
XML;
}

function local_ai_assistant_docx_document_rels(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"
    Target="settings.xml"/>
</Relationships>
XML;
}

function local_ai_assistant_docx_settings(): string {
    return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:defaultTabStop w:val="720"/>
</w:settings>
XML;
}

// ── Syllabus Detection ───────────────────────────────────────────────────────

/**
 * Sends document text to Gemini and asks whether it contains a course syllabus.
 * Forces a JSON response using Gemini's responseMimeType feature.
 *
 * Returned array shape (on success):
 *   [
 *     'is_syllabus'  => bool,
 *     'course_name'  => string,
 *     'weeks'        => [['title' => string, 'topics' => string[]], ...]
 *   ]
 *
 * Returns [] on any error.
 *
 * @param  string $text   Extracted text from uploaded document
 * @param  string $apikey Gemini API key
 * @return array
 */
function local_ai_assistant_detect_syllabus_json(string $text, string $apikey): array {
    $service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

    $body = json_encode(['text' => $text, 'api_key' => $apikey]);
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post(rtrim($service_url, '/') . '/analyze/syllabus', $body);

    if ($curl->get_errno()) {
        return [];
    }

    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

// ── Course Creation ──────────────────────────────────────────────────────────

/**
 * Parses "Тиждень N: Title" (or "Week N: Title") lines from Gemini outline text.
 *
 * @param  string $outline_text Raw text returned by Gemini outline task
 * @return string[]  Array of week title strings
 */
function local_ai_assistant_parse_weeks(string $outline_text): array {
    $weeks = [];
    foreach (explode("\n", $outline_text) as $line) {
        $line = trim($line);
        if (preg_match('/(?:тиждень|week)\s*\d+\s*[:\-\.]\s*\*{0,2}(.+)/iu', $line, $m)) {
            $title = trim(preg_replace('/\*+/', '', $m[1]));
            if ($title !== '') {
                $weeks[] = $title;
            }
        }
    }

    // Fallback: split by blank lines and take first line of each chunk
    if (empty($weeks)) {
        foreach (array_slice(array_filter(array_map('trim', explode("\n\n", $outline_text))), 0, 4) as $chunk) {
            $weeks[] = mb_substr(strip_tags(explode("\n", $chunk)[0]), 0, 100);
        }
    }

    return array_slice($weeks, 0, 12);
}

/**
 * Parses a cleaned syllabus text into a structured array of weeks with topics.
 * Each entry: ['title' => string, 'topics' => string[]]
 *
 * @param  string $outline_text  Cleaned outline text
 * @return array
 */
function local_ai_assistant_parse_weeks_full(string $outline_text): array {
    $weeks   = [];
    $current = null;

    foreach (explode("\n", $outline_text) as $raw) {
        $line = trim($raw);
        if ($line === '' || $line === '---') {
            continue;
        }

        // Week heading: "Тиждень N: Title" or "Week N: Title"
        if (preg_match('/^(?:тиждень|week)\s*\d+\s*[:\-\.]\s*(.+)/iu', $line, $m)) {
            if ($current !== null) {
                $weeks[] = $current;
            }
            $current = ['title' => trim(preg_replace('/\*+/', '', $m[1])), 'topics' => []];
            continue;
        }

        // Bullet topic line
        if ($current !== null && preg_match('/^[•*\-]\s+(.+)/', $line, $m)) {
            $current['topics'][] = trim($m[1]);
        }
    }

    if ($current !== null) {
        $weeks[] = $current;
    }

    return array_slice($weeks, 0, 12);
}

/**
 * Writes an HTML summary to each week section of a course.
 * The summary lists the week's topics as a <ul> and is visible
 * under the section heading on the course page.
 *
 * @param  int   $course_id
 * @param  array $weeks_full  Output of local_ai_assistant_parse_weeks_full()
 * @return void
 */
function local_ai_assistant_set_section_summaries(int $course_id, array $weeks_full): void {
    global $DB;

    foreach ($weeks_full as $i => $week) {
        if (empty($week['topics'])) {
            continue;
        }

        $section = $DB->get_record('course_sections', [
            'course'  => $course_id,
            'section' => $i + 1,
        ]);
        if (!$section) {
            continue;
        }

        $items = '';
        foreach ($week['topics'] as $topic) {
            $items .= '<li>' . htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        $DB->set_field('course_sections', 'summary',
            '<ul>' . $items . '</ul>', ['id' => $section->id]);
        $DB->set_field('course_sections', 'summaryformat',
            FORMAT_HTML, ['id' => $section->id]);
    }
    // No cache rebuild here — caller does it after all files are attached
}

/**
 * Asks Gemini which week section (1-based) a given file best belongs to.
 * Returns the matched section number, or 0 if no good match (→ General section).
 *
 * @param  string   $filename     Original filename
 * @param  string   $file_excerpt First ~800 chars of extracted text (or empty)
 * @param  string[] $week_titles  Ordered list of week titles
 * @param  string   $apikey
 * @return int  1-based section number, or 0 for General
 */
function local_ai_assistant_match_file_to_section(
    string $filename,
    string $file_excerpt,
    array  $week_titles,
    string $apikey
): int {
    if (empty($week_titles)) {
        return 0;
    }

    $service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

    $body = json_encode([
        'filename'    => $filename,
        'excerpt'     => mb_substr($file_excerpt, 0, 800),
        'week_titles' => array_values($week_titles),
        'api_key'     => $apikey,
    ]);
    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw = $curl->post(rtrim($service_url, '/') . '/analyze/match-section', $body);

    if ($curl->get_errno()) {
        return 0;
    }

    $data = json_decode($raw, true);
    return (int) ($data['section'] ?? 0);
}

/**
 * Creates a Moodle course with week sections named after supplied topics.
 *
 * @param  string   $coursename Human-readable course name
 * @param  string[] $weeks      Array of week/section titles
 * @return int  New course ID, or 0 on failure
 */
function local_ai_assistant_create_moodle_course(string $coursename, array $weeks, ?string $shortname = null): int {
    global $CFG, $DB, $USER;
    require_once($CFG->dirroot . '/course/lib.php');

    // Use user-provided shortname if given, otherwise auto-generate
    if (!empty($shortname)) {
        // Sanitise: keep letters, digits, hyphens, underscores; cap at 100 chars
        $shortname = preg_replace('/[^a-zA-Z0-9\-_а-яА-ЯіІїЇєЄ]/u', '', $shortname);
        $shortname = mb_substr(trim($shortname), 0, 100);
    }
    if (empty($shortname)) {
        $shortname = mb_substr(preg_replace('/\s+/', '_', trim($coursename)), 0, 15) . '_' . time();
    }

    // Ensure uniqueness
    $base   = $shortname;
    $suffix = 1;
    while ($DB->record_exists('course', ['shortname' => $shortname])) {
        $shortname = $base . '_' . $suffix++;
    }

    $coursedata              = new stdClass();
    $coursedata->fullname    = $coursename;
    $coursedata->shortname   = $shortname;
    $coursedata->category    = 1;
    $coursedata->format      = 'weeks';
    $coursedata->numsections = max(count($weeks), 1);
    $coursedata->visible     = 1;
    $coursedata->startdate   = time();

    try {
        $newcourse = create_course($coursedata);
    } catch (Exception $e) {
        return 0;
    }

    // Name each section after the AI-generated week title
    foreach ($weeks as $i => $title) {
        $section = $DB->get_record('course_sections', [
            'course'  => $newcourse->id,
            'section' => $i + 1,
        ]);
        if ($section) {
            $section->name    = clean_param($title, PARAM_TEXT);
            $section->visible = 1;
            $DB->update_record('course_sections', $section);
        }
    }

    rebuild_course_cache($newcourse->id, true);

    // Enrol the creator as editingteacher so the course appears in My Courses
    $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if ($roleid) {
        enrol_try_internal_enrol($newcourse->id, $USER->id, $roleid);
    }

    return $newcourse->id;
}

// ── File Attachment ──────────────────────────────────────────────────────────

/**
 * Attaches an uploaded file to a course as a File resource on section 0.
 *
 * The file is first saved to the current user's draft file area, then a
 * mod_resource module is created on the course so the file is visible on
 * the course page.
 *
 * @param  int   $course_id     Target course ID
 * @param  array $uploaded_file Entry from $_FILES (must have 'tmp_name' and 'name')
 * @param  int   $section       Course section number (0 = General/top section)
 * @return bool  true on success, false on any failure
 */
function local_ai_assistant_attach_file_to_course(int $course_id, array $uploaded_file, int $section = 0): bool {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/course/modlib.php');

    if (!function_exists('add_moduleinfo')) {
        return false;
    }
    if (empty($uploaded_file['tmp_name']) || !file_exists($uploaded_file['tmp_name'])) {
        return false;
    }

    $module = $DB->get_record('modules', ['name' => 'resource'], 'id');
    if (!$module) {
        return false; // mod_resource not installed
    }

    // ── 1. Save the uploaded file into the user's draft area ─────────────────
    $draftitemid = file_get_unused_draft_itemid();
    $usercontext = context_user::instance($USER->id);

    $filerecord = [
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftitemid,
        'filepath'  => '/',
        'filename'  => clean_filename($uploaded_file['name']),
    ];

    $fs = get_file_storage();
    try {
        $fs->create_file_from_pathname($filerecord, $uploaded_file['tmp_name']);
    } catch (Exception $e) {
        return false;
    }

    // ── 2. Build the minimum moduleinfo stdClass for add_moduleinfo ───────────
    $course = get_course($course_id);

    $moduleinfo                       = new stdClass();
    $moduleinfo->modulename           = 'resource';
    $moduleinfo->module               = $module->id;
    $moduleinfo->course               = $course_id;
    $moduleinfo->section              = $section;
    $moduleinfo->visible              = 1;
    $moduleinfo->visibleoncoursepage  = 1;
    $moduleinfo->name                 = pathinfo(
        clean_filename($uploaded_file['name']),
        PATHINFO_FILENAME
    );
    $moduleinfo->intro                = '';
    $moduleinfo->introformat          = FORMAT_HTML;
    $moduleinfo->printintro           = 0;

    // 'files' is the draft item ID — Moodle moves the file into
    // the resource's own context after add_moduleinfo().
    $moduleinfo->files                = $draftitemid;

    // Display: 0 = RESOURCELIB_DISPLAY_AUTO (open in browser or download)
    $moduleinfo->display              = 0;
    $moduleinfo->showsize             = 0;
    $moduleinfo->showtype             = 0;
    $moduleinfo->showdate             = 0;

    // Standard course-module fields required by add_moduleinfo
    $moduleinfo->cmidnumber           = '';
    $moduleinfo->groupmode            = 0;
    $moduleinfo->groupingid           = 0;
    $moduleinfo->availability         = null;
    $moduleinfo->completion           = 0;
    $moduleinfo->completionview       = 0;
    $moduleinfo->completionexpected   = 0;
    $moduleinfo->completionunlocked   = 1;
    $moduleinfo->tags                 = [];

    try {
        add_moduleinfo($moduleinfo, $course);
        return true;
    } catch (Exception $e) {
        return false;
    }
}


// ── Document Text Extraction ─────────────────────────────────────────────────

/**
 * Routes to the appropriate extractor based on file extension.
 *
 * @param  array $file  Entry from $_FILES
 * @return array [string $text, string $error]
 */
function local_ai_assistant_extract_text(array $file): array {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['docx', 'pdf', 'txt'], true)) {
        return ['', 'Unsupported file type. Please upload DOCX, PDF, or TXT.'];
    }
    if ($ext === 'txt') {
        $text = file_get_contents($file['tmp_name']);
        return $text === false ? ['', 'Could not read TXT file.'] : [trim($text), ''];
    }
    if ($ext === 'docx') {
        return local_ai_assistant_extract_docx($file['tmp_name']);
    }
    return local_ai_assistant_extract_pdf($file['tmp_name']);
}

/**
 * Extracts plain text from a DOCX file (via ZipArchive + word/document.xml).
 */
function local_ai_assistant_extract_docx(string $path): array {
    if (!class_exists('ZipArchive')) {
        return ['', 'ZipArchive PHP extension not available.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['', 'Could not open DOCX file.'];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return ['', 'Could not read DOCX content.'];
    }
    $xml  = preg_replace('/<\/w:p>/', "\n", $xml);
    $xml  = preg_replace('/<\/w:r>/', ' ', $xml);
    $text = trim(preg_replace('/\n{3,}/', "\n\n",
        preg_replace('/[ \t]+/', ' ',
            html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8')
        )
    ));
    return $text === '' ? ['', 'DOCX appears empty.'] : [$text, ''];
}

/**
 * Extracts plain text from a PDF file (requires smalot/pdfparser via Composer).
 */
function local_ai_assistant_extract_pdf(string $path): array {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['', 'PDF library not found. Use DOCX or TXT instead.'];
    }
    require_once($autoload);
    try {
        $pdf  = (new \Smalot\PdfParser\Parser())->parseFile($path);
        $text = $pdf->getText();
        return trim($text) === ''
            ? ['', 'Could not extract text from PDF. Try DOCX or TXT.']
            : [trim($text), ''];
    } catch (\Exception $e) {
        return ['', 'PDF error: ' . $e->getMessage()];
    }
}