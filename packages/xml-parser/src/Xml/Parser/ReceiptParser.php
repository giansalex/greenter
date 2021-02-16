<?php
/**
 * Created by PhpStorm.
 * User: Administrador
 * Date: 18/10/2017
 * Time: 06:12 PM
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DateTimeZone;
use DOMElement;
use Exception;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Receipt;
use Greenter\Model\TimeZonePe;
use Greenter\Parser\DocumentParserInterface;
use Greenter\Xml\XmlReader;

/**
 * Class ReceiptParser
 * @package Greenter\Xml\Parser
 */
class ReceiptParser implements DocumentParserInterface
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
     * Parse document.
     *
     * @param mixed $value
     * @return DocumentInterface
     * @throws Exception
     */
    public function parse($value): ?DocumentInterface
    {
        $this->reader = $this->load($value);
        $xml = $this->reader;
        $root = $this->rootNode = $xml->getXpath()->document->documentElement;

        $timezone = new DateTimeZone(TimeZonePe::DEFAULT);
        $receipt = new Receipt();
        $docFac = explode('-', $xml->getValue('cbc:ID', $root));
        $receipt->setSerie($docFac[0])
            ->setCorrelativo($docFac[1])
            ->setFechaEmision(new DateTime($xml->getValue('cbc:IssueDate', $root), $timezone))
            ->setMontoLetras($xml->getValue('cbc:Note', $root))
            ->setPerson($this->getPerson())
            ->setReceptor($this->getClient());

        $monetaryTotal = $xml->getNode('cac:LegalMonetaryTotal', $root);
        $receipt->setSubTotal((float)$xml->getValue('cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount', $root, '0'))
            ->setRetencion((float)$xml->getValue('cbc:TaxExclusiveAmount', $monetaryTotal, '0'))
            ->setTotal((float)$xml->getValue('cbc:PayableAmount', $monetaryTotal, '0'));

        $this->loadFromDetail($receipt);

        return $receipt;
    }

    private function getClient(): Client
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:AccountingCustomerParty', $this->rootNode);

        $cl = new Client();
        $cl->setNumDoc($xml->getValue('cbc:CustomerAssignedAccountID', $node))
            ->setTipoDoc($xml->getValue('cbc:AdditionalAccountID', $node))
            ->setRznSocial($xml->getValue('cac:Party/cac:PartyName/cbc:Name', $node))
            ->setAddress($this->getAddress($node));

        return $cl;
    }

    private function getPerson(): Company
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:AccountingSupplierParty', $this->rootNode);

        $cl = new Company();
        $cl->setRuc($xml->getValue('cbc:CustomerAssignedAccountID', $node))
            ->setRazonSocial($xml->getValue('cac:Party/cac:PartyName/cbc:Name', $node))
            ->setAddress($this->getAddress($node));

        return $cl;
    }

    private function getAddress(?DOMElement $node): Address
    {
        $xml = $this->reader;
        $address = new Address();
        $address->setDireccion($xml->getValue('cac:Party/cac:PostalAddress/cbc:StreetName', $node));

        return $address;
    }

    private function loadFromDetail(Receipt $receipt): void
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:InvoiceLine', $this->rootNode);

        $receipt
            ->setPorcentaje((float)$xml->getValue('cac:TaxTotal/cac:TaxSubtotal/cbc:Percent', $node, '0'))
            ->setConcepto($xml->getValue('cac:Item/cbc:Description', $node));
    }
}
