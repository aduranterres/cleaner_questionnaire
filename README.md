# Questionnaire cleaner for Moodle Data Cleaner

`cleaner_questionnaire` is a cleaner plugin for
[Moodle Data Cleaner](https://github.com/catalyst/moodle-local_datacleaner). It
removes response data created by the Moodle
[Questionnaire activity](https://moodle.org/plugins/mod_questionnaire), while
retaining the activities and their configuration.

## What is removed

- Questionnaire response records, including answers of every supported type.
- Files uploaded as part of a response.
- Grades associated with graded Questionnaire activities.
- Completion state for activities whose automatic completion depends on
  submitting a response.

## What is retained

- Questionnaire activities and their settings.
- Questions, answer choices, and feedback definitions.
- Grade item definitions.
- Completion settings.

The cleaner detects response tables available in the installed Questionnaire
version. If Questionnaire is not installed, it skips the cleanup without
failing the wider Data Cleaner run.

## Requirements

- Moodle 5.0 or later.
- [Moodle Data Cleaner](https://github.com/catalyst/moodle-local_datacleaner).
- [Questionnaire](https://moodle.org/plugins/mod_questionnaire) for the cleaner
  to have data to process. This is a soft dependency: the cleaner can remain
  installed when Questionnaire is absent.

## Installation

Install this plugin using the Moodle admin interface.

Enable **Delete Questionnaire responses** on the Data Cleaner configuration
page:

```text
Site administration > Plugins > Local plugins > Data cleaner > Manage cleaning tasks
```

## Dry run

Run only this cleaner in dry-run mode from the Moodle root:

```bash
php local/datacleaner/cli/clean.php --dryrun --filter=questionnaire
```

The dry-run output reports:

- The number of records in each Questionnaire response table.
- The number of response file records.
- The number of Questionnaire grade records.
- The number of submission-based completion records to recalculate.

Dry-run mode does not modify the database or stored files.

After reviewing the output and confirming that Data Cleaner's safety checks
identify a non-production environment, run:

```bash
php local/datacleaner/cli/clean.php --run --filter=questionnaire
```

Run both commands as the operating-system user that owns or normally runs the
Moodle application.

## Privacy

This plugin does not store personal data of its own. It deletes data managed by
the Questionnaire activity when Data Cleaner invokes it.

## License

Copyright 2026 Antonio Duran Terres.

This plugin is heavily based on code originally developed by Catalyst IT.

This plugin is licensed under the GNU General Public License, version 3 or
later. See <https://www.gnu.org/licenses/gpl-3.0.html>.
