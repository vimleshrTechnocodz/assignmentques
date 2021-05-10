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
 * The mod_assignmentques attempt reviewed event.
 *
 * @package    mod_assignmentques
 * @copyright  2014 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_assignmentques\event;

defined('MOODLE_INTERNAL') || die();

/**
 * The mod_assignmentques attempt reviewed event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int assignmentquesid: the id of the assignmentques.
 * }
 *
 * @package    mod_assignmentques
 * @since      Moodle 2.7
 * @copyright  2014 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_reviewed extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'assignmentques_attempts';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptreviewed', 'mod_assignmentques');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' has had their attempt with id '$this->objectid' reviewed by the user " .
            "with id '$this->relateduserid' for the assignmentques with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/assignmentques/review.php', array('attempt' => $this->objectid));
    }

    /**
     * Return the legacy event log data.
     *
     * @return array
     */
    protected function get_legacy_logdata() {
        return array($this->courseid, 'assignmentques', 'review', 'review.php?attempt=' . $this->objectid,
            $this->other['assignmentquesid'], $this->contextinstanceid);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->other['assignmentquesid'])) {
            throw new \coding_exception('The \'assignmentquesid\' value must be set in other.');
        }
    }

    public static function get_objectid_mapping() {
        return array('db' => 'assignmentques_attempts', 'restore' => 'assignmentques_attempt');
    }

    public static function get_other_mapping() {
        $othermapped = array();
        $othermapped['assignmentquesid'] = array('db' => 'assignmentques', 'restore' => 'assignmentques');

        return $othermapped;
    }
}
