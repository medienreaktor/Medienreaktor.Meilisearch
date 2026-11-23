<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\IndexPathService;
use PHPUnit\Framework\TestCase;

/**
 * A document records the path of its parent in `__path` and the paths above that in
 * `__parentPath`, as the 3.x line has indexed them from the start. A filter for
 * "below X" has to look in both.
 */
class IndexPathServiceTest extends TestCase
{
    public function testAPathListsTheAggregateIdsFromTheRootDown(): void
    {
        self::assertSame('/root/site/page', IndexPathService::pathFromAggregateIds(['root', 'site', 'page']));
    }

    public function testBelowMatchesChildrenByPathAndDeeperNodesByParentPath(): void
    {
        self::assertSame(
            '(__parentPath = "/root/site" OR __path = "/root/site")',
            IndexPathService::belowFilterForPath('/root/site')
        );
    }

    public function testBelowEscapesQuotesInThePath(): void
    {
        self::assertSame(
            '(__parentPath = "/a\\"b" OR __path = "/a\\"b")',
            IndexPathService::belowFilterForPath('/a"b')
        );
    }
}
