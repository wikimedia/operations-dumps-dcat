<?php
    /*
     * Some description and proper intro
     * TODO: Replace hardcoded languages by lookup+validation (removing qqq)
     * TODO: Replace hardcoded node/dataset ids
     * KNOWN ISSUES:
     *      assumes i18n file = lang code = iso639-1 code
     *      assumes one contactPoint and one publisher
     */
     
    class DCAT{
        
        /* constructs the whole dcat-ap document given an array of dump info
         * for the format of the array, see the test function
         */
        function outputXml($dumps){
            /* Setting XML header */
            @header ("content-type: text/xml charset=UTF-8");
            
            /* Initializing the XML Object */
            $xml = new XmlWriter();
            $xml->openMemory();
            $xml->setIndent(true);
            $xml->setIndentString('    ');
            
            /* set namespaces */
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElementNS('rdf', 'RDF', null);
            $xml->writeAttributeNS('xmlns', 'rdf', null, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $xml->writeAttributeNS('xmlns', 'xsi', null, 'http://purl.org/dc/terms/');
            $xml->writeAttributeNS('xmlns', 'dcat', null, 'http://www.w3.org/ns/dcat#');
            $xml->writeAttributeNS('xmlns', 'foaf', null, 'http://xmlns.com/foaf/0.1/');
            $xml->writeAttributeNS('xmlns', 'adms', null, 'http://www.w3.org/ns/adms#');
            $xml->writeAttributeNS('xmlns', 'vcard', null, 'http://www.w3.org/2006/vcard/ns#');
            
            /* an easy way of passing data around */
            function makeDataBlob($dumps){
                /* Open config file and languages */
                $config = json_decode(file_get_contents('config.json'), true);
                $langs = array(
                    "en" => 'i18n/en.json',
                );
                $i18n = array(
                    "en" => json_decode(file_get_contents($langs['en']), true),
                );
                $ids = array(
                    "publisher" => "_n42",
                    "contactPoint" => "_n43",
                    "liveDataset" => "liveData",
                    "dumpDatasetPrefix" => "dumpData",
                    "liveDistribLD" => "liveDataLD",
                    "liveDistribAPI" => "liveDataAPI",
                    "dumpDistribPrefix" => "dumpDist",
                );
                
                $data = array(
                    "config" => $config,
                    "dumps" => $dumps,
                    "i18n" => $i18n,
                    "ids" => $ids,
                );
                return $data;
            }
            
            /* adds additional data not needed for live distributions */
            function dumpDistributionExtras(XMLWriter $xml, $data, $dumpDate){
                $url = str_replace('$1', $dumpDate.'json.gz', $data['config']['dump-info']['accessURL']);
                
                $xml->startElementNS('dcat', 'accessURL', null);
                $xml->writeAttributeNS('rdf', 'resource', null, $url);
                $xml->endElement();
                
                $xml->startElementNS('dcat', 'downloadURL', null);
                $xml->writeAttributeNS('rdf', 'resource', null, $url);
                $xml->endElement();
                
                $xml->writeElementNS('dcterms', 'issued', null, $data['dumps'][$dumpDate]['timestamp']);
                
                $xml->startElementNS('dcat', 'byteSize', null);
                $xml->writeAttributeNS('rdf', 'datatype', null, 'http://www.w3.org/2001/XMLSchema#decimal');
                $xml->text($data['dumps'][$dumpDate]['byteSize']);
                $xml->endElement();
            }
            
            /* construct a LiveDistribution for a given prefix
             * dumpDate = null for live data
             */
            function writeDistribution(XMLWriter $xml, $data, $distribId, $prefix, $dumpDate){
                $id = $data['config']['uri'].'#'.$distribId.$dumpDate;
                
                $xml->startElementNS('rdf', 'Description', null);
                $xml->writeAttributeNS('rdf', 'about', null, $id);
            
                $xml->startElementNS('rdf', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://www.w3.org/ns/dcat#Distribution');
                $xml->endElement();
                
                $xml->startElementNS('dcterms', 'license', null);
                $xml->writeAttributeNS('rdf', 'resource', null, $data['config'][$prefix.'-info']['license']);
                $xml->endElement();
                
                if ( is_null($dumpDate) ){
                    $xml->startElementNS('dcat', 'accessURL', null);
                    $xml->writeAttributeNS('rdf', 'resource', null, $data['config'][$prefix.'-info']['accessURL']);
                    $xml->endElement();
                }
                else {
                    dumpDistributionExtras($xml, $data, $dumpDate);
                }

                /* add mediatypes */               
                foreach($data['config'][$prefix.'-info']['mediatype'] as $key => $value){
                    $xml->writeElementNS('dcterms', 'format', null, $value);
                }
                
                /* add description in each language */
                foreach($data['i18n'] as $key => $value){
                    $xml->startElementNS('dcterms', 'description', null);
                    $xml->writeAttributeNS('xml', 'lang', null, $key);
                    $xml->text($data['i18n'][$key]['distribution-'.$prefix.'-description']);
                    $xml->endElement();
                }

                $xml->endElement();
                return $id;
            }
            
            /* construct the LiveDataset
             * dumpDate = null for live data
             */
            function writeDataset(XMLWriter $xml, $data, $dumpDate, $datasetId, $publisher, $contactPoint, $distribution){
                $type = 'dump';
                if ( is_null($dumpDate) ){
                    $type = 'live';
                }
                
                $id = $data['config']['uri'].'#'.$datasetId.$dumpDate;
                
                $xml->startElementNS('rdf', 'Description', null);
                $xml->writeAttributeNS('rdf', 'about', null, $id);
                
                $xml->startElementNS('rdf', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://www.w3.org/ns/dcat#Dataset');
                $xml->endElement();
                
                $xml->startElementNS('adms', 'contactPoint', null);
                $xml->writeAttributeNS('rdf', 'nodeID', null, $contactPoint);
                $xml->endElement();
                
                $xml->startElementNS('dcterms', 'publisher', null);
                $xml->writeAttributeNS('rdf', 'nodeID', null, $publisher);
                $xml->endElement();
                
                if ($type === 'live'){
                    $xml->startElementNS('dcterms', 'accrualPeriodicity', null);
                    $xml->writeAttributeNS('rdf', 'resource', null, 'http://purl.org/cld/freq/continuous');
                    $xml->endElement();
                }
                
                /* add title and description in each language */
                foreach($data['i18n'] as $key => $value){
                    $xml->startElementNS('dcterms', 'title', null);
                    $xml->writeAttributeNS('xml', 'lang', null, $key);
                    if ($type === 'live'){
                        $xml->text($data['i18n'][$key]['dataset-live-title']);
                    }
                    else {
                        $xml->text(
                            str_replace('$1', $dumpDate, $data['i18n'][$key]['dataset-dump-title'])
                       );
                    }
                    $xml->endElement();
                    $xml->startElementNS('dcterms', 'description', null);
                    $xml->writeAttributeNS('xml', 'lang', null, $key);
                    $xml->text($data['i18n'][$key]['dataset-'.$type.'-description']);
                    $xml->endElement();
                }
                
                /* add datasets */               
                foreach($distribution as $key => $value){
                    $xml->startElementNS('dcat', 'distribution', null);
                    $xml->writeAttributeNS('rdf', 'resource', null, $data['config']['uri'].'#'.$value);
                    $xml->endElement();
                }
                
                $xml->endElement();
                return $id;
            }
            
            /* construct a publisher for the catalog and datasets with a given nodeId */
            function writePublisher(XMLWriter $xml, $data, $publisher){
                $xml->startElementNS('rdf', 'Description', null);
                $xml->writeAttributeNS('rdf', 'nodeID', null, $publisher);
                
                $xml->startElementNS('rdf', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://xmlns.com/foaf/0.1/Agent');
                $xml->endElement();
                
                $xml->writeElementNS('foaf', 'name', null, $data['config']['publisher']['name']);
                
                $xml->startElementNS('dcterms', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://purl.org/adms/publishertype/'.$data['config']['publisher']['publisherType']);
                $xml->endElement();
                
                $xml->writeElementNS('foaf', 'homepage', null, $data['config']['publisher']['homepage']);
                
                $xml->startElementNS('vcard', 'hasEmail', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'mailto:'.$data['config']['publisher']['email']);
                $xml->endElement();
                
                $xml->endElement();
            }

            /* construct a contactPoint for the datasets with a given nodeId */
            function writeContactPoint(XMLWriter $xml, $data, $contactPoint){
                $xml->startElementNS('rdf', 'Description', null);
                $xml->writeAttributeNS('rdf', 'nodeID', null, $contactPoint);
                
                $xml->startElementNS('rdf', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://www.w3.org/2006/vcard/ns#'.$data['config']['contactPoint']['vcardType']);
                $xml->endElement();
                
                $xml->startElementNS('vcard', 'hasEmail', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'mailto:'.$data['config']['contactPoint']['email']);
                $xml->endElement();

                $xml->writeElementNS('vcard', 'fn', null, $data['config']['contactPoint']['name']);
                
                $xml->endElement();
            }
            
            /* 
             * Function for outputting the catalog entry
             * i18n: array of lang files
             * config: array of configuration variables
             * publisher: the nodeId of the publisher
             * dataset: an array of the dataset identifiers
             */
            function writeCatalog(XMLWriter $xml, $data, $publisher, $dataset){
                $xml->startElementNS('rdf', 'Description', null);
                $xml->writeAttributeNS('rdf', 'about', null, $data['config']['uri']);
                
                $xml->startElementNS('rdf', 'type', null);
                $xml->writeAttributeNS('rdf', 'resource', null, 'http://www.w3.org/ns/dcat#Catalog');
                $xml->endElement();
                
                $xml->startElementNS('dcterms', 'license', null);
                $xml->writeAttributeNS('rdf', 'resource', null, $data['config']['catalog-license']);
                $xml->endElement();
                
                $xml->writeElementNS('foaf', 'homepage', null, 'https://www.wikidata.org');
                $xml->writeElementNS('dcterms', 'modified', null, date('Y-m-d'));
                $xml->writeElementNS('dcterms', 'issued', null, $data['config']['catalog-issued']);
                
                $xml->startElementNS('dcterms', 'publisher', null);
                $xml->writeAttributeNS('rdf', 'nodeID', null, $publisher);
                $xml->endElement();
    
                /* 
                 * add language, title and description in each language
                 */
                foreach($data['i18n'] as $key => $value){
                    $xml->startElementNS('dcterms', 'language', null);
                    $xml->writeAttributeNS('rdf', 'resource', null, 'http://id.loc.gov/vocabulary/iso639-1/'.$key);
                    $xml->endElement();
                    $xml->startElementNS('dcterms', 'title', null);
                    $xml->writeAttributeNS('xml', 'lang', null, $key);
                    $xml->text($data['i18n'][$key]['catalog-title']);
                    $xml->endElement();
                    $xml->startElementNS('dcterms', 'description', null);
                    $xml->writeAttributeNS('xml', 'lang', null, $key);
                    $xml->text($data['i18n'][$key]['catalog-description']);
                    $xml->endElement();
                }
                
                /* add datasets */               
                foreach($dataset as $key => $value){
                    $xml->startElementNS('dcat', 'dataset', null);
                    $xml->writeAttributeNS('rdf', 'resource', null, $data['config']['uri'].'#'.$value);
                    $xml->endElement();
                }
                
                $xml->endElement();
            }
  
            
            /* Calls previously declared functions to construct xml */
            $data = makeDataBlob($dumps);
            
            writePublisher($xml, $data, $data['ids']['publisher']);
            writeContactPoint($xml, $data, $data['ids']['contactPoint']);
            
            $dataset = array($data['ids']['liveDataset'],);
            
            writeDistribution($xml, $data, $data['ids']['liveDistribLD'], 'ld', null);
            writeDistribution($xml, $data, $data['ids']['liveDistribAPI'], 'api', null);
            writeDataset($xml, $data, null, $data['ids']['liveDataset'], $data['ids']['publisher'], $data['ids']['contactPoint'], array($data['ids']['liveDistribLD'], $data['ids']['liveDistribAPI']));
            
            foreach($data['dumps'] as $key => $value){
                $distId = writeDistribution($xml, $data, $data['ids']['dumpDistribPrefix'], 'dump', $key);
                array_push($dataset,
                    writeDataset($xml, $data, $key, $data['ids']['dumpDatasetPrefix'], $data['ids']['publisher'], $data['ids']['contactPoint'], array($distId, ))
                );
            }
            
            writeCatalog($xml, $data, $data['ids']['publisher'], $dataset);
            
            /* Closing last XML node */
            $xml->endElement();
            
            /* Printing the XML */
            echo $xml->outputMemory(true);
        }
        
        
        function test(){
            $dumps = array(
                '20150120' => array(
                    'timestamp' => '2015-01-21T02:31:00',
                    'byteSize' => '3969097664',
                ),
                '20150126' => array(
                    'timestamp' => '2015-01-26T14:47:00',
                    'byteSize' => '3997877455',
                ),
            );
            DCAT::outputXml($dumps);
        }
    }
?>
