<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Eel;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Eel\MeilisearchHelper;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The frontend filter ends up inside a tenant token, so Meilisearch enforces it and a
 * client cannot widen it by leaving a term out of its own request. That makes every
 * term here a site-wide policy rather than a frontend detail, and `_hiddenInIndex` is
 * the one term that is a judgement call: it is the Neos "Hide in menus" flag, which is
 * about navigation and need not mean a page should be unfindable.
 */
class MeilisearchHelperTest extends TestCase
{
    private const SITE_PATH = '/sites/example';
    private const DIMENSIONS_HASH = 'abc123';

    /**
     * @param array<string, mixed>|null $frontendFilterSettings Null leaves the property
     *     at its unconfigured default, which is what a site that never set it has.
     */
    private function helper(?array $frontendFilterSettings = null): MeilisearchHelper
    {
        // createConfiguredMock() rather than method()->willReturn(): the latter returns
        // an InvocationMocker whose namespace moves between the PHPUnit majors this
        // package supports, which the static analysis cannot resolve.
        $dimensionsService = $this->createConfiguredMock(DimensionsService::class, ['hash' => self::DIMENSIONS_HASH]);

        $helper = new MeilisearchHelper();
        $reflection = new \ReflectionObject($helper);

        $property = $reflection->getProperty('dimensionsService');
        $property->setAccessible(true);
        $property->setValue($helper, $dimensionsService);

        if ($frontendFilterSettings !== null) {
            $property = $reflection->getProperty('frontendFilterSettings');
            $property->setAccessible(true);
            $property->setValue($helper, $frontendFilterSettings);
        }

        return $helper;
    }

    private function siteNode(): NodeInterface
    {
        return $this->createConfiguredMock(NodeInterface::class, ['getPath' => self::SITE_PATH]);
    }

    private function filter(?array $frontendFilterSettings = null): string
    {
        // A non-empty dimension array keeps the node's context out of it: the helper
        // only falls back to the context when the caller passes no dimensions.
        return $this->helper($frontendFilterSettings)->frontendFilter($this->siteNode(), ['language' => ['en']]);
    }

    public function testExcludesNodesHiddenInIndexByDefault(): void
    {
        self::assertStringContainsString('_hiddenInIndex = false', $this->filter());
    }

    public function testExcludesNodesHiddenInIndexWhenConfiguredTo(): void
    {
        self::assertStringContainsString('_hiddenInIndex = false', $this->filter(['excludeHiddenInIndex' => true]));
    }

    public function testLeavesTheTermOutWhenTheSiteWantsThosePagesSearchable(): void
    {
        self::assertStringNotContainsString('_hiddenInIndex', $this->filter(['excludeHiddenInIndex' => false]));
    }

    /**
     * Dropping one term must not drop the terms that keep a search inside its own site
     * and dimension - those are not a judgement call.
     */
    public function testKeepsTheSiteDimensionAndHiddenTermsWhenTheOptionalOneIsOff(): void
    {
        $filter = $this->filter(['excludeHiddenInIndex' => false]);

        self::assertSame(
            '(__parentPath = "' . self::SITE_PATH . '" OR __path = "' . self::SITE_PATH . '") '
                . 'AND __dimensionsHash = "' . self::DIMENSIONS_HASH . '" AND _hidden = false',
            $filter
        );
    }
}
