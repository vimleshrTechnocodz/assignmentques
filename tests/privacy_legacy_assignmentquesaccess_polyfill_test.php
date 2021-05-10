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
 * Unit tests for the privacy legacy polyfill for assignmentques access rules.
 *
 * @package     mod_assignmentques
 * @category    test
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assignmentques/attemptlib.php');

/**
 * Unit tests for the privacy legacy polyfill for assignmentques access rules.
 *
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_privacy_legacy_assignmentquesaccess_polyfill_test extends advanced_testcase {
    /**
     * Test that the core_assignmentquesaccess\privacy\legacy_polyfill works and that the static _export_assignmentquesaccess_user_data can
     * be called.
     */
    public function test_export_assignmentquesaccess_user_data() {
        $assignmentques = $this->createMock(assignmentques::class);
        $user = (object) [];
        $returnvalue = (object) [];

        $mock = $this->createMock(test_privacy_legacy_assignmentquesaccess_polyfill_mock_wrapper::class);
        $mock->expects($this->once())
            ->method('get_return_value')
            ->with('_export_assignmentquesaccess_user_data', [$assignmentques, $user])
            ->willReturn($returnvalue);

        test_privacy_legacy_assignmentquesaccess_polyfill_provider::$mock = $mock;
        $result = test_privacy_legacy_assignmentquesaccess_polyfill_provider::export_assignmentquesaccess_user_data($assignmentques, $user);
        $this->assertSame($returnvalue, $result);
    }

    /**
     * Test the _delete_assignmentquesaccess_for_context shim.
     */
    public function test_delete_assignmentquesaccess_for_context() {
        $context = context_system::instance();

        $assignmentques = $this->createMock(assignmentques::class);

        $mock = $this->createMock(test_privacy_legacy_assignmentquesaccess_polyfill_mock_wrapper::class);
        $mock->expects($this->once())
            ->method('get_return_value')
            ->with('_delete_assignmentquesaccess_data_for_all_users_in_context', [$assignmentques]);

        test_privacy_legacy_assignmentquesaccess_polyfill_provider::$mock = $mock;
        test_privacy_legacy_assignmentquesaccess_polyfill_provider::delete_assignmentquesaccess_data_for_all_users_in_context($assignmentques);
    }

    /**
     * Test the _delete_assignmentquesaccess_for_user shim.
     */
    public function test_delete_assignmentquesaccess_for_user() {
        $context = context_system::instance();

        $assignmentques = $this->createMock(assignmentques::class);
        $user = (object) [];

        $mock = $this->createMock(test_privacy_legacy_assignmentquesaccess_polyfill_mock_wrapper::class);
        $mock->expects($this->once())
            ->method('get_return_value')
            ->with('_delete_assignmentquesaccess_data_for_user', [$assignmentques, $user]);

        test_privacy_legacy_assignmentquesaccess_polyfill_provider::$mock = $mock;
        test_privacy_legacy_assignmentquesaccess_polyfill_provider::delete_assignmentquesaccess_data_for_user($assignmentques, $user);
    }

    /**
     * Test the _delete_assignmentquesaccess_for_users shim.
     */
    public function test_delete_assignmentquesaccess_for_users() {
        $context = $this->createMock(context_module::class);
        $user = (object) [];
        $approveduserlist = new \core_privacy\local\request\approved_userlist($context, 'mod_assignmentques', [$user]);

        $mock = $this->createMock(test_privacy_legacy_assignmentquesaccess_polyfill_mock_wrapper::class);
        $mock->expects($this->once())
            ->method('get_return_value')
            ->with('_delete_assignmentquesaccess_data_for_users', [$approveduserlist]);

        test_privacy_legacy_assignmentquesaccess_polyfill_provider::$mock = $mock;
        test_privacy_legacy_assignmentquesaccess_polyfill_provider::delete_assignmentquesaccess_data_for_users($approveduserlist);
    }
}

/**
 * Legacy polyfill test class for the assignmentquesaccess_provider.
 *
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_privacy_legacy_assignmentquesaccess_polyfill_provider implements
        \core_privacy\local\metadata\provider,
        \mod_assignmentques\privacy\assignmentquesaccess_provider,
        \mod_assignmentques\privacy\assignmentquesaccess_user_provider {

    use \mod_assignmentques\privacy\legacy_assignmentquesaccess_polyfill;
    use \core_privacy\local\legacy_polyfill;

    /**
     * @var test_privacy_legacy_assignmentquesaccess_polyfill_provider $mock.
     */
    public static $mock = null;

    /**
     * Export all user data for the assignmentquesaccess plugin.
     *
     * @param \assignmentques $assignmentques
     * @param \stdClass $user
     */
    protected static function _export_assignmentquesaccess_user_data($assignmentques, $user) {
        return static::$mock->get_return_value(__FUNCTION__, func_get_args());
    }

    /**
     * Deletes all user data for the given context.
     *
     * @param \assignmentques $assignmentques
     */
    protected static function _delete_assignmentquesaccess_data_for_all_users_in_context($assignmentques) {
        static::$mock->get_return_value(__FUNCTION__, func_get_args());
    }

    /**
     * Delete personal data for the given user and context.
     *
     * @param   \assignmentques           $assignmentques The assignmentques being deleted
     * @param   \stdClass       $user The user to export data for
     */
    protected static function _delete_assignmentquesaccess_data_for_user($assignmentques, $user) {
        static::$mock->get_return_value(__FUNCTION__, func_get_args());
    }

    /**
     * Delete all user data for the specified users, in the specified context.
     *
     * @param   \core_privacy\local\request\approved_userlist   $userlist
     */
    protected static function _delete_assignmentquesaccess_data_for_users($userlist) {
        static::$mock->get_return_value(__FUNCTION__, func_get_args());
    }

    /**
     * Returns metadata about this plugin.
     *
     * @param   \core_privacy\local\metadata\collection $collection The initialised collection to add items to.
     * @return  \core_privacy\local\metadata\collection     A listing of user data stored through this system.
     */
    protected static function _get_metadata(\core_privacy\local\metadata\collection $collection) {
        return $collection;
    }
}

/**
 * Called inside the polyfill methods in the test polyfill provider, allowing us to ensure these are called with correct params.
 *
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_privacy_legacy_assignmentquesaccess_polyfill_mock_wrapper {
    /**
     * Get the return value for the specified item.
     */
    public function get_return_value() {
    }
}
