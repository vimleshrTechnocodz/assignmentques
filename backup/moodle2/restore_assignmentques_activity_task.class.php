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
 * @package    mod_assignmentques
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/backup/moodle2/restore_assignmentques_stepslib.php');


/**
 * assignmentques restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignmentques_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Assignmentques only has one structure step.
        $this->add_step(new restore_assignmentques_activity_structure_step('assignmentques_structure', 'assignmentques.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('assignmentques', array('intro'), 'assignmentques');
        $contents[] = new restore_decode_content('assignmentques_feedback',
                array('feedbacktext'), 'assignmentques_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('ASSIGNMENTQUESVIEWBYID',
                '/mod/assignmentques/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('ASSIGNMENTQUESVIEWBYQ',
                '/mod/assignmentques/view.php?q=$1', 'assignmentques');
        $rules[] = new restore_decode_rule('ASSIGNMENTQUESINDEX',
                '/mod/assignmentques/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * assignmentques logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('assignmentques', 'add',
                'view.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'update',
                'view.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'view',
                'view.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'preview',
                'view.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'report',
                'report.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'editquestions',
                'view.php?id={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('assignmentques', 'edit override',
                'overrideedit.php?id={assignmentques_override}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'delete override',
                'overrides.php.php?cmid={course_module}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('assignmentques', 'view summary',
                'summary.php?attempt={assignmentques_attempt}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'manualgrade',
                'comment.php?attempt={assignmentques_attempt}&question={question}', '{assignmentques}');
        $rules[] = new restore_log_rule('assignmentques', 'manualgrading',
                'report.php?mode=grading&q={assignmentques}', '{assignmentques}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'assignmentques_attempt' mapping because that is the
        // one containing the assignmentques_attempt->ids old an new for assignmentques-attempt.
        $rules[] = new restore_log_rule('assignmentques', 'attempt',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'attempt',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        // Old an new for assignmentques-submit.
        $rules[] = new restore_log_rule('assignmentques', 'submit',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'submit',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}');
        // Old an new for assignmentques-review.
        $rules[] = new restore_log_rule('assignmentques', 'review',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'review',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}');
        // Old an new for assignmentques-start attemp.
        $rules[] = new restore_log_rule('assignmentques', 'start attempt',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'start attempt',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}');
        // Old an new for assignmentques-close attemp.
        $rules[] = new restore_log_rule('assignmentques', 'close attempt',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'close attempt',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}');
        // Old an new for assignmentques-continue attempt.
        $rules[] = new restore_log_rule('assignmentques', 'continue attempt',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, null, 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'continue attempt',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}');
        // Old an new for assignmentques-continue attemp.
        $rules[] = new restore_log_rule('assignmentques', 'continue attemp',
                'review.php?id={course_module}&attempt={assignmentques_attempt}', '{assignmentques}',
                null, 'continue attempt', 'review.php?attempt={assignmentques_attempt}');
        $rules[] = new restore_log_rule('assignmentques', 'continue attemp',
                'review.php?attempt={assignmentques_attempt}', '{assignmentques}',
                null, 'continue attempt');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('assignmentques', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
