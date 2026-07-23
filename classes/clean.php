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

namespace cleaner_questionnaire;

/**
 * Data cleaner for Questionnaire responses.
 *
 * Questionnaire definitions are retained. Submitted response data, response
 * files, grades, and submission-based completion state are removed.
 *
 * @package    cleaner_questionnaire
 * @copyright  2026 Antonio Duran Terres
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clean extends \local_datacleaner\clean {
    /**
     * Task description.
     */
    const TASK = 'Removing Questionnaire response data';

    /**
     * Questionnaire response tables in deletion order.
     *
     * The parent response table must remain last.
     */
    private const RESPONSE_TABLES = [
        'questionnaire_response_bool',
        'questionnaire_response_date',
        'questionnaire_resp_multiple',
        'questionnaire_response_other',
        'questionnaire_response_rank',
        'questionnaire_resp_single',
        'questionnaire_response_text',
        'questionnaire_response_file',
        'questionnaire_response',
    ];

    /**
     * Execute the cleaning process.
     */
    public static function execute() {
        global $CFG, $DB;

        if (!self::is_questionnaire_available()) {
            echo "Questionnaire is not installed; skipping Questionnaire response cleanup.\n";
            return;
        }

        $tables = self::get_existing_response_tables();
        $questionnaires = self::get_questionnaires();
        $filecontextids = self::get_response_file_context_ids();
        $gradedquestionnaires = array_filter($questionnaires, static function ($questionnaire) {
            return (float) $questionnaire->grade !== 0.0;
        });
        $completionquestionnaires = array_filter($questionnaires, static function ($questionnaire) {
            return !empty($questionnaire->completionsubmit)
                && (int) $questionnaire->completion === COMPLETION_TRACKING_AUTOMATIC;
        });

        if (!empty(self::$options['dryrun'])) {
            self::print_dry_run_summary($tables);
            return;
        }

        require_once($CFG->dirroot . '/mod/questionnaire/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $steps = count($tables)
            + count($filecontextids)
            + count($gradedquestionnaires)
            + count($completionquestionnaires);
        self::new_task($steps);

        // Remove child response rows before their parent records.
        foreach ($tables as $table) {
            $DB->delete_records($table);
            self::next_step();
        }

        // File areas are collected before response_file rows are removed.
        $filestorage = get_file_storage();
        foreach ($filecontextids as $contextid) {
            $filestorage->delete_area_files($contextid, 'mod_questionnaire', 'response_file');
            self::next_step();
        }

        // Reset grades through the module API while retaining grade-item definitions.
        foreach ($gradedquestionnaires as $questionnaire) {
            questionnaire_grade_item_update($questionnaire, 'reset');
            self::next_step();
        }

        // Recalculate only automatic completion that depends on submission.
        $courses = [];
        foreach ($completionquestionnaires as $questionnaire) {
            if (!isset($courses[$questionnaire->course])) {
                $courses[$questionnaire->course] = $DB->get_record(
                    'course',
                    ['id' => $questionnaire->course],
                    '*',
                    MUST_EXIST
                );
            }

            $cm = get_coursemodule_from_id(
                'questionnaire',
                $questionnaire->cmid,
                $questionnaire->course,
                false,
                MUST_EXIST
            );
            $completion = new \completion_info($courses[$questionnaire->course]);
            $completion->reset_all_state($cm);
            self::next_step();
        }
    }

    /**
     * Check whether Questionnaire and its main response table are available.
     *
     * This is deliberately a soft dependency so DataCleaner can still be
     * installed on sites that do not use Questionnaire.
     *
     * @return bool
     */
    private static function is_questionnaire_available(): bool {
        global $DB;

        if (\core_component::get_component_directory('mod_questionnaire') === null) {
            return false;
        }

        return $DB->get_manager()->table_exists('questionnaire_response');
    }

    /**
     * Return response tables present in the current Questionnaire version.
     *
     * @return string[]
     */
    private static function get_existing_response_tables(): array {
        global $DB;

        $dbmanager = $DB->get_manager();
        return array_values(array_filter(self::RESPONSE_TABLES, static function ($table) use ($dbmanager) {
            return $dbmanager->table_exists($table);
        }));
    }

    /**
     * Get Questionnaire instances with the fields needed by grade and completion APIs.
     *
     * @return \stdClass[]
     */
    private static function get_questionnaires(): array {
        global $DB;

        $sql = "SELECT q.*, q.course AS courseid, cm.id AS cmid,
                       cm.idnumber AS cmidnumber, cm.completion
                  FROM {questionnaire} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
              ORDER BY q.id";

        return $DB->get_records_sql($sql, ['modulename' => 'questionnaire']);
    }

    /**
     * Get contexts containing Questionnaire response uploads.
     *
     * @return int[]
     */
    private static function get_response_file_context_ids(): array {
        global $DB;

        $sql = "SELECT DISTINCT contextid
                  FROM {files}
                 WHERE component = :component
                       AND filearea = :filearea";
        $records = $DB->get_records_sql($sql, [
            'component' => 'mod_questionnaire',
            'filearea' => 'response_file',
        ]);

        return array_map('intval', array_keys($records));
    }

    /**
     * Print a non-mutating summary of data that would be removed.
     *
     * @param string[] $tables Existing response tables.
     */
    private static function print_dry_run_summary(array $tables): void {
        global $DB;

        $responsecounts = [];
        $totalresponses = 0;
        foreach ($tables as $table) {
            $responsecounts[$table] = $DB->count_records($table);
            $totalresponses += $responsecounts[$table];
        }

        echo "Would delete {$totalresponses} records from Questionnaire response tables:\n";
        foreach ($responsecounts as $table => $count) {
            echo " - {$table}: {$count}\n";
        }

        $filecount = $DB->count_records('files', [
            'component' => 'mod_questionnaire',
            'filearea' => 'response_file',
        ]);
        echo "Would delete {$filecount} Questionnaire response file-area records.\n";

        $gradecount = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.itemtype = :itemtype
                    AND gi.itemmodule = :itemmodule",
            ['itemtype' => 'mod', 'itemmodule' => 'questionnaire']
        );
        echo "Would reset {$gradecount} Questionnaire grade records.\n";

        $completioncount = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {modules} m ON m.id = cm.module
               JOIN {questionnaire} q ON q.id = cm.instance
              WHERE m.name = :modulename
                    AND q.completionsubmit = :completionsubmit
                    AND cm.completion = :completion",
            [
                'modulename' => 'questionnaire',
                'completionsubmit' => 1,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
            ]
        );
        echo "Would recalculate {$completioncount} submission-based Questionnaire completion records.\n";
        echo "Questionnaire definitions, questions, choices, and feedback would be retained.\n";
    }
}
