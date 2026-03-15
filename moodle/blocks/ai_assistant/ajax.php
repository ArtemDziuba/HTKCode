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
    echo json_encode(['reply' => 'API key не налаштовано. Перейдіть в Site Administration → Plugins → AI Course Assistant.', 'actions' => []]);
    exit;
}

// ─── Build context: sections ──────────────────────────────────────────────────
$sections = $DB->get_records('course_sections',
    ['course' => $courseid], 'section ASC', 'id,section,name', 1, 20);
$sections_list = '';
foreach ($sections as $s) {
    $label = $s->name ?: 'Тиждень ' . $s->section;
    $sections_list .= "  Тиждень {$s->section}: {$label}\n";
}

// ─── Build context: assignments ───────────────────────────────────────────────
$assignments = $DB->get_records_sql(
    "SELECT a.id, a.name, a.allowsubmissionsfromdate, a.duedate, a.cutoffdate, a.gradingduedate
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      WHERE a.course = ?
      ORDER BY a.name", [$courseid]);

$assignments_list = '';
foreach ($assignments as $a) {
    $from    = $a->allowsubmissionsfromdate ? date('d.m.Y H:i', $a->allowsubmissionsfromdate) : 'не задано';
    $due     = $a->duedate                  ? date('d.m.Y H:i', $a->duedate)                  : 'не задано';
    $cutoff  = $a->cutoffdate               ? date('d.m.Y H:i', $a->cutoffdate)               : 'не задано';
    $grading = $a->gradingduedate           ? date('d.m.Y H:i', $a->gradingduedate)           : 'не задано';
    $assignments_list .= "  ID {$a->id}: \"{$a->name}\"\n";
    $assignments_list .= "    allowsubmissionsfromdate: {$from}\n";
    $assignments_list .= "    duedate: {$due}\n";
    $assignments_list .= "    cutoffdate: {$cutoff}\n";
    $assignments_list .= "    gradingduedate: {$grading}\n";
}
if (!$assignments_list) {
    $assignments_list = "  (немає завдань у курсі)\n";
}

// ─── System prompt ────────────────────────────────────────────────────────────
$system = "Ти — AI-асистент викладача в Moodle. Відповідай ВИКЛЮЧНО валідним JSON — жодного тексту поза JSON.\n\n"
    . "Поточні секції курсу:\n{$sections_list}\n"
    . "Поточні завдання (Assignment) курсу:\n{$assignments_list}\n"
    . "Формат відповіді:\n"
    . "{\"reply\": \"текст для викладача\", \"actions\": [ ...масив дій або порожній масив... ]}\n\n"
    . "Доступні типи дій — можна повертати КІЛЬКА дій в одному масиві:\n\n"
    . "1. Перейменувати секцію:\n"
    . "   {\"type\": \"rename_section\", \"section\": N, \"name\": \"Нова назва\"}\n\n"
    . "2. Додати секцію:\n"
    . "   {\"type\": \"add_section\", \"name\": \"Назва нової секції\"}\n\n"
    . "3. Змінити дати завдання:\n"
    . "   {\"type\": \"update_assignment_dates\", \"assignment_id\": ID, \"dates\": {\n"
    . "       \"allowsubmissionsfromdate\": \"DD.MM.YYYY HH:MM\",\n"
    . "       \"duedate\":                  \"DD.MM.YYYY HH:MM\",\n"
    . "       \"cutoffdate\":               \"DD.MM.YYYY HH:MM\",\n"
    . "       \"gradingduedate\":           \"DD.MM.YYYY HH:MM\"\n"
    . "   }}\n"
    . "   ВАЖЛИВО: якщо викладач вказав не всі дати — ти ЗОБОВ'ЯЗАНИЙ порахувати решту,\n"
    . "   зберігаючи той самий інтервал у секундах між датами що був до зміни.\n"
    . "   Наприклад: якщо duedate зсувається на +3 дні, то cutoffdate і gradingduedate\n"
    . "   теж зсуваються на +3 дні (якщо вони були ненульовими).\n"
    . "   Завжди передавай ВСІ 4 поля в об'єкті dates, або \"0\" якщо дата не задана.\n\n"
    . "Приклади:\n"
    . "- \"Перейменуй тижні 1, 2 і 3 на Вступ, Основи, Практика\" → 3 дії rename_section\n"
    . "- \"Додай теми про Python та про SQL\" → 2 дії add_section\n"
    . "- \"Зсунь дедлайн завдання ID 5 на 3 дні\" → 1 дія update_assignment_dates з усіма перерахованими датами\n\n"
    . "Якщо жодної дії не потрібно — повертай \"actions\": [].";

// ─── Call Gemini ──────────────────────────────────────────────────────────────
$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
    . urlencode($apikey);

$body = json_encode([
    'system_instruction' => ['parts' => [['text' => $system]]],
    'contents'           => [['parts' => [['text' => $message]]]],
    'generationConfig'   => ['temperature' => 0.2],
]);

$curl = new curl();
$curl->setHeader(['Content-Type: application/json']);
$raw  = $curl->post($endpoint, $body);
$data = json_decode($raw, true);

if (!empty($data['error'])) {
    echo json_encode(['reply' => 'Gemini error: ' . $data['error']['message'], 'actions' => []]);
    exit;
}

$text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$text   = preg_replace('/```json\s*|```/i', '', $text);
$parsed = json_decode(trim($text), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['reply' => $text, 'actions' => []]);
    exit;
}

