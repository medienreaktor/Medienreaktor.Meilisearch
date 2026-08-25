<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use PHPUnit\Framework\TestCase;

/**
 * The hash this service produces is the identity of every indexed document: it is
 * baked into the document id and into the `__dimensionsHash` filter that each query
 * carries. Changing it silently invalidates every existing index — documents stay in
 * place but no query matches them any more, and only a full reindex repairs it.
 *
 * These cases therefore pin the produced values, not just the behaviour.
 */
class DimensionsServiceTest extends TestCase
{
    private DimensionsService $dimensionsService;

    protected function setUp(): void
    {
        // hash() reads no collaborators, so the service needs no container here.
        $this->dimensionsService = new DimensionsService();
    }

    public function testHashOfNoDimensionsIsTheDefaultLiteral(): void
    {
        self::assertSame('default', $this->dimensionsService->hash([]));
    }

    /**
     * The value a single-language Neos 8 site indexes under. Asserted literally
     * because it is what already sits in deployed indexes.
     */
    public function testHashOfASingleDimensionValueMatchesTheDeployedValue(): void
    {
        self::assertSame(
            md5(json_encode(['language' => ['de']])),
            $this->dimensionsService->hash(['language' => ['de']])
        );
        self::assertSame(
            'fb11fdde869d0a8fcfe00a2fd35c031d',
            $this->dimensionsService->hash(['language' => ['de']])
        );
    }

    /**
     * A dimension value arrives as its fallback chain. Only the primary value
     * identifies the variant, so the chain collapses to its first entry — which is
     * why adding a fallback to an existing dimension does not move the hash.
     */
    public function testFallbackChainCollapsesToItsPrimaryValue(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['language' => ['de']]),
            $this->dimensionsService->hash(['language' => ['de', 'en']])
        );
    }

    public function testHashIsIndependentOfDimensionOrder(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['country' => ['at'], 'language' => ['de']]),
            $this->dimensionsService->hash(['language' => ['de'], 'country' => ['at']])
        );
    }

    public function testDistinctDimensionValuesHashDifferently(): void
    {
        self::assertNotSame(
            $this->dimensionsService->hash(['language' => ['de']]),
            $this->dimensionsService->hash(['language' => ['en']])
        );
    }

    /**
     * The indexer uses this to decide which dimension combinations a node shines
     * through into. Getting it wrong does not fail loudly — it indexes a variant under
     * the wrong dimension, or fails to index one at all.
     */
    public function testACombinationMatchesTheNodesOwnDimension(): void
    {
        self::assertTrue($this->dimensionsService->combinationFallsBackTo(
            ['language' => ['de']],
            ['language' => ['de']]
        ));
    }

    public function testACombinationInAnUnrelatedValueDoesNotMatch(): void
    {
        self::assertFalse($this->dimensionsService->combinationFallsBackTo(
            ['language' => ['de']],
            ['language' => ['en']]
        ));
    }

    /**
     * Shine-through: an English context that falls back to German carries German
     * content, so a German node belongs in that combination too.
     */
    public function testACombinationWhoseFallbackChainReachesTheNodeMatches(): void
    {
        self::assertTrue($this->dimensionsService->combinationFallsBackTo(
            ['language' => ['de']],
            ['language' => ['en', 'de']]
        ));
    }

    public function testItWorksForADimensionThatIsNotCalledLanguage(): void
    {
        self::assertTrue($this->dimensionsService->combinationFallsBackTo(
            ['country' => ['at']],
            ['country' => ['at']]
        ));
        self::assertFalse($this->dimensionsService->combinationFallsBackTo(
            ['country' => ['at']],
            ['country' => ['ch']]
        ));
    }

    public function testEveryDimensionOfTheCombinationHasToMatch(): void
    {
        self::assertFalse($this->dimensionsService->combinationFallsBackTo(
            ['language' => ['de'], 'country' => ['at']],
            ['language' => ['de'], 'country' => ['ch']]
        ));
    }

    /**
     * A site without dimensions produces a single empty combination — getAllAllowed-
     * Combinations() returns [[]] — and every node belongs to it.
     */
    public function testTheEmptyCombinationOfADimensionlessSiteMatches(): void
    {
        self::assertTrue($this->dimensionsService->combinationFallsBackTo([], []));
    }
}
