<?php
/**
 * DCAT-AP generation for Wikibase
 *
 * @author Lokal_Profil
 * @licence MIT
 *
 */

/**
 * Validate that config is json and contains all necessary keys
 *
 * @param array $config json decoded config file
 */
function validateConfig( array $config ) {
	// Later tests depend on these existing and being defined
	$topBool = array( "api-enabled", "dumps-enabled" );
	foreach ( $topBool as $val ) {
		if ( !array_key_exists( $val, $config ) ) {
			throw new Exception( "$val is missing from the config file" );
		} elseif ( !is_bool( $config[$val] ) ) {
			throw new Exception( "$val in the config file must be a boolean" );
		}
	}

	// Always required
	$top = array(
		"directory", "uri", "themes", "keywords", "publisher",
		"contactPoint", "ld-info", "catalog-license", "catalog-homepage",
		"catalog-i18n", "catalog-issued"
	);
	$sub = array(
		"publisher" => array( "publisherType", "homepage", "name", "email" ),
		"contactPoint" => array( "vcardType", "name", "email" ),
		"ld-info" => array( "accessURL", "mediatype", "license" )
	);

	// Dependent on topBool
	if ( $config['api-enabled'] ) {
		array_push( $top, "api-info" );
		$sub["api-info"] = array( "accessURL", "mediatype", "license" );
	}
	if ( $config['dumps-enabled'] ) {
		array_push( $top, "dump-info" );
		$sub["dump-info"] = array(
			"accessURL", "mediatype", "compression", "license"
		);
	}

	// Test
	foreach ( $top as $val ) {
		if ( !array_key_exists( $val, $config ) ) {
			throw new Exception( "$val is missing from the config file" );
		}
	}
	foreach ( $sub as $key => $subArray ) {
		foreach ( $subArray as $val ) {
			if ( !array_key_exists( $val, $config[$key] ) ) {
				throw new Exception(
					$key . "[" . $val . "] is missing from the config file"
				);
			}
		}
	}
}

/**
 * Load i18n files, local and remote, into an array
 *
 * @param array $langs array of langcode => filename
 * @param array $config json decoded config file
 * @return array: An i18n blob
 */
function makeI18nBlob( array $langs, array $config ) {
	// load i18n files into i18n array
	$i18n = array();
	foreach ( $langs as $langCode => $filename ) {
		$i18n[$langCode] = json_decode( file_get_contents( $filename ), true );
	}

	// load catalog i18n info from URL and add to i18n object
	$i18nJSON = json_decode( file_get_contents( $config['catalog-i18n'] ), true );
	if ( !isset( $i18nJSON ) ) {
		throw new Exception(
			"Could not read catalog-i18n. Are you sure " .
			$config['catalog-i18n'] .
			" exists and is valid json?"
		);
	}
	foreach ( array_keys( $i18n ) as $langCode ) {
		if ( array_key_exists( "$langCode-title", $i18nJSON ) ) {
			$i18n[$langCode]['catalog-title'] = $i18nJSON["$langCode-title"];
		}
		if ( array_key_exists( "$langCode-description", $i18nJSON ) ) {
			$i18n[$langCode]['catalog-description'] = $i18nJSON["$langCode-description"];
		}
	}

	return $i18n;
}

/**
 * Construct a data blob as an easy way of passing data around
 *
 * @param string $config path to config file
 * @return array: A data blob
 */
function makeDataBlob( $config ) {
	// Open config file and languages
	$config = json_decode( file_get_contents( $config ), true );
	validateConfig( $config );

	// identify existing i18n files and load into array
	$langs = array();
	foreach ( glob( __DIR__ . '/i18n/*.json' ) as $filename ) {
		if ( $filename !== __DIR__ . '/i18n/qqq.json' ) {
			$langcode = substr( $filename,
				strlen( __DIR__ . '/i18n/' ),
				-strlen( '.json' ) );
			$langs[$langcode] = $filename;
		}
	}
	$i18n = makeI18nBlob( $langs, $config );

	// hardcoded ids
	$ids = array(
		'publisher' => '_n42',
		'contactPoint' => '_n43',
		'dataset' => array(
			'live' => 'liveData',
			'dump' => 'dumpData',
		),
		'distribution' => array(
			'ld' => 'liveDataLD',
			'api' => 'liveDataAPI',
			'dump' => 'dumpDist',
		),
	);

	// stick loaded data into blob
	$data = array(
		'config' => $config,
		'dumps' => null,
		'i18n' => $i18n,
		'ids' => $ids,
	);
	return $data;
}

