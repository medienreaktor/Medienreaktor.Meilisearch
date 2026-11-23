<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\IndexPathService;
use PHPUnit\Framework\TestCase;

/**
 * `__parentPath = "<path of X>"` has to find every node below X, the direct children
 * included, and `__path` has to be the node's own path.
 */
class IndexPathServiceTest extends TestCase
{
    public function testThePathEndsWithTheNodeItself(): void
    {
        self::assertSame('/root/site/page', IndexPathService::pathFromAggregateIds(['root', 'site', 'page']));
    }

    public function testParentPathsIncludeTheDirectParent(): void
    {
        self::assertSame(
            ['/', '/root', '/root/site', '/root/site/page'],
            IndexPathService::parentPathsFromPath('/root/site/page/product')
        );
    }

    public function testARootNodeHasOnlyTheRootParentPath(): void
    {
        self::assertSame(['/'], IndexPathService::parentPathsFromPath('/root'));
    }

    public function testAnEmptyPathHasNoParentPaths(): void
    {
        self::assertSame([], IndexPathService::parentPathsFromPath(''));
    }
}
