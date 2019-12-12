<?php

namespace nzedb\processing\post;

use app\models\Settings;
use nzedb\Category;
use nzedb\NZB;
use nzedb\db\DB;

class AniDB
{
	const PROC_EXTFAIL = -1; // Release Anime title/episode # could not be extracted from searchname
	const PROC_NOMATCH = -2; // AniDB ID was not found in anidb table using extracted title/episode #

	const REGEX_NOFORN = 'English|Japanese|German|Danish|Flemish|Dutch|French|Swe(dish|sub)|Deutsch|Norwegian';

	/**
	 * @var bool Whether or not to echo messages to CLI
	 */
	public $echooutput;

	/**
	 * @var \nzedb\db\populate\AniDB
	 */
	public $padb;

	/**
	 * @var \nzedb\db\Settings
	 */
	public $pdo;

	/**
	 * @var int number of AniDB releases to process
	 */
	private $aniqty;

	/**
	 * @var int|string The status of the release being processed
	 */
	private $status;

	/**
	 * @param array $options Class instances / Echo to cli.
	 */
	public function __construct(array $options = array())
	{
		$defaults = [
			'Echo'     => false,
			'Settings' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && nZEDb_ECHOCLI);
		$this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());

		$qty = Settings::value('..maxanidbprocessed');
		$this->aniqty = !empty($qty) ? (int)$qty : 100;

