<?php

declare(strict_types=1);

namespace Tests\Greenter\Validator\Parser;

use Greenter\Validator\Entity\ErrorLevel;
use Greenter\Validator\Parser\ErrorResultParser;
use PHPUnit\Framework\TestCase;

class ErrorResultParserTest extends TestCase
{
    /**
     * @var ErrorResultParser
     */
    private $parser;

    protected function setUp(): void
    {
        $this->parser = new ErrorResultParser();
    }

    public function testInvalidParamToParse()
    {
        $message = 'xxx';
        $result = $this->parser->parse($message);

        $this->assertNotNull($result);
        $this->assertEquals('-1', $result->getCode());
        $this->assertEquals($message, $result->getMessage());
    }

    public function testParse()
    {
        $message = '1|1023|INVALID';

        $result = $this->parser->parse($message);

        $this->assertEquals('1023', $result->getCode());
        $this->assertEquals(ErrorLevel::EXCEPTION, $result->getLevel());
        $this->assertNotEmpty($result->getMessage());
        $this->assertNull($result->getNodePath());
        $this->assertNull($result->getNodeValue());
    }

    public function testParseFullNode()
    {
        $message = '2|2000|INVALID|/Invoice/LegalMonetaryTotal/PayableAmount|223.20';

        $result = $this->parser->parse($message);

        $this->assertEquals('2000', $result->getCode());
        $this->assertEquals(ErrorLevel::OBSERVATION, $result->getLevel());
        $this->assertNotEmpty($result->getMessage());
        $this->assertNotEmpty($result->getNodePath());
        $this->assertEquals('223.20', $result->getNodeValue());
    }
}
