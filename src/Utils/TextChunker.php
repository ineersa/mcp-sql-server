<?php

declare(strict_types=1);

namespace App\Utils;

class TextChunker
{
    /**
     * Split text into chunks, trying to break at safe boundaries.
     *
     * Lookback up to 100 chars to find:
     * 1. Row separator
     * 2. Column separator
     * 3. Whitespace
     *
     * @param string $text   The text to split
     * @param int    $maxLen Maximum length of each chunk
     * @param string $rowSep Row separator (priority 1)
     * @param string $colSep Column separator (priority 2)
     *
     * @return list<string>
     */
    public static function splitTextSafely(
        string $text,
        int $maxLen,
        string $rowSep = ' <||ROW_SEP||> ',
        string $colSep = ' <||SEP||> ',
    ): array {
        $chunks = [];
        $len = mb_strlen($text);
        $start = 0;

        while ($start < $len) {
            $remaining = $len - $start;

            // If remaining fits, just take it
            if ($remaining <= $maxLen) {
                $chunks[] = mb_substr($text, $start);
                break;
            }

            // Target splitting point
            $target = $start + $maxLen;

            // Define lookback window
            $lookbackAmount = 100;
            // Ensure we don't look back past start
            $searchStart = max($start, $target - $lookbackAmount);
            $searchLen = $target - $searchStart;

            // If search window is tiny or invalid, fallback to hard split
            if ($searchLen <= 0) {
                $chunks[] = mb_substr($text, $start, $maxLen);
                $start += $maxLen;
                continue;
            }

            $window = mb_substr($text, $searchStart, $searchLen);
            $splitOffset = null;

            // Priority 1: Row Separator
            $pos = mb_strrpos($window, $rowSep);
            if (false !== $pos) {
                // Split AFTER the separator to keep it attached to the previous chunk?
                // OR include it. The previous logic included it by adding length.
                $splitOffset = $searchStart + $pos + mb_strlen($rowSep);
            } else {
                // Priority 2: Column Separator
                $pos = mb_strrpos($window, $colSep);
                if (false !== $pos) {
                    $splitOffset = $searchStart + $pos + mb_strlen($colSep);
                } else {
                    // Priority 3: Whitespace
                    $lastSpace = false;
                    foreach ([' ', "\n", "\t", "\r"] as $char) {
                        $p = mb_strrpos($window, $char);
                        if (false !== $p && (false === $lastSpace || $p > $lastSpace)) {
                            $lastSpace = $p;
                        }
                    }
                    if (false !== $lastSpace) {
                        $splitOffset = $searchStart + $lastSpace + 1; // Include space
                    }
                }
            }

            if (null !== $splitOffset) {
                // Determine length to cut
                $cutLength = $splitOffset - $start;
                // Determine next start
                $chunks[] = mb_substr($text, $start, $cutLength);
                $start = $splitOffset;
            } else {
                // Fallback: Hard split at maxLen
                $chunks[] = mb_substr($text, $start, $maxLen);
                $start += $maxLen;
            }
        }

        return $chunks;
    }
}
