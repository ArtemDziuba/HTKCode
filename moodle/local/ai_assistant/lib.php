<?php
defined('MOODLE_INTERNAL') || die();

function local_ai_assistant_after_config(): void {
    global $CFG;

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $direct = $_GET['direct'] ?? '';

    // Never intercept our own page.
    if (str_ends_with($script, '/local/ai_assistant/create_course.php')) {
        return;
    }

    // Only intercept /course/edit.php
    if (!str_ends_with($script, '/course/edit.php')) {
        return;
    }

    // ?direct=1 means user already chose Manual on our page — let Moodle proceed.
    if (!empty($direct)) {
        return;
    }

    // Only intercept new course creation (no id = creating, with id = editing existing)
    $id = $_GET['id'] ?? '';
    if (!empty($id)) {
        return;
    }

    // Preserve the category parameter if present.
    $category = (int) ($_GET['category'] ?? 0);
    $qs = $category ? '?category=' . $category : '';

    header('Location: ' . rtrim($CFG->wwwroot, '/') . '/local/ai_assistant/create_course.php' . $qs);
    exit;
}