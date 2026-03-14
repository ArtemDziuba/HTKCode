<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_assistant_ask' => [
        'classname'     => \local_ai_assistant\external\ask::class,
        'methodname'    => 'execute',
        'description'   => 'Send a prompt to Claude and receive a generated text response.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
        'capabilities'  => 'local/ai_assistant:use',
    ],
];