		$this->status = 'NULL';
	}

	/**
	 * Queues anime releases for processing
	 */
	public function processAnimeReleases()
	{
		$results = $this->pdo->queryDirect(
			sprintf('
				SELECT searchname, id
				FROM releases
				WHERE nzbstatus = %d
				AND anidbid IS NULL
				AND categories_id = %d
				ORDER BY postdate DESC
				LIMIT %d',
				NZB::NZB_ADDED,
				Category::TV_ANIME,
				$this->aniqty
			)
		);

		if ($results instanceof \Traversable) {

			$this->doRandomSleep();

			$this->padb = new \nzedb\db\populate\AniDB(
				[
					'Echo'     => $this->echooutput,
					'Settings' => $this->pdo
				]
			);

			foreach ($results as $release) {
				$matched = $this->matchAnimeRelease($release);
				if ($matched === false) {
					$this->pdo->queryExec(
						sprintf('
							UPDATE releases
							SET anidbid = %d
							WHERE id = %d',
							$this->status,
							$release['id']
						)
					);
				}
			}
		} else {
			$this->pdo->log::out('No work to process.', 'info', true);
		}
	}

	/**
	 * Selects episode info for a local match
	 *
	 * @param int $anidbId
	 * @param int $episode
	 *
	 * @return array|bool
	 */
	private function checkAniDBInfo($anidbId, $episode = -1)
	{
		return $this->pdo->queryOneRow(
			sprintf('
				SELECT ae.anidbid, ae.episode_no,
					ae.airdate, ae.episode_title
				FROM anidb_episodes ae
				WHERE ae.anidbid = %d
				AND ae.episode_no = %d',
				$anidbId,
				$episode
			)
		);
	}

	/**
	 * Sleeps between 10 and 15 seconds for AniDB API cooldown
	 */
	private function doRandomSleep()
	{
		sleep(rand(10, 15));
	}

	/**
	 * Extracts anime title and episode info from release searchname
	 *
	 * @param string $cleanName
	 *
	 * @return array
	 */
	private function extractTitleEpisode(string $cleanName = '') : array
	{
		$cleanName = str_replace('_', ' ', $cleanName);

		if (preg_match('/(^|.*\")(\[[a-zA-Z\.\!?-]+\][\s_]*)?(\[BD\][\s_]*)?(\[\d{3,4}[ip]\][\s_]*)?(?P<title>[\w\s_.+!?\'-\(\)]+)(New Edit|(Blu-?ray)?( ?Box)?( ?Set)?)?([ _]-[ _]|([ ._-]Epi?(sode)?[ ._-]?0?)?[ ._-]?|[ ._-]Vol\.|[ ._-]E)(?P<epno>\d{1,3}|Movie|OVA|Complete Series)(v\d|-\d+)?[-_. ].*[\[\(\"]/i',
				$cleanName,
				$matches)
		) {
			$matches['epno'] = (int)$matches['epno'];
			if (\in_array($matches['epno'], ['Movie', 'OVA'])) {
				$matches['epno'] = 1;
			}
		} else if (preg_match('/^(\[[a-zA-Z\.\-!?]+\][\s_]*)?(\[BD\])?(\[\d{3,4}[ip]\])?(?P<title>[\w\s_.+!?\'-\(\)]+)(New Edit|(Blu-?ray)?( ?Box)?( ?Set)?)?\s*[\(\[](BD|\d{3,4}[ipx])/i',
			$cleanName,
			$matches)
		) {
			$matches['epno'] = 1;
		} else if (\preg_match('#^(\[[a-zA-Z\.\-!?]+\][\s_]*)?(?P<title>[\w -]+)?\s+-\s+(?P<epno>\d+)\s*(\[\d+p\])?$#', $cleanName, $matches)) {
			$matches['epno'] = (int)$matches['epno'];
		} else {
			if (nZEDb_DEBUG) {
				$this->pdo->log::out("\nCould not parse searchname '{$cleanName}'.", null, true);
			}
			$this->status = self::PROC_EXTFAIL;
		}

		if (!empty($matches['title'])) {
			$matches['title'] = trim(str_replace(['_', '.'], ' ', $matches['title']));
		}

		return $matches;
	}

	/**
	 * Retrieves AniDB Info using a cleaned name
	 *
	 * @param string $searchName
	 *
	 * @return array|bool
	 */
	private function getAnidbByName($searchName = '')
	{
		return $this->pdo->queryOneRow(
			sprintf("
				SELECT at.anidbid, at.title
				FROM anidb_titles AS at
				WHERE at.title %s",
				$this->pdo->likeString($searchName, true, true)
			)
		);
	}

	/**
	 * Matches the anime release to AniDB Info.
	 * If no info is available locally the AniDB API is invoked.
	 *
	 * @param array $release
	 *
	 * @return bool
	 */
	private function matchAnimeRelease(array $release = []) : bool
	{
		$matched = false;
		$type    = 'Local';

		// clean up the release name to ensure we get a good chance at getting a valid title
		$cleanArr = $this->extractTitleEpisode($release['searchname']);

		if (\is_array($cleanArr) && isset($cleanArr['title']) && is_numeric($cleanArr['epno'])) {

			echo $this->pdo->log::header(PHP_EOL . 'Looking Up: ') .
				$this->pdo->log::primary("   Title: {$cleanArr['title']}" . PHP_EOL .
				"   Episode: {$cleanArr['epno']}");

			// get anidb number for the title of the name
			$anidbId = $this->getAnidbByName($cleanArr['title']);

			if ($anidbId === false) {
				$tmpName = preg_replace('/\s/', '%', $cleanArr['title']);
				$anidbId = $this->getAnidbByName($tmpName);
			}

			if (!empty($anidbId) && is_numeric($anidbId['anidbid']) && $anidbId['anidbid'] > 0) {

				$updatedAni = $this->checkAniDBInfo($anidbId['anidbid'], $cleanArr['epno']);

				if ($updatedAni === false) {
					if ($this->updatedRecently($anidbId['anidbid']) === false) {
						$this->padb->populateTable('info', $anidbId['anidbid']);
						$this->doRandomSleep();
						$updatedAni = $this->checkAniDBInfo($anidbId['anidbid']);
						$type = 'Remote';
					} else {
						echo PHP_EOL .
							$this->pdo->log::info('This AniDB ID was not found to be accurate locally, but has been updated too recently to check AniDB.') .
							PHP_EOL;
					}
				}

				$this->updateRelease($anidbId['anidbid'], $release['id']);

				$this->pdo->log::out(
					$this->pdo->log::header("Matched {$type} AniDB ID: ", false) .
					$this->pdo->log::primary($anidbId['anidbid']) .
					$this->pdo->log::alternate('   Title: ', false) .
					$this->pdo->log::primary($anidbId['title']) .
					$this->pdo->log::alternate('   Episode: ', false) .
					$this->pdo->log::primary($cleanArr['epno']) .
					$this->pdo->log::alternate('   Episode Title: ', false) .
					$this->pdo->log::primary($updatedAni['episode_title'])
				);

				$matched = true;
			} else {
				if (nZEDb_DEBUG) {
					$this->pdo->log::out("\nCould not match searchname: {$release['searchname']}.", null, true);
				}
				$this->status = self::PROC_NOMATCH;
			}
		}

		return $matched;
	}

	/**
	 * @param $anidbId
	 * @param $relId
	 */
	private function updateRelease($anidbId, $relId)
	{
		$this->pdo->queryExec(
			sprintf("
				UPDATE releases
				SET anidbid = %d
				WHERE id = %d",
				$anidbId,
				$relId
			)
		);
	}

	/**
	 * Checks a specific Anime ID's last update time, returning true if it was updated in the
	 * last week
	 *
	 * @param int    $anidbId
	 *
	 * @return bool  Has it been 7 days, or more, since we last updated this AniDB ID or not?
	 */
	private function updatedRecently($anidbId) : bool
	{
		$result = $this->pdo->queryOneRow(
			sprintf('	SELECT ai.updated FROM anidb_info AS ai WHERE ai.anidbid = %d LIMIT 1',
				$anidbId)
		);

		if (\is_array($result)) {
			$result = \strtotime($result['updated']) > \strtotime('1 week ago');
		}

		return (bool)$result;
	}
}
