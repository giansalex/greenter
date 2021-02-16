<?php
/**
 * Created by PhpStorm.
 * User: Administrador
 * Date: 29/01/2018
 * Time: 01:25 PM
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use Exception;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Perception\Perception;
use Greenter\Model\Perception\PerceptionDetail;
use Greenter\Model\Retention\Exchange;
use Greenter\Model\Retention\Payment;
use Greenter\Model\TimeZonePe;
use Greenter\Parser\DocumentParserInterface;
use Greenter\Xml\XmlReader;

/**
 * Class PerceptionParser
 * @package Greenter\Xml\Parser
 */
class PerceptionParser implements DocumentParserInterface
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
        $idNum = explode('-', $xml->getValue('cbc:ID'));
        $perception = new Perception();
        $perception->setSerie($idNum[0])
            ->setCorrelativo($idNum[1])
            ->setFechaEmision(new DateTime($xml->getValue('cbc:IssueDate'), $this->timezone))
            ->setCompany($this->getCompany())
            ->setProveedor($this->getClient())
            ->setRegimen($xml->getValue('sac:SUNATPerceptionSystemCode'))
            ->setTasa((float)$xml->getValue('sac:SUNATPerceptionPercent', $root, '0'))
            ->setObservacion($xml->getValue('cbc:Note'))
            ->setImpPercibido((float)$xml->getValue('cbc:TotalInvoiceAmount', $root, '0'))
            ->setImpCobrado((float)$xml->getValue('sac:SUNATTotalCashed', $root, '0'))
            ->setDetails(iterator_to_array($this->getDetails()));

        return $perception;
    }

    private function getCompany()
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:AgentParty', $this->rootNode);

        $cl = new Company();
        $cl->setRuc($xml->getValue('cac:PartyIdentification/cbc:ID', $node))
            ->setNombreComercial($xml->getValue('cac:PartyName/cbc:Name', $node))
            ->setRazonSocial($xml->getValue('cac:PartyLegalEntity/cbc:RegistrationName', $node))
            ->setAddress($this->getAddress($node));

        return $cl;
    }

    private function getClient()
    {
        $xml = $this->reader;
        $node = $xml->getNode('cac:ReceiverParty', $this->rootNode);

        $ident = $xml->getNode('cac:PartyIdentification/cbc:ID', $node);
        $client = new Client();
        $client->setNumDoc($ident->nodeValue)
            ->setTipoDoc($ident->getAttribute('schemeID'))
            ->setRznSocial($xml->getValue('cac:PartyLegalEntity/cbc:RegistrationName', $node))
            ->setAddress($this->getAddress($node));

        return $client;
    }

    /**
     * @param DOMElement|null $node
     */
    private function getAddress(?DOMElement $node)
    {
        $xml = $this->reader;

        $address = $xml->getNode('cac:PostalAddress', $node);
        if ($address) {
            return (new Address())
                ->setDireccion($xml->getValue('cbc:StreetName', $address))
                ->setDepartamento($xml->getValue('cbc:CityName', $address))
                ->setProvincia($xml->getValue('cbc:CountrySubentity', $address))
                ->setDistrito($xml->getValue('cbc:District', $address))
                ->setUbigueo($xml->getValue('cbc:ID', $address));
        }

        return null;
    }

    private function getDetails()
    {
        $xml = $this->reader;
        $nodes = $xml->getNodes('sac:SUNATPerceptionDocumentReference', $this->rootNode);

        foreach ($nodes as $node) {
            $temp = $xml->getNode('cbc:ID', $node);
            $mount = $xml->getNode('cbc:TotalInvoiceAmount', $node);

            $det = new PerceptionDetail();
            $det->setTipoDoc($temp->getAttribute('schemeID'))
                ->setNumDoc($temp->nodeValue)
                ->setFechaEmision(new DateTime($xml->getValue('cbc:IssueDate', $node), $this->timezone))
                ->setImpTotal((float)$mount->nodeValue)
                ->setMoneda($mount->getAttribute('currencyID'))
                ->setCobros(iterator_to_array($this->getPayments($node)));

            $temp = $xml->getNode('sac:SUNATPerceptionInformation', $node);
            if (empty($temp)) {
                $det->setImpPercibido(0.00)
                    ->setImpCobrar(0.00);

                yield $det;
                continue;
            }

            $det
                ->setImpPercibido((float)$xml->getValue('sac:SUNATPerceptionAmount', $temp))
                ->setFechaPercepcion(new DateTime($xml->getValue('sac:SUNATPerceptionDate', $temp), $this->timezone))
                ->setImpCobrar((float)$xml->getValue('sac:SUNATNetTotalCashed', $temp));

            $cambio = $xml->getNode('cac:ExchangeRate', $temp);
            if ($cambio) {
                $exc = new Exchange();
                $exc->setMonedaRef($xml->getValue('cbc:SourceCurrencyCode', $cambio))
                    ->setMonedaObj($xml->getValue('cbc:TargetCurrencyCode', $cambio))
                    ->setFactor((float)$xml->getValue('cbc:CalculationRate', $cambio, '0'))
                    ->setFecha(new DateTime($xml->getValue('cbc:Date', $cambio), $this->timezone));
                $det->setTipoCambio($exc);
            }

            yield $det;
        }
    }

    private function getPayments(DOMNode $node)
    {
        $xml = $this->reader;

        $pays = $xml->getNodes('cac:Payment', $node);
        foreach ($pays as $pay) {
            $temp = $xml->getNode('cbc:PaidAmount', $pay);
            $payment = new Payment();
            $payment->setMoneda($temp->getAttribute('currencyID'))
                ->setImporte((float)$temp->nodeValue)
                ->setFecha(new DateTime($xml->getValue('cbc:PaidDate'), $this->timezone));

            yield $payment;
        }
    }
}
