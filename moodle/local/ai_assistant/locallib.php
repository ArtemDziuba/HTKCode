<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

function local_ai_assistant_call_gemini(string $task, string $prompt, string $apikey): array {
    $instructions = [
        'outline'    => 'Ти — досвідчений методист. Створи структуру курсу на 4 тижні на основі наданої теми. Для кожного тижня ОБОВ\'ЯЗКОВО використовуй формат: "Тиждень N: Назва теми". Відповідай українською мовою.',
        'quiz'       => 'Створи 3 тестові питання. Виводь їх ТІЛЬКИ у форматі Moodle GIFT українською мовою. Без Markdown, без пояснень.',
        'assignment' => 'Створи детальне практичне завдання з критеріями оцінювання на основі запиту. Відповідай українською мовою.',
        'rewrite'    => 'Ти — досвідчений методист. Перероби наданий документ: покращ структуру, чіткість та відповідність сучасним академічним стандартам. Збережи основний зміст, але зроби його більш професійним. Відповідай українською мовою.',
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

/**
 * Parses "Тиждень N: Title" lines from Gemini outline text.
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

    // Fallback: split by blank lines
    if (empty($weeks)) {
        foreach (array_slice(array_filter(array_map('trim', explode("\n\n", $outline_text))), 0, 4) as $chunk) {
            $weeks[] = mb_substr(strip_tags(explode("\n", $chunk)[0]), 0, 100);
        }
    }

    return array_slice($weeks, 0, 8);
}

/**
 * Creates a Moodle course with week sections named after AI-generated topics.
 * Returns new course ID or 0 on failure.
 */
function local_ai_assistant_create_moodle_course(string $coursename, array $weeks): int {
    global $CFG, $DB;
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

    // Enrol the creator as editingteacher so course appears in My Courses
    global $USER;
    $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if ($roleid) {
        enrol_try_internal_enrol($newcourse->id, $USER->id, $roleid);
    }

    return $newcourse->id;
}

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