$reply   = $parsed['reply']   ?? 'Готово!';
$actions = $parsed['actions'] ?? [];

// ─── Execute actions ──────────────────────────────────────────────────────────
$executed        = [];
$needs_cache     = false;

foreach ($actions as $action) {
    $type = $action['type'] ?? '';

    // ── rename_section ────────────────────────────────────────────────────────
    if ($type === 'rename_section' && isset($action['section'])) {
        $section = $DB->get_record('course_sections', [
            'course'  => $courseid,
            'section' => (int) $action['section'],
        ]);
        if ($section) {
            $section->name = clean_param($action['name'] ?? '', PARAM_TEXT);
            $DB->update_record('course_sections', $section);
            $needs_cache = true;
            $executed[] = ['type' => 'rename_section', 'section' => (int)$action['section'], 'name' => $section->name];
        }

        // ── add_section ───────────────────────────────────────────────────────────
    } elseif ($type === 'add_section') {
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
        $needs_cache = true;
        $executed[] = ['type' => 'add_section', 'name' => $newsection->name];

        // ── update_assignment_dates ───────────────────────────────────────────────
    } elseif ($type === 'update_assignment_dates' && !empty($action['assignment_id'])) {
        $assignid = (int) $action['assignment_id'];
        $assign   = $DB->get_record('assign', ['id' => $assignid, 'course' => $courseid]);
        if (!$assign) {
            continue;
        }

        $date_fields = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'];
        $incoming    = $action['dates'] ?? [];

        // Convert incoming date strings to timestamps; null = not explicitly set
        $new_ts = [];
        foreach ($date_fields as $field) {
            $val = $incoming[$field] ?? null;
            if (!$val || $val === '0' || $val === 0) {
                $new_ts[$field] = null;
            } else {
                $dt = DateTime::createFromFormat('d.m.Y H:i', $val,
                    new DateTimeZone(core_date::get_user_timezone()));
                $new_ts[$field] = $dt ? $dt->getTimestamp() : null;
            }
        }

        // Find explicitly changed fields
        $changed = array_filter($new_ts, fn($v) => $v !== null);

        if (!empty($changed)) {
            // Compute offset from first explicitly changed field
            reset($changed);
            $ref_field  = key($changed);
            $old_ref_ts = (int) $assign->$ref_field;
            $new_ref_ts = $changed[$ref_field];
            $offset     = ($old_ref_ts > 0) ? ($new_ref_ts - $old_ref_ts) : 0;

            foreach ($date_fields as $field) {
                if (isset($changed[$field])) {
                    $assign->$field = $changed[$field];
                } elseif ((int)$assign->$field > 0 && $offset !== 0) {
                    $assign->$field = (int)$assign->$field + $offset;
                }
                // If was 0 and not in changed — leave as 0
            }

            $DB->update_record('assign', $assign);
            $needs_cache = true;

            $executed[] = [
                'type'          => 'update_assignment_dates',
                'assignment_id' => $assignid,
                'name'          => $assign->name,
                'dates'         => [
                    'allowsubmissionsfromdate' => $assign->allowsubmissionsfromdate
                        ? date('d.m.Y H:i', $assign->allowsubmissionsfromdate) : null,
                    'duedate'        => $assign->duedate        ? date('d.m.Y H:i', $assign->duedate)        : null,
                    'cutoffdate'     => $assign->cutoffdate     ? date('d.m.Y H:i', $assign->cutoffdate)     : null,
                    'gradingduedate' => $assign->gradingduedate ? date('d.m.Y H:i', $assign->gradingduedate) : null,
                ],
            ];
        }
    }
}

if ($needs_cache) {
    rebuild_course_cache($courseid, true);
}

echo json_encode(['reply' => $reply, 'actions' => $executed]);
