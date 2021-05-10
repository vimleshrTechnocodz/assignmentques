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
 * Privacy Subsystem implementation for assignmentques_responses.
 *
 * @package    assignmentques_responses
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignmentques_responses\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/questionattempt.php');

/**
 * Privacy Subsystem for assignmentques_responses with user preferences.
 *
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\user_preference_provider {

    /**
     * Returns meta data about this system.
     *
     * @param   collection     $collection The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection) : collection {
        $collection->add_user_preference('assignmentques_report_responses_qtext', 'privacy:preference:qtext');
        $collection->add_user_preference('assignmentques_report_responses_resp', 'privacy:preference:resp');
        $collection->add_user_preference('assignmentques_report_responses_right', 'privacy:preference:right');
        $collection->add_user_preference('assignmentques_report_responses_which_tries', 'privacy:preference:which_tries');

        return $collection;
    }

    /**
     * Export all user preferences for the plugin.
     *
     * @param   int         $userid The userid of the user whose data is to be exported.
     */
    public static function export_user_preferences(int $userid) {
        $preferences = [
                'qtext',
                'resp',
                'right',
            ];

        foreach ($preferences as $key) {
            $preference = get_user_preferences("assignmentques_report_responses_{$key}", null, $userid);
            if (null !== $preference) {
                $desc = get_string("privacy:preference:{$key}", 'assignmentques_responses');
                writer::export_user_preference('assignmentques_responses', $key, transform::yesno($preference), $desc);
            }
        }

        $preference = get_user_preferences("assignmentques_report_responses_which_tries", null, $userid);
        if (null !== $preference) {
            switch($preference) {
                case \question_attempt::FIRST_TRY:
                    $value = get_string("privacy:preference:which_tries:first", 'assignmentques_responses');
                    break;
                case \question_attempt::LAST_TRY:
                    $value = get_string("privacy:preference:which_tries:last", 'assignmentques_responses');
                    break;
                case \question_attempt::ALL_TRIES:
                    $value = get_string("privacy:preference:which_tries:all", 'assignmentques_responses');
                    break;
            }
            $desc = get_string("privacy:preference:which_tries", 'assignmentques_responses');

            writer::export_user_preference('assignmentques_responses', 'which_tries', $value, $desc);
        }
    }
}
