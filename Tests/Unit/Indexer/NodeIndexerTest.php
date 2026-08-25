<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use Medienreaktor\Meilisearch\Exception;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Neos splits the node contract over two unrelated interfaces — properties, context
 * and visibility on Model\NodeInterface, traversal and the aggregate identifier on
 * TraversableNodeInterface — and nothing but the concrete Node implements both. The
 * indexer needs both halves, so it checks instead of assuming. These cases pin that
 * check, including the failure it exists to produce.
 */
class NodeIndexerTest extends TestCase
{
    private NodeIndexer $nodeIndexer;

    protected function setUp(): void
    {
        // requireTraversable() reads none of the injected collaborators.
        $this->nodeIndexer = new NodeIndexer();
    }

    public function testAcceptsTheConcreteNodeThatImplementsBothInterfaces(): void
    {
        $node = $this->createMock(Node::class);

        // Guards the premise rather than the guard: if Neos ever splits these apart,
        // this fails here rather than somewhere deep in an index run.
        self::assertInstanceOf(NodeInterface::class, $node);
        self::assertInstanceOf(TraversableNodeInterface::class, $node);

        self::assertSame($node, $this->nodeIndexer->requireTraversable($node));
    }

    public function testRejectsANodeMissingTheTraversalInterface(): void
    {
        $this->expectException(Exception::class);

        $this->nodeIndexer->requireTraversable($this->createMock(NodeInterface::class));
    }

    public function testRejectsSomethingThatIsNotANodeAtAll(): void
    {
        $this->expectException(Exception::class);

        $this->nodeIndexer->requireTraversable(new \stdClass());
    }

    public function testNamesTheTypeItWasGivenSoFailuresAreDiagnosable(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/got NULL\.$/');

        $this->nodeIndexer->requireTraversable(null);
    }
}
