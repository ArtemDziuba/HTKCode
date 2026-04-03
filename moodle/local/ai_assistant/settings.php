<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ai_assistant',
        get_string('pluginname', 'local_ai_assistant')
    );

    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_ai_assistant/service_url',
        get_string('setting_service_url', 'local_ai_assistant'),
        get_string('setting_service_url_desc', 'local_ai_assistant'),
        'http://localhost:8008'
    ));
}
