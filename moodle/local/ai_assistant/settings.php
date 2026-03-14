<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ai_assistant',
        get_string('pluginname', 'local_ai_assistant')
    );

    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_assistant/geminikey',
        get_string('setting_geminikey', 'local_ai_assistant'),
        get_string('setting_geminikey_desc', 'local_ai_assistant'),
        ''
    ));
}
