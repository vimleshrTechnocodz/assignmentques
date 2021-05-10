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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Administration settings definitions for the assignmentques module.
 *
 * @package   mod_assignmentques
 * @copyright 2010 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/lib.php');

// First get a list of assignmentques reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = core_component::get_plugin_list_with_file('assignmentques', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'assignmentques_'.$report);
    $reportsbyname[$strreportname] = $report;
}
core_collator::ksort($reportsbyname);

// First get a list of assignmentques reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = core_component::get_plugin_list_with_file('assignmentquesaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'assignmentquesaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
core_collator::ksort($rulesbyname);

// Create the assignmentques settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'assignmentques');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$assignmentquessettings = new admin_settingpage('modsettingassignmentques', $pagetitle, 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Introductory explanation that all the settings are defaults for the add assignmentques form.
    $assignmentquessettings->add(new admin_setting_heading('assignmentquesintro', '', get_string('configintro', 'assignmentques')));

    // Time limit.
    $assignmentquessettings->add(new admin_setting_configduration_with_advanced('assignmentques/timelimit',
            get_string('timelimit', 'assignmentques'), get_string('configtimelimitsec', 'assignmentques'),
            array('value' => '0', 'adv' => false), 60));

    // What to do with overdue attempts.
    $assignmentquessettings->add(new mod_assignmentques_admin_setting_overduehandling('assignmentques/overduehandling',
            get_string('overduehandling', 'assignmentques'), get_string('overduehandling_desc', 'assignmentques'),
            array('value' => 'autosubmit', 'adv' => false), null));

    // Grace period time.
    $assignmentquessettings->add(new admin_setting_configduration_with_advanced('assignmentques/graceperiod',
            get_string('graceperiod', 'assignmentques'), get_string('graceperiod_desc', 'assignmentques'),
            array('value' => '86400', 'adv' => false)));

    // Minimum grace period used behind the scenes.
    $assignmentquessettings->add(new admin_setting_configduration('assignmentques/graceperiodmin',
            get_string('graceperiodmin', 'assignmentques'), get_string('graceperiodmin_desc', 'assignmentques'),
            60, 1));

    // Number of attempts.
    $options = array(get_string('unlimited'));
    for ($i = 1; $i <= ASSIGNMENTQUES_MAX_ATTEMPT_OPTION; $i++) {
        $options[$i] = $i;
    }
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/attempts',
            get_string('attemptsallowed', 'assignmentques'), get_string('configattemptsallowed', 'assignmentques'),
            array('value' => 0, 'adv' => false), $options));

    // Grading method.
    $assignmentquessettings->add(new mod_assignmentques_admin_setting_grademethod('assignmentques/grademethod',
            get_string('grademethod', 'assignmentques'), get_string('configgrademethod', 'assignmentques'),
            array('value' => ASSIGNMENTQUES_GRADEHIGHEST, 'adv' => false), null));

    // Maximum grade.
    $assignmentquessettings->add(new admin_setting_configtext('assignmentques/maximumgrade',
            get_string('maximumgrade'), get_string('configmaximumgrade', 'assignmentques'), 10, PARAM_INT));

    // Questions per page.
    $perpage = array();
    $perpage[0] = get_string('never');
    $perpage[1] = get_string('aftereachquestion', 'assignmentques');
    for ($i = 2; $i <= ASSIGNMENTQUES_MAX_QPP_OPTION; ++$i) {
        $perpage[$i] = get_string('afternquestions', 'assignmentques', $i);
    }
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/questionsperpage',
            get_string('newpageevery', 'assignmentques'), get_string('confignewpageevery', 'assignmentques'),
            array('value' => 1, 'adv' => false), $perpage));

    // Navigation method.
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/navmethod',
            get_string('navmethod', 'assignmentques'), get_string('confignavmethod', 'assignmentques'),
            array('value' => ASSIGNMENTQUES_NAVMETHOD_FREE, 'adv' => true), assignmentques_get_navigation_options()));

    // Shuffle within questions.
    $assignmentquessettings->add(new admin_setting_configcheckbox_with_advanced('assignmentques/shuffleanswers',
            get_string('shufflewithin', 'assignmentques'), get_string('configshufflewithin', 'assignmentques'),
            array('value' => 1, 'adv' => false)));

    // Preferred behaviour.
    $assignmentquessettings->add(new admin_setting_question_behaviour('assignmentques/preferredbehaviour',
            get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'assignmentques'),
            'deferredfeedback'));

    // Can redo completed questions.
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/canredoquestions',
            get_string('canredoquestions', 'assignmentques'), get_string('canredoquestions_desc', 'assignmentques'),
            array('value' => 0, 'adv' => true),
            array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'assignmentques'))));

    // Each attempt builds on last.
    $assignmentquessettings->add(new admin_setting_configcheckbox_with_advanced('assignmentques/attemptonlast',
            get_string('eachattemptbuildsonthelast', 'assignmentques'),
            get_string('configeachattemptbuildsonthelast', 'assignmentques'),
            array('value' => 0, 'adv' => true)));

    // Review options.
    $assignmentquessettings->add(new admin_setting_heading('reviewheading',
            get_string('reviewoptionsheading', 'assignmentques'), ''));
    foreach (mod_assignmentques_admin_review_setting::fields() as $field => $name) {
        $default = mod_assignmentques_admin_review_setting::all_on();
        $forceduring = null;
        if ($field == 'attempt') {
            $forceduring = true;
        } else if ($field == 'overallfeedback') {
            $default = $default ^ mod_assignmentques_admin_review_setting::DURING;
            $forceduring = false;
        }
        $assignmentquessettings->add(new mod_assignmentques_admin_review_setting('assignmentques/review' . $field,
                $name, '', $default, $forceduring));
    }

    // Show the user's picture.
    $assignmentquessettings->add(new mod_assignmentques_admin_setting_user_image('assignmentques/showuserpicture',
            get_string('showuserpicture', 'assignmentques'), get_string('configshowuserpicture', 'assignmentques'),
            array('value' => 0, 'adv' => false), null));

    // Decimal places for overall grades.
    $options = array();
    for ($i = 0; $i <= ASSIGNMENTQUES_MAX_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/decimalpoints',
            get_string('decimalplaces', 'assignmentques'), get_string('configdecimalplaces', 'assignmentques'),
            array('value' => 2, 'adv' => false), $options));

    // Decimal places for question grades.
    $options = array(-1 => get_string('sameasoverall', 'assignmentques'));
    for ($i = 0; $i <= ASSIGNMENTQUES_MAX_Q_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $assignmentquessettings->add(new admin_setting_configselect_with_advanced('assignmentques/questiondecimalpoints',
            get_string('decimalplacesquestion', 'assignmentques'),
            get_string('configdecimalplacesquestion', 'assignmentques'),
            array('value' => -1, 'adv' => true), $options));

    // Show blocks during assignmentques attempts.
    $assignmentquessettings->add(new admin_setting_configcheckbox_with_advanced('assignmentques/showblocks',
            get_string('showblocks', 'assignmentques'), get_string('configshowblocks', 'assignmentques'),
            array('value' => 0, 'adv' => true)));

    // Password.
    $assignmentquessettings->add(new admin_setting_configpasswordunmask_with_advanced('assignmentques/password',
            get_string('requirepassword', 'assignmentques'), get_string('configrequirepassword', 'assignmentques'),
            array('value' => '', 'adv' => false)));

    // IP restrictions.
    $assignmentquessettings->add(new admin_setting_configtext_with_advanced('assignmentques/subnet',
            get_string('requiresubnet', 'assignmentques'), get_string('configrequiresubnet', 'assignmentques'),
            array('value' => '', 'adv' => true), PARAM_TEXT));

    // Enforced delay between attempts.
    $assignmentquessettings->add(new admin_setting_configduration_with_advanced('assignmentques/delay1',
            get_string('delay1st2nd', 'assignmentques'), get_string('configdelay1st2nd', 'assignmentques'),
            array('value' => 0, 'adv' => true), 60));
    $assignmentquessettings->add(new admin_setting_configduration_with_advanced('assignmentques/delay2',
            get_string('delaylater', 'assignmentques'), get_string('configdelaylater', 'assignmentques'),
            array('value' => 0, 'adv' => true), 60));

    // Browser security.
    $assignmentquessettings->add(new mod_assignmentques_admin_setting_browsersecurity('assignmentques/browsersecurity',
            get_string('showinsecurepopup', 'assignmentques'), get_string('configpopup', 'assignmentques'),
            array('value' => '-', 'adv' => true), null));

    $assignmentquessettings->add(new admin_setting_configtext('assignmentques/initialnumfeedbacks',
            get_string('initialnumfeedbacks', 'assignmentques'), get_string('initialnumfeedbacks_desc', 'assignmentques'),
            2, PARAM_INT, 5));

    // Allow user to specify if setting outcomes is an advanced setting.
    if (!empty($CFG->enableoutcomes)) {
        $assignmentquessettings->add(new admin_setting_configcheckbox('assignmentques/outcomes_adv',
            get_string('outcomesadvanced', 'assignmentques'), get_string('configoutcomesadvanced', 'assignmentques'),
            '0'));
    }

    // Autosave frequency.
    $assignmentquessettings->add(new admin_setting_configduration('assignmentques/autosaveperiod',
            get_string('autosaveperiod', 'assignmentques'), get_string('autosaveperiod_desc', 'assignmentques'), 60, 1));
}

// Now, depending on whether any reports have their own settings page, add
// the assignmentques setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $assignmentquessettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsassignmentquescat',
            get_string('modulename', 'assignmentques'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsassignmentquescat', $assignmentquessettings);

    // Add settings pages for the assignmentques report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsassignmentquescat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/assignmentques/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsassignmentquescat', $settings);
        }
    }

    // Add settings pages for the assignmentques access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsassignmentquescat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/assignmentques/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsassignmentquescat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