/**
 * Add additional data to a distribution entry when dealing with a dump.
 * Complement to writeDistribution()
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $dump the metadata on the dump being described
 * @param string $accessURL the url prefix for the filename
 */
function dumpDistributionExtras( XMLWriter $xml, array $dump, $accessURL ) {
	$url = str_replace(
		'$1',
		$dump['filename'],
		$accessURL
	);

	$xml->startElementNS( 'dcat', 'accessURL', null );
	$xml->writeAttribute( 'rdf:resource', $url );
	$xml->endElement();

	$xml->startElementNS( 'dcat', 'downloadURL', null );
	$xml->writeAttribute( 'rdf:resource', $url );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'issued', null );
	$xml->writeAttribute( 'rdf:datatype', 'http://www.w3.org/2001/XMLSchema#date' );
	$xml->text( $dump['timestamp'] );
	$xml->endElement();

	$xml->startElementNS( 'dcat', 'byteSize', null );
	$xml->writeAttribute( 'rdf:datatype', 'http://www.w3.org/2001/XMLSchema#decimal' );
	$xml->text( $dump['byteSize'] );
	$xml->endElement();
}

/**
 * Add i18n descriptions for a distribution
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 * @param string $prefix the type of distribution, one of 'ld', 'api' or 'dump'
 * @param string $format the file format, only used for dumps
 * @param string $compression the compression format, only used for dumps
 */
function writeDistributionI18n( XMLWriter $xml, array $data, $prefix,
	$format, $compression ) {

	foreach ( $data['i18n'] as $langCode => $langData ) {
		if ( array_key_exists( "distribution-$prefix-description", $langData ) ) {
			$formatDescription = $langData["distribution-$prefix-description"];
			if ( $prefix === 'dump' ) {
				$formatDescription = str_replace(
					'$1',
					$format,
					$formatDescription
				);
				$formatDescription = str_replace(
					'$2',
					$compression,
					$formatDescription
				);
			}
			$xml->startElementNS( 'dcterms', 'description', null );
			$xml->writeAttribute( 'xml:lang', $langCode );
			$xml->text( $formatDescription );
			$xml->endElement();
		}
	}
}

/**
 * Construct distribution entry for each format in which a distribution
 * is available. The DCAT-specification requires each format to be a
 * separate distribution.
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 * @param string $prefix the type of distribution, one of 'ld', 'api' or 'dump'
 * @param string|null $dumpDate the date of the dumpfile, null for live data
 */
