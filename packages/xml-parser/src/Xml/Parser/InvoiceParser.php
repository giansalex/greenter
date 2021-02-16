<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 05/10/2017
 * Time: 08:14
 */

declare(strict_types=1);

namespace Greenter\Xml\Parser;

use DateTime;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\Document;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Prepayment;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\Parser\DocumentParserInterface;

/**
 * Class InvoiceParser.
 * @package Greenter\Xml\Parser
 */
class InvoiceParser implements DocumentParserInterface
{
    /**
     * @param mixed $value
     * @return DocumentInterface
     */
    public function parse($value): ?DocumentInterface
    {
        $xpt = $this->getXpath($value);
        $inv = new Invoice();

        $docFac = explode('-', $this->defValue($xpt->query('/xt:Invoice/cbc:ID')));
        $inv->setUblVersion($this->defValue($xpt->query('/xt:Invoice/cbc:UBLVersionID')))
            ->setSerie($docFac[0])
            ->setCorrelativo($docFac[1])
            ->setTipoDoc($this->defValue($xpt->query('/xt:Invoice/cbc:InvoiceTypeCode')))
            ->setTipoMoneda($this->defValue($xpt->query('/xt:Invoice/cbc:DocumentCurrencyCode')))
            ->setFechaEmision(new DateTime($this->defValue($xpt->query('/xt:Invoice/cbc:IssueDate'))))
            ->setCompany($this->getCompany($xpt))
            ->setClient($this->getClient($xpt));

        $extensions = $xpt->query('/xt:Invoice/ext:UBLExtensions')->item(0);
        $additional = $xpt->query('//sac:AdditionalInformation', $extensions)->item(0);
        $this->loadTotals($inv, $xpt, $additional);
        $this->loadTributos($inv, $xpt);
        $monetaryTotal = $xpt->query('/xt:Invoice/cac:LegalMonetaryTotal')->item(0);
        $inv->setTipoOperacion($this->defValue($xpt->query('sac:SUNATTransaction/cbc:ID', $additional)))
            ->setSumDsctoGlobal((float)$this->defValue($xpt->query('cbc:AllowanceTotalAmount', $monetaryTotal), '0'))
            ->setTotalAnticipos((float)$this->defValue($xpt->query('cbc:PrepaidAmount', $monetaryTotal), '0'))
            ->setAnticipos(iterator_to_array($this->getPrepayments($xpt)))
            ->setMtoOtrosTributos((float)$this->defValue($xpt->query('cbc:ChargeTotalAmount', $monetaryTotal), '0'))
            ->setMtoImpVenta((float)$this->defValue($xpt->query('cbc:PayableAmount', $monetaryTotal), '0'))
            ->setDetails(iterator_to_array($this->getDetails($xpt)))
            ->setLegends(iterator_to_array($this->getLegends($xpt, $additional)));
        $this->loadExtras($xpt, $inv);

        return $inv;
    }

    private function getXpath($value)
    {
        $doc = $value;
        if (!($value instanceof DOMDocument)) {
            $doc = new DOMDocument();
            @$doc->loadXML($value);
        }

        $rootNamespace = $doc->documentElement->namespaceURI;
        $xpt = new DOMXPath($doc);
        $xpt->registerNamespace('xt', $rootNamespace);

        return $xpt;
    }

    private function defValue(DOMNodeList $nodeList, string $default = '')
    {
        if ($nodeList->length == 0) {
            return $default;
        }

        return $nodeList->item(0)->nodeValue;
    }

    private function defNodeAttribute(DOMNodeList $nodeList, string $attribute, string $default = '')
    {
        if ($nodeList->length == 0) {
            return $default;
        }

        $node = $nodeList->item(0);
        if (!$node instanceof DOMElement) {
            return $default;
        }

        return $node->getAttribute($attribute);
    }

