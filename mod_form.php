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
 * Defines the assignmentques module ettings form.
 *
 * @package    mod_assignmentques
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');


/**
 * Settings form for the assignmentques module.
 *
 * @copyright  2006 Jamie Pratt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignmentques_mod_form extends moodleform_mod {
    /** @var array options to be used with date_time_selector fields in the assignmentques. */
    public static $datefieldoptions = array('optional' => true);

    protected $_feedbacks;
    protected static $reviewfields = array(); // Initialised in the constructor.

    /** @var int the max number of attempts allowed in any user or group override on this assignmentques. */
    protected $maxattemptsanyoverride = null;

    public function __construct($current, $section, $cm, $course) {
        self::$reviewfields = array(
            'attempt'          => array('theattempt', 'assignmentques'),
            'correctness'      => array('whethercorrect', 'question'),
            'marks'            => array('marks', 'assignmentques'),
            'specificfeedback' => array('specificfeedback', 'question'),
            'generalfeedback'  => array('generalfeedback', 'question'),
            'rightanswer'      => array('rightanswer', 'question'),
            'overallfeedback'  => array('reviewoverallfeedback', 'assignmentques'),
        );
        parent::__construct($current, $section, $cm, $course);
    }

    protected function definition() {
        global $COURSE, $CFG, $DB, $PAGE;
        $assignmentquesconfig = get_config('assignmentques');
        $mform = $this->_form;

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name.
        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction.
        $this->standard_intro_elements(get_string('introduction', 'assignmentques'));

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'timing', get_string('timing', 'assignmentques'));

        // Open and close dates.
        $mform->addElement('date_time_selector', 'timeopen', get_string('assignmentquesopen', 'assignmentques'),
                self::$datefieldoptions);
        $mform->addHelpButton('timeopen', 'assignmentquesopenclose', 'assignmentques');

        $mform->addElement('date_time_selector', 'timeclose', get_string('assignmentquesclose', 'assignmentques'),
                self::$datefieldoptions);

        // Time limit.
        $mform->addElement('duration', 'timelimit', get_string('timelimit', 'assignmentques'),
                array('optional' => true));
        $mform->addHelpButton('timelimit', 'timelimit', 'assignmentques');
        $mform->setAdvanced('timelimit', $assignmentquesconfig->timelimit_adv);
        $mform->setDefault('timelimit', $assignmentquesconfig->timelimit);

        // What to do with overdue attempts.
        $mform->addElement('select', 'overduehandling', get_string('overduehandling', 'assignmentques'),
                assignmentques_get_overdue_handling_options());
        $mform->addHelpButton('overduehandling', 'overduehandling', 'assignmentques');
        $mform->setAdvanced('overduehandling', $assignmentquesconfig->overduehandling_adv);
        $mform->setDefault('overduehandling', $assignmentquesconfig->overduehandling);
        // TODO Formslib does OR logic on disableif, and we need AND logic here.
        // $mform->disabledIf('overduehandling', 'timelimit', 'eq', 0);
        // $mform->disabledIf('overduehandling', 'timeclose', 'eq', 0);

        // Grace period time.
        $mform->addElement('duration', 'graceperiod', get_string('graceperiod', 'assignmentques'),
                array('optional' => true));
        $mform->addHelpButton('graceperiod', 'graceperiod', 'assignmentques');
        $mform->setAdvanced('graceperiod', $assignmentquesconfig->graceperiod_adv);
        $mform->setDefault('graceperiod', $assignmentquesconfig->graceperiod);
        $mform->hideIf('graceperiod', 'overduehandling', 'neq', 'graceperiod');

        // -------------------------------------------------------------------------------
        // Grade settings.
        $this->standard_grading_coursemodule_elements();

        $mform->removeElement('grade');
        if (property_exists($this->current, 'grade')) {
            $currentgrade = $this->current->grade;
        } else {
            $currentgrade = $assignmentquesconfig->maximumgrade;
        }
        $mform->addElement('hidden', 'grade', $currentgrade);
        $mform->setType('grade', PARAM_FLOAT);

        // Number of attempts.
        $attemptoptions = array('0' => get_string('unlimited'));
        for ($i = 1; $i <= ASSIGNMENTQUES_MAX_ATTEMPT_OPTION; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'attempts', get_string('attemptsallowed', 'assignmentques'),
                $attemptoptions);
        $mform->setAdvanced('attempts', $assignmentquesconfig->attempts_adv);
        $mform->setDefault('attempts', $assignmentquesconfig->attempts);

        // Grading method.
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'assignmentques'),
                assignmentques_get_grading_options());
        $mform->addHelpButton('grademethod', 'grademethod', 'assignmentques');
        $mform->setAdvanced('grademethod', $assignmentquesconfig->grademethod_adv);
        $mform->setDefault('grademethod', $assignmentquesconfig->grademethod);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->hideIf('grademethod', 'attempts', 'eq', 1);
        }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'layouthdr', get_string('layout', 'assignmentques'));

        $pagegroup = array();
        $pagegroup[] = $mform->createElement('select', 'questionsperpage',
                get_string('newpage', 'assignmentques'), assignmentques_questions_per_page_options(), array('id' => 'id_questionsperpage'));
        //$mform->setDefault('questionsperpage', $assignmentquesconfig->questionsperpage);

        if (!empty($this->_cm)) {
            $pagegroup[] = $mform->createElement('checkbox', 'repaginatenow', '',
                    get_string('repaginatenow', 'assignmentques'), array('id' => 'id_repaginatenow'));
        }

        $mform->addGroup($pagegroup, 'questionsperpagegrp',
                get_string('newpage', 'assignmentques'), null, false);
        $mform->addHelpButton('questionsperpagegrp', 'newpage', 'assignmentques');
        $mform->setAdvanced('questionsperpagegrp', $assignmentquesconfig->questionsperpage_adv);

        // Navigation method.
        $mform->addElement('select', 'navmethod', get_string('navmethod', 'assignmentques'),
                assignmentques_get_navigation_options());
        $mform->addHelpButton('navmethod', 'navmethod', 'assignmentques');
        $mform->setAdvanced('navmethod', $assignmentquesconfig->navmethod_adv);
        $mform->setDefault('navmethod', $assignmentquesconfig->navmethod);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'interactionhdr', get_string('questionbehaviour', 'assignmentques'));

        // Shuffle within questions.
        $mform->addElement('selectyesno', 'shuffleanswers', get_string('shufflewithin', 'assignmentques'));
        $mform->addHelpButton('shuffleanswers', 'shufflewithin', 'assignmentques');
        $mform->setAdvanced('shuffleanswers', $assignmentquesconfig->shuffleanswers_adv);
        $mform->setDefault('shuffleanswers', $assignmentquesconfig->shuffleanswers);

        // How questions behave (question behaviour).
        if (!empty($this->current->preferredbehaviour)) {
            $currentbehaviour = $this->current->preferredbehaviour;
        } else {
            $currentbehaviour = '';
        }
        $behaviours = question_engine::get_behaviour_options($currentbehaviour);
        $mform->addElement('select', 'preferredbehaviour',
                get_string('howquestionsbehave', 'question'), $behaviours);
        $mform->addHelpButton('preferredbehaviour', 'howquestionsbehave', 'question');
        $mform->setDefault('preferredbehaviour', $assignmentquesconfig->preferredbehaviour);

        // Can redo completed questions.
        $redochoices = array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'assignmentques'));
        $mform->addElement('select', 'canredoquestions', get_string('canredoquestions', 'assignmentques'), $redochoices);
        $mform->addHelpButton('canredoquestions', 'canredoquestions', 'assignmentques');
        $mform->setAdvanced('canredoquestions', $assignmentquesconfig->canredoquestions_adv);
        $mform->setDefault('canredoquestions', $assignmentquesconfig->canredoquestions);
        foreach ($behaviours as $behaviour => $notused) {
            if (!question_engine::can_questions_finish_during_the_attempt($behaviour)) {
                $mform->hideIf('canredoquestions', 'preferredbehaviour', 'eq', $behaviour);
            }
        }

        // Each attempt builds on last.
        $mform->addElement('selectyesno', 'attemptonlast',
                get_string('eachattemptbuildsonthelast', 'assignmentques'));
        $mform->addHelpButton('attemptonlast', 'eachattemptbuildsonthelast', 'assignmentques');
        $mform->setAdvanced('attemptonlast', $assignmentquesconfig->attemptonlast_adv);
        $mform->setDefault('attemptonlast', $assignmentquesconfig->attemptonlast);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->hideIf('attemptonlast', 'attempts', 'eq', 1);
        }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'reviewoptionshdr',
                get_string('reviewoptionsheading', 'assignmentques'));
        $mform->addHelpButton('reviewoptionshdr', 'reviewoptionsheading', 'assignmentques');

        // Review options.
        $this->add_review_options_group($mform, $assignmentquesconfig, 'during',
                mod_assignmentques_display_options::DURING, true);
        $this->add_review_options_group($mform, $assignmentquesconfig, 'immediately',
                mod_assignmentques_display_options::IMMEDIATELY_AFTER);
        $this->add_review_options_group($mform, $assignmentquesconfig, 'open',
                mod_assignmentques_display_options::LATER_WHILE_OPEN);
        $this->add_review_options_group($mform, $assignmentquesconfig, 'closed',
                mod_assignmentques_display_options::AFTER_CLOSE);

        foreach ($behaviours as $behaviour => $notused) {
            $unusedoptions = question_engine::get_behaviour_unused_display_options($behaviour);
            foreach ($unusedoptions as $unusedoption) {
                $mform->disabledIf($unusedoption . 'during', 'preferredbehaviour',
                        'eq', $behaviour);
            }
        }
        $mform->disabledIf('attemptduring', 'preferredbehaviour',
                'neq', 'wontmatch');
        $mform->disabledIf('overallfeedbackduring', 'preferredbehaviour',
                'neq', 'wontmatch');
        foreach (self::$reviewfields as $field => $notused) {
            $mform->disabledIf($field . 'closed', 'timeclose[enabled]');
        }

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'display', get_string('appearance'));

        // Show user picture.
        $mform->addElement('select', 'showuserpicture', get_string('showuserpicture', 'assignmentques'),
                assignmentques_get_user_image_options());
        $mform->addHelpButton('showuserpicture', 'showuserpicture', 'assignmentques');
        $mform->setAdvanced('showuserpicture', $assignmentquesconfig->showuserpicture_adv);
        $mform->setDefault('showuserpicture', $assignmentquesconfig->showuserpicture);

        // Overall decimal points.
        $options = array();
        for ($i = 0; $i <= ASSIGNMENTQUES_MAX_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'decimalpoints', get_string('decimalplaces', 'assignmentques'),
                $options);
        $mform->addHelpButton('decimalpoints', 'decimalplaces', 'assignmentques');
        $mform->setAdvanced('decimalpoints', $assignmentquesconfig->decimalpoints_adv);
        $mform->setDefault('decimalpoints', $assignmentquesconfig->decimalpoints);

        // Question decimal points.
        $options = array(-1 => get_string('sameasoverall', 'assignmentques'));
        for ($i = 0; $i <= ASSIGNMENTQUES_MAX_Q_DECIMAL_OPTION; $i++) {
            $options[$i] = $i;
        }
        $mform->addElement('select', 'questiondecimalpoints',
                get_string('decimalplacesquestion', 'assignmentques'), $options);
        $mform->addHelpButton('questiondecimalpoints', 'decimalplacesquestion', 'assignmentques');
        $mform->setAdvanced('questiondecimalpoints', $assignmentquesconfig->questiondecimalpoints_adv);
        $mform->setDefault('questiondecimalpoints', $assignmentquesconfig->questiondecimalpoints);

        // Show blocks during assignmentques attempt.
        $mform->addElement('selectyesno', 'showblocks', get_string('showblocks', 'assignmentques'));
        $mform->addHelpButton('showblocks', 'showblocks', 'assignmentques');
        $mform->setAdvanced('showblocks', $assignmentquesconfig->showblocks_adv);
        $mform->setDefault('showblocks', $assignmentquesconfig->showblocks);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'security', get_string('extraattemptrestrictions', 'assignmentques'));

        // Require password to begin assignmentques attempt.
        $mform->addElement('passwordunmask', 'assignmentquespassword', get_string('requirepassword', 'assignmentques'));
        $mform->setType('assignmentquespassword', PARAM_TEXT);
        $mform->addHelpButton('assignmentquespassword', 'requirepassword', 'assignmentques');
        $mform->setAdvanced('assignmentquespassword', $assignmentquesconfig->password_adv);
        $mform->setDefault('assignmentquespassword', $assignmentquesconfig->password);

        // IP address.
        $mform->addElement('text', 'subnet', get_string('requiresubnet', 'assignmentques'));
        $mform->setType('subnet', PARAM_TEXT);
        $mform->addHelpButton('subnet', 'requiresubnet', 'assignmentques');
        $mform->setAdvanced('subnet', $assignmentquesconfig->subnet_adv);
        $mform->setDefault('subnet', $assignmentquesconfig->subnet);

        // Enforced time delay between assignmentques attempts.
        $mform->addElement('duration', 'delay1', get_string('delay1st2nd', 'assignmentques'),
                array('optional' => true));
        $mform->addHelpButton('delay1', 'delay1st2nd', 'assignmentques');
        $mform->setAdvanced('delay1', $assignmentquesconfig->delay1_adv);
        $mform->setDefault('delay1', $assignmentquesconfig->delay1);
        if ($this->get_max_attempts_for_any_override() < 2) {
            $mform->hideIf('delay1', 'attempts', 'eq', 1);
        }

        $mform->addElement('duration', 'delay2', get_string('delaylater', 'assignmentques'),
                array('optional' => true));
        $mform->addHelpButton('delay2', 'delaylater', 'assignmentques');
        $mform->setAdvanced('delay2', $assignmentquesconfig->delay2_adv);
        $mform->setDefault('delay2', $assignmentquesconfig->delay2);
        if ($this->get_max_attempts_for_any_override() < 3) {
            $mform->hideIf('delay2', 'attempts', 'eq', 1);
            $mform->hideIf('delay2', 'attempts', 'eq', 2);
        }

        // Browser security choices.
        $mform->addElement('select', 'browsersecurity', get_string('browsersecurity', 'assignmentques'),
                assignmentques_access_manager::get_browser_security_choices());
        $mform->addHelpButton('browsersecurity', 'browsersecurity', 'assignmentques');
        $mform->setAdvanced('browsersecurity', $assignmentquesconfig->browsersecurity_adv);
        $mform->setDefault('browsersecurity', $assignmentquesconfig->browsersecurity);

        // Any other rule plugins.
        assignmentques_access_manager::add_settings_form_fields($this, $mform);

        // -------------------------------------------------------------------------------
        $mform->addElement('header', 'overallfeedbackhdr', get_string('overallfeedback', 'assignmentques'));
        $mform->addHelpButton('overallfeedbackhdr', 'overallfeedback', 'assignmentques');

        if (isset($this->current->grade)) {
            $needwarning = $this->current->grade === 0;
        } else {
            $needwarning = $assignmentquesconfig->maximumgrade == 0;
        }
        if ($needwarning) {
            $mform->addElement('static', 'nogradewarning', '',
                    get_string('nogradewarning', 'assignmentques'));
        }

        $mform->addElement('static', 'gradeboundarystatic1',
                get_string('gradeboundary', 'assignmentques'), '100%');

        $repeatarray = array();
        $repeatedoptions = array();
        $repeatarray[] = $mform->createElement('editor', 'feedbacktext',
                get_string('feedback', 'assignmentques'), array('rows' => 3), array('maxfiles' => EDITOR_UNLIMITED_FILES,
                        'noclean' => true, 'context' => $this->context));
        $repeatarray[] = $mform->createElement('text', 'feedbackboundaries',
                get_string('gradeboundary', 'assignmentques'), array('size' => 10));
        $repeatedoptions['feedbacktext']['type'] = PARAM_RAW;
        $repeatedoptions['feedbackboundaries']['type'] = PARAM_RAW;

        if (!empty($this->_instance)) {
            $this->_feedbacks = $DB->get_records('assignmentques_feedback',
                    array('assignmentquesid' => $this->_instance), 'mingrade DESC');
            $numfeedbacks = count($this->_feedbacks);
        } else {
            $this->_feedbacks = array();
            $numfeedbacks = $assignmentquesconfig->initialnumfeedbacks;
        }
        $numfeedbacks = max($numfeedbacks, 1);

        $nextel = $this->repeat_elements($repeatarray, $numfeedbacks - 1,
                $repeatedoptions, 'boundary_repeats', 'boundary_add_fields', 3,
                get_string('addmoreoverallfeedbacks', 'assignmentques'), true);

        // Put some extra elements in before the button.
        $mform->insertElementBefore($mform->createElement('editor',
                "feedbacktext[$nextel]", get_string('feedback', 'assignmentques'), array('rows' => 3),
                array('maxfiles' => EDITOR_UNLIMITED_FILES, 'noclean' => true,
                      'context' => $this->context)),
                'boundary_add_fields');
        $mform->insertElementBefore($mform->createElement('static',
                'gradeboundarystatic2', get_string('gradeboundary', 'assignmentques'), '0%'),
                'boundary_add_fields');

        // Add the disabledif rules. We cannot do this using the $repeatoptions parameter to
        // repeat_elements because we don't want to dissable the first feedbacktext.
        for ($i = 0; $i < $nextel; $i++) {
            $mform->disabledIf('feedbackboundaries[' . $i . ']', 'grade', 'eq', 0);
            $mform->disabledIf('feedbacktext[' . ($i + 1) . ']', 'grade', 'eq', 0);
        }

        // -------------------------------------------------------------------------------
        $this->standard_coursemodule_elements();

        // Check and act on whether setting outcomes is considered an advanced setting.
        $mform->setAdvanced('modoutcomes', !empty($assignmentquesconfig->outcomes_adv));

        // The standard_coursemodule_elements method sets this to 100, but the
        // assignmentques has its own setting, so use that.
        $mform->setDefault('grade', $assignmentquesconfig->maximumgrade);

        // -------------------------------------------------------------------------------
        $this->add_action_buttons();

        $PAGE->requires->yui_module('moodle-mod_assignmentques-modform', 'M.mod_assignmentques.modform.init');
    }

    protected function add_review_options_group($mform, $assignmentquesconfig, $whenname,
            $when, $withhelp = false) {
        global $OUTPUT;

        $group = array();
        foreach (self::$reviewfields as $field => $string) {
            list($identifier, $component) = $string;

            $label = get_string($identifier, $component);
            if ($withhelp) {
                $label .= ' ' . $OUTPUT->help_icon($identifier, $component);
            }

            $group[] = $mform->createElement('checkbox', $field . $whenname, '', $label);
        }
        $mform->addGroup($group, $whenname . 'optionsgrp',
                get_string('review' . $whenname, 'assignmentques'), null, false);

        foreach (self::$reviewfields as $field => $notused) {
            $cfgfield = 'review' . $field;
            if ($assignmentquesconfig->$cfgfield & $when) {
                $mform->setDefault($field . $whenname, 1);
            } else {
                $mform->setDefault($field . $whenname, 0);
            }
        }

        if ($whenname != 'during') {
            $mform->disabledIf('correctness' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('specificfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('generalfeedback' . $whenname, 'attempt' . $whenname);
            $mform->disabledIf('rightanswer' . $whenname, 'attempt' . $whenname);
        }
    }

    protected function preprocessing_review_settings(&$toform, $whenname, $when) {
        foreach (self::$reviewfields as $field => $notused) {
            $fieldname = 'review' . $field;
            if (array_key_exists($fieldname, $toform)) {
                $toform[$field . $whenname] = $toform[$fieldname] & $when;
            }
        }
    }

    public function data_preprocessing(&$toform) {
        if (isset($toform['grade'])) {
            // Convert to a real number, so we don't get 0.0000.
            $toform['grade'] = $toform['grade'] + 0;
        }

        if (count($this->_feedbacks)) {
            $key = 0;
            foreach ($this->_feedbacks as $feedback) {
                $draftid = file_get_submitted_draft_itemid('feedbacktext['.$key.']');
                $toform['feedbacktext['.$key.']']['text'] = file_prepare_draft_area(
                    $draftid,               // Draftid.
                    $this->context->id,     // Context.
                    'mod_assignmentques',             // Component.
                    'feedback',             // Filarea.
                    !empty($feedback->id) ? (int) $feedback->id : null, // Itemid.
                    null,
                    $feedback->feedbacktext // Text.
                );
                $toform['feedbacktext['.$key.']']['format'] = $feedback->feedbacktextformat;
                $toform['feedbacktext['.$key.']']['itemid'] = $draftid;

                if ($toform['grade'] == 0) {
                    // When a assignmentques is un-graded, there can only be one lot of
                    // feedback. If the assignmentques previously had a maximum grade and
                    // several lots of feedback, we must now avoid putting text
                    // into input boxes that are disabled, but which the
                    // validation will insist are blank.
                    break;
                }

                if ($feedback->mingrade > 0) {
                    $toform['feedbackboundaries['.$key.']'] =
                            round(100.0 * $feedback->mingrade / $toform['grade'], 6) . '%';
                }
                $key++;
            }
        }

        if (isset($toform['timelimit'])) {
            $toform['timelimitenable'] = $toform['timelimit'] > 0;
        }

        $this->preprocessing_review_settings($toform, 'during',
                mod_assignmentques_display_options::DURING);
        $this->preprocessing_review_settings($toform, 'immediately',
                mod_assignmentques_display_options::IMMEDIATELY_AFTER);
        $this->preprocessing_review_settings($toform, 'open',
                mod_assignmentques_display_options::LATER_WHILE_OPEN);
        $this->preprocessing_review_settings($toform, 'closed',
                mod_assignmentques_display_options::AFTER_CLOSE);
        $toform['attemptduring'] = true;
        $toform['overallfeedbackduring'] = false;

        // Password field - different in form to stop browsers that remember
        // passwords from getting confused.
        if (isset($toform['password'])) {
            $toform['assignmentquespassword'] = $toform['password'];
            unset($toform['password']);
        }

        // Load any settings belonging to the access rules.
        if (!empty($toform['instance'])) {
            $accesssettings = assignmentques_access_manager::load_settings($toform['instance']);
            foreach ($accesssettings as $name => $value) {
                $toform[$name] = $value;
            }
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Check open and close times are consistent.
        if ($data['timeopen'] != 0 && $data['timeclose'] != 0 &&
                $data['timeclose'] < $data['timeopen']) {
            $errors['timeclose'] = get_string('closebeforeopen', 'assignmentques');
        }

        // Check that the grace period is not too short.
        if ($data['overduehandling'] == 'graceperiod') {
            $graceperiodmin = get_config('assignmentques', 'graceperiodmin');
            if ($data['graceperiod'] <= $graceperiodmin) {
                $errors['graceperiod'] = get_string('graceperiodtoosmall', 'assignmentques', format_time($graceperiodmin));
            }
        }

        if (array_key_exists('completion', $data) && $data['completion'] == COMPLETION_TRACKING_AUTOMATIC) {
            $completionpass = isset($data['completionpass']) ? $data['completionpass'] : $this->current->completionpass;

            // Show an error if require passing grade was selected and the grade to pass was set to 0.
            if ($completionpass && (empty($data['gradepass']) || grade_floatval($data['gradepass']) == 0)) {
                if (isset($data['completionpass'])) {
                    $errors['completionpassgroup'] = get_string('gradetopassnotset', 'assignmentques');
                } else {
                    $errors['gradepass'] = get_string('gradetopassmustbeset', 'assignmentques');
                }
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($data['feedbackboundaries'][$i] )) {
            $boundary = trim($data['feedbackboundaries'][$i]);
            if (strlen($boundary) > 0) {
                if ($boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $data['grade'] / 100.0;
                    } else {
                        $errors["feedbackboundaries[$i]"] =
                                get_string('feedbackerrorboundaryformat', 'assignmentques', $i + 1);
                    }
                } else if (!is_numeric($boundary)) {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorboundaryformat', 'assignmentques', $i + 1);
                }
            }
            if (is_numeric($boundary) && $boundary <= 0 || $boundary >= $data['grade'] ) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrorboundaryoutofrange', 'assignmentques', $i + 1);
            }
            if (is_numeric($boundary) && $i > 0 &&
                    $boundary >= $data['feedbackboundaries'][$i - 1]) {
                $errors["feedbackboundaries[$i]"] =
                        get_string('feedbackerrororder', 'assignmentques', $i + 1);
            }
            $data['feedbackboundaries'][$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($data['feedbackboundaries'])) {
            for ($i = $numboundaries; $i < count($data['feedbackboundaries']); $i += 1) {
                if (!empty($data['feedbackboundaries'][$i] ) &&
                        trim($data['feedbackboundaries'][$i] ) != '') {
                    $errors["feedbackboundaries[$i]"] =
                            get_string('feedbackerrorjunkinboundary', 'assignmentques', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($data['feedbacktext']); $i += 1) {
            if (!empty($data['feedbacktext'][$i]['text']) &&
                    trim($data['feedbacktext'][$i]['text'] ) != '') {
                $errors["feedbacktext[$i]"] =
                        get_string('feedbackerrorjunkinfeedback', 'assignmentques', $i + 1);
            }
        }

        // If CBM is involved, don't show the warning for grade to pass being larger than the maximum grade.
        if (($data['preferredbehaviour'] == 'deferredcbm') OR ($data['preferredbehaviour'] == 'immediatecbm')) {
            unset($errors['gradepass']);
        }
        // Any other rule plugins.
        $errors = assignmentques_access_manager::validate_settings_form_fields($errors, $data, $files, $this);

        return $errors;
    }

    /**
     * Display module-specific activity completion rules.
     * Part of the API defined by moodleform_mod
     * @return array Array of string IDs of added items, empty array if none
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $items = array();

        $group = array();
        $group[] = $mform->createElement('advcheckbox', 'completionpass', null, get_string('completionpass', 'assignmentques'),
                array('group' => 'cpass'));
        $mform->disabledIf('completionpass', 'completionusegrade', 'notchecked');
        $group[] = $mform->createElement('advcheckbox', 'completionattemptsexhausted', null,
                get_string('completionattemptsexhausted', 'assignmentques'),
                array('group' => 'cattempts'));
        $mform->disabledIf('completionattemptsexhausted', 'completionpass', 'notchecked');
        $mform->addGroup($group, 'completionpassgroup', get_string('completionpass', 'assignmentques'), ' &nbsp; ', false);
        $mform->addHelpButton('completionpassgroup', 'completionpass', 'assignmentques');
        $items[] = 'completionpassgroup';
        return $items;
    }

    /**
     * Called during validation. Indicates whether a module-specific completion rule is selected.
     *
     * @param array $data Input data (not yet validated)
     * @return bool True if one or more rules is enabled, false if none are.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionattemptsexhausted']) || !empty($data['completionpass']);
    }

    /**
     * Get the maximum number of attempts that anyone might have due to a user
     * or group override. Used to decide whether disabledIf rules should be applied.
     * @return int the number of attempts allowed. For the purpose of this method,
     * unlimited is returned as 1000, not 0.
     */
    public function get_max_attempts_for_any_override() {
        global $DB;

        if (empty($this->_instance)) {
            // Assignmentques not created yet, so no overrides.
            return 1;
        }

        if ($this->maxattemptsanyoverride === null) {
            $this->maxattemptsanyoverride = $DB->get_field_sql("
                    SELECT MAX(CASE WHEN attempts = 0 THEN 1000 ELSE attempts END)
                      FROM {assignmentques_overrides}
                     WHERE assignmentques = ?",
                    array($this->_instance));
            if ($this->maxattemptsanyoverride < 1) {
                // This happens when no override alters the number of attempts.
                $this->maxattemptsanyoverride = 1;
            }
        }

        return $this->maxattemptsanyoverride;
    }
}