function writeDistribution( XMLWriter $xml, array $data, $prefix, $dumpDate ) {
	$ids = array();

	$isDump = $prefix === 'dump';
	$allowedMediatypes = $data['config']["$prefix-info"]['mediatype'];
	$allowedCompressiontypes = array( '' => '' );  // dummy array for non-dumps
	if ( $isDump ) {
		$allowedCompressiontypes = $data['config']["$prefix-info"]['compression'];
	}

	foreach ( $allowedCompressiontypes as $compressionName => $compression ) {
		foreach ( $allowedMediatypes as $formatName => $mediatype ) {
			$format = $formatName;
			$contentType = $mediatype;
			if ( is_array( $mediatype ) ) {
				if ( array_key_exists( 'format', $mediatype ) ) {
					$format = $mediatype['format'];
				}
				if ( array_key_exists( 'contentType', $mediatype ) ) {
					$contentType = $mediatype['contentType'];
				}
			}
			$distributionKey = $format . $compression;

			// handle missing (and BETA) dump files
			if ( $isDump and !array_key_exists( $distributionKey , $data['dumps'][$dumpDate] ) ) {
				continue;
			}

			$id = $data['config']['uri'] . '#' .
				$data['ids']['distribution'][$prefix] .
				$dumpDate . $distributionKey;
			array_push( $ids, $id );

			$xml->startElementNS( 'rdf', 'Description', null );
			$xml->writeAttribute( 'rdf:about', $id );

			$xml->startElementNS( 'rdf', 'type', null );
			$xml->writeAttribute( 'rdf:resource',
				'http://www.w3.org/ns/dcat#Distribution' );
			$xml->endElement();

			$xml->startElementNS( 'dcterms', 'license', null );
			$xml->writeAttribute( 'rdf:resource',
				$data['config']["$prefix-info"]['license'] );
			$xml->endElement();

			if ( !$isDump ) {
				$xml->startElementNS( 'dcat', 'accessURL', null );
				$xml->writeAttribute( 'rdf:resource',
					$data['config']["$prefix-info"]['accessURL'] );
				$xml->endElement();
			} else {
				dumpDistributionExtras( $xml,
					$data['dumps'][$dumpDate][$distributionKey],
					$data['config']['dump-info']['accessURL']
				);
			}

			$xml->writeElementNS( 'dcterms', 'format', null, $contentType );

			// add description in each language
			writeDistributionI18n( $xml, $data, $prefix, $formatName,
				$compressionName );

			$xml->endElement();
		}
	}

	return $ids;
}

/**
 * Add i18n title and description for a dataset
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 * @param string|null $dumpDate the date of the dumpfile, null for live data
 * @param string $type 'dump' or 'live'
 */
function writeDatasetI18n( XMLWriter $xml, array $data, $dumpDate, $type ) {
	foreach ( $data['i18n'] as $langCode => $langData ) {
		if ( array_key_exists( "dataset-$type-title", $langData ) ) {
			$xml->startElementNS( 'dcterms', 'title', null );
			$xml->writeAttribute( 'xml:lang', $langCode );
			if ( $type === 'live' ) {
				$xml->text( $langData['dataset-live-title'] );
			} else {
				$xml->text(
					str_replace( '$1', $dumpDate, $langData['dataset-dump-title'] )
				);
			}
			$xml->endElement();
		}
		if ( array_key_exists( "dataset-$type-description", $langData ) ) {
			$xml->startElementNS( 'dcterms', 'description', null );
			$xml->writeAttribute( 'xml:lang', $langCode );
			$xml->text( $langData["dataset-$type-description"] );
			$xml->endElement();
		}
	}
}

/**
 * Construct a dataset entry
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 * @param string|null $dumpDate the date of the dumpfile, null for live data
 * @param array $distribution array of the distribution identifiers
 */
function writeDataset( XMLWriter $xml, array $data, $dumpDate, array $distribution ) {
	$type = 'dump';
	if ( is_null( $dumpDate ) ) {
		$type = 'live';
	}

	$id = $data['config']['uri'] . '#' . $data['ids']['dataset'][$type] . $dumpDate;

	$xml->startElementNS( 'rdf', 'Description', null );
	$xml->writeAttribute( 'rdf:about', $id );

	$xml->startElementNS( 'rdf', 'type', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://www.w3.org/ns/dcat#Dataset' );
	$xml->endElement();

	$xml->startElementNS( 'adms', 'contactPoint', null );
	$xml->writeAttribute( 'rdf:nodeID', $data['ids']['contactPoint'] );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'publisher', null );
	$xml->writeAttribute( 'rdf:nodeID', $data['ids']['publisher'] );
	$xml->endElement();

	if ( is_null( $dumpDate ) ) {
		$xml->startElementNS( 'dcterms', 'accrualPeriodicity', null );
		$xml->writeAttribute( 'rdf:resource',
			'http://purl.org/cld/freq/continuous' );
		$xml->endElement();
	}

	// add keywords
	foreach ( $data['config']['keywords'] as $key => $keyword ) {
		$xml->writeElementNS( 'dcat', 'keyword', null, $keyword );
	}

	// add themes
	foreach ( $data['config']['themes'] as $key => $keyword ) {
		$xml->startElementNS( 'dcat', 'theme', null );
		$xml->writeAttribute( 'rdf:resource',
			"http://eurovoc.europa.eu/$keyword" );
		$xml->endElement();
	}

	// add title and description in each language
	writeDatasetI18n( $xml, $data, $dumpDate, $type );

	// add distributions
	foreach ( $distribution as $key => $value ) {
		$xml->startElementNS( 'dcat', 'distribution', null );
		$xml->writeAttribute( 'rdf:resource', $value );
		$xml->endElement();
	}

	$xml->endElement();
	return $id;
}

