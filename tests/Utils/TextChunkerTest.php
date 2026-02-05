<?php

declare(strict_types=1);

namespace App\Tests\Utils;

use App\Utils\TextChunker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    private const string ROW_SEP = ' <||ROW_SEP||> ';
    private const string COL_SEP = ' <||SEP||> ';

    #[Test]
    public function itReturnsTextAsIsIfSmallerThanMaxLen(): void
    {
        $text = 'Short text';
        $chunks = TextChunker::splitTextSafely($text, 100);

        $this->assertCount(1, $chunks);
        $this->assertSame($text, $chunks[0]);
    }

    #[Test]
    public function itSplitsAtRowSeparator(): void
    {
        // 10 chars + SEP (15 chars) + 10 chars
        // Total 35 chars. Max len 20.
        // Should split after SEP.
        $part1 = 'First_Part'; // 10
        $part2 = 'SecondPart'; // 10
        $text = $part1.self::ROW_SEP.$part2;

        // SEP is ' <||ROW_SEP||> ' (15 chars)
        // 10 + 15 = 25 chars.
        // If Max is 25, it fits? No, max is chunk size.
        // Let's set MaxLen to 26 so first part + sep fits comfortably.
        // Wait, lookback is 100 in the code.
        // If maxLen is small (e.g. 20), lookback of 100 goes to 0.

        $maxLen = 26;
        $chunks = TextChunker::splitTextSafely($text, $maxLen, self::ROW_SEP, self::COL_SEP);

        // Expected:
        // Chunk 1: "First_Part <||ROW_SEP||> " (Length 25)
        // Chunk 2: "SecondPart"

        $this->assertCount(2, $chunks);
        $this->assertSame($part1.self::ROW_SEP, $chunks[0]);
        $this->assertSame($part2, $chunks[1]);
    }

    #[Test]
    public function itSplitsAtColumnSeparatorIfNoRowSeparator(): void
    {
        $part1 = 'Col1';
        $part2 = 'Col2';
        $text = $part1.self::COL_SEP.$part2;

        // Sep length 11.
        // "Col1 <||SEP||> " = 4 + 11 = 15 chars.
        // MaxLen 16.
        $maxLen = 16;

        $chunks = TextChunker::splitTextSafely($text, $maxLen, self::ROW_SEP, self::COL_SEP);

        $this->assertCount(2, $chunks);
        $this->assertSame($part1.self::COL_SEP, $chunks[0]);
        $this->assertSame($part2, $chunks[1]);
    }

    #[Test]
    public function itSplitsAtWhitespaceIfNoSeparators(): void
    {
        // "Hello World Again"
        // MaxLen 11.
        // "Hello World" is 11 chars.
        // Should split after "World" (space is at 11? No "Hello World" is 11).
        // Wait, "Hello World" is 11.
        // If limit is 11.
        // Target is 11. Lookback finds space at 5 ("Hello ").
        // So expected split: "Hello " and "World Again".

        $text = 'Hello World Again';
        $maxLen = 8; // "Hello Wo" -> cut at "Hello "

        $chunks = TextChunker::splitTextSafely($text, $maxLen);

        $this->assertCount(3, $chunks);
        $this->assertSame('Hello ', $chunks[0]);
        $this->assertSame('World ', $chunks[1]);
        $this->assertSame('Again', $chunks[2]);
    }

    #[Test]
    public function itHardSplitsIfNoSafeBoundaryFound(): void
    {
        $text = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxLen = 10;

        $chunks = TextChunker::splitTextSafely($text, $maxLen);

        // Should be ABCDEFGHIJ, KLMNOPQRST, UVWXYZ
        $this->assertCount(3, $chunks);
        $this->assertSame('ABCDEFGHIJ', $chunks[0]);
        $this->assertSame('KLMNOPQRST', $chunks[1]);
        $this->assertSame('UVWXYZ', $chunks[2]);
    }

    #[Test]
    public function itPrioritizesRowOverColOverSpace(): void
    {
        // Construct text with all 3 in the lookback window
        // safe window
        $text = 'Data'.self::COL_SEP.' '.self::ROW_SEP.'End';
        //       4    + 11             + 1 + 15              + 3 = 34 chars approx

        // Set maxLen such that all are within reach.
        // "Data <||SEP||>  <||ROW_SEP||> " = 4+11+1+15 = 31 chars.
        // MaxLen 32.

        // It should take the ROW_SEP because it's priority 1, and it's last?
        // The code uses `mb_strrpos` (last occurrence).
        // Row Sep is last in string.
        // So it naturally wins if it fits.

        // Let's invert order to test priority.
        // "Data" . ROW_SEP . " " . COL_SEP . "End"
        // If we split at COL_SEP, we cut late.
        // If we split at ROW_SEP, we cut early.
        // Logic: find LAST occurrence of Priority 1.
        // If found, use it.
        // If not, find LAST occurrence of Priority 2.

        $text = 'Data'.self::ROW_SEP.'Mid'.self::COL_SEP.'End';
        // ROW_SEP at index 4.
        // COL_SEP at index 4+15+3 = 22.
        // Full string len = 22 + 11 + 3 = 36.

        // MaxLen 35.
        // Window includes both.
        // Code checks ROW_SEP first. `mb_strrpos` finds it at 4.
        // Code checks COL_SEP (only if ROW_SEP not found? NO).
        // Code: if (false !== $pos) { splitOffset = ... } else { check ColSep }
        // So ROW_SEP takes precedence IF IT EXISTS in the window.
        // Wait, if ROW_SEP exists at index 4, and COL_SEP at 22...
        // And window covers 0 to 35.
        // Logic says: `pos = mb_strrpos($window, ROW_SEP)`. It finds index 4.
        // So it splits at 4 + len.
        // It IGNORIES COL_SEP at 22!
        // So it creates a small chunk: "Data <||ROW_SEP||> ".
        // And next chunk starts with "Mid...".
        // This is arguably correct ("Row separator is the ultimate boundary").

        $maxLen = 35;
        $chunks = TextChunker::splitTextSafely($text, $maxLen, self::ROW_SEP, self::COL_SEP);

        $this->assertEquals('Data'.self::ROW_SEP, $chunks[0]);
        // Rest is "Mid <||SEP||> End"
        $this->assertEquals('Mid'.self::COL_SEP.'End', $chunks[1]);
    }
}
