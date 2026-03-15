<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../../course/lib.php');

require_login();
require_sesskey();

header('Content-Type: application/json');

$message  = required_param('ai_message', PARAM_TEXT);
$courseid = required_param('courseid', PARAM_INT);

$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

$apikey = get_config('local_ai_assistant', 'geminikey');

if (empty($apikey)) {
    echo json_encode(['reply' => 'API key не налаштовано. Перейдіть в Site Administration → Plugins → AI Course Assistant.', 'action' => null]);
    exit;
}

// Get current sections for context
$sections = $DB->get_records('course_sections',
    ['course' => $courseid], 'section ASC', 'id,section,name', 1, 20);
$sections_list = '';
foreach ($sections as $s) {
    $name = $s->name ?: 'Тиждень ' . $s->section;
    $sections_list .= "Тиждень {$s->section}: {$name}\n";
}

$system = "Ти — AI-асистент викладача в Moodle. Поточний курс має такі секції:\n{$sections_list}\n
Коли викладач просить щось змінити — відповідай JSON у форматі:
{\"reply\": \"текст відповіді\", \"action\": {\"type\": \"rename_section\", \"section\": 1, \"name\": \"Нова назва\"}}

Доступні дії:
- rename_section: {\"type\": \"rename_section\", \"section\": N, \"name\": \"Назва\"}
- add_section: {\"type\": \"add_section\", \"name\": \"Назва нової секції\"}

Якщо дія не потрібна — використовуй: \"action\": null
ЗАВЖДИ відповідай валідним JSON. Нічого крім JSON.";

$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
    . urlencode($apikey);

$body = json_encode([
    'system_instruction' => ['parts' => [['text' => $system]]],
    'contents'           => [['parts' => [['text' => $message]]]],
    'generationConfig'   => ['temperature' => 0.3],
]);

$curl = new curl();
$curl->setHeader(['Content-Type: application/json']);
$raw  = $curl->post($endpoint, $body);
$data = json_decode($raw, true);

if (!empty($data['error'])) {
    echo json_encode(['reply' => 'Gemini error: ' . $data['error']['message'], 'action' => null]);
    exit;
}

$text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$text   = preg_replace('/```json|```/i', '', $text);
$parsed = json_decode(trim($text), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['reply' => $text, 'action' => null]);
    exit;
}

$reply  = $parsed['reply'] ?? 'Готово!';
$action = $parsed['action'] ?? null;

if ($action && !empty($action['type'])) {
    if ($action['type'] === 'rename_section' && !empty($action['section'])) {
        $section = $DB->get_record('course_sections', [
            'course'  => $courseid,
            'section' => (int) $action['section'],
        ]);
        if ($section) {
            $section->name = clean_param($action['name'] ?? '', PARAM_TEXT);
            $DB->update_record('course_sections', $section);
            rebuild_course_cache($courseid, true);
        }
    } elseif ($action['type'] === 'add_section') {
        $maxsection = $DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = ?', [$courseid]);
        $newsection                = new stdClass();
        $newsection->course        = $courseid;
        $newsection->section       = ($maxsection ?? 0) + 1;
        $newsection->name          = clean_param($action['name'] ?? 'Нова тема', PARAM_TEXT);
        $newsection->visible       = 1;
        $newsection->summary       = '';
        $newsection->summaryformat = 1;
        $newsection->sequence      = '';
        $DB->insert_record('course_sections', $newsection);
        rebuild_course_cache($courseid, true);
    }
}

echo json_encode(['reply' => $reply, 'action' => $action]);