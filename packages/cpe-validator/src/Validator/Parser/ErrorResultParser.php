<?php

declare(strict_types=1);

namespace Greenter\Validator\Parser;

use Greenter\Validator\Entity\CpeError;
use Greenter\Validator\Entity\ErrorLevel;

class ErrorResultParser implements ResultParserInterface
{
    public function parse(?string $raw): ?CpeError
    {
        $parts = explode('|', $raw);

        $len = count($parts);
        if ($len < 3) {
            return (new CpeError())
                ->setCode('-1')
                ->setMessage($raw);
        }

        $error = (new CpeError())
            ->setLevel($parts[0] === '1' ? ErrorLevel::EXCEPTION : ErrorLevel::OBSERVATION)
            ->setCode($parts[1])
            ->setMessage($parts[2]);

        if ($len >= 5) {
            $error->setNodePath($parts[3]);
            $error->setNodeValue($parts[4]);
        }

        return $error;
    }
}
