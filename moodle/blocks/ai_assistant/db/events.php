<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\block_ai_assistant\observer::course_created',
    ],
];