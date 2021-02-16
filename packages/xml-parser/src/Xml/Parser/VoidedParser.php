<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 08/11/2017
 * Time: 21:29
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DateTimeZone;
use DOMElement;
use Exception;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\TimeZonePe;
use Greenter\Model\Voided\Reversion;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use Greenter\Parser\DocumentParserInterface;
use Greenter\Xml\XmlReader;
use Iterator;

/**
 * Class VoidedParser
 * @package Greenter\Xml\Parser
 */
class VoidedParser implements DocumentParserInterface
{
    use XmlLoaderTrait;

    /**
     * @var XmlReader
     */
    private $reader;

    /**
     * @var DOMElement
     */
    private $rootNode;

    /**
     * @param mixed $value
     * @return DocumentInterface
     * @throws Exception
     */
    public function parse($value): ?DocumentInterface
    {
        $this->reader = $this->load($value);
        $xml = $this->reader;
        $root = $this->rootNode = $xml->getXpath()->document->documentElement;

        $id = explode('-', $xml->getValue('cbc:ID', $root));

        $timeZone = new DateTimeZone(TimeZonePe::DEFAULT);
        $voided = $id[0] == 'RA' ? new Voided() : new Reversion();
        $voided->setCorrelativo($id[2])
            ->setFecGeneracion(new DateTime($xml->getValue('cbc:ReferenceDate', $root), $timeZone))
            ->setFecComunicacion(new DateTime($xml->getValue('cbc:IssueDate', $root), $timeZone))
            ->setCompany($this->getCompany())
            ->setDetails(iterator_to_array($this->getDetails()));

        return $voided;
    }

    private function getCompany(): Company
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:AccountingSupplierParty', $this->rootNode);

        $cl = new Company();
        $cl->setRuc($xml->getValue('cbc:CustomerAssignedAccountID', $node))
            ->setNombreComercial($xml->getValue('cac:Party/cac:PartyName/cbc:Name', $node))
            ->setRazonSocial($xml->getValue('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node));

        return $cl;
    }

    private function getDetails(): Iterator
    {
        $xml = $this->reader;
        $nodes = $xml->getNodes('sac:VoidedDocumentsLine', $this->rootNode);

        foreach ($nodes as $node) {
            $det = new VoidedDetail();
            $det->setTipoDoc($xml->getValue('cbc:DocumentTypeCode', $node))
                ->setSerie($xml->getValue('sac:DocumentSerialID', $node))
                ->setCorrelativo($xml->getValue('sac:DocumentNumberID', $node))
                ->setDesMotivoBaja($xml->getValue('sac:VoidReasonDescription', $node));

            yield $det;
        }
    }
}
