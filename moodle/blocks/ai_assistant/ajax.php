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

// API key is managed by the AI service via its own environment variable.
$apikey = '';

// ─── Fetch course context (sections + assignments) ────────────────────────────
$sections = $DB->get_records('course_sections',
    ['course' => $courseid], 'section ASC', 'id,section,name', 1, 20);

$assignments = $DB->get_records_sql(
    "SELECT a.id, a.name, a.allowsubmissionsfromdate, a.duedate, a.cutoffdate, a.gradingduedate
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      WHERE a.course = ?
      ORDER BY a.name", [$courseid]);

// ─── Call AI service ──────────────────────────────────────────────────────────
$service_url = get_config('local_ai_assistant', 'service_url') ?: 'http://localhost:8008';

// Build structured sections/assignments arrays for the service
$sections_payload = [];
foreach ($sections as $s) {
    $sections_payload[] = [
        'section' => (int) $s->section,
        'name'    => $s->name ?: 'Тиждень ' . $s->section,
    ];
}

$assignments_payload = [];
foreach ($assignments as $a) {
    $assignments_payload[] = [
        'id'                         => (int) $a->id,
        'name'                       => $a->name,
        'allowsubmissionsfromdate'   => $a->allowsubmissionsfromdate ? date('d.m.Y H:i', $a->allowsubmissionsfromdate) : 'не задано',
        'duedate'                    => $a->duedate                  ? date('d.m.Y H:i', $a->duedate)                  : 'не задано',
        'cutoffdate'                 => $a->cutoffdate               ? date('d.m.Y H:i', $a->cutoffdate)               : 'не задано',
        'gradingduedate'             => $a->gradingduedate           ? date('d.m.Y H:i', $a->gradingduedate)           : 'не задано',
    ];
}

$body = json_encode([
    'message'     => $message,
    'sections'    => $sections_payload,
    'assignments' => $assignments_payload,
    'api_key'     => $apikey,
]);

$curl = new curl();
$curl->setHeader(['Content-Type: application/json']);
$raw  = $curl->post(rtrim($service_url, '/') . '/analyze/course-editor', $body);
$parsed = json_decode($raw, true);

if ($curl->get_errno() || !is_array($parsed)) {
    echo json_encode(['reply' => 'Помилка з\'єднання з AI-сервісом.', 'actions' => []]);
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
