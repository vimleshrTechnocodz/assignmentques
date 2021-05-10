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
 * Assignmentques events tests.
 *
 * @package    mod_assignmentques
 * @category   phpunit
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/attemptlib.php');

/**
 * Unit tests for assignmentques events.
 *
 * @package    mod_assignmentques
 * @category   phpunit
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assignmentques_events_testcase extends advanced_testcase {

    /**
     * Setup a assignmentques.
     *
     * @return assignmentques the generated assignmentques.
     */
    protected function prepare_assignmentques() {

        $this->resetAfterTest(true);

        // Create a course
        $course = $this->getDataGenerator()->create_course();

        // Make a assignmentques.
        $assignmentquesgenerator = $this->getDataGenerator()->get_plugin_generator('mod_assignmentques');

        $assignmentques = $assignmentquesgenerator->create_instance(array('course' => $course->id, 'questionsperpage' => 0,
                'grade' => 100.0, 'sumgrades' => 2));

        $cm = get_coursemodule_from_instance('assignmentques', $assignmentques->id, $course->id);

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add them to the assignmentques.
        assignmentques_add_assignmentques_question($saq->id, $assignmentques);
        assignmentques_add_assignmentques_question($numq->id, $assignmentques);

        // Make a user to do the assignmentques.
        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);

        return assignmentques::create($assignmentques->id, $user1->id);
    }

    /**
     * Setup a assignmentques attempt at the assignmentques created by {@link prepare_assignmentques()}.
     *
     * @param assignmentques $assignmentquesobj the generated assignmentques.
     * @param bool $ispreview Make the attempt a preview attempt when true.
     * @return array with three elements, array($assignmentquesobj, $quba, $attempt)
     */
    protected function prepare_assignmentques_attempt($assignmentquesobj, $ispreview = false) {
        // Start the attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, $ispreview);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);

        return array($assignmentquesobj, $quba, $attempt);
    }

    /**
     * Setup some convenience test data with a single attempt.
     *
     * @param bool $ispreview Make the attempt a preview attempt when true.
     * @return array with three elements, array($assignmentquesobj, $quba, $attempt)
     */
    protected function prepare_assignmentques_data($ispreview = false) {
        $assignmentquesobj = $this->prepare_assignmentques();
        return $this->prepare_assignmentques_attempt($assignmentquesobj, $ispreview);
    }

    public function test_attempt_submitted() {

        list($assignmentquesobj, $quba, $attempt) = $this->prepare_assignmentques_data();
        $attemptobj = assignmentques_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();

        $timefinish = time();
        $attemptobj->process_finish($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        // Validate the event.
        $this->assertCount(3, $events);
        $event = $events[2];
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_submitted', $event);
        $this->assertEquals('assignmentques_attempts', $event->objecttable);
        $this->assertEquals($assignmentquesobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals(null, $event->other['submitterid']); // Should be the user, but PHP Unit complains...
        $this->assertEquals('assignmentques_attempt_submitted', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_assignmentques';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $assignmentquesobj->get_cmid();
        $legacydata->courseid = $assignmentquesobj->get_courseid();
        $legacydata->assignmentquesid = $assignmentquesobj->get_assignmentquesid();
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $legacydata->submitterid = null;
        $legacydata->timefinish = $timefinish;
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_becameoverdue() {

        list($assignmentquesobj, $quba, $attempt) = $this->prepare_assignmentques_data();
        $attemptobj = assignmentques_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_going_overdue($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_becameoverdue', $event);
        $this->assertEquals('assignmentques_attempts', $event->objecttable);
        $this->assertEquals($assignmentquesobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertNotEmpty($event->get_description());
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('assignmentques_attempt_overdue', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_assignmentques';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $assignmentquesobj->get_cmid();
        $legacydata->courseid = $assignmentquesobj->get_courseid();
        $legacydata->assignmentquesid = $assignmentquesobj->get_assignmentquesid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_abandoned() {

        list($assignmentquesobj, $quba, $attempt) = $this->prepare_assignmentques_data();
        $attemptobj = assignmentques_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_abandon($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_abandoned', $event);
        $this->assertEquals('assignmentques_attempts', $event->objecttable);
        $this->assertEquals($assignmentquesobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('assignmentques_attempt_abandoned', $event->get_legacy_eventname());
        $legacydata = new stdClass();
        $legacydata->component = 'mod_assignmentques';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $assignmentquesobj->get_cmid();
        $legacydata->courseid = $assignmentquesobj->get_courseid();
        $legacydata->assignmentquesid = $assignmentquesobj->get_assignmentquesid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_started() {
        $assignmentquesobj = $this->prepare_assignmentques();

        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_started', $event);
        $this->assertEquals('assignmentques_attempts', $event->objecttable);
        $this->assertEquals($attempt->id, $event->objectid);
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals($assignmentquesobj->get_context(), $event->get_context());
        $this->assertEquals('assignmentques_attempt_started', $event->get_legacy_eventname());
        $this->assertEquals(context_module::instance($assignmentquesobj->get_cmid()), $event->get_context());
        // Check legacy log data.
        $expected = array($assignmentquesobj->get_courseid(), 'assignmentques', 'attempt', 'review.php?attempt=' . $attempt->id,
            $assignmentquesobj->get_assignmentquesid(), $assignmentquesobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        // Check legacy event data.
        $legacydata = new stdClass();
        $legacydata->component = 'mod_assignmentques';
        $legacydata->attemptid = $attempt->id;
        $legacydata->timestart = $attempt->timestart;
        $legacydata->timestamp = $attempt->timestart;
        $legacydata->userid = $attempt->userid;
        $legacydata->assignmentquesid = $assignmentquesobj->get_assignmentquesid();
        $legacydata->cmid = $assignmentquesobj->get_cmid();
        $legacydata->courseid = $assignmentquesobj->get_courseid();
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the edit page viewed event.
     *
     * There is no external API for updating a assignmentques, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_edit_page_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'courseid' => $course->id,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\edit_page_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\edit_page_viewed', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'editquestions', 'view.php?id=' . $assignmentques->cmid, $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt deleted event.
     */
    public function test_attempt_deleted() {
        list($assignmentquesobj, $quba, $attempt) = $this->prepare_assignmentques_data();

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        assignmentques_delete_attempt($attempt, $assignmentquesobj->get_assignmentques());
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_deleted', $event);
        $this->assertEquals(context_module::instance($assignmentquesobj->get_cmid()), $event->get_context());
        $expected = array($assignmentquesobj->get_courseid(), 'assignmentques', 'delete attempt', 'report.php?id=' . $assignmentquesobj->get_cmid(),
            $attempt->id, $assignmentquesobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test that preview attempt deletions are not logged.
     */
    public function test_preview_attempt_deleted() {
        // Create assignmentques with preview attempt.
        list($assignmentquesobj, $quba, $previewattempt) = $this->prepare_assignmentques_data(true);

        // Delete a preview attempt, capturing events.
        $sink = $this->redirectEvents();
        assignmentques_delete_attempt($previewattempt, $assignmentquesobj->get_assignmentques());

        // Verify that no events were generated.
        $this->assertEmpty($sink->get_events());
    }

    /**
     * Test the report viewed event.
     *
     * There is no external API for viewing reports, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_report_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'context' => $context = context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id,
                'reportname' => 'overview'
            )
        );
        $event = \mod_assignmentques\event\report_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\report_viewed', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'report', 'report.php?id=' . $assignmentques->cmid . '&mode=overview',
            $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt reviewed event.
     *
     * There is no external API for reviewing attempts, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_reviewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\attempt_reviewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_reviewed', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'review', 'review.php?attempt=1', $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt summary viewed event.
     *
     * There is no external API for viewing the attempt summary, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_summary_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\attempt_summary_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_summary_viewed', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'view summary', 'summary.php?attempt=1', $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override created event.
     *
     * There is no external API for creating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\user_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\user_override_created', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override created event.
     *
     * There is no external API for creating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id,
                'groupid' => 2
            )
        );
        $event = \mod_assignmentques\event\group_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\group_override_created', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override updated event.
     *
     * There is no external API for updating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\user_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\user_override_updated', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'edit override', 'overrideedit.php?id=1', $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override updated event.
     *
     * There is no external API for updating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id,
                'groupid' => 2
            )
        );
        $event = \mod_assignmentques\event\group_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\group_override_updated', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'edit override', 'overrideedit.php?id=1', $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override deleted event.
     */
    public function test_user_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        // Create an override.
        $override = new stdClass();
        $override->assignmentques = $assignmentques->id;
        $override->userid = 2;
        $override->id = $DB->insert_record('assignmentques_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        assignmentques_delete_override($assignmentques, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\user_override_deleted', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'delete override', 'overrides.php?cmid=' . $assignmentques->cmid, $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override deleted event.
     */
    public function test_group_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        // Create an override.
        $override = new stdClass();
        $override->assignmentques = $assignmentques->id;
        $override->groupid = 2;
        $override->id = $DB->insert_record('assignmentques_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        assignmentques_delete_override($assignmentques, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\group_override_deleted', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'delete override', 'overrides.php?cmid=' . $assignmentques->cmid, $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt viewed event.
     *
     * There is no external API for continuing an attempt, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assignmentques = $this->getDataGenerator()->create_module('assignmentques', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => context_module::instance($assignmentques->cmid),
            'other' => array(
                'assignmentquesid' => $assignmentques->id
            )
        );
        $event = \mod_assignmentques\event\attempt_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_viewed', $event);
        $this->assertEquals(context_module::instance($assignmentques->cmid), $event->get_context());
        $expected = array($course->id, 'assignmentques', 'continue attempt', 'review.php?attempt=1', $assignmentques->id, $assignmentques->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt previewed event.
     */
    public function test_attempt_preview_started() {
        $assignmentquesobj = $this->prepare_assignmentques();

        $quba = question_engine::make_questions_usage_by_activity('mod_assignmentques', $assignmentquesobj->get_context());
        $quba->set_preferred_behaviour($assignmentquesobj->get_assignmentques()->preferredbehaviour);

        $timenow = time();
        $attempt = assignmentques_create_attempt($assignmentquesobj, 1, false, $timenow, true);
        assignmentques_start_new_attempt($assignmentquesobj, $quba, $attempt, 1, $timenow);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        assignmentques_attempt_save_started($assignmentquesobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\attempt_preview_started', $event);
        $this->assertEquals(context_module::instance($assignmentquesobj->get_cmid()), $event->get_context());
        $expected = array($assignmentquesobj->get_courseid(), 'assignmentques', 'preview', 'view.php?id=' . $assignmentquesobj->get_cmid(),
            $assignmentquesobj->get_assignmentquesid(), $assignmentquesobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the question manually graded event.
     *
     * There is no external API for manually grading a question, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_question_manually_graded() {
        list($assignmentquesobj, $quba, $attempt) = $this->prepare_assignmentques_data();

        $params = array(
            'objectid' => 1,
            'courseid' => $assignmentquesobj->get_courseid(),
            'context' => context_module::instance($assignmentquesobj->get_cmid()),
            'other' => array(
                'assignmentquesid' => $assignmentquesobj->get_assignmentquesid(),
                'attemptid' => 2,
                'slot' => 3
            )
        );
        $event = \mod_assignmentques\event\question_manually_graded::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_assignmentques\event\question_manually_graded', $event);
        $this->assertEquals(context_module::instance($assignmentquesobj->get_cmid()), $event->get_context());
        $expected = array($assignmentquesobj->get_courseid(), 'assignmentques', 'manualgrade', 'comment.php?attempt=2&slot=3',
            $assignmentquesobj->get_assignmentquesid(), $assignmentquesobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }
}