/**
 * Construct the publisher for the catalog and datasets
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 */
function writePublisher( XMLWriter $xml, array $data ) {
	$xml->startElementNS( 'rdf', 'Description', null );
	$xml->writeAttribute( 'rdf:nodeID', $data['ids']['publisher'] );

	$xml->startElementNS( 'rdf', 'type', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://xmlns.com/foaf/0.1/Agent' );
	$xml->endElement();

	$xml->writeElementNS( 'foaf', 'name', null,
		$data['config']['publisher']['name'] );

	$xml->startElementNS( 'dcterms', 'type', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://purl.org/adms/publishertype/' .
			$data['config']['publisher']['publisherType'] );
	$xml->endElement();

	$xml->startElementNS( 'foaf', 'homepage', null );
	$xml->writeAttribute( 'rdf:resource',
		$data['config']['publisher']['homepage'] );
	$xml->endElement();

	$xml->startElementNS( 'vcard', 'hasEmail', null );
	$xml->writeAttribute( 'rdf:resource',
		'mailto:' . $data['config']['publisher']['email'] );
	$xml->endElement();

	$xml->endElement();
}

/**
 * Construct a contactPoint for the datasets
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 */
function writeContactPoint( XMLWriter $xml, array $data ) {
	$xml->startElementNS( 'rdf', 'Description', null );
	$xml->writeAttribute( 'rdf:nodeID', $data['ids']['contactPoint'] );

	$xml->startElementNS( 'rdf', 'type', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://www.w3.org/2006/vcard/ns#' .
			$data['config']['contactPoint']['vcardType'] );
	$xml->endElement();

	$xml->startElementNS( 'vcard', 'hasEmail', null );
	$xml->writeAttribute( 'rdf:resource',
		'mailto:' . $data['config']['contactPoint']['email'] );
	$xml->endElement();

	$xml->writeElementNS( 'vcard', 'fn', null,
		$data['config']['contactPoint']['name'] );

	$xml->endElement();
}

/**
 * Add language and i18n title and description for the catalog entry
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 */
function writeCatalogI18n( XMLWriter $xml, array $data ) {
		foreach ( $data['i18n'] as $langCode => $langData ) {
		$xml->startElementNS( 'dcterms', 'language', null );
		$xml->writeAttribute( 'rdf:resource',
			"http://id.loc.gov/vocabulary/iso639-1/$langCode" );
		$xml->endElement();

		if ( array_key_exists( 'catalog-title', $langData ) ) {
			$xml->startElementNS( 'dcterms', 'title', null );
			$xml->writeAttribute( 'xml:lang', $langCode );
			$xml->text( $langData['catalog-title'] );
			$xml->endElement();
		}
		if ( array_key_exists( 'catalog-description', $langData ) ) {
			$xml->startElementNS( 'dcterms', 'description', null );
			$xml->writeAttribute( 'xml:lang', $langCode );
			$xml->text( $langData['catalog-description'] );
			$xml->endElement();
		}
	}
}

/**
 * Construct the catalog entry
 *
 * @param XmlWriter $xml XML stream to write to
 * @param array $data data-blob of i18n and config variables
 * @param array $dataset array of the dataset identifiers
 */
function writeCatalog( XMLWriter $xml, array $data, array $dataset ) {
	$xml->startElementNS( 'rdf', 'Description', null );
	$xml->writeAttribute( 'rdf:about',
		$data['config']['uri'] . '#catalog' );

	$xml->startElementNS( 'rdf', 'type', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://www.w3.org/ns/dcat#Catalog' );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'license', null );
	$xml->writeAttribute( 'rdf:resource',
		$data['config']['catalog-license'] );
	$xml->endElement();

	$xml->startElementNS( 'dcat', 'themeTaxonomy', null );
	$xml->writeAttribute( 'rdf:resource',
		'http://eurovoc.europa.eu/' );
	$xml->endElement();

	$xml->startElementNS( 'foaf', 'homepage', null );
	$xml->writeAttribute( 'rdf:resource',
		$data['config']['catalog-homepage'] );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'modified', null );
	$xml->writeAttribute( 'rdf:datatype',
		'http://www.w3.org/2001/XMLSchema#date' );
	$xml->text( date( 'Y-m-d' ) );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'issued', null );
	$xml->writeAttribute( 'rdf:datatype',
		'http://www.w3.org/2001/XMLSchema#date' );
	$xml->text( $data['config']['catalog-issued'] );
	$xml->endElement();

	$xml->startElementNS( 'dcterms', 'publisher', null );
	$xml->writeAttribute( 'rdf:nodeID', $data['ids']['publisher'] );
	$xml->endElement();

	// add language, title and description in each language
	writeCatalogI18n( $xml, $data );

	// add datasets
	foreach ( $dataset as $key => $value ) {
		$xml->startElementNS( 'dcat', 'dataset', null );
		$xml->writeAttribute( 'rdf:resource', $value );
		$xml->endElement();
	}

	$xml->endElement();
}

