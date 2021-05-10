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
 * Library code used by assignmentques cron.
 *
 * @package   mod_assignmentques
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');


/**
 * This class holds all the code for automatically updating all attempts that have
 * gone over their time limit.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignmentques_overdue_attempt_updater {

    /**
     * Do the processing required.
     * @param int $timenow the time to consider as 'now' during the processing.
     * @param int $processto only process attempt with timecheckstate longer ago than this.
     * @return array with two elements, the number of attempt considered, and how many different assignmentqueszes that was.
     */
    public function update_overdue_attempts($timenow, $processto) {
        global $DB;

        $attemptstoprocess = $this->get_list_of_overdue_attempts($processto);

        $course = null;
        $assignmentques = null;
        $cm = null;

        $count = 0;
        $assignmentquescount = 0;
        foreach ($attemptstoprocess as $attempt) {
            try {

                // If we have moved on to a different assignmentques, fetch the new data.
                if (!$assignmentques || $attempt->assignmentques != $assignmentques->id) {
                    $assignmentques = $DB->get_record('assignmentques', array('id' => $attempt->assignmentques), '*', MUST_EXIST);
                    $cm = get_coursemodule_from_instance('assignmentques', $attempt->assignmentques);
                    $assignmentquescount += 1;
                }

                // If we have moved on to a different course, fetch the new data.
                if (!$course || $course->id != $assignmentques->course) {
                    $course = $DB->get_record('course', array('id' => $assignmentques->course), '*', MUST_EXIST);
                }

                // Make a specialised version of the assignmentques settings, with the relevant overrides.
                $assignmentquesforuser = clone($assignmentques);
                $assignmentquesforuser->timeclose = $attempt->usertimeclose;
                $assignmentquesforuser->timelimit = $attempt->usertimelimit;

                // Trigger any transitions that are required.
                $attemptobj = new assignmentques_attempt($attempt, $assignmentquesforuser, $cm, $course);
                $attemptobj->handle_if_time_expired($timenow, false);
                $count += 1;

            } catch (moodle_exception $e) {
                // If an error occurs while processing one attempt, don't let that kill cron.
                mtrace("Error while processing attempt {$attempt->id} at {$attempt->assignmentques} assignmentques:");
                mtrace($e->getMessage());
                mtrace($e->getTraceAsString());
                // Close down any currently open transactions, otherwise one error
                // will stop following DB changes from being committed.
                $DB->force_transaction_rollback();
            }
        }

        $attemptstoprocess->close();
        return array($count, $assignmentquescount);
    }

    /**
     * @return moodle_recordset of assignmentques_attempts that need to be processed because time has
     *     passed. The array is sorted by courseid then assignmentquesid.
     */
    public function get_list_of_overdue_attempts($processto) {
        global $DB;


        // SQL to compute timeclose and timelimit for each attempt:
        $assignmentquesausersql = assignmentques_get_attempt_usertime_sql(
                "iassignmentquesa.state IN ('inprogress', 'overdue') AND iassignmentquesa.timecheckstate <= :iprocessto");

        // This query should have all the assignmentques_attempts columns.
        return $DB->get_recordset_sql("
         SELECT assignmentquesa.*,
                assignmentquesauser.usertimeclose,
                assignmentquesauser.usertimelimit

           FROM {assignmentques_attempts} assignmentquesa
           JOIN {assignmentques} assignmentques ON assignmentques.id = assignmentquesa.assignmentques
           JOIN ( $assignmentquesausersql ) assignmentquesauser ON assignmentquesauser.id = assignmentquesa.id

          WHERE assignmentquesa.state IN ('inprogress', 'overdue')
            AND assignmentquesa.timecheckstate <= :processto
       ORDER BY assignmentques.course, assignmentquesa.assignmentques",

                array('processto' => $processto, 'iprocessto' => $processto));
    }
}