    private function loadTotals(Invoice $inv, DOMXPath $xpt, DOMNode $node = null)
    {
        if (empty($node)) {
            return;
        }

        $totals = $xpt->query('sac:AdditionalMonetaryTotal', $node);
        foreach ($totals as $total) {
            /**@var $total DOMElement*/
            $id = trim($this->defValue($xpt->query('cbc:ID', $total)));
            $val = (float)$this->defValue($xpt->query('cbc:PayableAmount', $total), '0');
            switch ($id) {
                case '1001':
                    $inv->setMtoOperGravadas($val);
                    break;
                case '1002':
                    $inv->setMtoOperInafectas($val);
                    break;
                case '1003':
                    $inv->setMtoOperExoneradas($val);
                    break;
                case '1004':
                    $inv->setMtoOperGratuitas($val);
                    break;
                case '2001':
                    $inv->setPerception((new SalePerception())
                        ->setCodReg($this->defNodeAttribute($xpt->query('cbc:ID', $total), 'schemeID'))
                        ->setMto($val)
                        ->setMtoBase((float)$this->defValue($xpt->query('sac:ReferenceAmount', $total), '0'))
                        ->setMtoTotal((float)$this->defValue($xpt->query('sac:TotalAmount', $total), '0')));
                    break;
                case '2003':
                    $inv->setDetraccion((new Detraction())
                        ->setMount($val)
                        ->setPercent((float)$this->defValue($xpt->query('cbc:Percent', $total), '0'))
                        ->setValueRef((float)$this->defValue($xpt->query('sac:ReferenceAmount', $total), '0')));
                    break;
                case '2005':
                    $inv->setMtoDescuentos($val);
                    break;
            }
        }
    }

    private function loadTributos(Invoice $inv, DOMXPath $xpt)
    {
        $taxs = $xpt->query('/xt:Invoice/cac:TaxTotal');
        foreach ($taxs as $tax) {
            $name = $this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = (float)$this->defValue($xpt->query('cbc:TaxAmount', $tax), '0');
            switch ($name) {
                case 'IGV':
                    $inv->setMtoIGV($val);
                    break;
                case 'ISC':
                    $inv->setMtoISC($val);
                    break;
                case 'OTROS':
                    $inv->setSumOtrosCargos($val);
                    break;
            }
        }
    }

    private function getPrepayments(DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:PrepaidPayment');
        if ($nodes->length == 0) {
            return;
        }
        foreach ($nodes as $node) {
            yield (new Prepayment())
                ->setTotal((float)$this->defValue($xpt->query('cbc:PaidAmount', $node), '0'))
                ->setTipoDocRel($this->defNodeAttribute($xpt->query('cbc:ID', $node), 'schemeID'))
                ->setNroDocRel($this->defValue($xpt->query('cbc:ID', $node)));
        }
    }

    private function getLegends(DOMXPath $xpt, DOMNode $node = null)
    {
        if (empty($node)) {
            return;
        }

        $legends = $xpt->query('sac:AdditionalProperty', $node);
        foreach ($legends as $legend) {
            /**@var $legend DOMElement*/
            yield (new Legend())
                ->setCode($this->defValue($xpt->query('cbc:ID', $legend)))
                ->setValue($this->defValue($xpt->query('cbc:Value', $legend)));
        }
    }

