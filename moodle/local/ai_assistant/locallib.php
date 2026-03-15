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
    $instructions = [
        'outline'    => 'Ти — досвідчений методист. Створи структуру курсу на 4 тижні. '
            . 'ОБОВ\'ЯЗКОВО починай відповідь з рядка "Назва курсу: <назва>" (без зірочок, без markdown). '
            . 'Потім для кожного тижня використовуй ТОЧНИЙ формат: "Тиждень N: Назва теми" (без зірочок). '
            . 'Для кожної теми тижня використовуй маркер "• тема". '
            . 'НЕ додавай жодних вступних коментарів, пояснень чи підсумків — тільки структуру курсу. '
            . 'Відповідай українською мовою.',
        'quiz'       => 'Створи 3 тестові питання. Виводь їх ТІЛЬКИ у форматі Moodle GIFT українською мовою. '
            . 'Без Markdown, без пояснень.',
        'assignment' => 'Створи детальне практичне завдання з критеріями оцінювання на основі запиту. '
            . 'Відповідай українською мовою.',
        'rewrite'    => 'Ти — досвідчений методист. Перероби наданий документ: покращ структуру, чіткість та '
            . 'відповідність сучасним академічним стандартам. Збережи основний зміст, але зроби його більш '
            . 'професійним. Відповідай українською мовою.',
    ];

    if (!isset($instructions[$task])) {
        return ['', 'Unknown task: ' . s($task)];
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
        . urlencode($apikey);

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $instructions[$task]]]],
        'contents'           => [['parts' => [['text' => $prompt]]]],
        'generationConfig'   => ['temperature' => $task === 'quiz' ? 0.2 : 0.7],
    ]);

    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw  = $curl->post($endpoint, $body);

    if ($curl->get_errno()) {
        return ['', 'cURL error: ' . $curl->error];
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['', 'Invalid JSON response from Gemini.'];
    }
    if (!empty($data['error'])) {
        return ['', 'Gemini API error: ' . ($data['error']['message'] ?? 'Unknown')];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return $text === '' ? ['', 'Gemini returned an empty response.'] : [$text, ''];
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
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
        . urlencode($apikey);

    $system = 'Ти — помічник. З наданого тексту вилучи лише коротку, чітку назву навчального курсу '
        . '(2–6 слів, заголовними літерами з великої, без лапок, без крапки в кінці). '
        . 'Відповідай ТІЛЬКИ назвою курсу — нічого іншого.';

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => [['text' => $raw_prompt]]]],
        'generationConfig'   => ['temperature' => 0.1, 'maxOutputTokens' => 30],
    ]);

    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw  = $curl->post($endpoint, $body);

    if ($curl->get_errno()) {
        return $raw_prompt;
    }

    $data = json_decode($raw, true);
    $name = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

    // Sanity check: if Gemini returns something too long or empty, fall back
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
    $system = <<<'SYS'
You are an academic document analyzer.

Analyze the provided document and determine if it contains a course syllabus or a structured course plan.

Respond ONLY with a single valid JSON object — no markdown fences, no extra commentary.
Use exactly this schema:
{
  "is_syllabus": <boolean>,
  "course_name": "<string — course title extracted from the document, or empty string>",
  "weeks": [
    {
      "title": "<short week/module title, e.g. Introduction to Python>",
      "topics": ["<topic 1>", "<topic 2>"]
    }
  ]
}

Rules:
- Set "is_syllabus" to true only if the document clearly describes a course schedule, learning objectives, or weekly topics.
- "weeks" must be an empty array when "is_syllabus" is false.
- Extract up to 12 weeks/modules maximum.
- Respond in the same language the document is written in for titles/topics.
SYS;

    // Truncate to avoid very large payloads (≈15 000 chars is plenty for detection)
    $truncated = mb_substr($text, 0, 15000);

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
        . urlencode($apikey);

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['parts' => [['text' => $truncated]]]],
        'generationConfig'   => [
            'temperature'      => 0.1,
            'responseMimeType' => 'application/json',
        ],
    ]);

    $curl = new curl();
    $curl->setHeader(['Content-Type: application/json']);
    $raw  = $curl->post($endpoint, $body);

    if ($curl->get_errno()) {
        return [];
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !empty($data['error'])) {
        return [];
    }

    $json_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Strip accidental markdown fences just in case
    $json_text = preg_replace('/^```(?:json)?\s*/i', '', trim($json_text));
    $json_text = preg_replace('/\s*```$/i', '', $json_text);

    $parsed = json_decode($json_text, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
        return [];
    }

    return $parsed;
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
 * Creates a Moodle course with week sections named after supplied topics.
 *
 * @param  string   $coursename Human-readable course name
 * @param  string[] $weeks      Array of week/section titles
 * @return int  New course ID, or 0 on failure
 */
function local_ai_assistant_create_moodle_course(string $coursename, array $weeks): int {
    global $CFG, $DB, $USER;
    require_once($CFG->dirroot . '/course/lib.php');

    $shortname = mb_substr(preg_replace('/\s+/', '_', trim($coursename)), 0, 15) . '_' . time();

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
    if (empty($uploaded_file['tmp_name']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
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