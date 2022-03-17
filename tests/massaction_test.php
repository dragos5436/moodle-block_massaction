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

namespace block_massaction;

use advanced_testcase;
use block_massaction;
use dml_exception;

/**
 * block_massaction phpunit test class.
 *
 * @package    block_massaction
 * @copyright  2021 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class massaction_test extends advanced_testcase {
    /**
     * Prepare testing.
     */
    public function setUp(): void {
        $generator = $this->getDataGenerator();
        $this->setAdminUser();
        $this->resetAfterTest();

        $teacher = $generator->create_user();
        $this->course = $generator->create_course(['numsections' => 5]);
        $generator->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        // Generate two modules of each type for each of the 5 sections, so we have 6 modules per section.
        for ($i = 0; $i < 10; $i++) {
            $generator->create_module('assign', ['course' => $this->course->id], ['section' => floor($i / 2)]);
            $generator->create_module('label', ['course' => $this->course->id], ['section' => floor($i / 2)]);
            $generator->create_module('page', ['course' => $this->course->id], ['section' => floor($i / 2)]);
        }

        $this->setUser($teacher);
    }

    public function test_extract_modules_from_json(): void {
        // Negative tests.
        $this->expectException(\moodle_exception::class);
        block_massaction\massactionutils::extract_modules_from_json('{}');
        $this->expectException(\moodle_exception::class);
        block_massaction\massactionutils::extract_modules_from_json('');
        $this->expectException(\moodle_exception::class);
        block_massaction\massactionutils::extract_modules_from_json('{[]}');

        // Positive tests.
        $modulerecords = $this->get_test_course_modules();
        $selectedmodules = array_splice($modulerecords, 1, 3);

        $func = function(object $modulerecords): int {
            return $modulerecords->id;
        };
        $selectedmodules = array_map($func, $selectedmodules);

        $jsonstring = '{"action":"moveleft","moduleIds":[';
        foreach ($selectedmodules as $module) {
            $jsonstring .= '"' . $module . '",';
        }
        $jsonstring = substr($jsonstring, 0, -1);
        $jsonstring .= ']}';
        $data = block_massaction\massactionutils::extract_modules_from_json($jsonstring);
        foreach ($selectedmodules as $module) {
            $this->assertTrue(in_array($module, $data->moduleIds));
            $this->assertTrue(in_array($module, array_keys($data->modulerecords)));
        }
        foreach ($data->moduleIds as $modid) {
            $this->assertTrue(in_array($modid, $selectedmodules));
        }
        foreach (array_keys($data->modulerecords) as $modid) {
            $this->assertTrue(in_array($modid, $selectedmodules));
        }
    }

    public function test_get_mod_names(): void {
        $modulerecords = $this->get_test_course_modules();
        $modnames = \block_massaction\massactionutils::get_mod_names($this->course->id);

        // Check if there is a modname object ['modid' => MOD_ID, 'name' => MOD_NAME] for each of the course modules in the course.
        $func1 = function(object $modnameobject): int {
            return $modnameobject->modid;
        };
        $func2 = function(object $mod): int {
            return $mod->id;
        };
        $this->assertTrue(array_map($func1, $modnames) === array_values(array_map($func2, $modulerecords)));

        $modinfo = get_fast_modinfo($this->course->id);
        foreach ($modnames as $modnameobject) {
            // Check for each given course module if the returned object contains the correct module's name.
            $this->assertEquals($modinfo->get_cm($modnameobject->modid)->get_name(), $modnameobject->name);
        }
    }

    /**
     * Get all test course modules.
     *
     * @return array the database records of the modules of the test course
     * @throws dml_exception
     */
    private function get_test_course_modules(): array {
        global $DB;
        $modulerecords = $DB->get_records_select('course_modules', 'course = ?', [$this->course->id], 'id');
        return $modulerecords;
    }

    public function test_mass_delete_modules(): void {
        global $DB;
        $modulerecords = $this->get_test_course_modules();
        block_massaction\actions::perform_deletion($modulerecords);
        foreach ($modulerecords as $module) {
            // We delete asynchronously, so we have to only check if there aren't any modules without deletion in progress.
            $modulerecord = $DB->get_record_select('course_modules', 'id = ? AND deletioninprogress = 0', [$module->id]);
            $this->assertEquals(false, $modulerecord);
        }
    }

    public function test_mass_move_modules_to_new_section(): void {
        $targetsectionnum = 4;

        // Method should do nothing for empty modules array.
        // Throwing an exception would make this whole test fail, so this a 'valid' test.
        block_massaction\actions::perform_moveto([], $targetsectionnum);

        // Move modules around so that they are not in id order.
        $this->shuffle_modules();

        // Select some random course modules from different sections to be moved.
        $moduleidstomove[] = get_fast_modinfo($this->course->id)->get_sections()[1][0];
        $moduleidstomove[] = get_fast_modinfo($this->course->id)->get_sections()[2][1];
        $moduleidstomove[] = get_fast_modinfo($this->course->id)->get_sections()[3][2];

        $module = $this->get_test_course_modules();
        $modulestomove = array_filter($module, function($module) use ($moduleidstomove) {
            return in_array($module->id, $moduleidstomove);
        });

        block_massaction\actions::perform_moveto($modulestomove, $targetsectionnum);
        // If the move of the selected modules has been successful, all the moved course module ids should be listed in the
        // 'sequence' field of the target section entry in the course_sections table, in the correct order.
        $i = 0;
        foreach ($moduleidstomove as $movedmoduleid) {
            $this->assertEquals($movedmoduleid, get_fast_modinfo($this->course->id)->get_sections()[$targetsectionnum][6 + $i]);
            $i++;
        }
    }

    public function test_mass_hide_unhide_modules(): void {
        global $CFG;

        // Method should do nothing for empty modules array.
        // Throwing an exception would make this whole test fail, so this is a 'valid' test.
        block_massaction\actions::set_visibility([], 1);

        // Select some random course modules from different sections to be hidden.
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[1][0];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[2][1];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[3][2];

        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });

        // Assert the modules are visible before calling method.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
        }
        block_massaction\actions::set_visibility($selectedmodules, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be hidden.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->visible);
        }

        // Check, if hide them again will change nothing.
        block_massaction\actions::set_visibility($selectedmodules, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be hidden.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->visible);
        }

        // All modules are hidden now, make them visible again.
        block_massaction\actions::set_visibility($selectedmodules, true);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be visible again.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
        }

        // All modules are visible now, check if making them visible again will change nothing.
        block_massaction\actions::set_visibility($selectedmodules, true);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now still be visible.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
        }

        // Check if we can hide them, but make them available.
        // First of all enable stealthing.
        $CFG->allowstealth = 1;

        block_massaction\actions::set_visibility($selectedmodules, true, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now still be available, but hidden on course page.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
            $this->assertEquals(0, $module->visibleoncoursepage);
        }

        // Check if we can show them again.
        block_massaction\actions::set_visibility($selectedmodules, true);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be completely visible again.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
            $this->assertEquals(1, $module->visibleoncoursepage);
        }

        // Hide them and then make them only available.
        block_massaction\actions::set_visibility($selectedmodules, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be completely hidden.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->visible);
        }
        // Now make them only available, but not visible on course page.
        block_massaction\actions::set_visibility($selectedmodules, true, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // All selected modules should now be only available, but not visible.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
            $this->assertEquals(0, $module->visibleoncoursepage);
        }

        // Now let's see if we avoid making modules available, but not visible on course page if the config option is not set.
        $CFG->allowstealth = 0;
        // First make them visible again.
        block_massaction\actions::set_visibility($selectedmodules, true);
        // Now try to make them 'available, but not visible on course page'.
        block_massaction\actions::set_visibility($selectedmodules, true, false);
        // Reload modules from database.
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        // They still should be visible, also on course page.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->visible);
            $this->assertEquals(1, $module->visibleoncoursepage);
        }
    }

    public function test_mass_duplicate_modules(): void {
        // Call with empty values should do nothing.
        block_massaction\actions::duplicate([]);
        block_massaction\actions::duplicate([new \stdClass()]);

        // Move modules around so that they are not in id order.
        $this->shuffle_modules();

        // Select some random course modules from different sections to be duplicated.
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[1][0];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[1][1];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[3][0];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[3][2];

        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        block_massaction\actions::duplicate($selectedmodules);

        $modinfo = get_fast_modinfo($this->course->id);
        $sections = $modinfo->get_sections();
        $idsinsectionordered = $sections[1];
        $this->assertEquals($selectedmoduleids[0], $idsinsectionordered[0]);
        $this->assertEquals($selectedmoduleids[1], $idsinsectionordered[1]);
        // After the six already existing modules the duplicated modules should appear.
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[6])->name,
            $modinfo->get_cm($selectedmoduleids[0])->name . ' (copy)');
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[7])->name,
            $modinfo->get_cm($selectedmoduleids[1])->name . ' (copy)');

        // Same for the other modules in the other section.
        $idsinsectionordered = $sections[3];
        $this->assertEquals($selectedmoduleids[2], $idsinsectionordered[0]);
        $this->assertEquals($selectedmoduleids[3], $idsinsectionordered[2]);
        // After the six already existing modules the duplicated modules should appear.
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[6])->name,
            $modinfo->get_cm($selectedmoduleids[2])->name . ' (copy)');
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[7])->name,
            $modinfo->get_cm($selectedmoduleids[3])->name . ' (copy)');

        // Now test 'duplicate to section'. We still have not done anything to section 4, so we just use
        // section 4 as target section.
        block_massaction\actions::duplicate($selectedmodules, 4);
        // After the 6 already existing modules we now should find all the duplicated ones.
        $modinfo = get_fast_modinfo($this->course->id);
        $sections = $modinfo->get_sections();
        $idsinsectionordered = $sections[4];
        // After the six already existing modules the duplicated modules should appear.
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[6])->name,
            $modinfo->get_cm($selectedmoduleids[0])->name . ' (copy)');
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[7])->name,
            $modinfo->get_cm($selectedmoduleids[1])->name . ' (copy)');
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[8])->name,
            $modinfo->get_cm($selectedmoduleids[2])->name . ' (copy)');
        $this->assertEquals($modinfo->get_cm($idsinsectionordered[9])->name,
            $modinfo->get_cm($selectedmoduleids[3])->name . ' (copy)');
    }

    public function test_mass_adjust_indentation(): void {
        // Method should do nothing for empty modules array.
        // Throwing an exception would make this whole test fail, so this a 'valid' test.
        block_massaction\actions::adjust_indentation([], 1);

        // Select some random course modules from different sections to be hidden.
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[1][0];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[2][1];
        $selectedmoduleids[] = get_fast_modinfo($this->course->id)->get_sections()[3][2];

        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });

        // Assert the modules are not indented yet.
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }
        // Negative tests: Method should only work if parameter 'amount' equals '1' oder '-1'.
        // In all other cases method should do nothing.
        block_massaction\actions::adjust_indentation($selectedmodules, 0);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });

        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }
        block_massaction\actions::adjust_indentation($selectedmodules, -2);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }
        block_massaction\actions::adjust_indentation($selectedmodules, 2);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }

        // Now indent to the right.
        block_massaction\actions::adjust_indentation($selectedmodules, 1);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(1, $module->indent);
        }

        // We now indent another 15 times to check if we properly handle maximum amount of indenting to the right.
        for ($i = 0; $i < 15; $i++) {
            block_massaction\actions::adjust_indentation($selectedmodules, 1);
        }
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(16, $module->indent);
        }
        // Indenting another time to the right now should do nothing.
        block_massaction\actions::adjust_indentation($selectedmodules, 1);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(16, $module->indent);
        }

        // We now indent 16 times to the left to be back at 'no indentation'.
        for ($i = 0; $i < 16; $i++) {
            block_massaction\actions::adjust_indentation($selectedmodules, -1);
        }
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }
        // Indenting another time to the left now should do nothing.
        block_massaction\actions::adjust_indentation($selectedmodules, -1);
        $selectedmodules = array_filter($this->get_test_course_modules(), function($module) use ($selectedmoduleids) {
            return in_array($module->id, $selectedmoduleids);
        });
        foreach ($selectedmodules as $module) {
            $this->assertEquals(0, $module->indent);
        }
    }

    /**
     * Shuffle modules for proper testing environment.
     */
    public function shuffle_modules(): void {
        // First of all: Re-order modules of some sections randomly.
        // Reason: We want to see if the order in the section is preserved which usually is different from the module ids.
        // The method to be tested should follow the sections order. To be able to see the correct effect we have to ensure that
        // the order of moduleids isn't the same as the order in the section.
        moveto_module(get_fast_modinfo($this->course->id)->get_cm(get_fast_modinfo($this->course->id)->get_sections()[1][0]),
            get_fast_modinfo($this->course->id)->get_section_info(1));
        moveto_module(get_fast_modinfo($this->course->id)->get_cm(get_fast_modinfo($this->course->id)->get_sections()[1][3]),
            get_fast_modinfo($this->course->id)->get_section_info(1));
        moveto_module(get_fast_modinfo($this->course->id)->get_cm(get_fast_modinfo($this->course->id)->get_sections()[3][0]),
            get_fast_modinfo($this->course->id)->get_section_info(3));
        moveto_module(get_fast_modinfo($this->course->id)->get_cm(get_fast_modinfo($this->course->id)->get_sections()[3][3]),
            get_fast_modinfo($this->course->id)->get_section_info(3));
    }
}