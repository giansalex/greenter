<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 05/10/2017
 * Time: 08:29
 */

declare(strict_types=1);

namespace Greenter\Xml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Class XmlReader
 * @package Greenter\Xml
 */
class XmlReader
{
    /**
     * @var DOMXPath
     */
    private $xpath;

    /**
     * @param string $xml
     */
    public function loadXml($xml)
    {
        $doc = new DOMDocument();
        @$doc->loadXML($xml);
        $this->loadDom($doc);
    }

    /**
     * @param DOMDocument $document
     */
    public function loadDom(DOMDocument $document)
    {
        $this->xpath = new DOMXPath($document);
    }

    /**
     * @return DOMXPath
     */
    public function getXpath()
    {
        return $this->xpath;
    }

    /**
     * @param string $query                 Query xpath
     * @param DomNode|null $context        Context node
     * @param string|null $def                   Default Value
     * @return string
     */
    public function getValue(string $query, ?DOMNode $context = null, ?string $def = ''): ?string
    {
        $nodes = $this->xpath->query($query, $context);
        if ($nodes->length == 0) {
            return $def;
        }

        return $nodes->item(0)->nodeValue;
    }

    /**
     * @param string        $query
     * @param DOMNode|null $context
     * @return DOMElement|null
     */
    public function getNode(string $query, ?DOMNode $context): ?DOMElement
    {
        $nodes = $this->xpath->query($query, $context);
        if ($nodes->length == 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @param string        $query
     * @param DOMNode|null $context
     * @return DOMNodeList
     */
    public function getNodes(string $query, ?DOMNode $context)
    {
        return $this->xpath->query($query, $context);
    }
}