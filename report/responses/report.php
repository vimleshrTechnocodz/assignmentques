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
 * This file defines the assignmentques responses report class.
 *
 * @package   assignmentques_responses
 * @copyright 2006 Jean-Michel Vedrine
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/responses/responses_options.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/responses/responses_form.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/responses/last_responses_table.php');
require_once($CFG->dirroot . '/mod/assignmentques/report/responses/first_or_all_responses_table.php');


/**
 * Assignmentques report subclass for the responses report.
 *
 * This report lists some combination of
 *  * what question each student saw (this makes sense if random questions were used).
 *  * the response they gave,
 *  * and what the right answer is.
 *
 * Like the overview report, there are options for showing students with/without
 * attempts, and for deleting selected attempts.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentques_responses_report extends assignmentques_attempts_report {

    public function display($assignmentques, $cm, $course) {
        global $OUTPUT, $DB;

        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $this->init(
                'responses', 'assignmentques_responses_settings_form', $assignmentques, $cm, $course);

        $options = new assignmentques_responses_options('responses', $assignmentques, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);

        } else {
            $options->process_settings_from_params();
        }

        $this->form->set_data($options->get_initial_form_data());

        // Load the required questions.
        $questions = assignmentques_report_get_significant_questions($assignmentques);

        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                array('context' => context_course::instance($course->id)));
        if ($options->whichtries === question_attempt::LAST_TRY) {
            $tableclassname = 'assignmentques_last_responses_table';
        } else {
            $tableclassname = 'assignmentques_first_or_all_responses_table';
        }
        $table = new $tableclassname($assignmentques, $this->context, $this->qmsubselect,
                $options, $groupstudentsjoins, $studentsjoins, $questions, $options->get_url());
        $filename = assignmentques_report_download_filename(get_string('responsesfilename', 'assignmentques_responses'),
                $courseshortname, $assignmentques->name);
        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($assignmentques->name, true));
        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->hasgroupstudents = false;
        if (!empty($groupstudentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                    $groupstudentsjoins->joins
                     WHERE $groupstudentsjoins->wheres";
            $this->hasgroupstudents = $DB->record_exists_sql($sql, $groupstudentsjoins->params);
        }
        $hasstudents = false;
        if (!empty($studentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                    FROM {user} u
                    $studentsjoins->joins
                    WHERE $studentsjoins->wheres";
            $hasstudents = $DB->record_exists_sql($sql, $studentsjoins->params);
        }
        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups in
            // groups mode, so setting allowed to empty (which means all assignmentques attempts
            // are accessible, is not a security problem.
            $allowedjoins = new \core\dml\sql_join();
        }

        $this->process_actions($assignmentques, $cm, $currentgroup, $groupstudentsjoins, $allowedjoins, $options->get_url());

        $hasquestions = assignmentques_has_questions($assignmentques->id);

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
            $this->print_standard_header_and_messages($cm, $course, $assignmentques,
                    $options, $currentgroup, $hasquestions, $hasstudents);

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $hasstudents && (!$currentgroup || $this->hasgroupstudents);
        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {

            $table->setup_sql_queries($allowedjoins);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = assignmentques_report_highlighting_grading_method(
                        $assignmentques, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="assignmentquesattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = array();
            $headers = array();

            if (!$table->is_downloading() && $options->checkboxcolumn) {
                $columns[] = 'checkbox';
                $headers[] = null;
            }

            $this->add_user_columns($table, $columns, $headers);
            $this->add_state_column($columns, $headers);

            if ($table->is_downloading()) {
                $this->add_time_columns($columns, $headers);
            }

            $this->add_grade_columns($assignmentques, $options->usercanseegrades, $columns, $headers);

            foreach ($questions as $id => $question) {
                if ($options->showqtext) {
                    $columns[] = 'question' . $id;
                    $headers[] = get_string('questionx', 'question', $question->number);
                }
                if ($options->showresponses) {
                    $columns[] = 'response' . $id;
                    $headers[] = get_string('responsex', 'assignmentques_responses', $question->number);
                }
                if ($options->showright) {
                    $columns[] = 'right' . $id;
                    $headers[] = get_string('rightanswerx', 'assignmentques_responses', $question->number);
                }
            }

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->sortable(true, 'uniqueid');

            // Set up the table.
            $table->define_baseurl($options->get_url());

            $this->configure_user_columns($table);

            $table->no_sorting('feedbacktext');
            $table->column_class('sumgrades', 'bold');

            $table->set_attribute('id', 'responses');

            $table->collapsible(true);

            $table->out($options->pagesize, true);
        }
        return true;
    }
}
