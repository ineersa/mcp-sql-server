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
        $part1 = 'First_Part'; // 10
        $part2 = 'SecondPart'; // 10
        $text = $part1.self::ROW_SEP.$part2;

        // maxLen 26 ensures part1 + sep fits (25 chars)
        $maxLen = 26;
        $chunks = TextChunker::splitTextSafely($text, $maxLen, self::ROW_SEP, self::COL_SEP);

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
        $text = 'Hello World Again';
        // "Hello Wo" -> cut at "Hello "
        $maxLen = 8;

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

        $this->assertCount(3, $chunks);
        $this->assertSame('ABCDEFGHIJ', $chunks[0]);
        $this->assertSame('KLMNOPQRST', $chunks[1]);
        $this->assertSame('UVWXYZ', $chunks[2]);
    }

    #[Test]
    public function itPrioritizesRowOverColOverSpace(): void
    {
        $text = 'Data'.self::ROW_SEP.'Mid'.self::COL_SEP.'End';
        // MaxLen large enough to cover Lookback
        $maxLen = 35;
        $chunks = TextChunker::splitTextSafely($text, $maxLen, self::ROW_SEP, self::COL_SEP);

        $this->assertEquals('Data'.self::ROW_SEP, $chunks[0]);
        $this->assertEquals('Mid'.self::COL_SEP.'End', $chunks[1]);
    }

    #[Test]
    public function itReconstructsOriginalStringExactly(): void
    {
        $input = 'Here is a long string that definitely needs splitting because it is quite long and we set a small limit.
It has newlines.
And '.self::ROW_SEP.' separators.
And '.self::COL_SEP.' column separators.
And meaningless words.';

        $maxLen = 20;

        $chunks = TextChunker::splitTextSafely($input, $maxLen, self::ROW_SEP, self::COL_SEP);

        $reconstructed = implode('', $chunks);

        $this->assertSame($input, $reconstructed, 'Reconstructed string should match original exactly');
    }

    #[Test]
    public function itHandlesMultibyteCharactersCorrectly(): void
    {
        // 5 chars: "He" (2) + "llo" (3) -> but "llo" replaced with multibyte.
        // "He" . "llö" ?
        // Let's use simpler: "A" . "😊" . "B"
        // 😊 is 1 char (mb), 4 bytes.

        $text = 'Start😊End';
        // "Start" = 5 chars. "😊" = 1 char. "End" = 3 chars. Total 9 chars.

        $maxLen = 6;
        // Should take "Start😊" (6 chars)?
        // Wait, standard says "Start" (5) + "😊" (1) = 6 chars.
        // If hard split at 6.

        $chunks = TextChunker::splitTextSafely($text, $maxLen);

        // Expected: "Start😊", "End"
        $this->assertCount(2, $chunks);
        $this->assertSame('Start😊', $chunks[0]);
        $this->assertSame('End', $chunks[1]);

        $this->assertSame($text, implode('', $chunks));
    }
}
