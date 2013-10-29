<?php

namespace SMW\Test\SQLStore;

use SMW\SQLStore\WantedPropertiesCollector;
use SMW\StoreFactory;
use SMW\DIProperty;
use SMW\Settings;

use SMWRequestOptions;

use FakeResultWrapper;

/**
 * @covers \SMW\SQLStore\WantedPropertiesCollector
 *
 * @ingroup SQLStoreTest
 *
 * @group SMW
 * @group SMWExtension
 *
 * @licence GNU GPL v2+
 * @since 1.9
 *
 * @author mwjames
 */
class WantedPropertiesCollectorTest extends \SMW\Test\SemanticMediaWikiTestCase {

	/**
	 * @return string|false
	 */
	public function getClass() {
		return '\SMW\SQLStore\WantedPropertiesCollector';
	}

	/**
	 * @since 1.9
	 *
	 * @return Database
	 */
	private function getMockDBConnection( $smwTitle = 'Foo', $count = 1 ) {

		// Injection object expected as the DB fetchObject
		$result = array(
			'count'     => $count,
			'smw_title' => $smwTitle
		);

		// Database stub object to make the test independent from any real DB
		$connection = $this->getMock( 'DatabaseMysql' );

		// Override method with expected return objects
		$connection->expects( $this->any() )
			->method( 'select' )
			->will( $this->returnValue( new FakeResultWrapper( array( (object)$result ) ) ) );

		return $connection;
	}

	/**
	 * @since 1.9
	 *
	 * @return WantedPropertiesCollector
	 */
	private function newInstance( $property = 'Foo', $count = 1, $cacheEnabled = false ) {

		$store = StoreFactory::getStore( 'SMWSQLStore3' );
		$connection = $this->getMockDBConnection( $property, $count );

		$settings = $this->newSettings( array(
			'smwgPDefaultType'                => '_wpg',
			'smwgCacheType'                   => 'hash',
			'smwgWantedPropertiesCache'       => $cacheEnabled,
			'smwgWantedPropertiesCacheExpiry' => 360,
		) );

		return new WantedPropertiesCollector( $store, $connection, $settings );
	}

	/**
	 * @since 1.9
	 */
	public function testConstructor() {
		$this->assertInstanceOf( $this->getClass(), $this->newInstance() );
	}

	/**
	 * @since 1.9
	 */
	public function testGetResults() {

		$count = rand();
		$property = $this->getRandomString();
		$expected = array( array( new DIProperty( $property ), $count ) );

		$instance = $this->newInstance( $property, $count );
		$instance->setRequestOptions(
			new SMWRequestOptions( $property, SMWRequestOptions::STRCOND_PRE )
		);

		$this->assertEquals( $expected, $instance->getResults() );
		$this->assertEquals( 1, $instance->getCount() );

	}

	/**
	 * @dataProvider getCacheNonCacheDataProvider
	 *
	 * @since 1.9
	 */
	public function testCacheNoCache( array $test, array $expected, array $info ) {

		// Sample A
		$instance = $this->newInstance(
			$test['A']['property'],
			$test['A']['count'],
			$test['cacheEnabled']
		);

		$this->assertEquals( $expected['A'], $instance->getResults(), $info['msg'] );

		// Sample B
		$instance = $this->newInstance(
			$test['B']['property'],
			$test['B']['count'],
			$test['cacheEnabled']
		);

		$this->assertEquals( $expected['B'], $instance->getResults(), $info['msg'] );
		$this->assertEquals( $test['cacheEnabled'], $instance->isCached() );
	}

	/**
	 * @return array
	 */
	public function getCacheNonCacheDataProvider() {
		$propertyA = $this->getRandomString();
		$propertyB = $this->getRandomString();
		$countA = rand();
		$countB = rand();

		return array(
			array(

				// #0 Cached
				array(
					'cacheEnabled' => true,
					'A' => array( 'property' => $propertyA, 'count' => $countA ),
					'B' => array( 'property' => $propertyB, 'count' => $countB ),
				),
				array(
					'A' => array( array( new DIProperty( $propertyA ), $countA ) ),
					'B' => array( array( new DIProperty( $propertyA ), $countA ) )
				),
				array( 'msg' => 'Failed asserting that A & B are identical for a cached result' )
			),
			array(

				// #1 Non-cached
				array(
					'cacheEnabled' => false,
					'A' => array( 'property' => $propertyA, 'count' => $countA ),
					'B' => array( 'property' => $propertyB, 'count' => $countB )
				),
				array(
					'A' => array( array( new DIProperty( $propertyA ), $countA ) ),
					'B' => array( array( new DIProperty( $propertyB ), $countB ) )
				),
				array( 'msg' => 'Failed asserting that A & B are not identical for a non-cached result' )
			)
		);
	}
}
