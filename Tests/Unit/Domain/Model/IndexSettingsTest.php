<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Model;

use Medienreaktor\Meilisearch\Domain\Model\IndexSettings;
use Medienreaktor\Meilisearch\Exception;
use PHPUnit\Framework\TestCase;

/**
 * A list left over from 2.x would still merge without a Flow error, just wrongly:
 * its entries overwrite other packages' attributes by position. These cases make
 * sure such configuration fails loudly instead of reaching Meilisearch.
 */
class IndexSettingsTest extends TestCase
{
    public function testEnabledAttributesBecomeTheListMeilisearchExpects(): void
    {
        $settings = IndexSettings::fromConfiguration([
            'filterableAttributes' => ['__identifier' => true, '__isAsset' => true],
            'sortableAttributes' => ['_geo' => true],
        ]);

        self::assertSame(
            ['filterableAttributes' => ['__identifier', '__isAsset'], 'sortableAttributes' => ['_geo']],
            $settings->toArray()
        );
    }

    public function testFalseAndNullSwitchAnAttributeOff(): void
    {
        $settings = IndexSettings::fromConfiguration([
            'filterableAttributes' => ['__identifier' => true, '_geo' => false, '__path' => null],
        ]);

        self::assertSame(['filterableAttributes' => ['__identifier']], $settings->toArray());
    }

    public function testOtherSettingsPassThroughUnchanged(): void
    {
        $configuration = [
            'searchableAttributes' => ['__fulltext.text', '__fulltext.h1'],
            'displayedAttributes' => ['*'],
        ];

        self::assertSame($configuration, IndexSettings::fromConfiguration($configuration)->toArray());
    }

    public function testAListEntryIsRejectedWithTheMapSpelling(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1759132801);
        $this->expectExceptionMessage('write "__isAsset: true" instead');

        IndexSettings::fromConfiguration(['filterableAttributes' => ['__identifier' => true, 0 => '__isAsset']]);
    }

    public function testANonBooleanValueIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1759132802);

        IndexSettings::fromConfiguration(['sortableAttributes' => ['datePublished' => 'yes']]);
    }

    public function testAScalarInPlaceOfTheMapIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1759132800);

        IndexSettings::fromConfiguration(['filterableAttributes' => '__identifier']);
    }
}
