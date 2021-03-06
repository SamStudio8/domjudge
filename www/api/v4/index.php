<?php declare(strict_types=1);
/**
 * DOMjudge public REST API
 *
 * Part of the DOMjudge Programming Contest Jury System and licenced
 * under the GNU GPL. See README and COPYING for details.
 */

if (!defined('DOMJUDGE_API_VERSION')) {
    define('DOMJUDGE_API_VERSION', 4);
}

require('init.php');
require_once(LIBWWWDIR . '/common.jury.php');
use DOMJudgeBundle\Utils\Utils;

global $api;
if (!isset($api)) {
    function checkargs($args, $mandatory)
    {
        global $api;

        foreach ($mandatory as $arg) {
            if (!isset($args[$arg])) {
                $api->createError("argument '$arg' is mandatory");
                return false;
            }
        }

        return true;
    }

    function safe_int($value)
    {
        return is_null($value) ? null : (int)$value;
    }

    function safe_float($value, $decimals = null)
    {
        if (is_null($value)) {
            return null;
        }
        if (is_null($decimals)) {
            return (float)$value;
        }

        // Truncate the string version to a specified number of decimals,
        // since PHP floats seem not very reliable in not giving e.g.
        // 1.9999 instead of 2.0.
        $decpos = strpos((string)$value, '.');
        if ($decpos===false) {
            return (float)$value;
        }
        return (float)substr((string)$value, 0, $decpos+$decimals+1);
    }

    function safe_bool($value)
    {
        return is_null($value) ? null : (bool)$value;
    }

    function safe_string($value)
    {
        return is_null($value) ? null : (string)$value;
    }

    $api = new RestApi();

    function judgings_PUT($args)
    {
        global $DB, $api;

        if (!isset($args['__primary_key'])) {
            $api->createError("judgingid is mandatory");
            return '';
        }
        if (count($args['__primary_key']) > 1) {
            $api->createError("only one judgingid is allowed");
            return '';
        }
        $judgingid = reset($args['__primary_key']);
        if (!isset($args['judgehost'])) {
            $api->createError("judgehost is mandatory");
            return '';
        }

        if (isset($args['output_compile'])) {
            if (isset($args['entry_point'])) {
                // We're updating the entry_point after submission time. This
                // probably does not work well when forwarding to another CCS.
                $subm = $DB->q('TUPLE SELECT s.cid, s.submitid
                                FROM judging j
                                LEFT JOIN submission s USING(submitid)
                                WHERE j.judgingid = %i', $judgingid);

                $DB->q('START TRANSACTION');
                $DB->q('UPDATE submission SET entry_point = %s
                        WHERE submitid = %i', $args['entry_point'], $subm['submitid']);

                $DB->q('COMMIT');
                // TODO: move this back to before the DB commit once it is moved to Symfony and uses Doctrine
                eventlog('submission', $subm['submitid'], 'update', $subm['cid']);
            }
            if ($args['compile_success']) {
                $DB->q('UPDATE judging SET output_compile = %s
                        WHERE judgingid = %i AND judgehost = %s',
                       base64_decode($args['output_compile']), $judgingid, $args['judgehost']);
            } else {
                $row = $DB->q('TUPLE SELECT s.cid, s.teamid, s.probid, s.langid, s.submitid, s.rejudgingid
                               FROM judging
                               LEFT JOIN submission s USING(submitid)
                               WHERE judgingid = %i', $judgingid);

                $DB->q('START TRANSACTION');
                $DB->q('UPDATE judging SET output_compile = %s,
                        result = "compiler-error", endtime = %f
                        WHERE judgingid = %i AND judgehost = %s',
                       base64_decode($args['output_compile']),
                       now(), $judgingid, $args['judgehost']);

                auditlog('judging', $judgingid, 'judged', 'compiler-error', $args['judgehost'], $row['cid']);

                $DB->q('COMMIT');

                // log to event table if no verification required
                // (case of verification required is handled in www/jury/verify.php)
                // TODO: move this back to before the DB commit once it is moved to Symfony and uses Doctrine
                if (! dbconfig_get('verification_required', 0) && !isset($row['rejudgingid'])) {
                    eventlog('judging', $judgingid, 'update', $row['cid']);
                }

                calcScoreRow((int)$row['cid'], (int)$row['teamid'], (int)$row['probid']);

                // We call alert here for the failed submission. Note that
                // this means that these alert messages should be treated
                // as confidential information.
                alert('reject', "submission $row[submitid], judging $judgingid: compiler-error");
            }
        }

        $DB->q('UPDATE judgehost SET polltime = %f WHERE hostname = %s',
               now(), $args['judgehost']);

        return '';
    }
    $doc = 'Update a judging.';
    $args = array('judgingid' => 'Judging corresponds to this specific judgingid.',
                  'judgehost' => 'Judging is judged by this specific judgehost.',
                  'compile_success' => 'Did the compilation succeed?',
                  'output_compile' => 'Ouput of compilation phase (base64 encoded).',
                  'entry_point' => 'Optional entry point auto-detected during compilation.');
    $exArgs = array();
    $roles = array('judgehost');
    $api->provideFunction('PUT', 'judgings', $doc, $args, $exArgs, $roles);

    /**
     * Judging_Runs
     */
    function judging_runs_POST($args)
    {
        global $DB, $api;

        if (!checkargs($args, array('judgingid', 'testcaseid', 'runresult', 'runtime',
                           'output_run', 'output_diff', 'output_error', 'output_system', 'judgehost'))) {
            return '';
        }

        $results_remap = dbconfig_get('results_remap');
        $results_prio = dbconfig_get('results_prio');

        if (array_key_exists($args['runresult'], $results_remap)) {
            logmsg(LOG_INFO, "Testcase $args[testcaseid] remapping result " . $args['runresult'] .
                         " -> " . $results_remap[$args['runresult']]);
            $args['runresult'] = $results_remap[$args['runresult']];
        }

        $jud = $DB->q('TUPLE SELECT judgingid, cid, result, rejudgingid
                       FROM judging
                       WHERE judgingid = %i', $args['judgingid']);

        $DB->q('START TRANSACTION');

        $runid = $DB->q('RETURNID INSERT INTO judging_run (judgingid, testcaseid, runresult,
                         runtime, endtime, output_run, output_diff, output_error, output_system)
                         VALUES (%i, %i, %s, %f, %f, %s, %s, %s, %s)',
                        $args['judgingid'], $args['testcaseid'], $args['runresult'],
                        $args['runtime'], now(),
                        base64_decode($args['output_run']),
                        base64_decode($args['output_diff']),
                        base64_decode($args['output_error']),
                        base64_decode($args['output_system']));

        $DB->q('COMMIT');

        // TODO: move this back to before the DB commit once it is moved to Symfony and uses Doctrine
        if (!isset($jud['rejudgingid'])) {
            eventlog('judging_run', $runid, 'create', $jud['cid']);
        }

        // result of this judging_run has been stored. now check whether
        // we're done or if more testcases need to be judged.

        $probid = $DB->q('VALUE SELECT probid FROM testcase
                          WHERE testcaseid = %i', $args['testcaseid']);

        $runresults = $DB->q('COLUMN SELECT runresult
                              FROM judging_run LEFT JOIN testcase USING(testcaseid)
                              WHERE judgingid = %i ORDER BY rank', $args['judgingid']);
        $numtestcases = $DB->q('VALUE SELECT count(*) FROM testcase WHERE probid = %i', $probid);

        $allresults = array_pad($runresults, (int)$numtestcases, null);

        if (($result = getFinalResult($allresults, $results_prio))!==null) {

        // Lookup global lazy evaluation of results setting and
            // possible problem specific override.
            $lazy_eval = dbconfig_get('lazy_eval_results', true);
            $prob_lazy = $DB->q('MAYBEVALUE SELECT cp.lazy_eval_results
                                 FROM judging j
                                 LEFT JOIN submission s USING(submitid)
                                 LEFT JOIN contestproblem cp ON (cp.cid=j.cid AND cp.probid=s.probid)
                                 WHERE judgingid = %i', $args['judgingid']);
            if (isset($prob_lazy)) {
                $lazy_eval = (bool)$prob_lazy;
            }

            if (count($runresults) == $numtestcases || $lazy_eval) {
                // NOTE: setting endtime here determines in testcases_GET
                // whether a next testcase will be handed out.
                $DB->q('UPDATE judging SET result = %s, endtime = %f
                        WHERE judgingid = %i', $result, now(), $args['judgingid']);
            } else {
                $DB->q('UPDATE judging SET result = %s
                        WHERE judgingid = %i', $result, $args['judgingid']);
            }

            // Only update if the current result is different from what we
            // had before. This should only happen when the old result was
            // NULL.
            if ($jud['result'] !== $result) {
                if ($jud['result'] !== null) {
                    error('internal bug: the evaluated result changed during judging');
                }

                $row = $DB->q('TUPLE SELECT s.cid, s.teamid, s.probid, s.langid, s.submitid
                               FROM judging
                               LEFT JOIN submission s USING(submitid)
                               WHERE judgingid = %i', $args['judgingid']);
                calcScoreRow((int)$row['cid'], (int)$row['teamid'], (int)$row['probid']);

                // We call alert here before possible validation. Note
                // that this means that these alert messages should be
                // treated as confidential information.
                alert(
                    ($result==='correct' ? 'accept' : 'reject'),
                    "submission $row[submitid], judging $args[judgingid]: $result"
                );

                // log to event table if no verification required
                // (case of verification required is handled in www/jury/verify.php)
                if (! dbconfig_get('verification_required', 0)) {
                    if (!isset($jud['rejudgingid'])) {
                        eventlog('judging', (int)$args['judgingid'], 'update', (int)$row['cid']);
                        updateBalloons((int)$row['submitid']);
                    }
                }

                auditlog('judging', (int)$args['judgingid'], 'judged', $result, $args['judgehost']);

                $just_finished = true;
            }
        }

        // Send an event for an endtime update if not done yet.
        if (!isset($jud['rejudgingid']) &&
            count($runresults) == $numtestcases && empty($just_finished)) {
            eventlog('judging', $args['judgingid'], 'update', $jud['cid']);
        }

        $DB->q('UPDATE judgehost SET polltime = %f WHERE hostname = %s',
               now(), $args['judgehost']);

        return '';
    }
    $doc = 'Add a new judging_run to the list of judging_runs. When relevant, finalize the judging.';
    $args = array('judgingid' => 'Judging_run corresponds to this specific judgingid.',
                  'testcaseid' => 'Judging_run corresponding to this specific testcaseid.',
                  'runresult' => 'Result of this run.',
                  'runtime' => 'Runtime of this run.',
                  'output_run' => 'Program output of this run (base64 encoded).',
                  'output_diff' => 'Program diff of this run (base64 encoded).',
                  'output_error' => 'Program error output of this run (base64 encoded).',
                  'output_system' => 'Judging system output of this run (base64 encoded).',
                  'judgehost' => 'Judgehost performing this judging');
    $exArgs = array();
    $roles = array('judgehost');
    $api->provideFunction('POST', 'judging_runs', $doc, $args, $exArgs, $roles);

    /**
     * POST a new submission
     */
    function submissions_POST($args)
    {
        global $userdata, $DB, $api;
        if (!checkargs($args, array('shortname','langid'))) {
            return '';
        }
        if (!checkargs($userdata, array('teamid'))) {
            return '';
        }
        $contests = getCurContests(true, $userdata['teamid'], false, 'shortname');
        $contest_shortname = null;

        if (isset($args['contest'])) {
            if (isset($contests[$args['contest']])) {
                $contest_shortname = $args['contest'];
            } else {
                $api->createError("Cannot find active contest '$args[contest]', or you are not part of it.");
                return '';
            }
        } else {
            if (count($contests) == 1) {
                $contest_shortname = key($contests);
            } else {
                $api->createError("No contest specified while multiple active contests found.");
                return '';
            }
        }
        $cid = $contests[$contest_shortname]['cid'];

        $probid = $DB->q('MAYBEVALUE SELECT probid FROM problem
                          INNER JOIN contestproblem USING (probid)
                          WHERE shortname = %s AND cid = %i AND allow_submit = 1',
                         $args['shortname'], $cid);
        if (empty($probid)) {
            error("Problem " . $args['shortname'] . " not found or or not submittable");
        }

        // rebuild array of filenames, paths to get rid of empty upload fields
        $FILEPATHS = $FILENAMES = array();
        foreach ($_FILES['code']['tmp_name'] as $fileid => $tmpname) {
            if (!empty($tmpname)) {
                checkFileUpload($_FILES['code']['error'][$fileid]);
                $FILEPATHS[] = $_FILES['code']['tmp_name'][$fileid];
                $FILENAMES[] = $_FILES['code']['name'][$fileid];
            }
        }

        $lang = $DB->q('MAYBETUPLE SELECT langid, name, require_entry_point, entry_point_description
                        FROM language
                        WHERE langid = %s AND allow_submit = 1', $args['langid']);

        if (! isset($lang)) {
            error("Unable to find language '$args[langid]' or not submittable");
        }
        $langid = $lang['langid'];

        $entry_point = null;
        if ($lang['require_entry_point']) {
            if (empty($args['entry_point'])) {
                $ep_desc = ($lang['entry_point_description'] ? : 'Entry point');
                error("$ep_desc required, but not specified.");
            }
            $entry_point = $args['entry_point'];
        }

        $sid = submit_solution((int)$userdata['teamid'], (int)$probid, (int)$cid, $langid, $FILEPATHS, $FILENAMES, null, $entry_point);

        auditlog('submission', $sid, 'added', 'via api', null, $cid);

        return safe_int($sid);
    }

    $args = array(
        'code[]' => 'Array of source files to submit',
        'shortname' => 'Problem shortname',
        'langid' => 'Language ID',
        'contest' => 'Contest short name. Required if more than one contest is active',
        'entry_point' => 'Optional entry point, e.g. Java main class.',
    );
    $doc = 'Post a new submission. You need to be authenticated with a team role. Returns the submission id. This is used by the submit client.

A trivial command line submisson using the curl binary could look like this:

curl -n -F "shortname=hello" -F "langid=c" -F "cid=2" -F "code[]=@test1.c" -F "code[]=@test2.c"  http://localhost/domjudge/api/submissions';
    $exArgs = array();
    $roles = array('team');
    $api->provideFunction('POST', 'submissions', $doc, $args, $exArgs, $roles);
}

// Now provide the api, which will handle the request
$api->provideApi(true);
