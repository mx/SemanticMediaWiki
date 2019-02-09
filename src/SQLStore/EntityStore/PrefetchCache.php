<?php

namespace SMW\SQLStore\EntityStore;

use SMW\SQLStore\SQLStore;
use SMW\SQLStore\PropertyTableDefinition as TableDefinition;
use SMWDataItem as DataItem;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\RequestOptions;
use SMW\DataTypeRegistry;
use RuntimeException;
use SMW\MediaWiki\LinkBatch;

/**
 * @license GNU GPL v2
 * @since 3.1
 *
 * @author mwjames
 */
class PrefetchCache {

	/**
	 * @var SQLStore
	 */
	private $store;

	/**
	 * @var PrefetchItemLookup
	 */
	private $prefetchItemLookup;

	/**
	 * @var []
	 */
	private $cache = [];

	/**
	 * @since 3.1
	 *
	 * @param SQLStore $store
	 * @param PrefetchItemLookup $prefetchItemLookup
	 */
	public function __construct( SQLStore $store, PrefetchItemLookup $prefetchItemLookup ) {
		$this->store = $store;
		$this->prefetchItemLookup = $prefetchItemLookup;
	}

	/**
	 * @since 3.1
	 *
	 * @param DIProperty $property
	 *
	 * @return boolean
	 */
	public function isCached( DIProperty $property ) {
		return isset( $this->cache[$property->getKey()] );
	}

/*


{{#ask: [[PropChain::+]]
 |?PropChain
 |?PropChain.PropChain=PropChain|+order=desc
 |?PropChain.PropChain.PropChain=PropChain
 |?PropChain.-PropChain=-PropChain
 |?PropChain.-PropChain.Has number=Has number
 |?PropChain.-PropChain.Has subobject.Has number=Has number
 |format=broadtable
 |limit=50
 |offset=0
 |link=all
 |sort=
 |order=asc
 |headers=show
 |searchlabel=... further results
 |class=sortable wikitable smwtable
}}
 */

	/**
	 * @since 3.1
	 *
	 * @param DIProperty $property
	 * @param RequestOptions $requestOptions
	 */
	public static function makeCacheKey( DIProperty $property, RequestOptions $requestOptions ) {

		$key = $property->getKey();

		// Use the .dot notation to distingish it from other prrintouts that
		// use the same property
		if ( isset( $requestOptions->isChain ) && $requestOptions->isChain ) {
			$key .= $requestOptions->isChain;
		}

		return $key;
	}

	/**
	 * Prefetch related data into the cache in order for the `LookupCache::get`
	 * to return the individual data.
	 *
	 * @since 3.1
	 *
	 * @param DIWikiPage[] $subjects
	 * @param DIProperty $property
	 * @param RequestOptions $requestOptions
	 */
	public function prefetch( array $subjects, DIProperty $property, RequestOptions $requestOptions ) {

		$fingerprint = '';

		foreach ( $subjects as $subject ) {
			$fingerprint .= $subject->getHash();
		}

		$requestOptions->setOption( RequestOptions::PREFETCH_FINGERPRINT, md5( $fingerprint ) );

		$result = $this->prefetchItemLookup->getPropertyValues(
			$subjects,
			$property,
			$requestOptions
		);

		$key = $this->makeCacheKey( $property, $requestOptions );
		$this->cache[$key] = $result;
	}

	/**
	 * @since 3.1
	 *
	 * @param DIWikiPage $subject
	 * @param DIProperty $property
	 * @param RequestOptions $requestOptions
	 *
	 * @return []
	 */
	public function getPropertyValues( DIWikiPage $subject, DIProperty $property, RequestOptions $requestOptions ) {

		$prop = $property->getKey();

		if ( isset( $requestOptions->isChain ) && $requestOptions->isChain ) {
			$prop .= $requestOptions->isChain;
		}

		$sid = $this->store->getObjectIds()->getSMWPageID(
			$subject->getDBkey(),
			$subject->getNamespace(),
			$subject->getInterwiki(),
			$subject->getSubobjectName(),
			true
		);

		if ( !isset( $this->cache[$prop][$sid] ) ) {
			return [];
		}

		return array_values( $this->cache[$prop][$sid] );
	}

	private function prefetchPropertySubjects( $subjects, $property, $requestOptions ) {

		$noninverse = new DIProperty(
			$property->getKey(),
			false
		);

		$type = DataTypeRegistry::getInstance()->getDataItemByType(
			$noninverse->findPropertyTypeID()
		);

		$tableid = $this->store->findPropertyTableID( $noninverse );
		$idTable = $this->store->getObjectIds();

		if ( $tableid === '' ) {
			return [];
		}

		$proptables = $this->store->getPropertyTables();
		$ids = [];

		foreach ( $subjects as $s ) {
			$sid = $idTable->getSMWPageID(
				$s->getDBkey(),
				$s->getNamespace(),
				$s->getInterwiki(),
				$s->getSubobjectName(),
				true
			);

			if ( $type !== $s->getDIType() || $sid == 0 ) {
				continue;
			}

			$s->setId( $sid );
			$ids[] = $sid;
		}

		$result = $this->propertySubjectsLookup->prefetchFromTable(
			$ids,
			$property,
			$proptables[$tableid],
			$requestOptions
		);

		return $result;
	}

	private function prefetchSemanticData( $subjects, $property, $requestOptions ) {

		$tableid = $this->store->findPropertyTableID( $property );
		$proptables = $this->store->getPropertyTables();

		if ( $tableid === '' || !isset( $proptables[$tableid] ) ) {
			return [];
		}

		$propTable = $proptables[$tableid];
		$key = $this->makeCacheKey( $property, $requestOptions );

		// Doing a bulk request to eliminate DB requests to match the
		// values of a single subject by relying on a `... WHERE IN ...`
		$data = $this->semanticDataLookup->prefetchDataFromTable(
			$subjects,
			$property,
			$propTable,
			$requestOptions
		);

		$result = [];
		$list = [];

		if ( isset( $this->cache[$key] ) ) {
			$result = $this->cache[$key];
		}

		$diHandler = $this->store->getDataItemHandlerForDIType(
			$propTable->getDiType()
		);

		foreach ( $data as $sid => $dbkeys ) {

			// Store by related SID, the caller is responsible to reassign the
			// results to a corresponding output
			if ( !isset( $result[$sid] ) ) {
				$result[$sid] = [];
			}

			foreach ( $dbkeys as $k => $v ) {
				try {
					$dataItem = $diHandler->dataItemFromDBKeys( $v );
					$list[] = $dataItem;
					// Apply uniqueness
					$result[$sid][$dataItem->getHash()] = $dataItem;
					$this->linkBatch->add( $dataItem );
				} catch ( SMWDataItemException $e ) {
					// maybe type assignment changed since data was stored;
					// don't worry, but we can only drop the data here
				}
			}
		}

		// Give the collective list of subjects a chance to warm up the cache and eliminate
		// DB requests to find a matching ID for each individual entity item
		if ( $propTable->getDiType() === DataItem::TYPE_WIKIPAGE ) {
			$this->store->getObjectIds()->warmUpCache( $list );
		}

		return $result;
	}

}
