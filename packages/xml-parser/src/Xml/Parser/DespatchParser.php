<?php
/**
 * Created by PhpStorm.
 * User: Administrador
 * Date: 30/10/2017
 * Time: 05:33 PM
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DateTimeZone;
use DOMElement;
use Exception;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Document;
use Greenter\Model\TimeZonePe;
use Greenter\Parser\DocumentParserInterface;
use Greenter\Xml\XmlReader;
use Iterator;

/**
 * Class DespatchParser
 */
class DespatchParser implements DocumentParserInterface
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
     * @var DateTimeZone
     */
    private $timezone;

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

        $this->timezone = new DateTimeZone(TimeZonePe::DEFAULT);
        $guia = new Despatch();
        $docGuia = explode('-', $xml->getValue('cbc:ID', $root));
        $guia->setSerie($docGuia[0])
            ->setCorrelativo($docGuia[1])
            ->setTipoDoc($xml->getValue('cbc:DespatchAdviceTypeCode', $root))
            ->setObservacion($xml->getValue('cbc:Note', $root))
            ->setFechaEmision(new DateTime($xml->getValue('cbc:IssueDate', $root), $this->timezone))
            ->setCompany($this->getCompany())
            ->setDestinatario($this->getClient('cac:DeliveryCustomerParty'))
            ->setTercero($this->getClient('cac:SellerSupplierParty'))
            ->setEnvio($this->getShipment())
            ->setDetails(iterator_to_array($this->getDetails()));

        $this->loadRelDocs($guia);

        return $guia;
    }

    private function getClient(string $nodeName): ?Client
    {
        $xml = $this->reader;
        $node = $xml->getNode($nodeName, $this->rootNode);

        $docNode = $xml->getNode('cbc:CustomerAssignedAccountID', $node);
        if (!$docNode) {
            return null;
        }

        $cl = new Client();
        $cl->setNumDoc($docNode->nodeValue)
            ->setTipoDoc($docNode->getAttribute('schemeID'))
            ->setRznSocial($xml->getValue('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node));

        return $cl;
    }

    private function getCompany(): Company
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:DespatchSupplierParty', $this->rootNode);

        $cl = new Company();
        $cl->setRuc($xml->getValue('cbc:CustomerAssignedAccountID', $node))
            ->setRazonSocial($xml->getValue('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node));

        return $cl;
    }

    private function loadRelDocs(Despatch $despatch): void
    {
        $xml = $this->reader;
        $bajaNode = $xml->getNode('cac:OrderReference', $this->rootNode);
        if ($bajaNode) {
            $baja = new Document();
            $baja->setTipoDoc($xml->getValue('cbc:OrderTypeCode', $bajaNode))
                ->setNroDoc($xml->getValue('cbc:ID', $bajaNode));
            $despatch->setDocBaja($baja);
        }
        $relDoc = $xml->getNode('cac:AdditionalDocumentReference', $this->rootNode);
        if ($relDoc) {
            $rel = new Document();
            $rel->setTipoDoc($xml->getValue('cbc:OrderTypeCode', $relDoc))
                ->setNroDoc($xml->getValue('cbc:ID', $relDoc));
            $despatch->setRelDoc($rel);
        }
    }

    private function getShipment(): Shipment
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:Shipment', $this->rootNode);

        $shp = new Shipment();
        $shp->setCodTraslado($xml->getValue('cbc:HandlingCode', $node))
            ->setDesTraslado($xml->getValue('cbc:Information', $node))
            ->setNumBultos((int)$xml->getValue('cbc:TotalTransportHandlingUnitQuantity', $node, '0'))
            ->setIndTransbordo($xml->getValue('cbc:SplitConsignmentIndicator', $node, 'false') == 'true')
            ->setNumContenedor($xml->getValue('cac:TransportHandlingUnit/cbc:ID', $node))
            ->setCodPuerto($xml->getValue('cac:FirstArrivalPortLocation/cbc:ID', $node));

        $otNode = $xml->getNode('cbc:GrossWeightMeasure', $node);
        if ($otNode) {
            $shp->setUndPesoTotal($otNode->getAttribute('unitCode'))
                ->setPesoTotal((float)$otNode->nodeValue);
        }
        $otNode = $xml->getNode('cac:Delivery/cac:DeliveryAddress', $node);
        if ($otNode) {
            $shp->setLlegada(new Direction($xml->getValue('cbc:ID', $otNode), $xml->getValue('cbc:StreetName', $otNode)));
        }
        $otNode = $xml->getNode('cac:OriginAddress', $node);
        if ($otNode) {
            $shp->setPartida(new Direction($xml->getValue('cbc:ID', $otNode), $xml->getValue('cbc:StreetName', $otNode)));
        }

        $otNode = $xml->getNode('cac:ShipmentStage', $node);
        $shp->setModTraslado($xml->getValue('cbc:TransportModeCode', $otNode))
            ->setFecTraslado(new DateTime($xml->getValue('cac:TransitPeriod/cbc:StartDate', $otNode), $this->timezone))
            ->setTransportista($this->getTransportista($otNode));

        return $shp;
    }

    private function getTransportista(?DOMElement $node): Transportist
    {
        $xml = $this->reader;
        $trans = new Transportist();
        $othNode = $xml->getNode('cac:CarrierParty', $node);
        if ($othNode) {
            $idNode = $xml->getNode('cac:PartyIdentification/cbc:ID', $othNode);

            $trans->setTipoDoc($idNode->getAttribute('schemeID'))
                ->setNumDoc($idNode->nodeValue)
                ->setRznSocial($xml->getValue('cac:PartyName/cbc:Name', $othNode));
        }

        $trans->setPlaca($xml->getValue('cac:TransportMeans/cac:RoadTransport/cbc:LicensePlateID', $node));
        $othNode = $xml->getNode('cac:DriverPerson/cbc:ID', $node);
        if ($othNode) {
            $trans->setChoferTipoDoc($othNode->getAttribute('schemeID'))
                ->setChoferDoc($othNode->nodeValue);
        }

        return $trans;
    }

    private function getDetails(): Iterator
    {
        $xml = $this->reader;
        $nodes = $xml->getNodes('cac:DespatchLine', $this->rootNode);

        foreach ($nodes as $node) {
            $quantity = $xml->getNode('cbc:DeliveredQuantity', $node);
            $det = new DespatchDetail();
            $det->setCantidad((float)$quantity->nodeValue)
                ->setUnidad($quantity->getAttribute('unitCode'))
                ->setDescripcion($xml->getValue('cac:Item/cbc:Name', $node))
                ->setCodigo($xml->getValue('cac:Item/cac:SellersItemIdentification/cbc:ID', $node))
                ->setCodProdSunat($xml->getValue('cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $node));

            yield $det;
        }
    }
}