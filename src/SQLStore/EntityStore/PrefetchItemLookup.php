<?php

namespace SMW\SQLStore\EntityStore;

use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\RequestOptions;
use SMW\SQLStore\SQLStore;
use SMW\Store;
use SMW\DataTypeRegistry;
use SMWDataItem as DataItem;
use SMW\MediaWiki\LinkBatch;
use RuntimeException;

/**
 * Prefetch values for a list of known subjects to a specific property to avoid
 * using `Store::getPropertyValues` for each single subject request.
 *
 * @license GNU GPL v2+
 * @since 3.1
 *
 * @author mwjames
 */
class PrefetchItemLookup {

	/**
	 * @var Store
	 */
	private $store;

	/**
	 * @var SemanticDataLookup
	 */
	private $semanticDataLookup;

	/**
	 * @var PropertySubjectsLookup
	 */
	private $propertySubjectsLookup;

	/**
	 * @var LinkBatch
	 */
	private $linkBatch;

	/**
	 * @var boolean
	 */
	private $itemIndex = false;

	/**
	 * @since 3.1
	 *
	 * @param Store $store
	 * @param CachingSemanticDataLookup $semanticDataLookup
	 * @param PropertySubjectsLookup $propertySubjectsLookup
	 * @param LinkBatch|null $LinkBatch
	 */
	public function __construct( Store $store, CachingSemanticDataLookup $semanticDataLookup, PropertySubjectsLookup $propertySubjectsLookup, LinkBatch $linkBatch = null ) {
		$this->store = $store;
		$this->semanticDataLookup = $semanticDataLookup;
		$this->propertySubjectsLookup = $propertySubjectsLookup;

		// Help reduce the amount of queries by allowing to prefetch those
		// links we know will be used for the display
		if ( $this->linkBatch === null ) {
			$this->linkBatch = new LinkBatch();
		}
	}

	/**
	 * @since 3.1
	 *
	 * @param boolean $itemIndex
	 */
	public function asItemIndex( $itemIndex = true ) {
		$this->itemIndex = (bool)$itemIndex;
	}

	/**
	 * @since 3.1
	 *
	 * @param array $subjects
	 * @param DIProperty $property
	 * @param RequestOptions $requestOptions
	 *
	 * @return []
	 */
	public function getPropertyValues( array $subjects, DIProperty $property, RequestOptions $requestOptions ) {

		if ( $property->isInverse() ) {
			return $this->prefetchPropertySubjects( $subjects, $property, $requestOptions );
		}

		return $this->prefetchSemanticData( $subjects, $property, $requestOptions );
	}

	private function prefetchSemanticData( array $subjects, DIProperty $property, RequestOptions $requestOptions ) {

		$tableid = $this->store->findPropertyTableID( $property );
		$proptables = $this->store->getPropertyTables();

		if ( $tableid === '' || !isset( $proptables[$tableid] ) ) {
			return [];
		}

		$propTable = $proptables[$tableid];
		$result = [];
		$list = [];

		// In prefetch mode avoid restricting the result due to use of WHERE IN
		$requestOptions->exclude_limit = true;

		$data = $this->semanticDataLookup->prefetchDataFromTable(
			$subjects,
			$property,
			$propTable,
			$requestOptions
		);

		$diHandler = $this->store->getDataItemHandlerForDIType(
			$propTable->getDiType()
		);

		foreach ( $data as $sid => $dbkeys ) {

			$i = 0;

			if ( $this->itemIndex ) {
				$subject = $this->store->getObjectIds()->getDataItemById(
					$sid
				);

				// Subject hash is used as identifying hash to split
				// the collected set of values
				$hash = $subject->getHash();
			} else {
				// SID, the caller is responsible to reassign the
				// results to a corresponding output
				$hash = $sid;
			}

			if ( !isset( $result[$hash] ) ) {
				$result[$hash] = [];
			}

			foreach ( $dbkeys as $k => $v ) {

				if ( $requestOptions->limit > 0 && $i > $requestOptions->limit ) {
					break;
				}

				try {
					$dataItem = $diHandler->dataItemFromDBKeys( $v );
					$list[] = $dataItem;
					$result[$hash][$dataItem->getHash()] = $dataItem;
				} catch ( \SMWDataItemException $e ) {
					// maybe type assignment changed since data was stored;
					// don't worry, but we can only drop the data here
				}

				$i++;
			}
		}

		if ( $propTable->getDiType() === DataItem::TYPE_WIKIPAGE ) {
			$this->store->getObjectIds()->warmUpCache( $list );
			$this->linkBatch->addFromList( $list );
		}

		$this->linkBatch->addFromList( $subjects );
		$this->linkBatch->execute();

		return $result;
	}

	private function prefetchPropertySubjects( array $subjects, DIProperty $property, RequestOptions $requestOptions ) {

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

		$this->linkBatch->addFromList( $subjects );
		$this->linkBatch->execute();

		return $result;
	}

}
