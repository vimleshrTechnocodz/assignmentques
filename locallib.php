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
 * Library of functions used by the assignmentques module.
 *
 * This contains functions that are called from within the assignmentques module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_assignmentques
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assignmentques/lib.php');
require_once($CFG->dirroot . '/mod/assignmentques/accessmanager.php');
require_once($CFG->dirroot . '/mod/assignmentques/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/assignmentques/renderer.php');
require_once($CFG->dirroot . '/mod/assignmentques/attemptlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');


/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the assignmentques close date. (1 hour)
 */
define('ASSIGNMENTQUES_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the assignmentques, then do not take them to the next page of the assignmentques. Instead
 * close the assignmentques immediately.
 */
define('ASSIGNMENTQUES_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in assignmentques settings.
 */
define('ASSIGNMENTQUES_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in assignmentques settings.
 */
define('ASSIGNMENTQUES_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in assignmentques settings.
 */
define('ASSIGNMENTQUES_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a assignmentques
 *
 * Creates an attempt object to represent an attempt at the assignmentques by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $assignmentquesobj the assignmentques object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $assignmentques->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this assignmentques.
 *
 * @return object the newly created attempt object.
 */
function assignmentques_create_attempt(assignmentques $assignmentquesobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $assignmentques = $assignmentquesobj->get_assignmentques();
    if ($assignmentques->sumgrades < 0.000005 && $assignmentques->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'assignmentques',
                new moodle_url('/mod/assignmentques/view.php', array('q' => $assignmentques->id)),
                    array('grade' => assignmentques_format_grade($assignmentques, $assignmentques->grade)));
    }

    if ($attemptnumber == 1 || !$assignmentques->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->assignmentques = $assignmentques->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'assignmentques');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->timemodifiedoffline = 0;
    $attempt->state = assignmentques_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $assignmentquesobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, assignmentques attempt.
 *
 * @param assignmentques      $assignmentquesobj            the assignmentques object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {

    // Usages for this user's previous assignmentques attempts.
    $qubaids = new \mod_assignmentques\question\qubaids_for_users_attempts(
            $assignmentquesobj->get_assignmentquesid(), $attempt->userid);

    // Fully load all the questions in this assignmentques.
    $assignmentquesobj->preload_questions();
    $assignmentquesobj->load_questions();

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = array();
    $maxmark = array();
    $page = array();
    foreach ($assignmentquesobj->get_questions() as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }
        if (!$assignmentquesobj->get_assignmentques()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = array();
        foreach ($questions as $question) {
            if (isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\bank\random_question_loader($qubaids, $usedquestionids);

        foreach ($assignmentquesobj->get_questions() as $questiondata) {
            $slot += 1;
            if ($questiondata->qtype != 'random') {
                continue;
            }

            $tagids = assignmentques_retrieve_slot_tag_ids($questiondata->slotid);

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()], $tagids)) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $assignmentquesobj->get_assignmentques()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $questionid = $randomloader->get_next_question_id($questiondata->randomfromcategory,
                    $questiondata->randomincludingsubcategories, $tagids);
            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'assignmentques',
                                           $assignmentquesobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $assignmentquesobj->get_assignmentques()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $sections = $assignmentquesobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array();
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $assignmentquesobj->get_assignmentques()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function assignmentques_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and assignmentques attempt in db and log the started attempt.
 *
 * @param assignmentques                       $assignmentquesobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('assignmentques_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $assignmentquesobj->get_courseid(),
        'context' => $assignmentquesobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'assignmentquesid' => $assignmentquesobj->get_assignmentquesid()
        );
        $event = \mod_assignmentques\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_assignmentques\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('assignmentques', $assignmentquesobj->get_assignmentques());
    $event->add_record_snapshot('assignmentques_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given assignmentques. This function does not return preview attempts.
 *
 * @param int $assignmentquesid the id of the assignmentques.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function assignmentques_get_user_attempt_unfinished($assignmentquesid, $userid) {
    $attempts = assignmentques_get_user_attempts($assignmentquesid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a assignmentques attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the assignmentques_attempts table).
 * @param object $assignmentques the assignmentques object.
 */
function assignmentques_delete_attempt($attempt, $assignmentques) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('assignmentques_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->assignmentques != $assignmentques->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to assignmentques $attempt->assignmentques " .
                "but was passed assignmentques $assignmentques->id.");
        return;
    }

    if (!isset($assignmentques->cmid)) {
        $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id, $assignmentques->course);
        $assignmentques->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('assignmentques_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = array(
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\attempt_deleted::create($params);
        $event->add_record_snapshot('assignmentques_attempts', $attempt);
        $event->trigger();
    }

    // Search assignmentques_attempts for other instances by this user.
    // If none, then delete record for this assignmentques, this user from assignmentques_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('assignmentques_attempts', array('userid' => $userid, 'assignmentques' => $assignmentques->id))) {
        $DB->delete_records('assignmentques_grades', array('userid' => $userid, 'assignmentques' => $assignmentques->id));
    } else {
        assignmentques_save_best_grade($assignmentques, $userid);
    }

    assignmentques_update_grades($assignmentques, $userid);
}

/**
 * Delete all the preview attempts at a assignmentques, or possibly all the attempts belonging
 * to one user.
 * @param object $assignmentques the assignmentques object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function assignmentques_delete_previews($assignmentques, $userid = null) {
    global $DB;
    $conditions = array('assignmentques' => $assignmentques->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('assignmentques_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        assignmentques_delete_attempt($attempt, $assignmentques);
    }
}

/**
 * @param int $assignmentquesid The assignmentques id.
 * @return bool whether this assignmentques has any (non-preview) attempts.
 */
function assignmentques_has_attempts($assignmentquesid) {
    global $DB;
    return $DB->record_exists('assignmentques_attempts', array('assignmentques' => $assignmentquesid, 'preview' => 0));
}

// Functions to do with assignmentques layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a assignmentques
 * @param int $assignmentquesid the id of the assignmentques to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function assignmentques_repaginate_questions($assignmentquesid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('assignmentques_sections', array('assignmentquesid' => $assignmentquesid), 'firstslot ASC');
    $firstslots = array();
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('assignmentques_slots', array('assignmentquesid' => $assignmentquesid),
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('assignmentques_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();
}

// Functions to do with assignmentques grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this assignmentques.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $assignmentques the assignmentques object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function assignmentques_rescale_grade($rawgrade, $assignmentques, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($assignmentques->sumgrades >= 0.000005) {
        $grade = $rawgrade * $assignmentques->grade / $assignmentques->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = assignmentques_format_question_grade($assignmentques, $grade);
    } else if ($format) {
        $grade = assignmentques_format_grade($assignmentques, $grade);
    }
    return $grade;
}

/**
 * Get the feedback object for this grade on this assignmentques.
 *
 * @param float $grade a grade on this assignmentques.
 * @param object $assignmentques the assignmentques settings.
 * @return false|stdClass the record object or false if there is not feedback for the given grade
 * @since  Moodle 3.1
 */
function assignmentques_feedback_record_for_grade($grade, $assignmentques) {
    global $DB;

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('assignmentques_feedback',
            'assignmentquesid = ? AND mingrade <= ? AND ? < maxgrade', array($assignmentques->id, $grade, $grade));

    return $feedback;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this assignmentques. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this assignmentques.
 * @param object $assignmentques the assignmentques settings.
 * @param object $context the assignmentques context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function assignmentques_feedback_for_grade($grade, $assignmentques, $context) {

    if (is_null($grade)) {
        return '';
    }

    $feedback = assignmentques_feedback_record_for_grade($grade, $assignmentques);

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_assignmentques', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $assignmentques the assignmentques database row.
 * @return bool Whether this assignmentques has any non-blank feedback text.
 */
function assignmentques_has_feedback($assignmentques) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($assignmentques->id, $cache)) {
        $cache[$assignmentques->id] = assignmentques_has_grades($assignmentques) &&
                $DB->record_exists_select('assignmentques_feedback', "assignmentquesid = ? AND " .
                    $DB->sql_isnotempty('assignmentques_feedback', 'feedbacktext', false, true),
                array($assignmentques->id));
    }
    return $cache[$assignmentques->id];
}

/**
 * Update the sumgrades field of the assignmentques. This needs to be called whenever
 * the grading structure of the assignmentques is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link assignmentques_delete_previews()} before you call this function.
 *
 * @param object $assignmentques a assignmentques.
 */
function assignmentques_update_sumgrades($assignmentques) {
    global $DB;

    $sql = 'UPDATE {assignmentques}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {assignmentques_slots}
                WHERE assignmentquesid = {assignmentques}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($assignmentques->id));
    $assignmentques->sumgrades = $DB->get_field('assignmentques', 'sumgrades', array('id' => $assignmentques->id));

    if ($assignmentques->sumgrades < 0.000005 && assignmentques_has_attempts($assignmentques->id)) {
        // If the assignmentques has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        assignmentques_set_grade(0, $assignmentques);
    }
}

/**
 * Update the sumgrades field of the attempts at a assignmentques.
 *
 * @param object $assignmentques a assignmentques.
 */
function assignmentques_update_all_attempt_sumgrades($assignmentques) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {assignmentques_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE assignmentques = :assignmentquesid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'assignmentquesid' => $assignmentques->id,
            'finishedstate' => assignmentques_attempt::FINISHED));
}

/**
 * The assignmentques grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in assignmentques_grades and assignmentques_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * assignmentques_update_all_attempt_sumgrades, assignmentques_update_all_final_grades and
 * assignmentques_update_grades.
 *
 * @param float $newgrade the new maximum grade for the assignmentques.
 * @param object $assignmentques the assignmentques we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function assignmentques_set_grade($newgrade, $assignmentques) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($assignmentques->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $assignmentques->grade;
    $assignmentques->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the assignmentques table.
    $DB->set_field('assignmentques', 'grade', $newgrade, array('id' => $assignmentques->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        assignmentques_update_all_final_grades($assignmentques);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {assignmentques_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE assignmentques = ?
        ", array($newgrade/$oldgrade, $timemodified, $assignmentques->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the assignmentques_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {assignmentques_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE assignmentquesid = ?
        ", array($factor, $factor, $assignmentques->id));
    }

    // Update grade item and send all grades to gradebook.
    assignmentques_grade_item_update($assignmentques);
    assignmentques_update_grades($assignmentques);

    $transaction->allow_commit();
    return true;
}

/**
 * Save the overall grade for a user at a assignmentques in the assignmentques_grades table
 *
 * @param object $assignmentques The assignmentques for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function assignmentques_save_best_grade($assignmentques, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = assignmentques_get_user_attempts($assignmentques->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = assignmentques_calculate_best_grade($assignmentques, $attempts);
    $bestgrade = assignmentques_rescale_grade($bestgrade, $assignmentques, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('assignmentques_grades', array('assignmentques' => $assignmentques->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('assignmentques_grades',
            array('assignmentques' => $assignmentques->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('assignmentques_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->assignmentques = $assignmentques->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('assignmentques_grades', $grade);
    }

    assignmentques_update_grades($assignmentques, $userid);
}

/**
 * Calculate the overall grade for a assignmentques given a number of attempts by a particular user.
 *
 * @param object $assignmentques    the assignmentques settings object.
 * @param array $attempts an array of all the user's attempts at this assignmentques in order.
 * @return float          the overall grade
 */
function assignmentques_calculate_best_grade($assignmentques, $attempts) {

    switch ($assignmentques->grademethod) {

        case ASSIGNMENTQUES_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case ASSIGNMENTQUES_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case ASSIGNMENTQUES_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case ASSIGNMENTQUES_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this assignmentques for all students.
 *
 * This function is equivalent to calling assignmentques_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $assignmentques the assignmentques settings.
 */
function assignmentques_update_all_final_grades($assignmentques) {
    global $DB;

    if (!$assignmentques->sumgrades) {
        return;
    }

    $param = array('iassignmentquesid' => $assignmentques->id, 'istatefinished' => assignmentques_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                iassignmentquesa.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {assignmentques_attempts} iassignmentquesa

            WHERE
                iassignmentquesa.state = :istatefinished AND
                iassignmentquesa.preview = 0 AND
                iassignmentquesa.assignmentques = :iassignmentquesid

            GROUP BY iassignmentquesa.userid
        ) first_last_attempts ON first_last_attempts.userid = assignmentquesa.userid";

    switch ($assignmentques->grademethod) {
        case ASSIGNMENTQUES_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(assignmentquesa.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'assignmentquesa.attempt = first_last_attempts.firstattempt AND';
            break;

        case ASSIGNMENTQUES_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(assignmentquesa.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'assignmentquesa.attempt = first_last_attempts.lastattempt AND';
            break;

        case ASSIGNMENTQUES_GRADEAVERAGE:
            $select = 'AVG(assignmentquesa.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case ASSIGNMENTQUES_GRADEHIGHEST:
            $select = 'MAX(assignmentquesa.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($assignmentques->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($assignmentques->grade / $assignmentques->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['assignmentquesid'] = $assignmentques->id;
    $param['assignmentquesid2'] = $assignmentques->id;
    $param['assignmentquesid3'] = $assignmentques->id;
    $param['assignmentquesid4'] = $assignmentques->id;
    $param['statefinished'] = assignmentques_attempt::FINISHED;
    $param['statefinished2'] = assignmentques_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT assignmentquesa.userid, $finalgrade AS newgrade
            FROM {assignmentques_attempts} assignmentquesa
            $join
            WHERE
                $where
                assignmentquesa.state = :statefinished AND
                assignmentquesa.preview = 0 AND
                assignmentquesa.assignmentques = :assignmentquesid3
            GROUP BY assignmentquesa.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {assignmentques_grades} qg
                WHERE assignmentques = :assignmentquesid
            UNION
                SELECT DISTINCT userid
                FROM {assignmentques_attempts} assignmentquesa2
                WHERE
                    assignmentquesa2.state = :statefinished2 AND
                    assignmentquesa2.preview = 0 AND
                    assignmentquesa2.assignmentques = :assignmentquesid2
            ) users

            LEFT JOIN {assignmentques_grades} qg ON qg.userid = users.userid AND qg.assignmentques = :assignmentquesid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->assignmentques = $assignmentques->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('assignmentques_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('assignmentques_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('assignmentques_grades', 'assignmentques = ? AND userid ' . $test,
                array_merge(array($assignmentques->id), $params));
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      assignmentquesid   => (array|int) attempts in given assignmentques(s)
 *                      groupid  => (array|int) assignmentqueszes with some override for given group(s)
 *
 */
function assignmentques_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("assignmentquesa.state IN ('inprogress', 'overdue')");
    $iwheres = array("iassignmentquesa.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "assignmentquesa.assignmentques IN (SELECT q.id FROM {assignmentques} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iassignmentquesa.assignmentques IN (SELECT q.id FROM {assignmentques} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "assignmentquesa.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iassignmentquesa.userid $incond";
    }

    if (isset($conditions['assignmentquesid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['assignmentquesid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "assignmentquesa.assignmentques $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['assignmentquesid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iassignmentquesa.assignmentques $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "assignmentquesa.assignmentques IN (SELECT qo.assignmentques FROM {assignmentques_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iassignmentquesa.assignmentques IN (SELECT qo.assignmentques FROM {assignmentques_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $assignmentquesausersql = assignmentques_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN assignmentquesauser.usertimelimit = 0 AND assignmentquesauser.usertimeclose = 0 THEN NULL
               WHEN assignmentquesauser.usertimelimit = 0 THEN assignmentquesauser.usertimeclose
               WHEN assignmentquesauser.usertimeclose = 0 THEN assignmentquesa.timestart + assignmentquesauser.usertimelimit
               WHEN assignmentquesa.timestart + assignmentquesauser.usertimelimit < assignmentquesauser.usertimeclose THEN assignmentquesa.timestart + assignmentquesauser.usertimelimit
               ELSE assignmentquesauser.usertimeclose END +
          CASE WHEN assignmentquesa.state = 'overdue' THEN assignmentques.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {assignmentques_attempts} assignmentquesa
                        JOIN {assignmentques} assignmentques ON assignmentques.id = assignmentquesa.assignmentques
                        JOIN ( $assignmentquesausersql ) assignmentquesauser ON assignmentquesauser.id = assignmentquesa.id
                         SET assignmentquesa.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {assignmentques_attempts} assignmentquesa
                         SET timecheckstate = $timecheckstatesql
                        FROM {assignmentques} assignmentques, ( $assignmentquesausersql ) assignmentquesauser
                       WHERE assignmentques.id = assignmentquesa.assignmentques
                         AND assignmentquesauser.id = assignmentquesa.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE assignmentquesa
                         SET timecheckstate = $timecheckstatesql
                        FROM {assignmentques_attempts} assignmentquesa
                        JOIN {assignmentques} assignmentques ON assignmentques.id = assignmentquesa.assignmentques
                        JOIN ( $assignmentquesausersql ) assignmentquesauser ON assignmentquesauser.id = assignmentquesa.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {assignmentques_attempts} assignmentquesa
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {assignmentques} assignmentques, ( $assignmentquesausersql ) assignmentquesauser
                            WHERE assignmentques.id = assignmentquesa.assignmentques
                              AND assignmentquesauser.id = assignmentquesa.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 * The query used herein is very similar to the one in function assignmentques_get_user_timeclose, so, in case you
 * would change either one of them, make sure to apply your changes to both.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias iassignmentquesa for the assignmentques attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function assignmentques_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $assignmentquesausersql = "
          SELECT iassignmentquesa.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), iassignmentques.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), iassignmentques.timelimit) AS usertimelimit

           FROM {assignmentques_attempts} iassignmentquesa
           JOIN {assignmentques} iassignmentques ON iassignmentques.id = iassignmentquesa.assignmentques
      LEFT JOIN {assignmentques_overrides} quo ON quo.assignmentques = iassignmentquesa.assignmentques AND quo.userid = iassignmentquesa.userid
      LEFT JOIN {groups_members} gm ON gm.userid = iassignmentquesa.userid
      LEFT JOIN {assignmentques_overrides} qgo1 ON qgo1.assignmentques = iassignmentquesa.assignmentques AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {assignmentques_overrides} qgo2 ON qgo2.assignmentques = iassignmentquesa.assignmentques AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {assignmentques_overrides} qgo3 ON qgo3.assignmentques = iassignmentquesa.assignmentques AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {assignmentques_overrides} qgo4 ON qgo4.assignmentques = iassignmentquesa.assignmentques AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY iassignmentquesa.id, iassignmentques.id, iassignmentques.timeclose, iassignmentques.timelimit";
    return $assignmentquesausersql;
}

/**
 * Return the attempt with the best grade for a assignmentques
 *
 * Which attempt is the best depends on $assignmentques->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $assignmentques    The assignmentques for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the assignmentques
 */
function assignmentques_calculate_best_attempt($assignmentques, $attempts) {

    switch ($assignmentques->grademethod) {

        case ASSIGNMENTQUES_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case ASSIGNMENTQUES_GRADEAVERAGE: // We need to do something with it.
        case ASSIGNMENTQUES_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case ASSIGNMENTQUES_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the assignmentques grade
 *      from the individual attempt grades.
 */
function assignmentques_get_grading_options() {
    return array(
        ASSIGNMENTQUES_GRADEHIGHEST => get_string('gradehighest', 'assignmentques'),
        ASSIGNMENTQUES_GRADEAVERAGE => get_string('gradeaverage', 'assignmentques'),
        ASSIGNMENTQUES_ATTEMPTFIRST => get_string('attemptfirst', 'assignmentques'),
        ASSIGNMENTQUES_ATTEMPTLAST  => get_string('attemptlast', 'assignmentques')
    );
}

/**
 * @param int $option one of the values ASSIGNMENTQUES_GRADEHIGHEST, ASSIGNMENTQUES_GRADEAVERAGE,
 *      ASSIGNMENTQUES_ATTEMPTFIRST or ASSIGNMENTQUES_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function assignmentques_get_grading_option_name($option) {
    $strings = assignmentques_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue assignmentques
 *      attempts.
 */
function assignmentques_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'assignmentques'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'assignmentques'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'assignmentques'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function assignmentques_get_user_image_options() {
    return array(
        ASSIGNMENTQUES_SHOWIMAGE_NONE  => get_string('shownoimage', 'assignmentques'),
        ASSIGNMENTQUES_SHOWIMAGE_SMALL => get_string('showsmallimage', 'assignmentques'),
        ASSIGNMENTQUES_SHOWIMAGE_LARGE => get_string('showlargeimage', 'assignmentques'),
    );
}

/**
 * Return an user's timeclose for all assignmentqueszes in a course, hereby taking into account group and user overrides.
 *
 * @param int $courseid the course id.
 * @return object An object with of all assignmentquesids and close unixdates in this course, taking into account the most lenient
 * overrides, if existing and 0 if no close date is set.
 */
function assignmentques_get_user_timeclose($courseid) {
    global $DB, $USER;

    // For teacher and manager/admins return timeclose.
    if (has_capability('moodle/course:update', context_course::instance($courseid))) {
        $sql = "SELECT assignmentques.id, assignmentques.timeclose AS usertimeclose
                  FROM {assignmentques} assignmentques
                 WHERE assignmentques.course = :courseid";

        $results = $DB->get_records_sql($sql, array('courseid' => $courseid));
        return $results;
    }

    $sql = "SELECT q.id,
  COALESCE(v.userclose, v.groupclose, q.timeclose, 0) AS usertimeclose
  FROM (
      SELECT assignmentques.id as assignmentquesid,
             MAX(quo.timeclose) AS userclose, MAX(qgo.timeclose) AS groupclose
       FROM {assignmentques} assignmentques
  LEFT JOIN {assignmentques_overrides} quo on assignmentques.id = quo.assignmentques AND quo.userid = :userid
  LEFT JOIN {groups_members} gm ON gm.userid = :useringroupid
  LEFT JOIN {assignmentques_overrides} qgo on assignmentques.id = qgo.assignmentques AND qgo.groupid = gm.groupid
      WHERE assignmentques.course = :courseid
   GROUP BY assignmentques.id) v
       JOIN {assignmentques} q ON q.id = v.assignmentquesid";

    $results = $DB->get_records_sql($sql, array('userid' => $USER->id, 'useringroupid' => $USER->id, 'courseid' => $courseid));
    return $results;

}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function assignmentques_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'assignmentques');
    $pageoptions[1] = get_string('everyquestion', 'assignmentques');
    for ($i = 2; $i <= ASSIGNMENTQUES_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'assignmentques', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a assignmentques attempt state.
 * @param string $state one of the state constants like {@link assignmentques_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function assignmentques_attempt_state_name($state) {
    switch ($state) {
        case assignmentques_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'assignmentques');
        case assignmentques_attempt::OVERDUE:
            return get_string('stateoverdue', 'assignmentques');
        case assignmentques_attempt::FINISHED:
            return get_string('statefinished', 'assignmentques');
        case assignmentques_attempt::ABANDONED:
            return get_string('stateabandoned', 'assignmentques');
        default:
            throw new coding_exception('Unknown assignmentques attempt state.');
    }
}

// Other assignmentques functions ////////////////////////////////////////////////////////

/**
 * @param object $assignmentques the assignmentques.
 * @param int $cmid the course_module object for this assignmentques.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function assignmentques_question_action_icons($assignmentques, $cmid, $question, $returnurl, $variant = null) {
    $html = assignmentques_question_preview_button($assignmentques, $question, false, $variant) . ' ' .
            assignmentques_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this assignmentques.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function assignmentques_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit') ||
                    question_has_capability_on($question, 'move'))) {
        $action = $stredit;
        $icon = 't/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view')) {
        $action = $strview;
        $icon = 'i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton">' .
                $OUTPUT->pix_icon($icon, $action) . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $assignmentques the assignmentques settings
 * @param object $question the question
 * @param int $variant which question variant to preview (optional).
 * @return moodle_url to preview this question with the options from this assignmentques.
 */
function assignmentques_question_preview_url($assignmentques, $question, $variant = null) {
    // Get the appropriate display options.
    $displayoptions = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
            mod_assignmentques_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $assignmentques->preferredbehaviour,
            $maxmark, $displayoptions, $variant);
}

/**
 * @param object $assignmentques the assignmentques settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @return the HTML for a preview question icon.
 */
function assignmentques_question_preview_button($assignmentques, $question, $label = false, $variant = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use')) {
        return '';
    }

    return $PAGE->get_renderer('mod_assignmentques', 'edit')->question_preview_icon($assignmentques, $question, $label, $variant);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the assignmentques context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function assignmentques_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this assignmentques attempt is in - in the sense used by
 * assignmentques_get_review_options, not in the sense of $attempt->state.
 * @param object $assignmentques the assignmentques settings
 * @param object $attempt the assignmentques_attempt database row.
 * @return int one of the mod_assignmentques_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function assignmentques_attempt_state($assignmentques, $attempt) {
    if ($attempt->state == assignmentques_attempt::IN_PROGRESS) {
        return mod_assignmentques_display_options::DURING;
    } else if ($assignmentques->timeclose && time() >= $assignmentques->timeclose) {
        return mod_assignmentques_display_options::AFTER_CLOSE;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_assignmentques_display_options::IMMEDIATELY_AFTER;
    } else {
        return mod_assignmentques_display_options::LATER_WHILE_OPEN;
    }
}

/**
 * The the appropraite mod_assignmentques_display_options object for this attempt at this
 * assignmentques right now.
 *
 * @param stdClass $assignmentques the assignmentques instance.
 * @param stdClass $attempt the attempt in question.
 * @param context $context the assignmentques context.
 *
 * @return mod_assignmentques_display_options
 */
function assignmentques_get_review_options($assignmentques, $attempt, $context) {
    $options = mod_assignmentques_display_options::make_from_assignmentques($assignmentques, assignmentques_attempt_state($assignmentques, $attempt));

    $options->readonly = true;
    $options->flags = assignmentques_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/assignmentques/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == assignmentques_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/assignmentques:grade', $context)) {        
                $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/assignmentques/comment.php',
                array('attempt' => $attempt->id));        
    }elseif(!empty($attempt->id)){        
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/assignmentques/comment.php',
                array('attempt' => $attempt->id));        
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/assignmentques:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different assignmentques attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = assignmentques_get_combined_reviewoptions(...)
 *
 * @param object $assignmentques the assignmentques instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function assignmentques_get_combined_reviewoptions($assignmentques, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    // This shouldn't happen, but we need to prevent reveal information.
    if (empty($attempts)) {
        return array($someoptions, $someoptions);
    }

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_assignmentques_display_options::make_from_assignmentques($assignmentques,
                assignmentques_attempt_state($assignmentques, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function assignmentques_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_assignmentques';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'assignmentques', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'assignmentques', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'assignmentques', $a);
    $eventdata->contexturl        = $a->assignmentquesurl;
    $eventdata->contexturlname    = $a->assignmentquesname;
    $eventdata->customdata        = [
        'cmid' => $a->assignmentquescmid,
        'instance' => $a->assignmentquesid,
        'attemptid' => $a->attemptid,
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function assignmentques_send_notification($recipient, $submitter, $a) {
    global $PAGE;

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_assignmentques';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'assignmentques', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'assignmentques', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'assignmentques', $a);
    $eventdata->contexturl        = $a->assignmentquesreviewurl;
    $eventdata->contexturlname    = $a->assignmentquesname;
    $userpicture = new user_picture($submitter);
    $userpicture->includetoken = $recipient->id; // Generate an out-of-session token for the user receiving the message.
    $eventdata->customdata        = [
        'cmid' => $a->assignmentquescmid,
        'instance' => $a->assignmentquesid,
        'attemptid' => $a->attemptid,
        'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a assignmentques attempt is submitted.
 *
 * @param object $course the course
 * @param object $assignmentques the assignmentques
 * @param object $attempt this attempt just finished
 * @param object $context the assignmentques context
 * @param object $cm the coursemodule for this assignmentques
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function assignmentques_send_notification_messages($course, $assignmentques, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($assignmentques) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $assignmentques, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/assignmentques:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $notifyfields .= get_all_user_name_fields(true, 'u');
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the assignmentques is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/assignmentques:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->courseid        = $course->id;
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Assignmentques info.
    $a->assignmentquesname        = $assignmentques->name;
    $a->assignmentquesreporturl   = $CFG->wwwroot . '/mod/assignmentques/report.php?id=' . $cm->id;
    $a->assignmentquesreportlink  = '<a href="' . $a->assignmentquesreporturl . '">' .
            format_string($assignmentques->name) . ' report</a>';
    $a->assignmentquesurl         = $CFG->wwwroot . '/mod/assignmentques/view.php?id=' . $cm->id;
    $a->assignmentqueslink        = '<a href="' . $a->assignmentquesurl . '">' . format_string($assignmentques->name) . '</a>';
    $a->assignmentquesid          = $assignmentques->id;
    $a->assignmentquescmid        = $cm->id;
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->assignmentquesreviewurl   = $CFG->wwwroot . '/mod/assignmentques/review.php?attempt=' . $attempt->id;
    $a->assignmentquesreviewlink  = '<a href="' . $a->assignmentquesreviewurl . '">' .
            format_string($assignmentques->name) . ' review</a>';
    $a->attemptid       = $attempt->id;
    // Student who sat the assignmentques info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && assignmentques_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && assignmentques_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when a assignmentques attempt becomes overdue.
 *
 * @param assignmentques_attempt $attemptobj all the data about the assignmentques attempt.
 */
function assignmentques_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/assignmentques:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $assignmentquesname = format_string($attemptobj->get_assignmentques_name());

    $deadlines = array();
    if ($attemptobj->get_assignmentques()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_assignmentques()->timelimit;
    }
    if ($attemptobj->get_assignmentques()->timeclose) {
        $deadlines[] = $attemptobj->get_assignmentques()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_assignmentques()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_course()->id;
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Assignmentques info.
    $a->assignmentquesname           = $assignmentquesname;
    $a->assignmentquesurl            = $attemptobj->view_url();
    $a->assignmentqueslink           = '<a href="' . $a->assignmentquesurl . '">' . $assignmentquesname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $assignmentquesname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_assignmentques';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'assignmentques', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'assignmentques', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'assignmentques', $a);
    $eventdata->contexturl        = $a->assignmentquesurl;
    $eventdata->contexturlname    = $a->assignmentquesname;
    $eventdata->customdata        = [
        'cmid' => $attemptobj->get_cmid(),
        'instance' => $attemptobj->get_assignmentquesid(),
        'attemptid' => $attemptobj->get_attemptid(),
    ];

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the assignmentques_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function assignmentques_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('assignmentques_attempts', $event->objectid);
    $assignmentques    = $event->get_record_snapshot('assignmentques', $attempt->assignmentques);
    $cm      = get_coursemodule_from_id('assignmentques', $event->get_context()->instanceid, $event->courseid);

    if (!($course && $assignmentques && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && ($assignmentques->completionattemptsexhausted || $assignmentques->completionpass)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return assignmentques_send_notification_messages($course, $assignmentques, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_assignmentques\group_observers::group_member_added()}.
 */
function assignmentques_groups_member_added_handler($event) {
    debugging('assignmentques_groups_member_added_handler() is deprecated, please use ' .
        '\mod_assignmentques\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
    assignmentques_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_assignmentques\group_observers::group_member_removed()}.
 */
function assignmentques_groups_member_removed_handler($event) {
    debugging('assignmentques_groups_member_removed_handler() is deprecated, please use ' .
        '\mod_assignmentques\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    assignmentques_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_assignmentques\group_observers::group_deleted()}.
 */
function assignmentques_groups_group_deleted_handler($event) {
    global $DB;
    debugging('assignmentques_groups_group_deleted_handler() is deprecated, please use ' .
        '\mod_assignmentques\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
    assignmentques_process_group_deleted_in_course($event->courseid);
}

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function assignmentques_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all assignmentqueszes with orphaned group overrides.
    $sql = "SELECT o.id, o.assignmentques
              FROM {assignmentques_overrides} o
              JOIN {assignmentques} assignmentques ON assignmentques.id = o.assignmentques
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE assignmentques.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql_menu($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('assignmentques_overrides', 'id', array_keys($records));
    assignmentques_update_open_attempts(array('assignmentquesid' => array_unique(array_values($records))));
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_assignmentques\group_observers::group_member_removed()}.
 */
function assignmentques_groups_members_removed_handler($event) {
    debugging('assignmentques_groups_members_removed_handler() is deprecated, please use ' .
        '\mod_assignmentques\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    if ($event->userid == 0) {
        assignmentques_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        assignmentques_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard assignmentques JavaScript module.
 * @return array a standard jsmodule structure.
 */
function assignmentques_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_assignmentques',
        'fullpath' => '/mod/assignmentques/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'assignmentques'),
            array('startattempt', 'assignmentques'),
            array('timesup', 'assignmentques'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the assignmentques.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignmentques_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * assignmentques attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the assignmentques settings, and a time constant.
     * @param object $assignmentques the assignmentques settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_assignmentques_display_options set up appropriately.
     */
    public static function make_from_assignmentques($assignmentques, $when) {
        $options = new self();

        $options->attempt = self::extract($assignmentques->reviewattempt, $when, true, false);
        $options->correctness = self::extract($assignmentques->reviewcorrectness, $when);
        $options->marks = self::extract($assignmentques->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($assignmentques->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($assignmentques->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($assignmentques->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($assignmentques->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($assignmentques->questiondecimalpoints != -1) {
            $options->markdp = $assignmentques->questiondecimalpoints;
        } else {
            $options->markdp = $assignmentques->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular assignmentques.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_assignmentques extends qubaid_join {
    public function __construct($assignmentquesid, $includepreviews = true, $onlyfinished = false) {
        $where = 'assignmentquesa.assignmentques = :assignmentquesaassignmentques';
        $params = array('assignmentquesaassignmentques' => $assignmentquesid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = assignmentques_attempt::FINISHED;
        }

        parent::__construct('{assignmentques_attempts} assignmentquesa', 'assignmentquesa.uniqueid', $where, $params);
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to a particular user and assignmentques combination.
 *
 * @copyright  2018 Andrew Nicols <andrwe@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_assignmentques_user extends qubaid_join {
    /**
     * Constructor for this qubaid.
     *
     * @param   int     $assignmentquesid The assignmentques to search.
     * @param   int     $userid The user to filter on
     * @param   bool    $includepreviews Whether to include preview attempts
     * @param   bool    $onlyfinished Whether to only include finished attempts or not
     */
    public function __construct($assignmentquesid, $userid, $includepreviews = true, $onlyfinished = false) {
        $where = 'assignmentquesa.assignmentques = :assignmentquesaassignmentques AND assignmentquesa.userid = :assignmentquesauserid';
        $params = [
            'assignmentquesaassignmentques' => $assignmentquesid,
            'assignmentquesauserid' => $userid,
        ];

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = assignmentques_attempt::FINISHED;
        }

        parent::__construct('{assignmentques_attempts} assignmentquesa', 'assignmentquesa.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @return string
 */
function assignmentques_question_tostring($question, $showicon = false, $showquestiontext = true) {
    $result = '';

    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, array('noclean' => true, 'para' => false));
        $questiontext = shorten_text($questiontext, 200);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function assignmentques_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * @param object $assignmentques the assignmentques settings.
 * @param int $slot which question in the assignmentques to test.
 * @return bool whether the user can use this question.
 */
function assignmentques_has_question_use($assignmentques, $slot) {
    global $DB;
    $question = $DB->get_record_sql("
            SELECT q.*
              FROM {assignmentques_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.assignmentquesid = ? AND slot.slot = ?", array($assignmentques->id, $slot));
    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to a assignmentques
 *
 * Adds a question to a assignmentques by updating $assignmentques as well as the
 * assignmentques and assignmentques_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $assignmentques The extended assignmentques object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in assignmentques to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the assignmentques
 */
function assignmentques_add_assignmentques_question($questionid, $assignmentques, $page = 0, $maxmark = null) {
    global $DB;

    // Make sue the question is not of the "random" type.
    $questiontype = $DB->get_field('question', 'qtype', array('id' => $questionid));
    if ($questiontype == 'random') {
        throw new coding_exception(
                'Adding "random" questions via assignmentques_add_assignmentques_question() is deprecated. Please use assignmentques_add_random_questions().'
        );
    }

    $trans = $DB->start_delegated_transaction();
    $slots = $DB->get_records('assignmentques_slots', array('assignmentquesid' => $assignmentques->id),
            'slot', 'questionid, slot, page, id');
    if (array_key_exists($questionid, $slots)) {
        $trans->allow_commit();
        return false;
    }

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new question instance.
    $slot = new stdClass();
    $slot->assignmentquesid = $assignmentques->id;
    $slot->questionid = $questionid;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('assignmentques_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        assignmentques_update_section_firstslots($assignmentques->id, 1, max($lastslotbefore, 1));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($assignmentques->questionsperpage && $numonlastpage >= $assignmentques->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $DB->insert_record('assignmentques_slots', $slot);
    $trans->allow_commit();
}

/**
 * Move all the section headings in a certain slot range by a certain offset.
 *
 * @param int $assignmentquesid the id of a assignmentques
 * @param int $direction amount to adjust section heading positions. Normally +1 or -1.
 * @param int $afterslot adjust headings that start after this slot.
 * @param int|null $beforeslot optionally, only adjust headings before this slot.
 */
function assignmentques_update_section_firstslots($assignmentquesid, $direction, $afterslot, $beforeslot = null) {
    global $DB;
    $where = 'assignmentquesid = ? AND firstslot > ?';
    $params = [$direction, $assignmentquesid, $afterslot];
    if ($beforeslot) {
        $where .= ' AND firstslot < ?';
        $params[] = $beforeslot;
    }
    $firstslotschanges = $DB->get_records_select_menu('assignmentques_sections',
            $where, $params, '', 'firstslot, firstslot + ?');
    update_field_with_unique_index('assignmentques_sections', 'firstslot', $firstslotschanges, ['assignmentquesid' => $assignmentquesid]);
}

/**
 * Add a random question to the assignmentques at a given point.
 * @param stdClass $assignmentques the assignmentques settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 * @param int[] $tagids Array of tagids. The question that will be picked randomly should be tagged with all these tags.
 */
function assignmentques_add_random_questions($assignmentques, $addonpage, $categoryid, $number,
        $includesubcategories, $tagids = []) {
    global $DB;

    $category = $DB->get_record('question_categories', array('id' => $categoryid));
    if (!$category) {
        print_error('invalidcategoryid', 'error');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    $tags = \core_tag_tag::get_bulk($tagids, 'id, name');
    $tagstrings = [];
    foreach ($tags as $tag) {
        $tagstrings[] = "{$tag->id},{$tag->name}";
    }

    // Find existing random questions in this category that are
    // not used by any assignmentques.
    $existingquestions = $DB->get_records_sql(
        "SELECT q.id, q.qtype FROM {question} q
        WHERE qtype = 'random'
            AND category = ?
            AND " . $DB->sql_compare_text('questiontext') . " = ?
            AND NOT EXISTS (
                    SELECT *
                      FROM {assignmentques_slots}
                     WHERE questionid = q.id)
        ORDER BY id", array($category->id, $includesubcategories ? '1' : '0'));

    for ($i = 0; $i < $number; $i++) {
        // Take as many of orphaned "random" questions as needed.
        if (!$question = array_shift($existingquestions)) {
            $form = new stdClass();
            $form->category = $category->id . ',' . $category->contextid;
            $form->includesubcategories = $includesubcategories;
            $form->fromtags = $tagstrings;
            $form->defaultmark = 1;
            $form->hidden = 1;
            $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
            $question = new stdClass();
            $question->qtype = 'random';
            $question = question_bank::get_qtype('random')->save_question($question, $form);
            if (!isset($question->id)) {
                print_error('cannotinsertrandomquestion', 'assignmentques');
            }
        }

        $randomslotdata = new stdClass();
        $randomslotdata->assignmentquesid = $assignmentques->id;
        $randomslotdata->questionid = $question->id;
        $randomslotdata->questioncategoryid = $categoryid;
        $randomslotdata->includingsubcategories = $includesubcategories ? 1 : 0;
        $randomslotdata->maxmark = 1;

        $randomslot = new \mod_assignmentques\local\structure\slot_random($randomslotdata);
        $randomslot->set_assignmentques($assignmentques);
        $randomslot->set_tags($tags);
        $randomslot->insert($addonpage);
    }
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $assignmentques       assignmentques object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.1
 */
function assignmentques_view($assignmentques, $course, $cm, $context) {

    $params = array(
        'objectid' => $assignmentques->id,
        'context' => $context
    );

    $event = \mod_assignmentques\event\course_module_viewed::create($params);
    $event->add_record_snapshot('assignmentques', $assignmentques);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Validate permissions for creating a new attempt and start a new preview attempt if required.
 *
 * @param  assignmentques $assignmentquesobj assignmentques object
 * @param  assignmentques_access_manager $accessmanager assignmentques access manager
 * @param  bool $forcenew whether was required to start a new preview attempt
 * @param  int $page page to jump to in the attempt
 * @param  bool $redirect whether to redirect or throw exceptions (for web or ws usage)
 * @return array an array containing the attempt information, access error messages and the page to jump to in the attempt
 * @throws moodle_assignmentques_exception
 * @since Moodle 3.1
 */
function assignmentques_validate_new_attempt(assignmentques $assignmentquesobj, assignmentques_access_manager $accessmanager, $forcenew, $page, $redirect) {
    global $DB, $USER;
    $timenow = time();

    if ($assignmentquesobj->is_preview_user() && $forcenew) {
        $accessmanager->current_attempt_finished();
    }

    // Check capabilities.
    if (!$assignmentquesobj->is_preview_user()) {
        $assignmentquesobj->require_capability('mod/assignmentques:attempt');
    }

    // Check to see if a new preview was requested.
    if ($assignmentquesobj->is_preview_user() && $forcenew) {
        // To force the creation of a new preview, we mark the current attempt (if any)
        // as abandoned. It will then automatically be deleted below.
        $DB->set_field('assignmentques_attempts', 'state', assignmentques_attempt::ABANDONED,
                array('assignmentques' => $assignmentquesobj->get_assignmentquesid(), 'userid' => $USER->id));
    }

    // Look for an existing attempt.
    $attempts = assignmentques_get_user_attempts($assignmentquesobj->get_assignmentquesid(), $USER->id, 'all', true);
    $lastattempt = end($attempts);

    $attemptnumber = null;
    // If an in-progress attempt exists, check password then redirect to it.
    if ($lastattempt && ($lastattempt->state == assignmentques_attempt::IN_PROGRESS ||
            $lastattempt->state == assignmentques_attempt::OVERDUE)) {
        $currentattemptid = $lastattempt->id;
        $messages = $accessmanager->prevent_access();

        // If the attempt is now overdue, deal with that.
        $assignmentquesobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

        // And, if the attempt is now no longer in progress, redirect to the appropriate place.
        if ($lastattempt->state == assignmentques_attempt::ABANDONED || $lastattempt->state == assignmentques_attempt::FINISHED) {
            if ($redirect) {
                redirect($assignmentquesobj->review_url($lastattempt->id));
            } else {
                throw new moodle_assignmentques_exception($assignmentquesobj, 'attemptalreadyclosed');
            }
        }

        // If the page number was not explicitly in the URL, go to the current page.
        if ($page == -1) {
            $page = $lastattempt->currentpage;
        }

    } else {
        while ($lastattempt && $lastattempt->preview) {
            $lastattempt = array_pop($attempts);
        }

        // Get number for the next or unfinished attempt.
        if ($lastattempt) {
            $attemptnumber = $lastattempt->attempt + 1;
        } else {
            $lastattempt = false;
            $attemptnumber = 1;
        }
        $currentattemptid = null;

        $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

        if ($page == -1) {
            $page = 0;
        }
    }
    return array($currentattemptid, $attemptnumber, $lastattempt, $messages, $page);
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param assignmentques $assignmentquesobj assignmentques object
 * @param int $attemptnumber the attempt number
 * @param object $lastattempt last attempt object
 * @param bool $offlineattempt whether is an offline attempt or not
 * @param array $forcedrandomquestions slot number => question id. Used for random questions,
 *      to force the choice of a particular actual question. Intended for testing purposes only.
 * @param array $forcedvariants slot number => variant. Used for questions with variants,
 *      to force the choice of a particular variant. Intended for testing purposes only.
 * @return object the new attempt
 * @since  Moodle 3.1
 */
function assignmentques_prepare_and_start_new_attempt(assignmentques $assignmentquesobj, $attemptnumber, $lastattempt,
        $offlineattempt = false, $forcedrandomquestions = [], $forcedvariants = []) {
    global $DB, $USER;

    // Delete any previous preview attempts belonging to this user.
    assignmentques_delete_previews($assignmentquesobj->get_assignmentques(), $USER->id);

    $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
    $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

    // Create the new attempt and initialize the question sessions
    $timenow = time(); // Update time now, in case the server is running really slowly.
    $attempt = assignmentques_create_attempt($assignmentquesobj, $attemptnumber, $lastattempt, $timenow, $assignmentquesobj->is_preview_user());

    if (!($assignmentquesobj->get_assignmentques()->attemptonlast && $lastattempt)) {
        $attempt = assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, $attemptnumber, $timenow,
                $forcedrandomquestions, $forcedvariants);
    } else {
        $attempt = assignmentques_start_attempt_built_on_last($quba, $attempt, $lastattempt);
    }

    $transaction = $DB->start_delegated_transaction();

    // Init the timemodifiedoffline for offline attempts.
    if ($offlineattempt) {
        $attempt->timemodifiedoffline = $attempt->timemodified;
    }
    $attempt = assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}

/**
 * Check if the given calendar_event is either a user or group override
 * event for assignmentques.
 *
 * @param calendar_event $event The calendar event to check
 * @return bool
 */
function assignmentques_is_overriden_calendar_event(\calendar_event $event) {
    global $DB;

    if (!isset($event->modulename)) {
        return false;
    }

    if ($event->modulename != 'assignmentques') {
        return false;
    }

    if (!isset($event->instance)) {
        return false;
    }

    if (!isset($event->userid) && !isset($event->groupid)) {
        return false;
    }

    $overrideparams = [
        'assignmentques' => $event->instance
    ];

    if (isset($event->groupid)) {
        $overrideparams['groupid'] = $event->groupid;
    } else if (isset($event->userid)) {
        $overrideparams['userid'] = $event->userid;
    }

    return $DB->record_exists('assignmentques_overrides', $overrideparams);
}

/**
 * Retrieves tag information for the given list of assignmentques slot ids.
 * Currently the only slots that have tags are random question slots.
 *
 * Example:
 * If we have 3 slots with id 1, 2, and 3. The first slot has two tags, the second
 * has one tag, and the third has zero tags. The return structure will look like:
 * [
 *      1 => [
 *          assignmentques_slot_tags.id => { ...tag data... },
 *          assignmentques_slot_tags.id => { ...tag data... },
 *      ],
 *      2 => [
 *          assignmentques_slot_tags.id => { ...tag data... },
 *      ],
 *      3 => [],
 * ]
 *
 * @param int[] $slotids The list of id for the assignmentques slots.
 * @return array[] List of assignmentques_slot_tags records indexed by slot id.
 */
function assignmentques_retrieve_tags_for_slot_ids($slotids) {
    global $DB;

    if (empty($slotids)) {
        return [];
    }

    $slottags = $DB->get_records_list('assignmentques_slot_tags', 'slotid', $slotids);
    $tagsbyid = core_tag_tag::get_bulk(array_filter(array_column($slottags, 'tagid')), 'id, name');
    $tagsbyname = false; // It will be loaded later if required.
    $emptytagids = array_reduce($slotids, function($carry, $slotid) {
        $carry[$slotid] = [];
        return $carry;
    }, []);

    return array_reduce(
        $slottags,
        function($carry, $slottag) use ($slottags, $tagsbyid, $tagsbyname) {
            if (isset($tagsbyid[$slottag->tagid])) {
                // Make sure that we're returning the most updated tag name.
                $slottag->tagname = $tagsbyid[$slottag->tagid]->name;
            } else {
                if ($tagsbyname === false) {
                    // We were hoping that this query could be avoided, but life
                    // showed its other side to us!
                    $tagcollid = core_tag_area::get_collection('core', 'question');
                    $tagsbyname = core_tag_tag::get_by_name_bulk(
                        $tagcollid,
                        array_column($slottags, 'tagname'),
                        'id, name'
                    );
                }
                if (isset($tagsbyname[$slottag->tagname])) {
                    // Make sure that we're returning the current tag id that matches
                    // the given tag name.
                    $slottag->tagid = $tagsbyname[$slottag->tagname]->id;
                } else {
                    // The tag does not exist anymore (neither the tag id nor the tag name
                    // matches an existing tag).
                    // We still need to include this row in the result as some callers might
                    // be interested in these rows. An example is the editing forms that still
                    // need to display tag names even if they don't exist anymore.
                    $slottag->tagid = null;
                }
            }

            $carry[$slottag->slotid][$slottag->id] = $slottag;
            return $carry;
        },
        $emptytagids
    );
}

/**
 * Retrieves tag information for the given assignmentques slot.
 * A assignmentques slot have some tags if and only if it is representing a random question by tags.
 *
 * @param int $slotid The id of the assignmentques slot.
 * @return stdClass[] List of assignmentques_slot_tags records.
 */
function assignmentques_retrieve_slot_tags($slotid) {
    $slottags = assignmentques_retrieve_tags_for_slot_ids([$slotid]);
    return $slottags[$slotid];
}

/**
 * Retrieves tag ids for the given assignmentques slot.
 * A assignmentques slot have some tags if and only if it is representing a random question by tags.
 *
 * @param int $slotid The id of the assignmentques slot.
 * @return int[]
 */
function assignmentques_retrieve_slot_tag_ids($slotid) {
    $tags = assignmentques_retrieve_slot_tags($slotid);

    // Only work with tags that exist.
    return array_filter(array_column($tags, 'tagid'));
}

/**
 * Get assignmentques attempt and handling error.
 *
 * @param int $attemptid the id of the current attempt.
 * @param int|null $cmid the course_module id for this assignmentques.
 * @return assignmentques_attempt $attemptobj all the data about the assignmentques attempt.
 * @throws moodle_exception
 */
function assignmentques_create_attempt_handling_errors($attemptid, $cmid = null) {
    try {
        $attempobj = assignmentques_attempt::create($attemptid);
    } catch (moodle_exception $e) {
        if (!empty($cmid)) {
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assignmentques');
            $continuelink = new moodle_url('/mod/assignmentques/view.php', array('id' => $cmid));
            $context = context_module::instance($cm->id);
            if (has_capability('mod/assignmentques:preview', $context)) {
                throw new moodle_exception('attempterrorcontentchange', 'assignmentques', $continuelink);
            } else {
                throw new moodle_exception('attempterrorcontentchangeforuser', 'assignmentques', $continuelink);
            }
        } else {
            throw new moodle_exception('attempterrorinvalid', 'assignmentques');
        }
    }
    if (!empty($cmid) && $attempobj->get_cmid() != $cmid) {
        throw new moodle_exception('invalidcoursemodule');
    } else {
        return $attempobj;
    }
}
