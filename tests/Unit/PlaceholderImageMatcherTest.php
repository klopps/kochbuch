<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Kochbuch\Service\PlaceholderImageMatcher;

final class PlaceholderImageMatcherTest extends TestCase
{
    private function placeholder(string $filename, array $keywords): array
    {
        return ['filename' => $filename, 'keywords' => $keywords];
    }

    public function testMatchesByNameBeforeCheckingTags(): void
    {
        $placeholders = [
            $this->placeholder('cookie.png', [['locale' => 'de', 'keyword' => 'kekse']]),
            $this->placeholder('fish.png', [['locale' => 'de', 'keyword' => 'fisch']]),
        ];

        // Name contains "Kekse"; tags would otherwise match "fisch" first.
        $result = PlaceholderImageMatcher::match('Schokokekse', ['fisch'], $placeholders);

        $this->assertSame('cookie.png', $result);
    }

    public function testNameMatchIsCaseAndUmlautInsensitiveSubstring(): void
    {
        $placeholders = [$this->placeholder('cake.png', [['locale' => 'de', 'keyword' => 'kuchen']])];

        $this->assertSame('cake.png', PlaceholderImageMatcher::match('KÄSEKUCHEN', [], $placeholders));
    }

    public function testFallsBackToTagsWhenNameDoesNotMatch(): void
    {
        $placeholders = [$this->placeholder('fish.png', [['locale' => 'en', 'keyword' => 'fish']])];

        $result = PlaceholderImageMatcher::match('Dinner', ['fish'], $placeholders);

        $this->assertSame('fish.png', $result);
    }

    public function testUsesTheFirstTagInOrderThatMatchesAnything(): void
    {
        $placeholders = [
            $this->placeholder('cookie.png', [['locale' => 'de', 'keyword' => 'kekse']]),
            $this->placeholder('fish.png', [['locale' => 'de', 'keyword' => 'fisch']]),
        ];

        // Both tags match something, but "fisch" comes first, so its
        // placeholder wins even though "kekse" would also match.
        $result = PlaceholderImageMatcher::match('Untitled', ['fisch', 'kekse'], $placeholders);

        $this->assertSame('fish.png', $result);
    }

    public function testPrefersTheLongerMoreSpecificKeywordOverAShorterSubstringMatch(): void
    {
        $placeholders = [
            // "eis" is a substring of "Reisbratling" too - the shorter,
            // unrelated keyword must not win just because it happens to be
            // checked first (insertion order here is deliberately the
            // "wrong" way round, to prove length - not order - decides).
            $this->placeholder('ice-cream.png', [['locale' => 'de', 'keyword' => 'eis']]),
            $this->placeholder('rice.png', [['locale' => 'de', 'keyword' => 'Reis']]),
        ];

        $this->assertSame('rice.png', PlaceholderImageMatcher::match('Reisbratling', [], $placeholders));
    }

    public function testLongestKeywordPriorityAlsoAppliesWhenMatchingTags(): void
    {
        $placeholders = [
            $this->placeholder('ice-cream.png', [['locale' => 'de', 'keyword' => 'eis']]),
            $this->placeholder('rice.png', [['locale' => 'de', 'keyword' => 'Reis']]),
        ];

        $result = PlaceholderImageMatcher::match('Untitled', ['Reisbratling'], $placeholders);

        $this->assertSame('rice.png', $result);
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $placeholders = [$this->placeholder('cookie.png', [['locale' => 'de', 'keyword' => 'kekse']])];

        $this->assertNull(PlaceholderImageMatcher::match('Salat', ['gemuese'], $placeholders));
    }

    public function testEmptyKeywordNeverMatches(): void
    {
        $placeholders = [$this->placeholder('cookie.png', [['locale' => 'de', 'keyword' => '']])];

        $this->assertNull(PlaceholderImageMatcher::match('Anything', [], $placeholders));
    }
}