    private function getClient(DOMXPath $xp)
    {
        $node = $xp->query('/xt:Invoice/cac:AccountingCustomerParty')->item(0);

        $cl = new Client();
        $cl->setNumDoc($this->defValue($xp->query('cbc:CustomerAssignedAccountID', $node)))
            ->setTipoDoc($this->defValue($xp->query('cbc:AdditionalAccountID', $node)))
            ->setRznSocial($this->defValue($xp->query('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $node));

        return $cl;
    }

    private function getCompany(DOMXPath $xp)
    {
        $node = $xp->query('/xt:Invoice/cac:AccountingSupplierParty')->item(0);

        $cl = new Company();
        $cl->setRuc($this->defValue($xp->query('cbc:CustomerAssignedAccountID', $node)))
            ->setNombreComercial($this->defValue($xp->query('cac:Party/cac:PartyName/cbc:Name', $node)))
            ->setRazonSocial($this->defValue($xp->query('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $node));

        return $cl;
    }

    private function loadExtras(DOMXPath $xpt, Invoice $inv)
    {
        $inv->setCompra($this->defValue($xpt->query('/xt:Invoice/cac:OrderReference/cbc:ID')));
        $fecVen = $this->defValue($xpt->query('/xt:Invoice/cac:PaymentMeans/cbc:PaymentDueDate'));
        if (!empty($fecVen)) {
            $inv->setFecVencimiento(new DateTime($fecVen));
        }

        $inv->setGuias(iterator_to_array($this->getGuias($xpt)));
    }

    private function getGuias(DOMXPath $xpt)
    {
        $guias = $xpt->query('/xt:Invoice/cac:DespatchDocumentReference');
        if ($guias->length == 0) {
            return;
        }

        foreach ($guias as $guia) {
            $item = new Document();
            $item->setTipoDoc($this->defValue($xpt->query('cbc:DocumentTypeCode', $guia)));
            $item->setNroDoc($this->defValue($xpt->query('cbc:ID', $guia)));

            yield $item;
        }
    }

    /**
     * @param DOMXPath $xp
     * @param DOMNode $node
     * @return Address|null
     */
    private function getAddress(DOMXPath $xp, $node)
    {
        $nAd = $xp->query('cac:Party/cac:PostalAddress', $node);
        if ($nAd->length > 0) {
            $address = $nAd->item(0);

            return (new Address())
                ->setDireccion($this->defValue($xp->query('cbc:StreetName', $address)))
                ->setDepartamento($this->defValue($xp->query('cbc:CityName', $address)))
                ->setProvincia($this->defValue($xp->query('cbc:CountrySubentity', $address)))
                ->setDistrito($this->defValue($xp->query('cbc:District', $address)))
                ->setUbigueo($this->defValue($xp->query('cbc:ID', $address)));
        }

        return null;
    }

    private function getDetails(DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:InvoiceLine');

        foreach ($nodes as $node) {
            $det = new SaleDetail();
            $det->setCantidad((float)$this->defValue($xpt->query('cbc:InvoicedQuantity', $node), '0'))
                ->setUnidad($this->defNodeAttribute($xpt->query('cbc:InvoicedQuantity', $node), 'unitCode'))
                ->setMtoValorVenta((float)$this->defValue($xpt->query('cbc:LineExtensionAmount', $node)))
                ->setMtoValorUnitario((float)$this->defValue($xpt->query('cac:Price/cbc:PriceAmount', $node)))
                ->setDescripcion($this->defValue($xpt->query('cac:Item/cbc:Description', $node)))
                ->setCodProducto($this->defValue($xpt->query('cac:Item/cac:SellersItemIdentification/cbc:ID', $node)))
                ->setCodProdSunat($this->defValue($xpt->query('cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $node)));

            $this->loadTaxDetail($det, $xpt, $node);
            $this->loadDescuentosDetail($det, $xpt, $node);
            $this->loadPricesDetail($det, $xpt, $node);

            yield $det;
        }
    }

    private function loadTaxDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $taxs = $xpt->query('cac:TaxTotal', $detailNode);
        foreach ($taxs as $tax) {
            $name = $this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = (float)$this->defValue($xpt->query('cbc:TaxAmount', $tax), '0');
            switch ($name) {
                case 'IGV':
                    $detail->setIgv($val)
                           ->setTipAfeIgv($this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode', $tax)));
                    break;
                case 'ISC':
                    $detail->setIsc($val)
                           ->setTipSisIsc($this->defValue($xpt->query('cac:TaxSubtotal/cac:TaxCategory/cbc:TierRange', $tax)));
                    break;
            }
        }
    }

    private function loadDescuentosDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $descs = $xpt->query('cac:AllowanceCharge', $detailNode);
        foreach ($descs as $desc) {
            $charge = $this->defValue($xpt->query('cbc:ChargeIndicator', $desc));
            $charge = trim($charge);
            if ($charge == 'false') {
                $val = (float)$this->defValue($xpt->query('cbc:Amount', $desc), '0');
                $detail->setDescuento($val);
            }
        }
    }

    private function loadPricesDetail(SaleDetail $detail, DOMXPath $xpt, DOMNode $detailNode)
    {
        $prices = $xpt->query('cac:PricingReference', $detailNode);
        foreach ($prices as $price) {
            $code = $this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceTypeCode', $price));
            $value = (float)$this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceAmount', $price), '0');
            $code = trim($code);

            switch ($code) {
                case '01':
                    $detail->setMtoPrecioUnitario($value);
                    break;
                case '02':
                    $detail->setMtoValorGratuito($value);
                    break;
            }
        }
    }
}
