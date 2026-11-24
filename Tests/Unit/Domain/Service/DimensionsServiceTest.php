<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

/**
 * The hash this service produces is the identity of every indexed document: it is
 * baked into the document id and into the `__dimensionsHash` filter that each query
 * carries. Changing it silently invalidates every existing index — documents stay in
 * place but no query matches them any more, and only a full reindex repairs it.
 *
 * The indexer hashes dimension space points, queries hash whatever dimensions they
 * were given, so both have to arrive at DimensionSpacePoint::$hash. These cases
 * therefore pin the produced values, not just the behaviour.
 */
class DimensionsServiceTest extends TestCase
{
    private DimensionsService $dimensionsService;

    protected function setUp(): void
    {
        // hash() reads no collaborators, so the service needs no container here.
        $this->dimensionsService = new DimensionsService();
    }

    public function testHashOfADimensionSpacePointIsItsOwnHash(): void
    {
        $dimensionSpacePoint = DimensionSpacePoint::fromArray(['language' => 'de']);

        self::assertSame($dimensionSpacePoint->hash, $this->dimensionsService->hash($dimensionSpacePoint));
    }

    /**
     * The value a single-language site indexes under. Asserted literally, so that a
     * change in how it is computed cannot pass as a refactoring.
     */
    public function testHashOfASingleDimensionValueIsPinned(): void
    {
        self::assertSame('1041cc1fe1030c1a82ac24346f8c69a7', $this->dimensionsService->hash(['language' => 'de']));
        self::assertSame(md5('{"language":"de"}'), $this->dimensionsService->hash(['language' => 'de']));
    }

    public function testHashOfNoDimensionsIsTheHashOfTheEmptyDimensionSpacePoint(): void
    {
        self::assertSame(DimensionSpacePoint::createWithoutDimensions()->hash, $this->dimensionsService->hash([]));
        self::assertSame('d751713988987e9331980363e24189ce', $this->dimensionsService->hash([]));
    }

    /**
     * Coordinates and a dimension space point of the same coordinates have to meet,
     * or a query built from settings would never find what the indexer wrote.
     */
    public function testCoordinatesHashLikeTheirDimensionSpacePoint(): void
    {
        self::assertSame(
            DimensionSpacePoint::fromArray(['language' => 'en', 'country' => 'at'])->hash,
            $this->dimensionsService->hash(['language' => 'en', 'country' => 'at'])
        );
    }

    /**
     * A dimension value in the Neos 8 shape arrives as its fallback chain. Only the
     * primary value identifies the variant, so the chain collapses to its first entry —
     * which is why adding a fallback to an existing dimension does not move the hash.
     */
    public function testAValueListCountsWithItsFirstValue(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['language' => 'de']),
            $this->dimensionsService->hash(['language' => ['de']])
        );
        self::assertSame(
            $this->dimensionsService->hash(['language' => 'de']),
            $this->dimensionsService->hash(['language' => ['de', 'en']])
        );
    }

    public function testADimensionWithAnEmptyValueListIsLeftOut(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['language' => 'de']),
            $this->dimensionsService->hash(['language' => ['de'], 'country' => []])
        );
    }

    public function testHashIsIndependentOfDimensionOrder(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['country' => 'at', 'language' => 'de']),
            $this->dimensionsService->hash(['language' => 'de', 'country' => 'at'])
        );
    }

    public function testDistinctDimensionValuesHashDifferently(): void
    {
        self::assertNotSame(
            $this->dimensionsService->hash(['language' => 'de']),
            $this->dimensionsService->hash(['language' => 'en'])
        );
    }

    /**
     * Coordinates are strings in a dimension space point; a numeric value from YAML
     * must not hash differently from its string.
     */
    public function testANumericValueHashesLikeItsString(): void
    {
        self::assertSame(
            $this->dimensionsService->hash(['year' => '2026']),
            $this->dimensionsService->hash(['year' => 2026])
        );
    }

    /**
     * Content shining through from a fallback is read in the dimension space point it
     * shows up in, and indexed under that one rather than under its origin.
     */
    public function testHashByNodeIsTheHashOfTheDimensionSpacePointTheNodeWasReadIn(): void
    {
        $node = Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::fromArray(['language' => 'en']),
            NodeAggregateId::fromString('node'),
            OriginDimensionSpacePoint::fromArray(['language' => 'de']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Neos.Neos:Document'),
            new PropertyCollection(SerializedPropertyValues::createEmpty(), new PropertyConverter(new Serializer())),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty()
        );

        self::assertSame($this->dimensionsService->hash(['language' => 'en']), $this->dimensionsService->hashByNode($node));
    }
}
