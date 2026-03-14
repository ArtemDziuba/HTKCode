<?php
namespace local_ai_assistant\hook;

defined('MOODLE_INTERNAL') || die();

class course_edit_inject {

    /**
     * Inject the AI assistant panel on the new course creation page only.
     */
    public static function inject(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT;

        // Only fire on the course edit page.
        if ($PAGE->pagetype !== 'course-edit') {
            return;
        }

        // Only on new course creation (id = 0).
        $courseid = optional_param('id', 0, PARAM_INT);
        if ($courseid !== 0) {
            return;
        }

        // Check capability.
        $context = \context_system::instance();
        if (!has_capability('local/ai_assistant:use', $context)) {
            return;
        }

        // Load the AMD module.
        $PAGE->requires->js_call_amd('local_ai_assistant/panel', 'init');

        // Render and inject the panel template.
        $templatecontext = [
            'title'          => get_string('panel_title', 'local_ai_assistant'),
            'placeholder'    => get_string('panel_placeholder', 'local_ai_assistant'),
            'submit_label'   => get_string('panel_submit', 'local_ai_assistant'),
            'thinking_label' => get_string('panel_thinking', 'local_ai_assistant'),
            'clear_label'    => get_string('panel_clear', 'local_ai_assistant'),
            'copy_label'     => get_string('panel_copy', 'local_ai_assistant'),
            'copied_label'   => get_string('panel_copied', 'local_ai_assistant'),
            'error_msg'      => get_string('panel_error', 'local_ai_assistant'),
        ];

        $html = $OUTPUT->render_from_template('local_ai_assistant/panel', $templatecontext);
        $hook->add_html($html);
    }
}