/**
 * Construct the whole DCAT-AP document given an array of dump info
 *
 * @param array $data data-blob of i18n and config variables
 * @return string: xmldata
 */
function outputXml( array $data ) {
	// Initializing the XML Object
	$xml = new XmlWriter();
	$xml->openMemory();
	$xml->setIndent( true );
	$xml->setIndentString( '    ' );

	// set namespaces
	$xml->startDocument( '1.0', 'UTF-8' );
	$xml->startElementNS( 'rdf', 'RDF', null );
	$xml->writeAttribute( 'xmlns:rdf',
		'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );
	$xml->writeAttribute( 'xmlns:dcterms',
		'http://purl.org/dc/terms/' );
	$xml->writeAttribute( 'xmlns:dcat',
		'http://www.w3.org/ns/dcat#' );
	$xml->writeAttribute( 'xmlns:foaf',
		'http://xmlns.com/foaf/0.1/' );
	$xml->writeAttribute( 'xmlns:adms',
		'http://www.w3.org/ns/adms#' );
	$xml->writeAttribute( 'xmlns:vcard',
		'http://www.w3.org/2006/vcard/ns#' );

	// Calls previously declared functions to construct xml
	writePublisher( $xml, $data );
	writeContactPoint( $xml, $data );

	$dataset = array();

	// Live dataset and distributions
	$liveDistribs = writeDistribution( $xml, $data, 'ld', null );
	if ( $data['config']['api-enabled'] ) {
		$liveDistribs = array_merge( $liveDistribs,
			writeDistribution( $xml, $data, 'api', null )
		);
	}
	array_push( $dataset,
		writeDataset( $xml, $data, null, $liveDistribs )
	);

	// Dump dataset and distributions
	if ( $data['config']['dumps-enabled'] ) {
		foreach ( $data['dumps'] as $key => $value ) {
			$distIds = writeDistribution( $xml, $data, 'dump', $key );
			array_push( $dataset,
				writeDataset( $xml, $data, $key, $distIds )
			);
		}
	}

	writeCatalog( $xml, $data, $dataset );

	// Closing last XML node
	$xml->endElement();

	// Printing the XML
	return $xml->outputMemory( true );
}

/**
 * Given a dump directory produce array with data needed by outputXml()
 *
 * @param string $dirname directory name
 * @param array $data data-blob of i18n and config variables
 * @return array: of dumpdata, or empty array
 */
function scanDump( $dirname, array $data ) {
	$testStrings = array();
	foreach ( $data['config']['dump-info']['compression'] as $compression ) {
		foreach ( $data['config']['dump-info']['mediatype'] as $format => $mediatype ) {
			$prefix = 'all';
			if ( is_array( $mediatype ) ) {
				if ( array_key_exists( 'format', $mediatype ) ) {
					$format = $mediatype['format'];
				}
				if ( array_key_exists( 'prefix', $mediatype ) ) {
					$prefix = $mediatype['prefix'];
				}
			}
			$testStrings["$format$compression"] = '-' . $prefix . '.' . $format . '.' . $compression;
		}
	}

	$dumps = array();

	// each valid subdirectory has the form YYYYMMDD and refers to a timestamp
	foreach ( glob( $dirname . '/[0-9]*', GLOB_ONLYDIR ) as $subdir ) {
		// $subdir = testdirNew/20150120
		$subDump = array();
		foreach ( glob( $subdir . '/*' ) as $filename ) {
			// match each file against an expected test string
			foreach ( $testStrings as $fileEnding => $testString ) {
				if ( substr( $filename, -strlen( $testString ) ) === $testString ) {
					$info = stat( $filename );
					$filename = substr( $filename, strlen( $dirname . '/' ) );
					$subDump[$fileEnding] = array(
						'timestamp' => gmdate( 'Y-m-d', $info['mtime'] ),
						'byteSize' => $info['size'],
						'filename' => $filename
					);
				}
			}
		}
		// if files found then add to dumps
		if ( count( $subDump ) > 0 ) {
			$subdir = substr( $subdir, strlen( $dirname . '/' ) );
			$dumps[$subdir] = $subDump;
		}
	}

	return $dumps;
}

/**
 * Scan dump directory for dump files (if any) and
 * create dcatap.rdf in the same directory
 *
 * @param array $options command line options to override defaults
 */
function run( array $options ) {
	// Load config variables and i18n a data blob
	if ( !isset( $options['config'] ) ) {
		$options['config'] = 'config.json';
	}
	if ( !is_file( $options['config'] ) ) {
		throw new Exception( $options['config'] . " does not seem to exist" );
	}
	$data = makeDataBlob( $options['config'] );

	// Load directories from config/options and test for existence
	if ( !isset( $options['dumpDir'] ) ) {
		$options['dumpDir'] = $data['config']['directory'];
	}
	if ( !is_dir( $options['dumpDir'] ) or !is_readable( $options['dumpDir'] ) ) {
		throw new Exception(
			$options['dumpDir'] . " is not a valid readable directory"
		);
	}
	if ( !isset( $options['outputDir'] ) ) {
		$options['outputDir'] = $data['config']['directory'];
	}
	if ( !is_dir( $options['outputDir'] ) or !is_writable( $options['outputDir'] ) ) {
		throw new Exception(
			$options['outputDir'] . " is not a valid writable directory"
		);
	}

	// add dump data to data blob
	$data['dumps'] = scanDump( $options['dumpDir'], $data );

	// create xml string from data blob
	$xml = outputXml( $data );

	file_put_contents( $options['outputDir'] . "/dcatap.rdf", $xml );
}

// run from command-line with options
// Load options
$longOpts = array(
	"config::",     // Path to the config.json, default: config.json
	"dumpDir::",    // Path to the directory containing entity dumps, default: set in config
	"outputDir::"   // Path where dcat.rdf should be outputted, default: same as dumpDir
);
$options = getopt( '', $longOpts );
try {
	run( $options );
} catch ( Exception $e ) {
	die( $e->getMessage() );
}
