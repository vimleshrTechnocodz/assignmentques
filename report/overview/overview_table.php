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
 * This file defines the assignmentques grades table.
 *
 * @package   assignmentques_overview
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/report/attemptsreport_table.php');


/**
 * This is a table subclass for displaying the assignmentques grades report.
 *
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignmentques_overview_table extends assignmentques_attempts_report_table {

    protected $regradedqs = array();

    /**
     * Constructor
     * @param object $assignmentques
     * @param context $context
     * @param string $qmsubselect
     * @param assignmentques_overview_options $options
     * @param \core\dml\sql_join $groupstudentsjoins
     * @param \core\dml\sql_join $studentsjoins
     * @param array $questions
     * @param moodle_url $reporturl
     */
    public function __construct($assignmentques, $context, $qmsubselect,
            assignmentques_overview_options $options, \core\dml\sql_join $groupstudentsjoins,
            \core\dml\sql_join $studentsjoins, $questions, $reporturl) {
        parent::__construct('mod-assignmentques-report-overview-report', $assignmentques , $context,
                $qmsubselect, $options, $groupstudentsjoins, $studentsjoins, $questions, $reporturl);
    }

    public function build_table() {
        global $DB;

        if (!$this->rawdata) {
            return;
        }

        $this->strtimeformat = str_replace(',', ' ', get_string('strftimedatetime'));
        parent::build_table();

        // End of adding the data from attempts. Now add averages at bottom.
        $this->add_separator();

        if (!empty($this->groupstudentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                    {$this->groupstudentsjoins->joins}
                     WHERE {$this->groupstudentsjoins->wheres}";
            $groupstudents = $DB->get_records_sql($sql, $this->groupstudentsjoins->params);
            if ($groupstudents) {
                $this->add_average_row(get_string('groupavg', 'grades'), $this->groupstudentsjoins);
            }
        }

        if (!empty($this->studentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                    {$this->studentsjoins->joins}
                     WHERE {$this->studentsjoins->wheres}";
            $students = $DB->get_records_sql($sql, $this->studentsjoins->params);
            if ($students) {
                $this->add_average_row(get_string('overallaverage', 'grades'), $this->studentsjoins);
            }
        }
    }

    /**
     * Calculate the average overall and question scores for a set of attempts at the assignmentques.
     *
     * @param string $label the title ot use for this row.
     * @param \core\dml\sql_join $usersjoins to indicate a set of users.
     * @return array of table cells that make up the average row.
     */
    public function compute_average_row($label, \core\dml\sql_join $usersjoins) {
        global $DB;

        list($fields, $from, $where, $params) = $this->base_sql($usersjoins);
        $record = $DB->get_record_sql("
                SELECT AVG(assignmentquesaouter.sumgrades) AS grade, COUNT(assignmentquesaouter.sumgrades) AS numaveraged
                  FROM {assignmentques_attempts} assignmentquesaouter
                  JOIN (
                       SELECT DISTINCT assignmentquesa.id
                         FROM $from
                        WHERE $where
                       ) relevant_attempt_ids ON assignmentquesaouter.id = relevant_attempt_ids.id
                ", $params);
        $record->grade = assignmentques_rescale_grade($record->grade, $this->assignmentques, false);
        if ($this->is_downloading()) {
            $namekey = 'lastname';
        } else {
            $namekey = 'fullname';
        }
        $averagerow = array(
            $namekey       => $label,
            'sumgrades'    => $this->format_average($record),
            'feedbacktext' => strip_tags(assignmentques_report_feedback_for_grade(
                                         $record->grade, $this->assignmentques->id, $this->context))
        );

        if ($this->options->slotmarks) {
            $dm = new question_engine_data_mapper();
            $qubaids = new qubaid_join("{assignmentques_attempts} assignmentquesaouter
                  JOIN (
                       SELECT DISTINCT assignmentquesa.id
                         FROM $from
                        WHERE $where
                       ) relevant_attempt_ids ON assignmentquesaouter.id = relevant_attempt_ids.id",
                    'assignmentquesaouter.uniqueid', '1 = 1', $params);
            $avggradebyq = $dm->load_average_marks($qubaids, array_keys($this->questions));

            $averagerow += $this->format_average_grade_for_questions($avggradebyq);
        }

        return $averagerow;
    }

    /**
     * Add an average grade row for a set of users.
     *
     * @param string $label the title ot use for this row.
     * @param \core\dml\sql_join $usersjoins (joins, wheres, params) for the users to average over.
     */
    protected function add_average_row($label, \core\dml\sql_join $usersjoins) {
        //$averagerow = $this->compute_average_row($label, $usersjoins);
        //$this->add_data_keyed($averagerow);
    }

    /**
     * Helper userd by {@link add_average_row()}.
     * @param array $gradeaverages the raw grades.
     * @return array the (partial) row of data.
     */
    protected function format_average_grade_for_questions($gradeaverages) {
        $row = array();

        if (!$gradeaverages) {
            $gradeaverages = array();
        }

        foreach ($this->questions as $question) {
            if (isset($gradeaverages[$question->slot]) && $question->maxmark > 0) {
                $record = $gradeaverages[$question->slot];
                $record->grade = assignmentques_rescale_grade(
                        $record->averagefraction * $question->maxmark, $this->assignmentques, false);

            } else {
                $record = new stdClass();
                $record->grade = null;
                $record->numaveraged = 0;
            }

            $row['qsgrade' . $question->slot] = $this->format_average($record, true);
        }

        return $row;
    }

    /**
     * Format an entry in an average row.
     * @param object $record with fields grade and numaveraged.
     * @param bool $question true if this is a question score, false if it is an overall score.
     * @return string HTML fragment for an average score (with number of things included in the average).
     */
    protected function format_average($record, $question = false) {
        if (is_null($record->grade)) {
            $average = '-';
        } else if ($question) {
            $average = assignmentques_format_question_grade($this->assignmentques, $record->grade);
        } else {
            $average = assignmentques_format_grade($this->assignmentques, $record->grade);
        }

        if ($this->download) {
            return $average;
        } else if (is_null($record->numaveraged) || $record->numaveraged == 0) {
            return html_writer::tag('span', html_writer::tag('span',
                    $average, array('class' => 'average')), array('class' => 'avgcell'));
        } else {
            return html_writer::tag('span', html_writer::tag('span',
                    $average, array('class' => 'average')) . ' ' . html_writer::tag('span',
                    '(' . $record->numaveraged . ')', array('class' => 'count')),
                    array('class' => 'avgcell'));
        }
    }

    protected function submit_buttons() {
        if (has_capability('mod/assignmentques:regrade', $this->context)) {
            echo '<input type="submit" class="btn btn-secondary mr-1" name="regrade" value="' .
                    get_string('regradeselected', 'assignmentques_overview') . '"/>';
        }
        parent::submit_buttons();
    }

    public function col_sumgrades($attempt) {
        if ($attempt->state != assignmentques_attempt::FINISHED) {
            return '-';
        }

        $grade = assignmentques_rescale_grade($attempt->sumgrades, $this->assignmentques);
        if ($this->is_downloading()) {
            return $grade;
        }

        if (isset($this->regradedqs[$attempt->usageid])) {
            $newsumgrade = 0;
            $oldsumgrade = 0;
            foreach ($this->questions as $question) {
                if (isset($this->regradedqs[$attempt->usageid][$question->slot])) {
                    $newsumgrade += $this->regradedqs[$attempt->usageid]
                            [$question->slot]->newfraction * $question->maxmark;
                    $oldsumgrade += $this->regradedqs[$attempt->usageid]
                            [$question->slot]->oldfraction * $question->maxmark;
                } else {
                    $newsumgrade += $this->lateststeps[$attempt->usageid]
                            [$question->slot]->fraction * $question->maxmark;
                    $oldsumgrade += $this->lateststeps[$attempt->usageid]
                            [$question->slot]->fraction * $question->maxmark;
                }
            }
            $newsumgrade = assignmentques_rescale_grade($newsumgrade, $this->assignmentques);
            $oldsumgrade = assignmentques_rescale_grade($oldsumgrade, $this->assignmentques);
            $grade = html_writer::tag('del', $oldsumgrade) . '/' .
                    html_writer::empty_tag('br') . $newsumgrade;
        }
        return html_writer::link(new moodle_url('/mod/assignmentques/review.php',
                array('attempt' => $attempt->attempt)), $grade,
                array('title' => get_string('reviewattempt', 'assignmentques')));
    }

    /***Get comment status* */
    function get_comment_status($questionAttempt,$step){
        $grade=''; 
        if($questionAttempt){    
            if(!empty($questionAttempt->status)){
                $showcolors = get_config('block_quescolorsetting',$questionAttempt->status);
                $grade = '<span class="reportcol '.$questionAttempt->status.'" 
                    style="background:'.$showcolors.'"
                    title="'.get_string($questionAttempt->status,'assignmentques').'" 
                    > <b class="quest">'.$questionAttempt->question.'</b></span>';
            }else{
                if($step->state!='todo'){
                    $showcolors = get_config('block_quescolorsetting','completecolor');
                    $grade = '<span class="reportcol '.$questionAttempt->status.'" 
                    style="background:'.$showcolors.'"
                    title="'.get_string('attempted','block_quescolorsetting').'" 
                    > <b class="quest">'.$questionAttempt->question.'</b></span>'; 
                }else{
                    $showcolors = get_config('block_quescolorsetting','notcompletecolor');
                    $grade = '<span class="reportcol '.$questionAttempt->status.'" 
                    style="background:'.$showcolors.'"
                    title="'.get_string('notattempted','block_quescolorsetting').'" 
                    > <b class="quest">'.$questionAttempt->question.'</b></span>'; 
                }
                
            }
        }else{                  
            if($step->state!='todo'){                
                $showcolors = get_config('block_quescolorsetting','completecolor');
                $grade = '<span class="reportcol" 
                style="background:'.$showcolors.'"
                title="'.get_string('attempted','block_quescolorsetting').'" 
                > <b class="quest">'.$questionAttempt->question.'</b></span>';
            }else{
                $showcolors = get_config('block_quescolorsetting','notcompletecolor');
                $grade = '<span class="reportcol" 
                style="background:'.$showcolors.'"
                title="'.get_string('notattempted','block_quescolorsetting').'" 
                > <b class="quest">'.$questionAttempt->question.'</b></span>';
            }
        }
        return $grade;
    }
    /**
     * @param string $colname the name of the column.
     * @param object $attempt the row of data - see the SQL in display() in
     * mod/assignmentques/report/overview/report.php to see what fields are present,
     * and what they are called.
     * @return string the contents of the cell.
     */
    public function other_cols($colname, $attempt) {
        global $DB;
        if (!preg_match('/^qsgrade(\d+)$/', $colname, $matches)) {
            return null;
        }
        $slot = $matches[1];
        $commentcon = array('attempt'=>$attempt->attempt,'slot'=>$slot);
        $questionAttempt=end($DB->get_records('assignmentques_comment', $commentcon));

        $commentcon=array('questionusageid'=>$attempt->usageid,'slot'=>$slot);
		$quesattempt=end($DB->get_records('question_attempts', $commentcon));
        $question_t=$DB->get_record('question', array('id'=>$quesattempt->questionid));
        $questionAttempt->question = strip_tags($question_t->questiontext);
        $commentcon=array('questionattemptid'=>$quesattempt->id);
		$step =end($DB->get_records('question_attempt_steps', $commentcon));

        $question = $this->questions[$slot];
        if (!isset($this->lateststeps[$attempt->usageid][$slot])) {
            return '-';
        }

        $stepdata = $this->lateststeps[$attempt->usageid][$slot];
        $state = question_state::get($stepdata->state);

        if ($question->maxmark == 0) {
            $grade = '-';
        } else if (is_null($stepdata->fraction)) {
            if ($state == question_state::$needsgrading) {
                //$grade = get_string('requiresgrading', 'question');
                $grade = $this->get_comment_status($questionAttempt,$step);
            } else {                
                $grade = $this->get_comment_status($questionAttempt,$step);
            }
        } else {
            $grade = assignmentques_rescale_grade(
                    $stepdata->fraction * $question->maxmark, $this->assignmentques, 'question');
        }

        if ($this->is_downloading()) {
            return $grade;
        }

        if (isset($this->regradedqs[$attempt->usageid][$slot])) {
            $gradefromdb = $grade;
            $newgrade = assignmentques_rescale_grade(
                    $this->regradedqs[$attempt->usageid][$slot]->newfraction * $question->maxmark,
                    $this->assignmentques, 'question');
            $oldgrade = assignmentques_rescale_grade(
                    $this->regradedqs[$attempt->usageid][$slot]->oldfraction * $question->maxmark,
                    $this->assignmentques, 'question');

            $grade = html_writer::tag('del', $oldgrade) . '/' .
                    html_writer::empty_tag('br') . $newgrade;
        }
        
        return html_writer::link(new moodle_url('/mod/assignmentques/review.php',
                array('attempt' => $attempt->attempt)), $grade,
                array('title' =>''));
        //return $this->make_review_link($grade, $attempt, $slot);
    }

    public function col_regraded($attempt) {
        if ($attempt->regraded == '') {
            return '';
        } else if ($attempt->regraded == 0) {
            return get_string('needed', 'assignmentques_overview');
        } else if ($attempt->regraded == 1) {
            return get_string('done', 'assignmentques_overview');
        }
    }

    protected function update_sql_after_count($fields, $from, $where, $params) {
        $fields .= ", COALESCE((
                                SELECT MAX(qqr.regraded)
                                  FROM {assignmentques_overview_regrades} qqr
                                 WHERE qqr.questionusageid = assignmentquesa.uniqueid
                          ), -1) AS regraded";
        if ($this->options->onlyregraded) {
            $where .= " AND COALESCE((
                                    SELECT MAX(qqr.regraded)
                                      FROM {assignmentques_overview_regrades} qqr
                                     WHERE qqr.questionusageid = assignmentquesa.uniqueid
                                ), -1) <> -1";
        }
        return [$fields, $from, $where, $params];
    }

    protected function requires_latest_steps_loaded() {
        return $this->options->slotmarks;
    }

    protected function is_latest_step_column($column) {
        if (preg_match('/^qsgrade([0-9]+)/', $column, $matches)) {
            return $matches[1];
        }
        return false;
    }

    protected function get_required_latest_state_fields($slot, $alias) {
        return "$alias.fraction * $alias.maxmark AS qsgrade$slot";
    }

    public function query_db($pagesize, $useinitialsbar = true) {
        parent::query_db($pagesize, $useinitialsbar);

        if ($this->options->slotmarks && has_capability('mod/assignmentques:regrade', $this->context)) {
            $this->regradedqs = $this->get_regraded_questions();
        }
    }

    /**
     * Get all the questions in all the attempts being displayed that need regrading.
     * @return array A two dimensional array $questionusageid => $slot => $regradeinfo.
     */
    protected function get_regraded_questions() {
        global $DB;

        $qubaids = $this->get_qubaids_condition();
        $regradedqs = $DB->get_records_select('assignmentques_overview_regrades',
                'questionusageid ' . $qubaids->usage_id_in(), $qubaids->usage_id_in_params());
        return assignmentques_report_index_by_keys($regradedqs, array('questionusageid', 'slot'));
    }
}
