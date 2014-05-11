<?php

namespace SMW\Tests;

use SMW\Tests\Util\MwDatabaseTableBuilder;
use SMW\StoreFactory;

use RuntimeException;

/**
 * @ingroup Test
 *
 * @group SMW
 * @group SMWExtension
 * @group semantic-mediawiki
 * @group mediawiki-database
 * @group medium
 *
 * @license GNU GPL v2+
 * @since 1.9.3
 *
 * @author mwjames
 */
abstract class MwDBaseUnitTestCase extends \PHPUnit_Framework_TestCase {

	/* @var MwDatabaseTableBuilder */
	protected $mwDatabaseTableBuilder = null;

	protected $destroyDatabaseTables = false;
	protected $isUsableUnitTestDatabase = true;
	protected $databaseToBeExcluded = null;

	/**
	 * It is assumed that each test that makes use of the TestCase is requesting
	 * a "real" DB connection
	 *
	 * By default, the database tables are being re-used but it is possible to
	 * request a trear down so that the next test can rebuild the tables from
	 * scratch
	 */
	public function run( \PHPUnit_Framework_TestResult $result = null ) {

		$this->mwDatabaseTableBuilder = MwDatabaseTableBuilder::getInstance( $this->getStore() );
		$this->mwDatabaseTableBuilder->removeAvailableDatabaseType( $this->databaseToBeExcluded );

		try {
			$this->mwDatabaseTableBuilder->doBuild();
		} catch ( RuntimeException $e ) {
			$this->isUsableUnitTestDatabase = false;
		}

		parent::run( $result );

		if ( $this->isUsableUnitTestDatabase && $this->destroyDatabaseTables ) {
			$this->mwDatabaseTableBuilder->doDestroy();
		}
	}

	protected function removeDatabaseTypeFromTest( $databaseToBeExcluded ) {
		$this->databaseToBeExcluded = $databaseToBeExcluded;
	}

	protected function destroyDatabaseTablesOnEachRun() {
		$this->destroyDatabaseTables = true;
	}

	protected function getStore() {
		return StoreFactory::getStore();
	}

	protected function getDBConnection() {
		return $this->mwDatabaseTableBuilder->getDBConnection();
	}

	protected function isUsableUnitTestDatabase() {
		return $this->isUsableUnitTestDatabase;
	}

}
