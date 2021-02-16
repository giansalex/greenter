<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 28/01/2018
 * Time: 13:04
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DateTimeZone;
use DOMElement;
use DOMNode;
use Exception;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Summary\SummaryPerception;
use Greenter\Model\TimeZonePe;
use Greenter\Parser\DocumentParserInterface;
use Greenter\Xml\XmlReader;
use Iterator;

/**
 * Class SummaryParser
 * @package Greenter\Xml\Parser
 */
class SummaryParser implements DocumentParserInterface
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
     *
     * @return DocumentInterface
     * @throws Exception
     */
    public function parse($value): ?DocumentInterface
    {
        $this->reader = $this->load($value);
        $xml = $this->reader;
        $root = $this->rootNode = $xml->getXpath()->document->documentElement;

        $timeZone = new DateTimeZone(TimeZonePe::DEFAULT);
        $id = explode('-', $xml->getValue('cbc:ID', $root));
        $summary = new Summary();
        $summary->setCorrelativo($id[2])
            ->setFecGeneracion(new DateTime($xml->getValue('cbc:ReferenceDate', $root), $timeZone))
            ->setFecResumen(new DateTime($xml->getValue('cbc:IssueDate', $root), $timeZone))
            ->setCompany($this->getCompany())
            ->setDetails(iterator_to_array($this->getDetails()));

        return $summary;
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
        $nodes = $xml->getNodes('sac:SummaryDocumentsLine', $this->rootNode);

        foreach ($nodes as $node) {
            $det = new SummaryDetail();
            $det->setTipoDoc($xml->getValue('cbc:DocumentTypeCode', $node))
                ->setSerieNro($xml->getValue('cbc:ID', $node))
                ->setEstado(trim($xml->getValue('cac:Status/cbc:ConditionCode', $node)))
                ->setClienteTipo(trim($xml->getValue('cac:AccountingCustomerParty/cbc:AdditionalAccountID', $node)))
                ->setClienteNro(trim($xml->getValue('cac:AccountingCustomerParty/cbc:CustomerAssignedAccountID', $node)))
                ->setTotal((float)$xml->getValue('sac:TotalAmount', $node, '0'))
                ->setMtoOtrosCargos((float)$xml->getValue('cac:AllowanceCharge/cbc:Amount', $node, '0'));

            $ref = $xml->getNode('cac:BillingReference', $node);
            if ($ref) {
                $doc = new Document();
                $doc->setTipoDoc(trim($xml->getValue('cac:InvoiceDocumentReference/cbc:DocumentTypeCode', $ref)))
                    ->setNroDoc(trim($xml->getValue('cac:InvoiceDocumentReference/cbc:ID', $ref)));
                $det->setDocReferencia($doc);
            }

            $this->loadPerception($det, $node);
            $this->loadTotals($det, $node);
            $this->loadTaxs($det, $node);

            yield $det;
        }
    }

    private function loadPerception(SummaryDetail $detail, ?DOMNode $node): void
    {
        $xml = $this->reader;
        $ref = $xml->getNode('sac:SUNATPerceptionSummaryDocumentReference', $node);
        if ($ref === null) {
            return;
        }

        $perc = new SummaryPerception();
        $perc->setCodReg(trim($xml->getValue('sac:SUNATPerceptionSystemCode', $ref)))
            ->setTasa((float)$xml->getValue('sac:SUNATPerceptionPercent', $ref, '0'))
            ->setMto((float)$xml->getValue('sac:TotalInvoiceAmount', $ref, '0'))
            ->setMtoTotal((float)$xml->getValue('sac:SUNATTotalCashed', $ref, '0'))
            ->setMtoBase((float)$xml->getValue('sac:TaxableAmount', $ref, '0'));

        $detail->setPercepcion($perc);
    }

    private function loadTotals(SummaryDetail $detail, ?DOMNode $node): void
    {
        $xml = $this->reader;
        $totals = $xml->getNodes('sac:BillingPayment', $node);
        foreach ($totals as $total) {
            /**@var $total \DOMElement*/
            $id = trim($xml->getValue('cbc:InstructionID', $total));
            $val = (float)$xml->getValue('cbc:PaidAmount', $total, '0');
            switch ($id) {
                case '01':
                    $detail->setMtoOperGravadas($val);
                    break;
                case '02':
                    $detail->setMtoOperExoneradas($val);
                    break;
                case '03':
                    $detail->setMtoOperInafectas($val);
                    break;
                case '04':
                    $detail->setMtoOperExportacion($val);
                    break;
                case '05':
                    $detail->setMtoOperGratuitas($val);
                    break;
            }
        }
    }

    private function loadTaxs(SummaryDetail $detail, ?DOMNode $node): void
    {
        $xml = $this->reader;
        $taxs = $xml->getNodes('cac:TaxTotal', $node);
        foreach ($taxs as $tax) {
            $name = trim($xml->getValue('cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = (float)$xml->getValue('cbc:TaxAmount', $tax, '0');
            switch ($name) {
                case 'IGV':
                    $detail->setMtoIGV($val);
                    break;
                case 'IVAP':
                    $detail->setMtoIvap($val);
                    break;
                case 'ISC':
                    $detail->setMtoISC($val);
                    break;
                case 'OTROS':
                    $detail->setMtoOtrosTributos($val);
                    break;
            }
        }
    }
}