<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Chat - Admin settings page
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aichat', get_string('pluginname', 'local_aichat'));

    // -------------------------------------------------------------------------
    // General.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/generalheading',
        get_string('generalheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/enabled',
        get_string('enabled', 'local_aichat'),
        get_string('enabled_desc', 'local_aichat'),
        0
    ));

    // -------------------------------------------------------------------------
    // Azure OpenAI Connection.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/azureheading',
        get_string('azureheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/endpoint',
        get_string('endpoint', 'local_aichat'),
        get_string('endpoint_desc', 'local_aichat'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_aichat/apikey',
        get_string('apikey', 'local_aichat'),
        get_string('apikey_desc', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/chatdeployment',
        get_string('chatdeployment', 'local_aichat'),
        get_string('chatdeployment_desc', 'local_aichat'),
        '',
        '/^[a-zA-Z0-9._-]+$/'
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/embeddingdeployment',
        get_string('embeddingdeployment', 'local_aichat'),
        get_string('embeddingdeployment_desc', 'local_aichat'),
        '',
        '/^[a-zA-Z0-9._-]+$/'
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/apiversion',
        get_string('apiversion', 'local_aichat'),
        get_string('apiversion_desc', 'local_aichat'),
        '2024-08-01-preview',
        PARAM_NOTAGS
    ));

    // -------------------------------------------------------------------------
    // Model Configuration.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/modelheading',
        get_string('modelheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/maxtokens',
        get_string('maxtokens', 'local_aichat'),
        get_string('maxtokens_desc', 'local_aichat'),
        '1024',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/temperature',
        get_string('temperature', 'local_aichat'),
        get_string('temperature_desc', 'local_aichat'),
        '0.3',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aichat/systemprompt',
        get_string('systemprompt', 'local_aichat'),
        get_string('systemprompt_desc', 'local_aichat'),
        get_string('systemprompt_default', 'local_aichat')
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/historywindow',
        get_string('historywindow', 'local_aichat'),
        get_string('historywindow_desc', 'local_aichat'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/enablesuggestions',
        get_string('enablesuggestions', 'local_aichat'),
        get_string('enablesuggestions_desc', 'local_aichat'),
        0
    ));

    // -------------------------------------------------------------------------
    // RAG Configuration.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/ragheading',
        get_string('ragheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/ragtokenbudget',
        get_string('ragtokenbudget', 'local_aichat'),
        get_string('ragtokenbudget_desc', 'local_aichat'),
        '3000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/ragtopk',
        get_string('ragtopk', 'local_aichat'),
        get_string('ragtopk_desc', 'local_aichat'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/ragthreshold',
        get_string('ragthreshold', 'local_aichat'),
        get_string('ragthreshold_desc', 'local_aichat'),
        '0.7',
        PARAM_FLOAT
    ));

    // -------------------------------------------------------------------------
    // Usage Limits.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/limitsheading',
        get_string('limitsheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/dailylimit',
        get_string('dailylimit', 'local_aichat'),
        get_string('dailylimit_desc', 'local_aichat'),
        '50',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/burstlimit',
        get_string('burstlimit', 'local_aichat'),
        get_string('burstlimit_desc', 'local_aichat'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/maxmsglength',
        get_string('maxmsglength', 'local_aichat'),
        get_string('maxmsglength_desc', 'local_aichat'),
        '2000',
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // Privacy & Compliance.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/privacyheading',
        get_string('privacyheading', 'local_aichat'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_aichat/privacynotice',
        get_string('privacynotice', 'local_aichat'),
        get_string('privacynotice_desc', 'local_aichat'),
        get_string('privacynotice_default', 'local_aichat')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/showprivacynotice',
        get_string('showprivacynotice', 'local_aichat'),
        get_string('showprivacynotice_desc', 'local_aichat'),
        1
    ));

    // -------------------------------------------------------------------------
    // Security.
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/securityheading',
        get_string('securityheading', 'local_aichat'),
        get_string('securityheading_desc', 'local_aichat')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/cbenabled',
        get_string('cbenabled', 'local_aichat'),
        get_string('cbenabled_desc', 'local_aichat'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/cbfailurethreshold',
        get_string('cbfailurethreshold', 'local_aichat'),
        get_string('cbfailurethreshold_desc', 'local_aichat'),
        '3',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/cbcooldownminutes',
        get_string('cbcooldownminutes', 'local_aichat'),
        get_string('cbcooldownminutes_desc', 'local_aichat'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aichat/enablefilelog',
        get_string('enablefilelog', 'local_aichat'),
        get_string('enablefilelog_desc', 'local_aichat'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'local_aichat/loglevel',
        get_string('loglevel', 'local_aichat'),
        get_string('loglevel_desc', 'local_aichat'),
        'ERROR',
        [
            'DEBUG' => 'DEBUG',
            'INFO'  => 'INFO',
            'WARN'  => 'WARN',
            'ERROR' => 'ERROR',
        ]
    ));

    // -------------------------------------------------------------------------
    // Bot Appearance (Theming).
    // -------------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_aichat/themingheading',
        get_string('themingheading', 'local_aichat'),
        get_string('themingheading_desc', 'local_aichat')
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_aichat/primarycolor',
        get_string('primarycolor', 'local_aichat'),
        get_string('primarycolor_desc', 'local_aichat'),
        '#4f46e5'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_aichat/secondarycolor',
        get_string('secondarycolor', 'local_aichat'),
        get_string('secondarycolor_desc', 'local_aichat'),
        '#3730a3'
    ));

    $settings->add(new admin_setting_configtext(
        'local_aichat/headertitle',
        get_string('headertitle', 'local_aichat'),
        get_string('headertitle_desc', 'local_aichat'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_aichat/botavatar',
        get_string('botavatar', 'local_aichat'),
        get_string('botavatar_desc', 'local_aichat'),
        'botavatar',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.svg', '.jpg', '.jpeg']]
    ));

    // Register the settings page.
    $ADMIN->add('localplugins', $settings);

    // Register admin token usage dashboard as an external admin page.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_aichat_admindashboard',
        get_string('costdashboard', 'local_aichat'),
        new moodle_url('/local/aichat/admin_dashboard.php'),
        'local/aichat:viewadmindashboard'
    ));
}
