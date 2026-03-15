<?php
/**
 * AJAX endpoint for the course-creation chatbot.
 *
 * POST params:
 *   sesskey   — Moodle session key
 *   message   — user's latest message (text)
 *   category  — (int, optional) course category ID
 *
 * Optional file upload:
 *   ai_file[] — one or more files to attach / analyse
 *
 * Returns JSON:
 * {
 *   reply:     string,   // bot message to display
 *   ready:     bool,     // true = course was created
 *   course_id: int,      // set when ready = true
 *   course_url: string,  // set when ready = true
 *   error:     string    // set on failure
 * }
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

// ── Helpers ──────────────────────────────────────────────────────────────────
function json_out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── API key ──────────────────────────────────────────────────────────────────
$apikey = get_config('local_ai_assistant', 'claudekey')
    ?: get_config('local_ai_assistant', 'geminikey')
    ?: get_config('local_ai_assistant', 'apikey')
    ?: get_config('block_ai_assistant',  'claudekey')
    ?: get_config('block_ai_assistant',  'geminikey');

if (empty($apikey)) {
    json_out(['error' => 'API key не налаштовано. Зверніться до адміністратора.']);
}

// ── Category ─────────────────────────────────────────────────────────────────
$category = optional_param('category', 1, PARAM_INT);

// ── Session conversation history ─────────────────────────────────────────────
$session_key = 'local_ai_assistant_chat_' . $USER->id;
if (!isset($SESSION->{$session_key})) {
    $SESSION->{$session_key} = [];
}
$history = &$SESSION->{$session_key};

// ── User message ──────────────────────────────────────────────────────────────
$user_message = trim(optional_param('message', '', PARAM_TEXT));

// ── Pending files (persisted across turns) ───────────────────────────────────
$files_key = 'local_ai_assistant_files_' . $USER->id;
if (!isset($SESSION->{$files_key})) {
    $SESSION->{$files_key} = [];
}

// ── Handle newly uploaded files ───────────────────────────────────────────────
$file_texts = [];

if (!empty($_FILES['ai_file']['name'])) {
    $names  = (array) $_FILES['ai_file']['name'];
    $tmps   = (array) $_FILES['ai_file']['tmp_name'];
    $errors = (array) $_FILES['ai_file']['error'];

    foreach ($names as $i => $name) {
        if (empty($name) || ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        // Copy to a persistent temp path so it survives beyond this request
        $persistent = tempnam(sys_get_temp_dir(), 'aia_') ;
        if (move_uploaded_file($tmps[$i], $persistent)) {
            $entry = ['name' => $name, 'tmp_name' => $persistent, 'error' => 0, 'size' => filesize($persistent)];
            $SESSION->{$files_key}[] = $entry;

            [$text] = local_ai_assistant_extract_text($entry);
            if ($text !== '') {
                $file_texts[] = "=== {$name} ===\n" . mb_substr($text, 0, 3000);
            }
        }
    }
}

// Also extract text from previously stored files (for context in later turns)
foreach ($SESSION->{$files_key} as $entry) {
    $already = "=== {$entry['name']} ===";
    $alreadyInTexts = false;
    foreach ($file_texts as $ft) {
        if (str_starts_with($ft, $already)) { $alreadyInTexts = true; break; }
    }
    if (!$alreadyInTexts && file_exists($entry['tmp_name'])) {
        [$text] = local_ai_assistant_extract_text($entry);
        if ($text !== '') {
            $file_texts[] = $already . "\n" . mb_substr($text, 0, 3000);
        }
    }
}

$uploaded_files_raw = $SESSION->{$files_key};

// Build the full user turn content (message + file excerpts)
$full_user_message = $user_message;
if (!empty($file_texts)) {
    $full_user_message .= "\n\n[Завантажені файли:]\n" . implode("\n\n", $file_texts);
}

if ($full_user_message === '') {
    json_out(['error' => 'Порожнє повідомлення.']);
}

// Append user turn to history
$history[] = ['role' => 'user', 'content' => $full_user_message];

// ── AI turn ───────────────────────────────────────────────────────────────────
$ai = local_ai_assistant_chat_turn($history, $apikey);

$reply           = $ai['message']          ?? 'Щось пішло не так.';
$ready           = !empty($ai['ready']);
$course_name     = trim($ai['course_name']      ?? '');
$course_shortname = trim($ai['course_shortname'] ?? '');
$weeks_data      = $ai['weeks']             ?? [];

// Append assistant turn to history
$history[] = ['role' => 'assistant', 'content' => $reply];

// Keep history from growing too large (last 20 turns)
if (count($history) > 20) {
    $history = array_slice($history, -20);
}

// ── Create course when AI says ready ─────────────────────────────────────────
if ($ready && !empty($course_name) && !empty($weeks_data)) {

    $week_titles = array_filter(array_map(fn($w) => trim($w['title'] ?? ''), $weeks_data));

    $course_id = local_ai_assistant_create_moodle_course(
        $course_name,
        array_values($week_titles),
        $course_shortname ?: null
    );

    if ($course_id > 0) {
        // Section summaries
        local_ai_assistant_set_section_summaries($course_id, $weeks_data);

        // Attach uploaded files to best-matching sections
        foreach ($uploaded_files_raw as $ufile) {
            [$excerpt] = local_ai_assistant_extract_text($ufile);
            $sec = local_ai_assistant_match_file_to_section(
                $ufile['name'], $excerpt, array_values($week_titles), $apikey
            );
            local_ai_assistant_attach_file_to_course($course_id, $ufile, $sec);
        }

        // Build + attach syllabus DOCX
        $syllabus_text = "Назва курсу: {$course_name}\n\n";
        foreach ($weeks_data as $i => $w) {
            $syllabus_text .= "Тиждень " . ($i + 1) . ": " . ($w['title'] ?? '') . "\n";
            foreach (($w['topics'] ?? []) as $topic) {
                $syllabus_text .= "• {$topic}\n";
            }
            $syllabus_text .= "\n";
        }
        local_ai_assistant_attach_syllabus_to_course($course_id, $syllabus_text);

        // Clean up persistent temp files and clear session
        foreach ($SESSION->{$files_key} as $entry) {
            if (file_exists($entry['tmp_name'])) {
                @unlink($entry['tmp_name']);
            }
        }
        $SESSION->{$session_key} = [];
        $SESSION->{$files_key}   = [];

        $course_url = (new moodle_url('/course/view.php', ['id' => $course_id]))->out(false);

        json_out([
            'reply'      => $reply,
            'ready'      => true,
            'course_id'  => $course_id,
            'course_url' => $course_url,
        ]);
    } else {
        json_out(['reply' => $reply, 'ready' => false, 'error' => 'Не вдалося створити курс.']);
    }
}

json_out(['reply' => $reply, 'ready' => false]);