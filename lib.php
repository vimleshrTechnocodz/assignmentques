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
 * Library of functions for the assignmentques module.
 *
 * This contains functions that are called also from outside the assignmentques module
 * Functions that are only called by the assignmentques module itself are in {@link locallib.php}
 *
 * @package    mod_assignmentques
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the assignmentques settings form.
 */
define('ASSIGNMENTQUES_MAX_ATTEMPT_OPTION', 10);
define('ASSIGNMENTQUES_MAX_QPP_OPTION', 50);
define('ASSIGNMENTQUES_MAX_DECIMAL_OPTION', 5);
define('ASSIGNMENTQUES_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('ASSIGNMENTQUES_GRADEHIGHEST', '1');
define('ASSIGNMENTQUES_GRADEAVERAGE', '2');
define('ASSIGNMENTQUES_ATTEMPTFIRST', '3');
define('ASSIGNMENTQUES_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the assignmentques are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('ASSIGNMENTQUES_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within assignmentqueszes.
 */
define('ASSIGNMENTQUES_NAVMETHOD_FREE', 'free');
define('ASSIGNMENTQUES_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Event types.
 */
define('ASSIGNMENTQUES_EVENT_TYPE_OPEN', 'open');
define('ASSIGNMENTQUES_EVENT_TYPE_CLOSE', 'close');

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $assignmentques the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function assignmentques_add_instance($assignmentques) {
    global $DB;
    $cmid = $assignmentques->coursemodule;

    // Process the options from the form.
    $assignmentques->created = time();
    $result = assignmentques_process_options($assignmentques);
    if ($result && is_string($result)) {
        return $result;
    }
    
    // Try to store it in the database.
    $assignmentques->id = $DB->insert_record('assignmentques', $assignmentques);
    
    // Create the first section for this assignmentques.
    $DB->insert_record('assignmentques_sections', array('assignmentquesid' => $assignmentques->id,
            'firstslot' => 1, 'heading' => '', 'shufflequestions' => 0));

    // Do the processing required after an add or an update.
    assignmentques_after_add_or_update($assignmentques);

    return $assignmentques->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $assignmentques the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function assignmentques_update_instance($assignmentques, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    // Process the options from the form.
    $result = assignmentques_process_options($assignmentques);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldassignmentques = $DB->get_record('assignmentques', array('id' => $assignmentques->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $assignmentques->sumgrades = $oldassignmentques->sumgrades;
    $assignmentques->grade     = $oldassignmentques->grade;

    // Update the database.
    $assignmentques->id = $assignmentques->instance;
    $DB->update_record('assignmentques', $assignmentques);

    // Do the processing required after an add or an update.
    assignmentques_after_add_or_update($assignmentques);

    if ($oldassignmentques->grademethod != $assignmentques->grademethod) {
        assignmentques_update_all_final_grades($assignmentques);
        assignmentques_update_grades($assignmentques);
    }

    $assignmentquesdateschanged = $oldassignmentques->timelimit   != $assignmentques->timelimit
                     || $oldassignmentques->timeclose   != $assignmentques->timeclose
                     || $oldassignmentques->graceperiod != $assignmentques->graceperiod;
    if ($assignmentquesdateschanged) {
        assignmentques_update_open_attempts(array('assignmentquesid' => $assignmentques->id));
    }

    // Delete any previous preview attempts.
    assignmentques_delete_previews($assignmentques);

    // Repaginate, if asked to.
    if (!empty($assignmentques->repaginatenow)) {
        assignmentques_repaginate_questions($assignmentques->id, $assignmentques->questionsperpage);
    }

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the assignmentques to delete.
 * @return bool success or failure.
 */
function assignmentques_delete_instance($id) {
    global $DB;

    $assignmentques = $DB->get_record('assignmentques', array('id' => $id), '*', MUST_EXIST);

    assignmentques_delete_all_attempts($assignmentques);
    assignmentques_delete_all_overrides($assignmentques);

    // Look for random questions that may no longer be used when this assignmentques is gone.
    $sql = "SELECT q.id
              FROM {assignmentques_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.assignmentquesid = ? AND q.qtype = ?";
    $questionids = $DB->get_fieldset_sql($sql, array($assignmentques->id, 'random'));

    // We need to do the following deletes before we try and delete randoms, otherwise they would still be 'in use'.
    $assignmentquesslots = $DB->get_fieldset_select('assignmentques_slots', 'id', 'assignmentquesid = ?', array($assignmentques->id));
    $DB->delete_records_list('assignmentques_slot_tags', 'slotid', $assignmentquesslots);
    $DB->delete_records('assignmentques_slots', array('assignmentquesid' => $assignmentques->id));
    $DB->delete_records('assignmentques_sections', array('assignmentquesid' => $assignmentques->id));

    foreach ($questionids as $questionid) {
        question_delete_question($questionid);
    }

    $DB->delete_records('assignmentques_feedback', array('assignmentquesid' => $assignmentques->id));

    assignmentques_access_manager::delete_settings($assignmentques);

    $events = $DB->get_records('event', array('modulename' => 'assignmentques', 'instance' => $assignmentques->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    assignmentques_grade_item_delete($assignmentques);
    // We must delete the module record after we delete the grade item.
    $DB->delete_records('assignmentques', array('id' => $assignmentques->id));

    return true;
}

/**
 * Deletes a assignmentques override from the database and clears any corresponding calendar events
 *
 * @param object $assignmentques The assignmentques object.
 * @param int $overrideid The id of the override being deleted
 * @param bool $log Whether to trigger logs.
 * @return bool true on success
 */
function assignmentques_delete_override($assignmentques, $overrideid, $log = true) {
    global $DB;

    if (!isset($assignmentques->cmid)) {
        $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id, $assignmentques->course);
        $assignmentques->cmid = $cm->id;
    }

    $override = $DB->get_record('assignmentques_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    if (isset($override->groupid)) {
        // Create the search array for a group override.
        $eventsearcharray = array('modulename' => 'assignmentques',
            'instance' => $assignmentques->id, 'groupid' => (int)$override->groupid);
    } else {
        // Create the search array for a user override.
        $eventsearcharray = array('modulename' => 'assignmentques',
            'instance' => $assignmentques->id, 'userid' => (int)$override->userid);
    }
    $events = $DB->get_records('event', $eventsearcharray);
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('assignmentques_overrides', array('id' => $overrideid));

    if ($log) {
        // Set the common parameters for one of the events we will be triggering.
        $params = array(
            'objectid' => $override->id,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $override->assignmentques
            )
        );
        // Determine which override deleted event to fire.
        if (!empty($override->userid)) {
            $params['relateduserid'] = $override->userid;
            $event = \mod_assignmentques\event\user_override_deleted::create($params);
        } else {
            $params['other']['groupid'] = $override->groupid;
            $event = \mod_assignmentques\event\group_override_deleted::create($params);
        }

        // Trigger the override deleted event.
        $event->add_record_snapshot('assignmentques_overrides', $override);
        $event->trigger();
    }

    return true;
}

/**
 * Deletes all assignmentques overrides from the database and clears any corresponding calendar events
 *
 * @param object $assignmentques The assignmentques object.
 * @param bool $log Whether to trigger logs.
 */
function assignmentques_delete_all_overrides($assignmentques, $log = true) {
    global $DB;

    $overrides = $DB->get_records('assignmentques_overrides', array('assignmentques' => $assignmentques->id), 'id');
    foreach ($overrides as $override) {
        assignmentques_delete_override($assignmentques, $override->id, $log);
    }
}

/**
 * Updates a assignmentques object with override information for a user.
 *
 * Algorithm:  For each assignmentques setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the assignmentques setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   assignmentques->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $assignmentques The assignmentques object.
 * @param int $userid The userid.
 * @return object $assignmentques The updated assignmentques object.
 */
function assignmentques_update_effective_access($assignmentques, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('assignmentques_overrides', array('assignmentques' => $assignmentques->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($assignmentques->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {assignmentques_overrides}
                WHERE groupid $extra AND assignmentques = ?";
        $params[] = $assignmentques->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with assignmentques defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $assignmentques->{$key} = $override->{$key};
        }
    }

    return $assignmentques;
}

/**
 * Delete all the attempts belonging to a assignmentques.
 *
 * @param object $assignmentques The assignmentques object.
 */
function assignmentques_delete_all_attempts($assignmentques) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_assignmentques($assignmentques->id));
    $DB->delete_records('assignmentques_attempts', array('assignmentques' => $assignmentques->id));
    $DB->delete_records('assignmentques_grades', array('assignmentques' => $assignmentques->id));
}

/**
 * Delete all the attempts belonging to a user in a particular assignmentques.
 *
 * @param object $assignmentques The assignmentques object.
 * @param object $user The user object.
 */
function assignmentques_delete_user_attempts($assignmentques, $user) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_assignmentques_user($assignmentques->get_assignmentquesid(), $user->id));
    $params = [
        'assignmentques' => $assignmentques->get_assignmentquesid(),
        'userid' => $user->id,
    ];
    $DB->delete_records('assignmentques_attempts', $params);
    $DB->delete_records('assignmentques_grades', $params);
}

/**
 * Get the best current grade for a particular user in a assignmentques.
 *
 * @param object $assignmentques the assignmentques settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this assignmentques, or null if this user does
 * not have a grade on this assignmentques.
 */
function assignmentques_get_best_grade($assignmentques, $userid) {
    global $DB;
    $grade = $DB->get_field('assignmentques_grades', 'grade',
            array('assignmentques' => $assignmentques->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded assignmentques? If this method returns true, you can assume that
 * $assignmentques->grade and $assignmentques->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $assignmentques a row from the assignmentques table.
 * @return bool whether this is a graded assignmentques.
 */
function assignmentques_has_grades($assignmentques) {
    return $assignmentques->grade >= 0.000005 && $assignmentques->sumgrades >= 0.000005;
}

/**
 * Does this assignmentques allow multiple tries?
 *
 * @return bool
 */
function assignmentques_allows_multiple_tries($assignmentques) {
    $bt = question_engine::get_behaviour_type($assignmentques->preferredbehaviour);
    return $bt->allows_multiple_submitted_responses();
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $assignmentques
 * @return object|null
 */
function assignmentques_user_outline($course, $user, $mod, $assignmentques) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'assignmentques', $assignmentques->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    // If the user can't see hidden grades, don't return that information.
    $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
    if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;
    } else {
        $result->info = get_string('grade') . ': ' . get_string('hidden', 'grades');
    }

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $assignmentques
 * @return bool
 */
function assignmentques_user_complete($course, $user, $mod, $assignmentques) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'assignmentques', $assignmentques->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        // If the user can't see hidden grades, don't return that information.
        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
        if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
            }
        } else {
            echo $OUTPUT->container(get_string('grade') . ': ' . get_string('hidden', 'grades'));
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.get_string('hidden', 'grades'));
            }
        }
    }

    if ($attempts = $DB->get_records('assignmentques_attempts',
            array('userid' => $user->id, 'assignmentques' => $assignmentques->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'assignmentques', $attempt->attempt) . ': ';
            if ($attempt->state != assignmentques_attempt::FINISHED) {
                echo assignmentques_attempt_state_name($attempt->state);
            } else {
                if (!isset($gitem)) {
                    if (!empty($grades->items[0]->grades)) {
                        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
                    } else {
                        $gitem = new stdClass();
                        $gitem->hidden = true;
                    }
                }
                if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
                    echo assignmentques_format_grade($assignmentques, $attempt->sumgrades) . '/' . assignmentques_format_grade($assignmentques, $assignmentques->sumgrades);
                } else {
                    echo get_string('hidden', 'grades');
                }
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'assignmentques');
    }

    return true;
}


/**
 * @param int|array $assignmentquesids A assignmentques ID, or an array of assignmentques IDs.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this assignmentques. Returns an empty
 *      array if there are none.
 */
function assignmentques_get_user_attempts($assignmentquesids, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the assignmentques_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = assignmentques_attempt::FINISHED;
            $params['state2'] = assignmentques_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = assignmentques_attempt::IN_PROGRESS;
            $params['state2'] = assignmentques_attempt::OVERDUE;
            break;
    }

    $assignmentquesids = (array) $assignmentquesids;
    list($insql, $inparams) = $DB->get_in_or_equal($assignmentquesids, SQL_PARAMS_NAMED);
    $params += $inparams;
    $params['userid'] = $userid;

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    return $DB->get_records_select('assignmentques_attempts',
            "assignmentques $insql AND userid = :userid" . $previewclause . $statuscondition,
            $params, 'assignmentques, attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $assignmentquesid id of assignmentques
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with assignmentques_format_grade for display.
 */
function assignmentques_get_user_grades($assignmentques, $userid = 0) {
    global $CFG, $DB;

    $params = array($assignmentques->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {assignmentques_grades} qg ON u.id = qg.userid
            JOIN {assignmentques_attempts} qa ON qa.assignmentques = qg.assignmentques AND qa.userid = u.id

            WHERE qg.assignmentques = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $assignmentques The assignmentques table row, only $assignmentques->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function assignmentques_format_grade($assignmentques, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'assignmentques');
    }
    return format_float($grade, $assignmentques->decimalpoints);
}

/**
 * Determine the correct number of decimal places required to format a grade.
 *
 * @param object $assignmentques The assignmentques table row, only $assignmentques->decimalpoints is used.
 * @return integer
 */
function assignmentques_get_grade_format($assignmentques) {
    if (empty($assignmentques->questiondecimalpoints)) {
        $assignmentques->questiondecimalpoints = -1;
    }

    if ($assignmentques->questiondecimalpoints == -1) {
        return $assignmentques->decimalpoints;
    }

    return $assignmentques->questiondecimalpoints;
}

/**
 * Round a grade to the correct number of decimal places, and format it for display.
 *
 * @param object $assignmentques The assignmentques table row, only $assignmentques->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function assignmentques_format_question_grade($assignmentques, $grade) {
    return format_float($grade, assignmentques_get_grade_format($assignmentques));
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $assignmentques the assignmentques settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function assignmentques_update_grades($assignmentques, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($assignmentques->grade == 0) {
        assignmentques_grade_item_update($assignmentques);

    } else if ($grades = assignmentques_get_user_grades($assignmentques, $userid)) {
        assignmentques_grade_item_update($assignmentques, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        assignmentques_grade_item_update($assignmentques, $grade);

    } else {
        assignmentques_grade_item_update($assignmentques);
    }
}

/**
 * Create or update the grade item for given assignmentques
 *
 * @category grade
 * @param object $assignmentques object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function assignmentques_grade_item_update($assignmentques, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $assignmentques)) { // May not be always present.
        $params = array('itemname' => $assignmentques->name, 'idnumber' => $assignmentques->cmidnumber);
    } else {
        $params = array('itemname' => $assignmentques->name);
    }

    if ($assignmentques->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $assignmentques->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the assignmentques is set to not show grades while the assignmentques is still open,
    //    and is set to show grades after the assignmentques is closed, then create the
    //    grade_item with a show-after date that is the assignmentques close date.
    // 2. If the assignmentques is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the assignmentques is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($assignmentques->timeclose) {
            $params['hidden'] = $assignmentques->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the assignmentques logic, then we need to
        // hide it if the assignmentques is hidden from students.
        if (property_exists($assignmentques, 'visible')) {
            // Saving the assignmentques form, and cm not yet updated in the database.
            $params['hidden'] = !$assignmentques->visible;
        } else {
            $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id);
            $params['hidden'] = !$cm->visible;
        }
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($assignmentques->course, 'mod', 'assignmentques', $assignmentques->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/assignmentques/report.php?q=' . $assignmentques->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/assignmentques', $assignmentques->course, 'mod', 'assignmentques', $assignmentques->id, 0, $grades, $params);
}

/**
 * Delete grade item for given assignmentques
 *
 * @category grade
 * @param object $assignmentques object
 * @return object assignmentques
 */
function assignmentques_grade_item_delete($assignmentques) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/assignmentques', $assignmentques->course, 'mod', 'assignmentques', $assignmentques->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every assignmentques event in the site is checked, else
 * only assignmentques events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @param int|stdClass $instance Assignmentques module instance or ID.
 * @param int|stdClass $cm Course module object or ID (not used in this module).
 * @return bool
 */
function assignmentques_refresh_events($courseid = 0, $instance = null, $cm = null) {
    global $DB;

    // If we have instance information then we can just update the one event instead of updating all events.
    if (isset($instance)) {
        if (!is_object($instance)) {
            $instance = $DB->get_record('assignmentques', array('id' => $instance), '*', MUST_EXIST);
        }
        assignmentques_update_events($instance);
        return true;
    }

    if ($courseid == 0) {
        if (!$assignmentqueszes = $DB->get_records('assignmentques')) {
            return true;
        }
    } else {
        if (!$assignmentqueszes = $DB->get_records('assignmentques', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($assignmentqueszes as $assignmentques) {
        assignmentques_update_events($assignmentques);
    }

    return true;
}

/**
 * Returns all assignmentques graded users since a given time for specified assignmentques
 */
function assignmentques_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $assignmentques = $DB->get_record('assignmentques', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['assignmentquesid'] = $assignmentques->id;

    $ufields = user_picture::fields('u', null, 'useridagain');
    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     {$ufields}
                FROM {assignmentques_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.assignmentques = :assignmentquesid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/assignmentques:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id,
                        $attempt->userid, $cm->groupingid);
                $usersgroups = array_keys($usersgroups);
                if (!array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $options = assignmentques_get_review_options($assignmentques, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'assignmentques';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (assignmentques_has_grades($assignmentques) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = assignmentques_format_grade($assignmentques, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = assignmentques_format_grade($assignmentques, $assignmentques->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = user_picture::unalias($attempt, null, 'useridagain');
        $tmpactivity->user->fullname  = fullname($tmpactivity->user, $viewfullnames);

        $activities[$index++] = $tmpactivity;
    }
}

function assignmentques_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo $OUTPUT->image_icon('icon', $modname, $activity->type);
        echo '<a href="' . $CFG->wwwroot . '/mod/assignmentques/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'assignmentques', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/assignmentques/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the assignmentques options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $assignmentques The variables set on the form.
 */
function assignmentques_process_options($assignmentques) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $assignmentques->timemodified = time();

    // Assignmentques name.
    if (!empty($assignmentques->name)) {
        $assignmentques->name = trim($assignmentques->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $assignmentques->password = $assignmentques->assignmentquespassword;
    unset($assignmentques->assignmentquespassword);

    // Assignmentques feedback.
    if (isset($assignmentques->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($assignmentques->feedbacktext); $i += 1) {
            if (empty($assignmentques->feedbacktext[$i]['text'])) {
                $assignmentques->feedbacktext[$i]['text'] = '';
            } else {
                $assignmentques->feedbacktext[$i]['text'] = trim($assignmentques->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($assignmentques->feedbackboundaries[$i])) {
            $boundary = trim($assignmentques->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $assignmentques->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'assignmentques', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $assignmentques->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'assignmentques', $i + 1);
            }
            if ($i > 0 && $boundary >= $assignmentques->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'assignmentques', $i + 1);
            }
            $assignmentques->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($assignmentques->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($assignmentques->feedbackboundaries); $i += 1) {
                if (!empty($assignmentques->feedbackboundaries[$i]) &&
                        trim($assignmentques->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'assignmentques', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($assignmentques->feedbacktext); $i += 1) {
            if (!empty($assignmentques->feedbacktext[$i]['text']) &&
                    trim($assignmentques->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'assignmentques', $i + 1);
            }
        }
        // Needs to be bigger than $assignmentques->grade because of '<' test in assignmentques_feedback_for_grade().
        $assignmentques->feedbackboundaries[-1] = $assignmentques->grade + 1;
        $assignmentques->feedbackboundaries[$numboundaries] = 0;
        $assignmentques->feedbackboundarycount = $numboundaries;
    } else {
        $assignmentques->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $assignmentques->reviewattempt = assignmentques_review_option_form_to_db($assignmentques, 'attempt');
    $assignmentques->reviewcorrectness = assignmentques_review_option_form_to_db($assignmentques, 'correctness');
    $assignmentques->reviewmarks = assignmentques_review_option_form_to_db($assignmentques, 'marks');
    $assignmentques->reviewspecificfeedback = assignmentques_review_option_form_to_db($assignmentques, 'specificfeedback');
    $assignmentques->reviewgeneralfeedback = assignmentques_review_option_form_to_db($assignmentques, 'generalfeedback');
    $assignmentques->reviewrightanswer = assignmentques_review_option_form_to_db($assignmentques, 'rightanswer');
    $assignmentques->reviewoverallfeedback = assignmentques_review_option_form_to_db($assignmentques, 'overallfeedback');
    $assignmentques->reviewattempt |= mod_assignmentques_display_options::DURING;
    $assignmentques->reviewoverallfeedback &= ~mod_assignmentques_display_options::DURING;

    // Ensure that disabled checkboxes in completion settings are set to 0.
    if (empty($assignmentques->completionusegrade)) {
        $assignmentques->completionpass = 0;
    }
    if (empty($assignmentques->completionpass)) {
        $assignmentques->completionattemptsexhausted = 0;
    }
}

/**
 * Helper function for {@link assignmentques_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function assignmentques_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_assignmentques_display_options::DURING,
        'immediately' => mod_assignmentques_display_options::IMMEDIATELY_AFTER,
        'open' => mod_assignmentques_display_options::LATER_WHILE_OPEN,
        'closed' => mod_assignmentques_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of assignmentques_add_instance
 * and assignmentques_update_instance, to do the common processing.
 *
 * @param object $assignmentques the assignmentques object.
 */
function assignmentques_after_add_or_update($assignmentques) {
    global $DB;
    $cmid = $assignmentques->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $assignmentques->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('assignmentques_feedback', array('assignmentquesid' => $assignmentques->id));

    for ($i = 0; $i <= $assignmentques->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->assignmentquesid = $assignmentques->id;
        $feedback->feedbacktext = $assignmentques->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $assignmentques->feedbacktext[$i]['format'];
        $feedback->mingrade = $assignmentques->feedbackboundaries[$i];
        $feedback->maxgrade = $assignmentques->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('assignmentques_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$assignmentques->feedbacktext[$i]['itemid'],
                $context->id, 'mod_assignmentques', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $assignmentques->feedbacktext[$i]['text']);
        $DB->set_field('assignmentques_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    assignmentques_access_manager::save_settings($assignmentques);

    // Update the events relating to this assignmentques.
    assignmentques_update_events($assignmentques);
    $completionexpected = (!empty($assignmentques->completionexpected)) ? $assignmentques->completionexpected : null;
    \core_completion\api::update_completion_date_event($assignmentques->coursemodule, 'assignmentques', $assignmentques->id, $completionexpected);

    // Update related grade item.
    assignmentques_grade_item_update($assignmentques);
}

/**
 * This function updates the events associated to the assignmentques.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses ASSIGNMENTQUES_MAX_EVENT_LENGTH
 * @param object $assignmentques the assignmentques object.
 * @param object optional $override limit to a specific override
 */
function assignmentques_update_events($assignmentques, $override = null) {
    global $DB;

    // Load the old events relating to this assignmentques.
    $conds = array('modulename'=>'assignmentques',
                   'instance'=>$assignmentques->id);
    if (!empty($override)) {
        // Only load events for this override.
        if (isset($override->userid)) {
            $conds['userid'] = $override->userid;
        } else {
            $conds['groupid'] = $override->groupid;
        }
    }
    $oldevents = $DB->get_records('event', $conds, 'id ASC');

    // Now make a to-do list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the assignmentques, so we need to add all the overrides.
        $overrides = $DB->get_records('assignmentques_overrides', array('assignmentques' => $assignmentques->id), 'id ASC');
        // It is necessary to add an empty stdClass to the beginning of the array as the $oldevents
        // list contains the original (non-override) event for the module. If this is not included
        // the logic below will end up updating the wrong row when we try to reconcile this $overrides
        // list against the $oldevents list.
        array_unshift($overrides, new stdClass());
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    // Get group override priorities.
    $grouppriorities = assignmentques_get_group_override_priorities($assignmentques->id);

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $assignmentques->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $assignmentques->timeclose;

        // Only add open/close events for an override if they differ from the assignmentques default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($assignmentques->coursemodule)) {
            $cmid = $assignmentques->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('assignmentques', $assignmentques->id, $assignmentques->course)->id;
        }

        $event = new stdClass();
        $event->type = !$timeclose ? CALENDAR_EVENT_TYPE_ACTION : CALENDAR_EVENT_TYPE_STANDARD;
        $event->description = format_module_intro('assignmentques', $assignmentques, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $assignmentques->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'assignmentques';
        $event->instance    = $assignmentques->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->timesort    = $timeopen;
        $event->visible     = instance_is_visible('assignmentques', $assignmentques);
        $event->eventtype   = ASSIGNMENTQUES_EVENT_TYPE_OPEN;
        $event->priority    = null;

        // Determine the event name and priority.
        if ($groupid) {
            // Group override event.
            $params = new stdClass();
            $params->assignmentques = $assignmentques->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'assignmentques', $params);
            // Set group override priority.
            if ($grouppriorities !== null) {
                $openpriorities = $grouppriorities['open'];
                if (isset($openpriorities[$timeopen])) {
                    $event->priority = $openpriorities[$timeopen];
                }
            }
        } else if ($userid) {
            // User override event.
            $params = new stdClass();
            $params->assignmentques = $assignmentques->name;
            $eventname = get_string('overrideusereventname', 'assignmentques', $params);
            // Set user override priority.
            $event->priority = CALENDAR_EVENT_USER_OVERRIDE_PRIORITY;
        } else {
            // The parent event.
            $eventname = $assignmentques->name;
        }

        if ($addopen or $addclose) {
            // Separate start and end events.
            $event->timeduration  = 0;
            if ($timeopen && $addopen) {
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = get_string('assignmentqueseventopens', 'assignmentques', $eventname);
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event, false);
            }
            if ($timeclose && $addclose) {
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->type      = CALENDAR_EVENT_TYPE_ACTION;
                $event->name      = get_string('assignmentqueseventcloses', 'assignmentques', $eventname);
                $event->timestart = $timeclose;
                $event->timesort  = $timeclose;
                $event->eventtype = ASSIGNMENTQUES_EVENT_TYPE_CLOSE;
                if ($groupid && $grouppriorities !== null) {
                    $closepriorities = $grouppriorities['close'];
                    if (isset($closepriorities[$timeclose])) {
                        $event->priority = $closepriorities[$timeclose];
                    }
                }
                calendar_event::create($event, false);
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * Calculates the priorities of timeopen and timeclose values for group overrides for a assignmentques.
 *
 * @param int $assignmentquesid The assignmentques ID.
 * @return array|null Array of group override priorities for open and close times. Null if there are no group overrides.
 */
function assignmentques_get_group_override_priorities($assignmentquesid) {
    global $DB;

    // Fetch group overrides.
    $where = 'assignmentques = :assignmentques AND groupid IS NOT NULL';
    $params = ['assignmentques' => $assignmentquesid];
    $overrides = $DB->get_records_select('assignmentques_overrides', $where, $params, '', 'id, timeopen, timeclose');
    if (!$overrides) {
        return null;
    }

    $grouptimeopen = [];
    $grouptimeclose = [];
    foreach ($overrides as $override) {
        if ($override->timeopen !== null && !in_array($override->timeopen, $grouptimeopen)) {
            $grouptimeopen[] = $override->timeopen;
        }
        if ($override->timeclose !== null && !in_array($override->timeclose, $grouptimeclose)) {
            $grouptimeclose[] = $override->timeclose;
        }
    }

    // Sort open times in ascending manner. The earlier open time gets higher priority.
    sort($grouptimeopen);
    // Set priorities.
    $opengrouppriorities = [];
    $openpriority = 1;
    foreach ($grouptimeopen as $timeopen) {
        $opengrouppriorities[$timeopen] = $openpriority++;
    }

    // Sort close times in descending manner. The later close time gets higher priority.
    rsort($grouptimeclose);
    // Set priorities.
    $closegrouppriorities = [];
    $closepriority = 1;
    foreach ($grouptimeclose as $timeclose) {
        $closegrouppriorities[$timeclose] = $closepriority++;
    }

    return [
        'open' => $opengrouppriorities,
        'close' => $closegrouppriorities
    ];
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function assignmentques_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function assignmentques_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function assignmentques_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('assignmentques_slots',
            'questionid ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{assignmentques_attempts} assignmentquesa',
            'assignmentquesa.uniqueid', 'assignmentquesa.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the assignmentques.
 *
 * @param $mform the course reset form that is being built.
 */
function assignmentques_reset_course_form_definition($mform) {
    $mform->addElement('header', 'assignmentquesheader', get_string('modulenameplural', 'assignmentques'));
    $mform->addElement('advcheckbox', 'reset_assignmentques_attempts',
            get_string('removeallassignmentquesattempts', 'assignmentques'));
    $mform->addElement('advcheckbox', 'reset_assignmentques_user_overrides',
            get_string('removealluseroverrides', 'assignmentques'));
    $mform->addElement('advcheckbox', 'reset_assignmentques_group_overrides',
            get_string('removeallgroupoverrides', 'assignmentques'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function assignmentques_reset_course_form_defaults($course) {
    return array('reset_assignmentques_attempts' => 1,
                 'reset_assignmentques_group_overrides' => 1,
                 'reset_assignmentques_user_overrides' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function assignmentques_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $assignmentqueszes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {assignmentques} q ON cm.instance = q.id
            WHERE m.name = 'assignmentques' AND cm.course = ?", array($courseid));

    foreach ($assignmentqueszes as $assignmentques) {
        assignmentques_grade_item_update($assignmentques, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * assignmentques attempts for course $data->courseid, if $data->reset_assignmentques_attempts is
 * set and true.
 *
 * Also, move the assignmentques open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function assignmentques_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'assignmentques');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_assignmentques_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{assignmentques_attempts} assignmentquesa JOIN {assignmentques} assignmentques ON assignmentquesa.assignmentques = assignmentques.id',
                'assignmentquesa.uniqueid', 'assignmentques.course = :assignmentquescourseid',
                array('assignmentquescourseid' => $data->courseid)));

        $DB->delete_records_select('assignmentques_attempts',
                'assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'assignmentques'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('assignmentques_grades',
                'assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            assignmentques_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'assignmentques'),
            'error' => false);
    }

    // Remove user overrides.
    if (!empty($data->reset_assignmentques_user_overrides)) {
        $DB->delete_records_select('assignmentques_overrides',
                'assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?) AND userid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('useroverridesdeleted', 'assignmentques'),
            'error' => false);
    }
    // Remove group overrides.
    if (!empty($data->reset_assignmentques_group_overrides)) {
        $DB->delete_records_select('assignmentques_overrides',
                'assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?) AND groupid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('groupoverridesdeleted', 'assignmentques'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {assignmentques_overrides}
                         SET timeopen = timeopen + ?
                       WHERE assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {assignmentques_overrides}
                         SET timeclose = timeclose + ?
                       WHERE assignmentques IN (SELECT id FROM {assignmentques} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        shift_course_mod_dates('assignmentques', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'assignmentques'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints assignmentques summaries on MyMoodle Page
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param array $courses
 * @param array $htmlarray
 */
function assignmentques_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;

    debugging('The function assignmentques_print_overview() is now deprecated.', DEBUG_DEVELOPER);

    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$assignmentqueszes = get_all_instances_in_courses('assignmentques', $courses)) {
        return;
    }

    // Get the assignmentqueszes attempts.
    $attemptsinfo = [];
    $assignmentquesids = [];
    foreach ($assignmentqueszes as $assignmentques) {
        $assignmentquesids[] = $assignmentques->id;
        $attemptsinfo[$assignmentques->id] = ['count' => 0, 'hasfinished' => false];
    }
    $attempts = assignmentques_get_user_attempts($assignmentquesids, $USER->id);
    foreach ($attempts as $attempt) {
        $attemptsinfo[$attempt->assignmentques]['count']++;
        $attemptsinfo[$attempt->assignmentques]['hasfinished'] = true;
    }
    unset($attempts);

    // Fetch some language strings outside the main loop.
    $strassignmentques = get_string('modulename', 'assignmentques');
    $strnoattempts = get_string('noattempts', 'assignmentques');

    // We want to list assignmentqueszes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($assignmentqueszes as $assignmentques) {
        if ($assignmentques->timeclose >= $now && $assignmentques->timeopen < $now) {
            $str = '';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($assignmentques->coursemodule);
            if (has_capability('mod/assignmentques:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $assignmentques objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' . assignmentques_num_attempt_summary($assignmentques, $assignmentques, true) . '</div>';

            } else if (has_any_capability(array('mod/assignmentques:reviewmyattempts', 'mod/assignmentques:attempt'), $context)) { // Student
                // For student-like people, tell them how many attempts they have made.

                if (isset($USER->id)) {
                    if ($attemptsinfo[$assignmentques->id]['hasfinished']) {
                        // The student's last attempt is finished.
                        continue;
                    }

                    if ($attemptsinfo[$assignmentques->id]['count'] > 0) {
                        $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'assignmentques', $attemptsinfo[$assignmentques->id]['count']) . '</div>';
                    } else {
                        $str .= '<div class="info">' . $strnoattempts . '</div>';
                    }

                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }

            } else {
                // For ayone else, there is no point listing this assignmentques, so stop processing.
                continue;
            }

            // Give a link to the assignmentques, and the deadline.
            $html = '<div class="assignmentques overview">' .
                    '<div class="name">' . $strassignmentques . ': <a ' .
                    ($assignmentques->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/assignmentques/view.php?id=' .
                    $assignmentques->coursemodule . '">' .
                    $assignmentques->name . '</a></div>';
            $html .= '<div class="info">' . get_string('assignmentquescloseson', 'assignmentques',
                    userdate($assignmentques->timeclose)) . '</div>';
            $html .= $str;
            $html .= '</div>';
            if (empty($htmlarray[$assignmentques->course]['assignmentques'])) {
                $htmlarray[$assignmentques->course]['assignmentques'] = $html;
            } else {
                $htmlarray[$assignmentques->course]['assignmentques'] .= $html;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular assignmentques,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $assignmentques the assignmentques object. Only $assignmentques->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function assignmentques_num_attempt_summary($assignmentques, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('assignmentques_attempts', array('assignmentques'=> $assignmentques->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{assignmentques_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE assignmentques = ? AND preview = 0 AND groupid = ?',
                        array($assignmentques->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'assignmentques', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{assignmentques_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE assignmentques = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($assignmentques->id), $params));
                return get_string('attemptsnumyourgroups', 'assignmentques', $a);
            }
        }
        return get_string('attemptsnum', 'assignmentques', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link assignmentques_num_attempt_summary()} but wrapped in a link
 * to the assignmentques reports.
 *
 * @param object $assignmentques the assignmentques object. Only $assignmentques->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the assignmentques context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function assignmentques_attempt_summary_link_to_reports($assignmentques, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = assignmentques_num_attempt_summary($assignmentques, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/assignmentques/report/reportlib.php');
    $url = new moodle_url('/mod/assignmentques/report.php', array(
            'id' => $cm->id, 'mode' => assignmentques_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if assignmentques supports feature
 */
function assignmentques_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_COMPLETION_HAS_RULES:      return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function assignmentques_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    return question_get_all_capabilities();
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $assignmentquesnode
 * @return void
 */
function assignmentques_extend_settings_navigation($settings, $assignmentquesnode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $assignmentquesnode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/assignmentques:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/assignmentques/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'assignmentques'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_assignmentques_groupoverrides');
        $assignmentquesnode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'assignmentques'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_assignmentques_useroverrides');
        $assignmentquesnode->add_node($node, $beforekey);
    }

    if (has_capability('mod/assignmentques:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editassignmentques', 'assignmentques'),
                new moodle_url('/mod/assignmentques/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_assignmentques_edit',
                new pix_icon('t/edit', ''));
        $assignmentquesnode->add_node($node, $beforekey);
    }

    if (has_capability('mod/assignmentques:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/assignmentques/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'assignmentques'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_assignmentques_preview',
                new pix_icon('i/preview', ''));
        $assignmentquesnode->add_node($node, $beforekey);
    }

    if (has_any_capability(array('mod/assignmentques:viewreports', 'mod/assignmentques:grade'), $PAGE->cm->context)) {
        require_once($CFG->dirroot . '/mod/assignmentques/report/reportlib.php');
        $reportlist = assignmentques_report_list($PAGE->cm->context);

        $url = new moodle_url('/mod/assignmentques/report.php',
                array('id' => $PAGE->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $assignmentquesnode->add_node(navigation_node::create(get_string('results', 'assignmentques'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', '')), $beforekey);

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/assignmentques/report.php',
                    array('id' => $PAGE->cm->id, 'mode' => $report));
            $reportnode->add_node(navigation_node::create(get_string($report, 'assignmentques_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'assignmentques_report_' . $report, new pix_icon('i/item', '')));
        }
    }

    question_extend_settings_navigation($assignmentquesnode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the assignmentques files.
 *
 * @package  mod_assignmentques
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function assignmentques_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$assignmentques = $DB->get_record('assignmentques', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('assignmentques_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_assignmentques/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a assignmentques attempt.
 *
 * @package  mod_assignmentques
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this assignmentques attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function assignmentques_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    $attemptobj = assignmentques_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/assignmentques:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function assignmentques_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-assignmentques-*'       => get_string('page-mod-assignmentques-x', 'assignmentques'),
        'mod-assignmentques-view'    => get_string('page-mod-assignmentques-view', 'assignmentques'),
        'mod-assignmentques-attempt' => get_string('page-mod-assignmentques-attempt', 'assignmentques'),
        'mod-assignmentques-summary' => get_string('page-mod-assignmentques-summary', 'assignmentques'),
        'mod-assignmentques-review'  => get_string('page-mod-assignmentques-review', 'assignmentques'),
        'mod-assignmentques-edit'    => get_string('page-mod-assignmentques-edit', 'assignmentques'),
        'mod-assignmentques-report'  => get_string('page-mod-assignmentques-report', 'assignmentques'),
    );
    return $module_pagetype;
}

/**
 * @return the options for assignmentques navigation.
 */
function assignmentques_get_navigation_options() {
    return array(
        ASSIGNMENTQUES_NAVMETHOD_FREE => get_string('navmethod_free', 'assignmentques'),
        ASSIGNMENTQUES_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'assignmentques')
    );
}

/**
 * Obtains the automatic completion state for this assignmentques on any conditions
 * in assignmentques settings, such as if all attempts are used or a certain grade is achieved.
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function assignmentques_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    global $CFG;

    $assignmentques = $DB->get_record('assignmentques', array('id' => $cm->instance), '*', MUST_EXIST);
    if (!$assignmentques->completionattemptsexhausted && !$assignmentques->completionpass) {
        return $type;
    }

    // Check if the user has used up all attempts.
    if ($assignmentques->completionattemptsexhausted) {
        $attempts = assignmentques_get_user_attempts($assignmentques->id, $userid, 'finished', true);
        if ($attempts) {
            $lastfinishedattempt = end($attempts);
            $context = context_module::instance($cm->id);
            $assignmentquesobj = assignmentques::create($assignmentques->id, $userid);
            $accessmanager = new assignmentques_access_manager($assignmentquesobj, time(),
                    has_capability('mod/assignmentques:ignoretimelimits', $context, $userid, false));
            if ($accessmanager->is_finished(count($attempts), $lastfinishedattempt)) {
                return true;
            }
        }
    }

    // Check for passing grade.
    if ($assignmentques->completionpass) {
        require_once($CFG->libdir . '/gradelib.php');
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                'itemmodule' => 'assignmentques', 'iteminstance' => $cm->instance, 'outcomeid' => null));
        if ($item) {
            $grades = grade_grade::fetch_users_grades($item, array($userid), false);
            if (!empty($grades[$userid])) {
                return $grades[$userid]->is_passed($item);
            }
        }
    }
    return false;
}

/**
 * Check if the module has any update that affects the current user since a given time.
 *
 * @param  cm_info $cm course module data
 * @param  int $from the time to check updates from
 * @param  array $filter  if we need to check only specific updates
 * @return stdClass an object with the different type of areas indicating if they were updated or not
 * @since Moodle 3.2
 */
function assignmentques_check_updates_since(cm_info $cm, $from, $filter = array()) {
    global $DB, $USER, $CFG;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    $updates = course_check_module_updates_since($cm, $from, array(), $filter);

    // Check if questions were updated.
    $updates->questions = (object) array('updated' => false);
    $assignmentquesobj = assignmentques::create($cm->instance, $USER->id);
    $assignmentquesobj->preload_questions();
    $assignmentquesobj->load_questions();
    $questionids = array_keys($assignmentquesobj->get_questions());
    if (!empty($questionids)) {
        list($questionsql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $select = 'id ' . $questionsql . ' AND (timemodified > :time1 OR timecreated > :time2)';
        $params['time1'] = $from;
        $params['time2'] = $from;
        $questions = $DB->get_records_select('question', $select, $params, '', 'id');
        if (!empty($questions)) {
            $updates->questions->updated = true;
            $updates->questions->itemids = array_keys($questions);
        }
    }

    // Check for new attempts or grades.
    $updates->attempts = (object) array('updated' => false);
    $updates->grades = (object) array('updated' => false);
    $select = 'assignmentques = ? AND userid = ? AND timemodified > ?';
    $params = array($cm->instance, $USER->id, $from);

    $attempts = $DB->get_records_select('assignmentques_attempts', $select, $params, '', 'id');
    if (!empty($attempts)) {
        $updates->attempts->updated = true;
        $updates->attempts->itemids = array_keys($attempts);
    }
    $grades = $DB->get_records_select('assignmentques_grades', $select, $params, '', 'id');
    if (!empty($grades)) {
        $updates->grades->updated = true;
        $updates->grades->itemids = array_keys($grades);
    }

    // Now, teachers should see other students updates.
    if (has_capability('mod/assignmentques:viewreports', $cm->context)) {
        $select = 'assignmentques = ? AND timemodified > ?';
        $params = array($cm->instance, $from);

        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS) {
            $groupusers = array_keys(groups_get_activity_shared_group_members($cm));
            if (empty($groupusers)) {
                return $updates;
            }
            list($insql, $inparams) = $DB->get_in_or_equal($groupusers);
            $select .= ' AND userid ' . $insql;
            $params = array_merge($params, $inparams);
        }

        $updates->userattempts = (object) array('updated' => false);
        $attempts = $DB->get_records_select('assignmentques_attempts', $select, $params, '', 'id');
        if (!empty($attempts)) {
            $updates->userattempts->updated = true;
            $updates->userattempts->itemids = array_keys($attempts);
        }

        $updates->usergrades = (object) array('updated' => false);
        $grades = $DB->get_records_select('assignmentques_grades', $select, $params, '', 'id');
        if (!empty($grades)) {
            $updates->usergrades->updated = true;
            $updates->usergrades->itemids = array_keys($grades);
        }
    }
    return $updates;
}

/**
 * Get icon mapping for font-awesome.
 */
function mod_assignmentques_get_fontawesome_icon_map() {
    return [
        'mod_assignmentques:navflagged' => 'fa-flag',
    ];
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id to use for all capability checks, etc. Set to 0 for current user (default).
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_assignmentques_core_calendar_provide_event_action(calendar_event $event,
                                                     \core_calendar\action_factory $factory,
                                                     int $userid = 0) {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    if (empty($userid)) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['assignmentques'][$event->instance];
    $assignmentquesobj = assignmentques::create($cm->instance, $userid);
    $assignmentques = $assignmentquesobj->get_assignmentques();

    // Check they have capabilities allowing them to view the assignmentques.
    if (!has_any_capability(['mod/assignmentques:reviewmyattempts', 'mod/assignmentques:attempt'], $assignmentquesobj->get_context(), $userid)) {
        return null;
    }

    $completion = new \completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false, $userid);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    assignmentques_update_effective_access($assignmentques, $userid);

    // Check if assignmentques is closed, if so don't display it.
    if (!empty($assignmentques->timeclose) && $assignmentques->timeclose <= time()) {
        return null;
    }

    if (!$assignmentquesobj->is_participant($userid)) {
        // If the user is not a participant then they have
        // no action to take. This will filter out the events for teachers.
        return null;
    }

    $attempts = assignmentques_get_user_attempts($assignmentquesobj->get_assignmentquesid(), $userid);
    if (!empty($attempts)) {
        // The student's last attempt is finished.
        return null;
    }

    $name = get_string('attemptassignmentquesnow', 'assignmentques');
    $url = new \moodle_url('/mod/assignmentques/view.php', [
        'id' => $cm->id
    ]);
    $itemcount = 1;
    $actionable = true;

    // Check if the assignmentques is not currently actionable.
    if (!empty($assignmentques->timeopen) && $assignmentques->timeopen > time()) {
        $actionable = false;
    }

    return $factory->create_instance(
        $name,
        $url,
        $itemcount,
        $actionable
    );
}

/**
 * Add a get_coursemodule_info function in case any assignmentques type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function assignmentques_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionattemptsexhausted, completionpass';
    if (!$assignmentques = $DB->get_record('assignmentques', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $assignmentques->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('assignmentques', $assignmentques, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionattemptsexhausted'] = $assignmentques->completionattemptsexhausted;
        $result->customdata['customcompletionrules']['completionpass'] = $assignmentques->completionpass;
    }

    return $result;
}

/**
 * Callback which returns human-readable strings describing the active completion custom rules for the module instance.
 *
 * @param cm_info|stdClass $cm object with fields ->completion and ->customdata['customcompletionrules']
 * @return array $descriptions the array of descriptions for the custom rules.
 */
function mod_assignmentques_get_completion_active_rule_descriptions($cm) {
    // Values will be present in cm_info, and we assume these are up to date.
    if (empty($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        switch ($key) {
            case 'completionattemptsexhausted':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionattemptsexhausteddesc', 'assignmentques');
                }
                break;
            case 'completionpass':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionpassdesc', 'assignmentques', format_time($val));
                }
                break;
            default:
                break;
        }
    }
    return $descriptions;
}

/**
 * Returns the min and max values for the timestart property of a assignmentques
 * activity event.
 *
 * The min and max values will be the timeopen and timeclose properties
 * of the assignmentques, respectively, if they are set.
 *
 * If either value isn't set then null will be returned instead to
 * indicate that there is no cutoff for that value.
 *
 * If the vent has no valid timestart range then [false, false] will
 * be returned. This is the case for overriden events.
 *
 * A minimum and maximum cutoff return value will look like:
 * [
 *     [1505704373, 'The date must be after this date'],
 *     [1506741172, 'The date must be before this date']
 * ]
 *
 * @throws \moodle_exception
 * @param \calendar_event $event The calendar event to get the time range for
 * @param stdClass $assignmentques The module instance to get the range from
 * @return array
 */
function mod_assignmentques_core_calendar_get_valid_event_timestart_range(\calendar_event $event, \stdClass $assignmentques) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    // Overrides do not have a valid timestart range.
    if (assignmentques_is_overriden_calendar_event($event)) {
        return [false, false];
    }

    $mindate = null;
    $maxdate = null;

    if ($event->eventtype == ASSIGNMENTQUES_EVENT_TYPE_OPEN) {
        if (!empty($assignmentques->timeclose)) {
            $maxdate = [
                $assignmentques->timeclose,
                get_string('openafterclose', 'assignmentques')
            ];
        }
    } else if ($event->eventtype == ASSIGNMENTQUES_EVENT_TYPE_CLOSE) {
        if (!empty($assignmentques->timeopen)) {
            $mindate = [
                $assignmentques->timeopen,
                get_string('closebeforeopen', 'assignmentques')
            ];
        }
    }

    return [$mindate, $maxdate];
}

/**
 * This function will update the assignmentques module according to the
 * event that has been modified.
 *
 * It will set the timeopen or timeclose value of the assignmentques instance
 * according to the type of event provided.
 *
 * @throws \moodle_exception
 * @param \calendar_event $event A assignmentques activity calendar event
 * @param \stdClass $assignmentques A assignmentques activity instance
 */
function mod_assignmentques_core_calendar_event_timestart_updated(\calendar_event $event, \stdClass $assignmentques) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');

    if (!in_array($event->eventtype, [ASSIGNMENTQUES_EVENT_TYPE_OPEN, ASSIGNMENTQUES_EVENT_TYPE_CLOSE])) {
        // This isn't an event that we care about so we can ignore it.
        return;
    }

    $courseid = $event->courseid;
    $modulename = $event->modulename;
    $instanceid = $event->instance;
    $modified = false;
    $closedatechanged = false;

    // Something weird going on. The event is for a different module so
    // we should ignore it.
    if ($modulename != 'assignmentques') {
        return;
    }

    if ($assignmentques->id != $instanceid) {
        // The provided assignmentques instance doesn't match the event so
        // there is nothing to do here.
        return;
    }

    // We don't update the activity if it's an override event that has
    // been modified.
    if (assignmentques_is_overriden_calendar_event($event)) {
        return;
    }

    $coursemodule = get_fast_modinfo($courseid)->instances[$modulename][$instanceid];
    $context = context_module::instance($coursemodule->id);

    // The user does not have the capability to modify this activity.
    if (!has_capability('moodle/course:manageactivities', $context)) {
        return;
    }

    if ($event->eventtype == ASSIGNMENTQUES_EVENT_TYPE_OPEN) {
        // If the event is for the assignmentques activity opening then we should
        // set the start time of the assignmentques activity to be the new start
        // time of the event.
        if ($assignmentques->timeopen != $event->timestart) {
            $assignmentques->timeopen = $event->timestart;
            $modified = true;
        }
    } else if ($event->eventtype == ASSIGNMENTQUES_EVENT_TYPE_CLOSE) {
        // If the event is for the assignmentques activity closing then we should
        // set the end time of the assignmentques activity to be the new start
        // time of the event.
        if ($assignmentques->timeclose != $event->timestart) {
            $assignmentques->timeclose = $event->timestart;
            $modified = true;
            $closedatechanged = true;
        }
    }

    if ($modified) {
        $assignmentques->timemodified = time();
        $DB->update_record('assignmentques', $assignmentques);

        if ($closedatechanged) {
            assignmentques_update_open_attempts(array('assignmentquesid' => $assignmentques->id));
        }

        // Delete any previous preview attempts.
        assignmentques_delete_previews($assignmentques);
        assignmentques_update_events($assignmentques);
        $event = \core\event\course_module_updated::create_from_cm($coursemodule, $context);
        $event->trigger();
    }
}

/**
 * Generates the question bank in a fragment output. This allows
 * the question bank to be displayed in a modal.
 *
 * The only expected argument provided in the $args array is
 * 'querystring'. The value should be the list of parameters
 * URL encoded and used to build the question bank page.
 *
 * The individual list of parameters expected can be found in
 * question_build_edit_resources.
 *
 * @param array $args The fragment arguments.
 * @return string The rendered mform fragment.
 */
function mod_assignmentques_output_fragment_assignmentques_question_bank($args) {
    global $CFG, $DB, $PAGE;
    require_once($CFG->dirroot . '/mod/assignmentques/locallib.php');
    require_once($CFG->dirroot . '/question/editlib.php');

    $querystring = preg_replace('/^\?/', '', $args['querystring']);
    $params = [];
    parse_str($querystring, $params);

    // Build the required resources. The $params are all cleaned as
    // part of this process.
    list($thispageurl, $contexts, $cmid, $cm, $assignmentques, $pagevars) =
            question_build_edit_resources('editq', '/mod/assignmentques/edit.php', $params);

    // Get the course object and related bits.
    $course = $DB->get_record('course', array('id' => $assignmentques->course), '*', MUST_EXIST);
    require_capability('mod/assignmentques:manage', $contexts->lowest());

    // Create assignmentques question bank view.
    $questionbank = new mod_assignmentques\question\bank\custom_view($contexts, $thispageurl, $course, $cm, $assignmentques);
    $questionbank->set_assignmentques_has_attempts(assignmentques_has_attempts($assignmentques->id));

    // Output.
    $renderer = $PAGE->get_renderer('mod_assignmentques', 'edit');
    return $renderer->question_bank_contents($questionbank, $pagevars);
}

/**
 * Generates the add random question in a fragment output. This allows the
 * form to be rendered in javascript, for example inside a modal.
 *
 * The required arguments as keys in the $args array are:
 *      cat {string} The category and category context ids comma separated.
 *      addonpage {int} The page id to add this question to.
 *      returnurl {string} URL to return to after form submission.
 *      cmid {int} The course module id the questions are being added to.
 *
 * @param array $args The fragment arguments.
 * @return string The rendered mform fragment.
 */
function mod_assignmentques_output_fragment_add_random_question_form($args) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/assignmentques/addrandomform.php');

    $contexts = new \question_edit_contexts($args['context']);
    $formoptions = [
        'contexts' => $contexts,
        'cat' => $args['cat']
    ];
    $formdata = [
        'category' => $args['cat'],
        'addonpage' => $args['addonpage'],
        'returnurl' => $args['returnurl'],
        'cmid' => $args['cmid']
    ];

    $form = new assignmentques_add_random_form(
        new \moodle_url('/mod/assignmentques/addrandom.php'),
        $formoptions,
        'post',
        '',
        null,
        true,
        $formdata
    );
    $form->set_data($formdata);

    return $form->render();
}
