<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ai_assistant',
        get_string('pluginname', 'local_ai_assistant')
    );

    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ai_assistant/apikey',
        get_string('settings_apikey', 'local_ai_assistant'),
        get_string('settings_apikey_desc', 'local_ai_assistant'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_ai_assistant/model',
        get_string('settings_model', 'local_ai_assistant'),
        get_string('settings_model_desc', 'local_ai_assistant'),
        'claude-sonnet-4-20250514',
        [
            'claude-opus-4-20250514'   => 'Claude Opus 4',
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4 (рекомендовано)',
        ]
    ));
}
