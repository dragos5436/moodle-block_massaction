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
 * Hook class for filtering a list of sections.
 *
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_massaction\hook;

use coding_exception;

defined('MOODLE_INTERNAL') || die();

#[\core\attribute\label('Hook dispatched whenever block_massaction is using a list of sections.')]
#[\core\attribute\tags('block_massaction')]
/**
 * Hook class for filtering a list of sections.
 *
 * @copyright  2024 ISB Bayern
 * @author     Philipp Memmel
 * @package    block_massaction
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_sections {

    /** @var array Array of section numbers which originally are available by block_massaction. */
    private readonly array $originalsectionnums;

    /** @var bool Determines if the user will be able to keep the original section of a course module when performing some operations. */
    private bool $keeporiginalsectionallowed;

    /** @var bool Determines if the user will be able to create a new section when performing some operations. */
    private bool $createnewsectionallowed;

    /** @var string Constant declaring that the sections to filter are the sections from the same course that contains the selected
     *   course modules.
     */
    public const SAMECOURSE = 'samecourse';

    /** @var string Constant declaring that the sections to filter are the sections from another course than the one that contains
     *   the selected course modules (for example duplicating course modules to another course).
     */
    public const ANOTHERCOURSE = 'anothercourse';

    /**
     * Creates the hook object.
     *
     * @param int $courseid the course id which is target for section select
     * @param array $sectionnums the section numbers which are available (so the available sections the hook listeners may filter)
     * @param string $targetcourse either {@see self::SAMECOURSE} or {@see self::ANOTHERCOURSE}, depending on the situation in which
     *  the hook is being called.
     */
    public function __construct(
            private readonly int $courseid,
            private array $sectionnums,
            private readonly string $targetcourse,
    ) {
        if (!in_array($this->targetcourse, [self::SAMECOURSE, self::ANOTHERCOURSE])) {
            throw new coding_exception('Parameter $targetcourse has to be one of the constants SAMECOURSE or ANOTHERCOURSE');
        }
        if ($this->targetcourse === self::ANOTHERCOURSE) {
            // If the target course is a different course than the one containing the course modules which an action is applied to
            // we initially enable the two options. They can be disabled by hook listeners afterwards.
            $this->keeporiginalsectionallowed = true;
            $this->createnewsectionallowed = true;
        }
        $this->originalsectionnums = $this->sectionnums;
    }

    /**
     * Getter for the available sections without any changes by any hook listener.
     *
     * @return array array of section numbers which are available by block_massaction
     */
    public function get_original_sectionnums(): array {
        return $this->originalsectionnums;
    }

    /**
     * Getter for the course id the section numbers are referring to.
     *
     * You can determine if this course id belongs to the same course
     *
     * @return int
     */
    public function get_courseid(): int {
        return $this->courseid;
    }

    /**
     * Returns one of the constants {@see self::SAMECOURSE} or {@see self::ANOTHERCOURSE}.
     *
     * Based on the constants the hook listener can determine if the hook has been called from a situation where the target course
     * id and the sections which are being filtered belong to the same course as the course modules or a different one (for example
     * when duplicating to another course).
     * @return string one of the constants {@see self::SAMECOURSE}, {@see self::ANOTHERCOURSE}
     */
    public function get_targetcourse(): string {
        return $this->targetcourse;
    }

    /**
     * Getter for the currently available section numbers.
     *
     * This will be evaluated by block_massaction to determine the available sections.
     *
     * @return array array of available section numbers (integers)
     */
    public function get_sectionnums(): array {
        return array_values($this->sectionnums);
    }

    /**
     * Remove a section number from the list of available/allowed section numbers.
     *
     * Does nothing if a section number is passed which is not contained in the list of currently available sections
     *
     * @param int $sectionnum The section number to remove from the list
     */
    public function remove_sectionnum(int $sectionnum): void {
        $index = array_search($sectionnum, $this->sectionnums);
        if ($index === false) {
            return;
        }
        unset($this->sectionnums[$index]);
    }

    /**
     * Disables the option to keep the original section of a course module.
     *
     * @throws coding_exception in case of the target course is not a different course, because this would make no sense
     */
    public function disable_keeporiginalsection(): void {
        if ($this->get_targetcourse() !== self::ANOTHERCOURSE) {
            throw new coding_exception('Disabling the option of keeping the original section is only possible when the target '
                    . 'course is different from the one that contains the course modules to apply an action to. Use '
                    . 'get_targetcourse() function to determine in which place the filter_sections hook has been called.');
        }
        $this->keeporiginalsectionallowed = false;
    }

    /**
     * Returns if the option to keep the course modules in the original section when duplicating or not.
     *
     * This information is only used in the case that the target course is different from the one that contains the course modules.
     *
     * @return bool if the user will be allowed to keep the original section of the course modules
     */
    public function is_keeporiginalsectionallowed(): bool {
        return $this->keeporiginalsectionallowed;
    }

    /**
     * Disables the option to create a new section.
     *
     * @throws coding_exception in case of the target course is not a different course, because this would make no sense
     */
    public function disable_createnewsection(): void {
        if ($this->get_targetcourse() !== self::ANOTHERCOURSE) {
            throw new coding_exception('Disabling the option of creating a new section is only possible when the target '
                    . 'course is different from the one that contains the course modules to apply an action to. Use '
                    . 'get_targetcourse() function to determine in which place the filter_sections hook has been called.');
        }
        $this->createnewsectionallowed = false;
    }

    /**
     * Returns if the option to create a new section is allowed or not.
     *
     * This information is only used in the case that the target course is different from the one that contains the course modules.
     *
     * @return bool if the user will be allowed to create a new section
     */
    public function is_createnewsectionallowed(): bool {
        return $this->createnewsectionallowed;
    }
}
