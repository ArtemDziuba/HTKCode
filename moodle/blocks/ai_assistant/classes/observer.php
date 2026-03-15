<?php
namespace block_ai_assistant;

defined('MOODLE_INTERNAL') || die();

class observer {
    public static function course_created(\core\event\course_created $event): void {
        global $DB;

        $courseid = $event->objectid;
        $context = \context_course::instance($courseid);

        $exists = $DB->record_exists('block_instances', [
            'blockname' => 'ai_assistant',
            'parentcontextid' => $context->id,
            'pagetypepattern' => 'course-view-*',
        ]);

        if ($exists) {
            return;
        }

        $bi = new \stdClass();
        $bi->blockname = 'ai_assistant';
        $bi->parentcontextid = $context->id;
        $bi->showinsubcontexts = 0;
        $bi->requiredbytheme = 0;
        $bi->pagetypepattern = 'course-view-*';
        $bi->subpagepattern = null;
        $bi->defaultregion = 'side-pre';
        $bi->defaultweight = -10;
        $bi->configdata = '';
        $bi->timecreated = time();
        $bi->timemodified = time();

        $DB->insert_record('block_instances', $bi);
    }
}