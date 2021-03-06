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
 * Unit tests for (some of) mod/assignmentques/locallib.php.
 *
 * @package    mod_assignmentques
 * @category   test
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/lib.php');

/**
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_assignmentques_lib_testcase extends advanced_testcase {
    public function test_assignmentques_has_grades() {
        $assignmentques = new stdClass();
        $assignmentques->grade = '100.0000';
        $assignmentques->sumgrades = '100.0000';
        $this->assertTrue(assignmentques_has_grades($assignmentques));
        $assignmentques->sumgrades = '0.0000';
        $this->assertFalse(assignmentques_has_grades($assignmentques));
        $assignmentques->grade = '0.0000';
        $this->assertFalse(assignmentques_has_grades($assignmentques));
        $assignmentques->sumgrades = '100.0000';
        $this->assertFalse(assignmentques_has_grades($assignmentques));
    }

    public function test_assignmentques_format_grade() {
        $assignmentques = new stdClass();
        $assignmentques->decimalpoints = 2;
        $this->assertEquals(assignmentques_format_grade($assignmentques, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(assignmentques_format_grade($assignmentques, 0), format_float(0, 2));
        $this->assertEquals(assignmentques_format_grade($assignmentques, 1.000000000000), format_float(1, 2));
        $assignmentques->decimalpoints = 0;
        $this->assertEquals(assignmentques_format_grade($assignmentques, 0.12345678), '0');
    }

    public function test_assignmentques_get_grade_format() {
        $assignmentques = new stdClass();
        $assignmentques->decimalpoints = 2;
        $this->assertEquals(assignmentques_get_grade_format($assignmentques), 2);
        $this->assertEquals($assignmentques->questiondecimalpoints, -1);
        $assignmentques->questiondecimalpoints = 2;
        $this->assertEquals(assignmentques_get_grade_format($assignmentques), 2);
        $assignmentques->decimalpoints = 3;
        $assignmentques->questiondecimalpoints = -1;
        $this->assertEquals(assignmentques_get_grade_format($assignmentques), 3);
        $assignmentques->questiondecimalpoints = 4;
        $this->assertEquals(assignmentques_get_grade_format($assignmentques), 4);
    }

    public function test_assignmentques_format_question_grade() {
        $assignmentques = new stdClass();
        $assignmentques->decimalpoints = 2;
        $assignmentques->questiondecimalpoints = 2;
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0), format_float(0, 2));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 1.000000000000), format_float(1, 2));
        $assignmentques->decimalpoints = 3;
        $assignmentques->questiondecimalpoints = -1;
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0.12345678), format_float(0.123, 3));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0), format_float(0, 3));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 1.000000000000), format_float(1, 3));
        $assignmentques->questiondecimalpoints = 4;
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0.12345678), format_float(0.1235, 4));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 0), format_float(0, 4));
        $this->assertEquals(assignmentques_format_question_grade($assignmentques, 1.000000000000), format_float(1, 4));
    }

    /**
     * Test deleting a assignmentques instance.
     */
    public function test_assignmentques_delete_instance() {
        global $SITE, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Setup a assignmentques with 1 standard and 1 random question.
        $assignmentquesgenerator = $this->getDataGenerator()->get_plugin_generator('mod_assignmentques');
        $assignmentques = $assignmentquesgenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $standardq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));

        assignmentques_add_assignmentques_question($standardq->id, $assignmentques);
        assignmentques_add_random_questions($assignmentques, 0, $cat->id, 1, false);

        // Get the random question.
        $randomq = $DB->get_record('question', array('qtype' => 'random'));

        assignmentques_delete_instance($assignmentques->id);

        // Check that the random question was deleted.
        $count = $DB->count_records('question', array('id' => $randomq->id));
        $this->assertEquals(0, $count);
        // Check that the standard question was not deleted.
        $count = $DB->count_records('question', array('id' => $standardq->id));
        $this->assertEquals(1, $count);

        // Check that all the slots were removed.
        $count = $DB->count_records('assignmentques_slots', array('assignmentquesid' => $assignmentques->id));
        $this->assertEquals(0, $count);

        // Check that the assignmentques was removed.
        $count = $DB->count_records('assignmentques', array('id' => $assignmentques->id));
        $this->assertEquals(0, $count);
    }

    /**
     * Test checking the completion state of a assignmentques.
     */
    public function test_assignmentques_get_completion_state() {
        global $CFG, $DB;
        $this->resetAfterTest(true);

        // Enable completion before creating modules, otherwise the completion data is not written in DB.
        $CFG->enablecompletion = true;

        // Create a course and student.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => true));
        $passstudent = $this->getDataGenerator()->create_user();
        $failstudent = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->assertNotEmpty($studentrole);

        // Enrol students.
        $this->assertTrue($this->getDataGenerator()->enrol_user($passstudent->id, $course->id, $studentrole->id));
        $this->assertTrue($this->getDataGenerator()->enrol_user($failstudent->id, $course->id, $studentrole->id));

        // Make a scale and an outcome.
        $scale = $this->getDataGenerator()->create_scale();
        $data = array('courseid' => $course->id,
                      'fullname' => 'Team work',
                      'shortname' => 'Team work',
                      'scaleid' => $scale->id);
        $outcome = $this->getDataGenerator()->create_grade_outcome($data);

        // Make a assignmentques with the outcome on.
        $assignmentquesgenerator = $this->getDataGenerator()->get_plugin_generator('mod_assignmentques');
        $data = array('course' => $course->id,
                      'outcome_'.$outcome->id => 1,
                      'grade' => 100.0,
                      'questionsperpage' => 0,
                      'sumgrades' => 1,
                      'completion' => COMPLETION_TRACKING_AUTOMATIC,
                      'completionusegrade' => 1,
                      'completionpass' => 1);
        $assignmentques = $assignmentquesgenerator->create_instance($data);
        $cm = get_coursemodule_from_id('assignmentques', $assignmentques->cmid);

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        assignmentques_add_assignmentques_question($question->id, $assignmentques);

        $assignmentquesobj = assignmentques::create($assignmentques->id, $passstudent->id);

        // Set grade to pass.
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                                        'itemmodule' => 'assignmentques', 'iteminstance' => $assignmentques->id, 'outcomeid' => null));
        $item->gradepass = 80;
        $item->update();

        // Start the passing attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, false, $passstudent->id);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $tosubmit = array(1 => array('answer' => '3.14'));
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        // Start the failing attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, false, $failstudent->id);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $tosubmit = array(1 => array('answer' => '0'));
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        // Check the results.
        $this->assertTrue(assignmentques_get_completion_state($course, $cm, $passstudent->id, 'return'));
        $this->assertFalse(assignmentques_get_completion_state($course, $cm, $failstudent->id, 'return'));
    }

    public function test_assignmentques_get_user_attempts() {
        global $DB;
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $assignmentquesgen = $dg->get_plugin_generator('mod_assignmentques');
        $course = $dg->create_course();
        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();
        $role = $DB->get_record('role', ['shortname' => 'student']);

        $dg->enrol_user($u1->id, $course->id, $role->id);
        $dg->enrol_user($u2->id, $course->id, $role->id);
        $dg->enrol_user($u3->id, $course->id, $role->id);
        $dg->enrol_user($u4->id, $course->id, $role->id);

        $assignmentques1 = $assignmentquesgen->create_instance(['course' => $course->id, 'sumgrades' => 2]);
        $assignmentques2 = $assignmentquesgen->create_instance(['course' => $course->id, 'sumgrades' => 2]);

        // Questions.
        $questgen = $dg->get_plugin_generator('core_question');
        $assignmentquescat = $questgen->create_question_category();
        $question = $questgen->create_question('numerical', null, ['category' => $assignmentquescat->id]);
        assignmentques_add_assignmentques_question($question->id, $assignmentques1);
        assignmentques_add_assignmentques_question($question->id, $assignmentques2);

        $assignmentquesobj1a = assignmentques::create($assignmentques1->id, $u1->id);
        $assignmentquesobj1b = assignmentques::create($assignmentques1->id, $u2->id);
        $assignmentquesobj1c = assignmentques::create($assignmentques1->id, $u3->id);
        $assignmentquesobj1d = assignmentques::create($assignmentques1->id, $u4->id);
        $assignmentquesobj2a = assignmentques::create($assignmentques2->id, $u1->id);

        // Set attempts.
        $quba1a = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj1a->get_context());
        $quba1a->set_preferred_behaviour($assignmentquesobj1a->get_assignmentques()->preferredbehaviour);
        $quba1b = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj1b->get_context());
        $quba1b->set_preferred_behaviour($assignmentquesobj1b->get_assignmentques()->preferredbehaviour);
        $quba1c = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj1c->get_context());
        $quba1c->set_preferred_behaviour($assignmentquesobj1c->get_assignmentques()->preferredbehaviour);
        $quba1d = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj1d->get_context());
        $quba1d->set_preferred_behaviour($assignmentquesobj1d->get_assignmentques()->preferredbehaviour);
        $quba2a = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj2a->get_context());
        $quba2a->set_preferred_behaviour($assignmentquesobj2a->get_assignmentques()->preferredbehaviour);

        $timenow = time();

        // User 1 passes assignmentques 1.
        $attempt = assignmentques_create_attempt($assignmentquesobj1a, 1, false, $timenow, false, $u1->id);
        assignmentques_start_new_attempt($assignmentquesobj1a, $quba1a, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj1a, $quba1a, $attempt);
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_submitted_actions($timenow, false, [1 => ['answer' => '3.14']]);
        $attemptobj->process_finish($timenow, false);

        // User 2 goes overdue in assignmentques 1.
        $attempt = assignmentques_create_attempt($assignmentquesobj1b, 1, false, $timenow, false, $u2->id);
        assignmentques_start_new_attempt($assignmentquesobj1b, $quba1b, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj1b, $quba1b, $attempt);
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_going_overdue($timenow, true);

        // User 3 does not finish assignmentques 1.
        $attempt = assignmentques_create_attempt($assignmentquesobj1c, 1, false, $timenow, false, $u3->id);
        assignmentques_start_new_attempt($assignmentquesobj1c, $quba1c, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj1c, $quba1c, $attempt);

        // User 4 abandons the assignmentques 1.
        $attempt = assignmentques_create_attempt($assignmentquesobj1d, 1, false, $timenow, false, $u4->id);
        assignmentques_start_new_attempt($assignmentquesobj1d, $quba1d, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj1d, $quba1d, $attempt);
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_abandon($timenow, true);

        // User 1 attempts the assignmentques three times (abandon, finish, in progress).
        $quba2a = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj2a->get_context());
        $quba2a->set_preferred_behaviour($assignmentquesobj2a->get_assignmentques()->preferredbehaviour);

        $attempt = assignmentques_create_attempt($assignmentquesobj2a, 1, false, $timenow, false, $u1->id);
        assignmentques_start_new_attempt($assignmentquesobj2a, $quba2a, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj2a, $quba2a, $attempt);
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_abandon($timenow, true);

        $quba2a = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj2a->get_context());
        $quba2a->set_preferred_behaviour($assignmentquesobj2a->get_assignmentques()->preferredbehaviour);

        $attempt = assignmentques_create_attempt($assignmentquesobj2a, 2, false, $timenow, false, $u1->id);
        assignmentques_start_new_attempt($assignmentquesobj2a, $quba2a, $attempt, 2, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj2a, $quba2a, $attempt);
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_finish($timenow, false);

        $quba2a = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj2a->get_context());
        $quba2a->set_preferred_behaviour($assignmentquesobj2a->get_assignmentques()->preferredbehaviour);

        $attempt = assignmentques_create_attempt($assignmentquesobj2a, 3, false, $timenow, false, $u1->id);
        assignmentques_start_new_attempt($assignmentquesobj2a, $quba2a, $attempt, 3, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj2a, $quba2a, $attempt);

        // Check for user 1.
        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u1->id, 'all');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u1->id, 'finished');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u1->id, 'unfinished');
        $this->assertCount(0, $attempts);

        // Check for user 2.
        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u2->id, 'all');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::OVERDUE, $attempt->state);
        $this->assertEquals($u2->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u2->id, 'finished');
        $this->assertCount(0, $attempts);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u2->id, 'unfinished');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::OVERDUE, $attempt->state);
        $this->assertEquals($u2->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        // Check for user 3.
        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u3->id, 'all');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::IN_PROGRESS, $attempt->state);
        $this->assertEquals($u3->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u3->id, 'finished');
        $this->assertCount(0, $attempts);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u3->id, 'unfinished');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::IN_PROGRESS, $attempt->state);
        $this->assertEquals($u3->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        // Check for user 4.
        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u4->id, 'all');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::ABANDONED, $attempt->state);
        $this->assertEquals($u4->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u4->id, 'finished');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::ABANDONED, $attempt->state);
        $this->assertEquals($u4->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques1->id, $u4->id, 'unfinished');
        $this->assertCount(0, $attempts);

        // Multiple attempts for user 1 in assignmentques 2.
        $attempts = assignmentques_get_user_attempts($assignmentques2->id, $u1->id, 'all');
        $this->assertCount(3, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::ABANDONED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::IN_PROGRESS, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);

        $attempts = assignmentques_get_user_attempts($assignmentques2->id, $u1->id, 'finished');
        $this->assertCount(2, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::ABANDONED, $attempt->state);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);

        $attempts = assignmentques_get_user_attempts($assignmentques2->id, $u1->id, 'unfinished');
        $this->assertCount(1, $attempts);
        $attempt = array_shift($attempts);

        // Multiple assignmentques attempts fetched at once.
        $attempts = assignmentques_get_user_attempts([$assignmentques1->id, $assignmentques2->id], $u1->id, 'all');
        $this->assertCount(4, $attempts);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques1->id, $attempt->assignmentques);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::ABANDONED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::FINISHED, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);
        $attempt = array_shift($attempts);
        $this->assertEquals(assignmentques_attempt::IN_PROGRESS, $attempt->state);
        $this->assertEquals($u1->id, $attempt->userid);
        $this->assertEquals($assignmentques2->id, $attempt->assignmentques);
    }

    /**
     * Test for assignmentques_get_group_override_priorities().
     */
    public function test_assignmentques_get_group_override_priorities() {
        global $DB;
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $assignmentquesgen = $dg->get_plugin_generator('mod_assignmentques');
        $course = $dg->create_course();

        $assignmentques = $assignmentquesgen->create_instance(['course' => $course->id, 'sumgrades' => 2]);

        $this->assertNull(assignmentques_get_group_override_priorities($assignmentques->id));

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));

        $now = 100;
        $override1 = (object)[
            'assignmentques' => $assignmentques->id,
            'groupid' => $group1->id,
            'timeopen' => $now,
            'timeclose' => $now + 20
        ];
        $DB->insert_record('assignmentques_overrides', $override1);

        $override2 = (object)[
            'assignmentques' => $assignmentques->id,
            'groupid' => $group2->id,
            'timeopen' => $now - 10,
            'timeclose' => $now + 10
        ];
        $DB->insert_record('assignmentques_overrides', $override2);

        $priorities = assignmentques_get_group_override_priorities($assignmentques->id);
        $this->assertNotEmpty($priorities);

        $openpriorities = $priorities['open'];
        // Override 2's time open has higher priority since it is sooner than override 1's.
        $this->assertEquals(2, $openpriorities[$override1->timeopen]);
        $this->assertEquals(1, $openpriorities[$override2->timeopen]);

        $closepriorities = $priorities['close'];
        // Override 1's time close has higher priority since it is later than override 2's.
        $this->assertEquals(1, $closepriorities[$override1->timeclose]);
        $this->assertEquals(2, $closepriorities[$override2->timeclose]);
    }

    public function test_assignmentques_core_calendar_provide_event_action_open() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student and enrol into the course.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeopen' => time() - DAYSECS, 'timeclose' => time() + DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);
        // Now, log in as student.
        $this->setUser($student);
        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('attemptassignmentquesnow', 'assignmentques'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_assignmentques_core_calendar_provide_event_action_open_for_user() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student and enrol into the course.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeopen' => time() - DAYSECS, 'timeclose' => time() + DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('attemptassignmentquesnow', 'assignmentques'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    public function test_assignmentques_core_calendar_provide_event_action_closed() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeclose' => time() - DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_CLOSE);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Confirm the result was null.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory));
    }

    public function test_assignmentques_core_calendar_provide_event_action_closed_for_user() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeclose' => time() - DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_CLOSE);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Confirm the result was null.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id));
    }

    public function test_assignmentques_core_calendar_provide_event_action_open_in_future() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student and enrol into the course.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeopen' => time() + DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_CLOSE);
        // Now, log in as student.
        $this->setUser($student);
        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('attemptassignmentquesnow', 'assignmentques'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertFalse($actionevent->is_actionable());
    }

    public function test_assignmentques_core_calendar_provide_event_action_open_in_future_for_user() {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student and enrol into the course.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'timeopen' => time() + DAYSECS));

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_CLOSE);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id);

        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('attemptassignmentquesnow', 'assignmentques'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertFalse($actionevent->is_actionable());
    }

    public function test_assignmentques_core_calendar_provide_event_action_no_capability() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));

        // Enrol student.
        $this->assertTrue($this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id));

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        // Remove the permission to attempt or review the assignmentques for the student role.
        $coursecontext = context_course::instance($course->id);
        assign_capability('mod/assignmentques:reviewmyattempts', CAP_PROHIBIT, $studentrole->id, $coursecontext);
        assign_capability('mod/assignmentques:attempt', CAP_PROHIBIT, $studentrole->id, $coursecontext);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Set current user to the student.
        $this->setUser($student);

        // Confirm null is returned.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory));
    }

    public function test_assignmentques_core_calendar_provide_event_action_no_capability_for_user() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));

        // Enrol student.
        $this->assertTrue($this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id));

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        // Remove the permission to attempt or review the assignmentques for the student role.
        $coursecontext = context_course::instance($course->id);
        assign_capability('mod/assignmentques:reviewmyattempts', CAP_PROHIBIT, $studentrole->id, $coursecontext);
        assign_capability('mod/assignmentques:attempt', CAP_PROHIBIT, $studentrole->id, $coursecontext);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Confirm null is returned.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id));
    }

    public function test_assignmentques_core_calendar_provide_event_action_already_finished() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));

        // Enrol student.
        $this->assertTrue($this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id));

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'sumgrades' => 1));

        // Add a question to the assignmentques.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        assignmentques_add_assignmentques_question($question->id, $assignmentques);

        // Get the assignmentques object.
        $assignmentquesobj = assignmentques::create($assignmentques->id, $student->id);

        // Create an attempt for the student in the assignmentques.
        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, false, $student->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

        // Finish the attempt.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_finish($timenow, false);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Set current user to the student.
        $this->setUser($student);

        // Confirm null is returned.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory));
    }

    public function test_assignmentques_core_calendar_provide_event_action_already_finished_for_user() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a student.
        $student = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));

        // Enrol student.
        $this->assertTrue($this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id));

        // Create a assignmentques.
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id,
            'sumgrades' => 1));

        // Add a question to the assignmentques.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        assignmentques_add_assignmentques_question($question->id, $assignmentques);

        // Get the assignmentques object.
        $assignmentquesobj = assignmentques::create($assignmentques->id, $student->id);

        // Create an attempt for the student in the assignmentques.
        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, false, $student->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

        // Finish the attempt.
        $attemptobj = assignmentques_attempt::create($attempt->id);
        $attemptobj->process_finish($timenow, false);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id, ASSIGNMENTQUES_EVENT_TYPE_OPEN);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Confirm null is returned.
        $this->assertNull(mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id));
    }

    public function test_assignmentques_core_calendar_provide_event_action_already_completed() {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Get some additional data.
        $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed.
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    public function test_assignmentques_core_calendar_provide_event_action_already_completed_for_user() {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $this->setAdminUser();

        // Create the activity.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id),
            array('completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS));

        // Enrol a student in the course.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Get some additional data.
        $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id);

        // Create a calendar event.
        $event = $this->create_action_event($course->id, $assignmentques->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED);

        // Mark the activity as completed for the student.
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm, $student->id);

        // Create an action factory.
        $factory = new \core_calendar\action_factory();

        // Decorate action event for the student.
        $actionevent = mod_assignmentques_core_calendar_provide_event_action($event, $factory, $student->id);

        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    /**
     * Creates an action event.
     *
     * @param int $courseid
     * @param int $instanceid The assignmentques id.
     * @param string $eventtype The event type. eg. ASSIGNMENTQUES_EVENT_TYPE_OPEN.
     * @return bool|calendar_event
     */
    private function create_action_event($courseid, $instanceid, $eventtype) {
        $event = new stdClass();
        $event->name = 'Calendar event';
        $event->modulename  = 'assignmentques';
        $event->courseid = $courseid;
        $event->instance = $instanceid;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = $eventtype;
        $event->timestart = time();

        return calendar_event::create($event);
    }

    /**
     * Test the callback responsible for returning the completion rule descriptions.
     * This function should work given either an instance of the module (cm_info), such as when checking the active rules,
     * or if passed a stdClass of similar structure, such as when checking the the default completion settings for a mod type.
     */
    public function test_mod_assignmentques_completion_get_active_rule_descriptions() {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two activities, both with automatic completion. One has the 'completionsubmit' rule, one doesn't.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 2]);
        $assignmentques1 = $this->getDataGenerator()->create_module('assignmentques', [
            'course' => $course->id,
            'completion' => 2,
            'completionusegrade' => 1,
            'completionattemptsexhausted' => 1,
            'completionpass' => 1
        ]);
        $assignmentques2 = $this->getDataGenerator()->create_module('assignmentques', [
            'course' => $course->id,
            'completion' => 2,
            'completionusegrade' => 0
        ]);
        $cm1 = cm_info::create(get_coursemodule_from_instance('assignmentques', $assignmentques1->id));
        $cm2 = cm_info::create(get_coursemodule_from_instance('assignmentques', $assignmentques2->id));

        // Data for the stdClass input type.
        // This type of input would occur when checking the default completion rules for an activity type, where we don't have
        // any access to cm_info, rather the input is a stdClass containing completion and customdata attributes, just like cm_info.
        $moddefaults = new stdClass();
        $moddefaults->customdata = ['customcompletionrules' => [
            'completionattemptsexhausted' => 1,
            'completionpass' => 1
        ]];
        $moddefaults->completion = 2;

        $activeruledescriptions = [
            get_string('completionattemptsexhausteddesc', 'assignmentques'),
            get_string('completionpassdesc', 'assignmentques'),
        ];
        $this->assertEquals(mod_assignmentques_get_completion_active_rule_descriptions($cm1), $activeruledescriptions);
        $this->assertEquals(mod_assignmentques_get_completion_active_rule_descriptions($cm2), []);
        $this->assertEquals(mod_assignmentques_get_completion_active_rule_descriptions($moddefaults), $activeruledescriptions);
        $this->assertEquals(mod_assignmentques_get_completion_active_rule_descriptions(new stdClass()), []);
    }

    /**
     * A user who does not have capabilities to add events to the calendar should be able to create a assignmentques.
     */
    public function test_creation_with_no_calendar_capabilities() {
        $this->resetAfterTest();
        $course = self::getDataGenerator()->create_course();
        $context = context_course::instance($course->id);
        $user = self::getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $roleid = self::getDataGenerator()->create_role();
        self::getDataGenerator()->role_assign($roleid, $user->id, $context->id);
        assign_capability('moodle/calendar:manageentries', CAP_PROHIBIT, $roleid, $context, true);
        $generator = self::getDataGenerator()->get_plugin_generator('mod_assignmentques');
        // Create an instance as a user without the calendar capabilities.
        $this->setUser($user);
        $time = time();
        $params = array(
            'course' => $course->id,
            'timeopen' => $time + 200,
            'timeclose' => $time + 2000,
        );
        $generator->create_instance($params);
    }
}
