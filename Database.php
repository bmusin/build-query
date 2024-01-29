<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $FAIL = function () { throw new Exception('Failure.'); };

        $SPEC_DGT = '?d'; // целое число
        $SPEC_FLT = '?f'; // число с плавающей точкой
        $SPEC_ARR = '?a'; // массив (список/словарь)
        $SPEC_IDS = '?#'; // идентификатор(-ы)
        $SPEC_NOS = '? '; // не указан тип спецификатора

        $SPEC_TYPE_DGT = 'SPEC_TYPE_DIGIT';
        $SPEC_TYPE_FLT = 'SPEC_TYPE_FLOAT';
        $SPEC_TYPE_ARR = 'SPEC_TYPE_ARRAY';
        $SPEC_TYPE_IDS = 'SPEC_TYPE_IDS';
        $SPEC_TYPE_NOS = 'SPEC_TYPE_NOS';
        $SPEC_TYPE_NO_TYPE = 'NO TYPE';

        $result = $query;
        $arg2formatted = function ($arg, $specType = 'NO TYPE') use ($SPEC_TYPE_IDS) {
            $inQuotes = function ($str) { return "'$str'"; };
            $inBackticks = function ($str) { return "`$str`"; };

            $array2arg = function ($argArray, $specType) use ($inQuotes, $inBackticks, $SPEC_TYPE_IDS) {
                $isList = array_is_list($argArray);
                if ($specType === $SPEC_TYPE_IDS) {
                    if ($isList) {
                        $argArray = array_map(function ($arg) use ($inBackticks) { return $inBackticks($arg); }, $argArray);
                        $result = implode(', ', $argArray);
                    } else {
                        $argStrings = [];
                        foreach ($argArray as $argKey => $argValue) {
                            $argType = gettype($argValue);
                            $argValue = $argType === 'NULL' ? 'NULL' : $argValue;
                            $argValue = $argType === 'string' ? $inQuotes($argValue) : $argValue;
                            $argStrings[] = sprintf("`%s` = %s", $argKey, $argValue);
                        }
                        $result = implode(', ', $argStrings);
                    }
                } else { // $specType === $SPEC_TYPE_ARR
                    if ($isList) {
                        $result = is_string($argArray[0])
                            ? implode(
                                ', ',
                                array_map(function ($arg) use ($inQuotes) { return $inQuotes($arg); }, $argArray)
                            )
                            : implode(', ', $argArray)
                        ;
                    } else {
                        $argStrings = [];
                        foreach ($argArray as $argKey => $argValue) {
                            $argType = gettype($argValue);
                            $argValue = $argType === 'NULL' ? 'NULL' : $argValue;
                            $argValue = $argType === 'string' ? $inQuotes($argValue) : $argValue;
                            $argStrings[] = sprintf("`%s` = %s", $argKey, $argValue);
                        }
                        $result = implode(', ', $argStrings);
                    }
                }
                return $result;
            };
            $argType = gettype($arg);
            return match ($argType) {
                'string' => $specType === $SPEC_TYPE_IDS ? $inBackticks($arg) : $inQuotes($arg),
                'integer', 'double' => $arg,
                'NULL' => 'null',
                'boolean' => $arg ? 1 : 0,
                'array' => $array2arg($arg, $specType),
                default => false,
            };
        };

        while (1) {
            $posDgt = strpos($result, $SPEC_DGT);
            $posFlt = strpos($result, $SPEC_FLT);
            $posArr = strpos($result, $SPEC_ARR);
            $posIds = strpos($result, $SPEC_IDS);
            $posNos = strpos($result, $SPEC_NOS);

            $hasSpecs = $posDgt !== false || $posFlt !== false || $posArr !== false || $posIds !== false || $posNos !== false;

            $noArgs = count($args) === 0;
            if ($hasSpecs) {
                if ($noArgs) { $FAIL(); }
            } else {
                if ($noArgs) { break; }
                else { $FAIL(); }
            }

            $pos = strlen($result);
            $posType = $SPEC_TYPE_NO_TYPE;
            if ($posDgt && $posDgt < $pos) { $pos = $posDgt; $posType = $SPEC_TYPE_DGT; }
            if ($posFlt && $posFlt < $pos) { $pos = $posFlt; $posType = $SPEC_TYPE_FLT; }
            if ($posArr && $posArr < $pos) { $pos = $posArr; $posType = $SPEC_TYPE_ARR; }
            if ($posIds && $posIds < $pos) { $pos = $posIds; $posType = $SPEC_TYPE_IDS; }
            if ($posNos && $posNos < $pos) { $pos = $posNos; $posType = $SPEC_TYPE_NOS; }

            $formattedArg = $arg2formatted(array_shift($args), $posType);
            if ($formattedArg === false) { $FAIL(); }

            $replaceFirstSubstr = function ($search, $replace, $subject) {
                $pos = strpos($subject, $search);
                if ($pos !== false) { return substr_replace($subject, $replace, $pos, strlen($search)); }
                return $subject;
            };

            $condStart = '{ AND';
            $condEnd = '}';
            $posBrRight = $pos + strpos(substr($result, $pos), $condEnd);
            $posBrLeft = strpos(substr($result, 0, $pos), $condStart);
            $shouldInclude = false;
            if ($posBrRight && $posBrLeft) {
                if ($formattedArg === $this->skip()) {
                    $result = substr_replace($result, '', $posBrLeft, $posBrRight - $posBrLeft + 1);
                } else {
                    $result = substr_replace(
                        $result,
                        substr($result, $posBrLeft + 1, $posBrRight - $posBrLeft - 1),
                        $posBrLeft,
                        $posBrRight - $posBrLeft + 1
                    );
                    $shouldInclude = true;
                }
            }

            if ($posBrRight && $posBrLeft && !$shouldInclude) {
                continue;
            }
            switch ($posType) {
                case $SPEC_TYPE_DGT: $result = $replaceFirstSubstr($SPEC_DGT, $formattedArg, $result); break;
                case $SPEC_TYPE_FLT: $result = $replaceFirstSubstr($SPEC_FLT, $formattedArg, $result); break;
                case $SPEC_TYPE_ARR: $result = $replaceFirstSubstr($SPEC_ARR, $formattedArg, $result); break;
                case $SPEC_TYPE_IDS: $result = $replaceFirstSubstr($SPEC_IDS, $formattedArg, $result); break;
                case $SPEC_TYPE_NOS: $result = $replaceFirstSubstr('?', $formattedArg, $result); break;
            }
        }
        return $result;
    }

    public function skip()
    {
        return 456;
    }
}
