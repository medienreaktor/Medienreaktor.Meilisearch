<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\FrontendFilterRules;
use Medienreaktor\Meilisearch\Exception;
use PHPUnit\Framework\TestCase;

/**
 * The rules end up inside tenant tokens, where Meilisearch enforces them and a client
 * cannot widen them, and their attributes have to be filterable or every frontend search
 * fails. So these pin both what the terms say and which attributes they name.
 */
class FrontendFilterRulesTest extends TestCase
{
    /**
     * @param array<string, mixed> $rules
     */
    private static function withRules(array $rules, bool $excludeHiddenInIndex = true): FrontendFilterRules
    {
        return new FrontendFilterRules(['excludeHiddenInIndex' => $excludeHiddenInIndex, 'excludeRules' => $rules]);
    }

    public function testUnconfiguredSettingsKeepTheHiddenInIndexTerm(): void
    {
        $rules = new FrontendFilterRules(null);

        self::assertSame(['_hiddenInIndex = false'], $rules->terms());
        self::assertSame(['__parentPath', '__path', '__dimensionsHash', '_hidden', '_hiddenInIndex'], $rules->attributes());
    }

    public function testTheHiddenInIndexTermAndItsAttributeGoTogether(): void
    {
        $rules = self::withRules([], false);

        self::assertSame([], $rules->terms());
        self::assertNotContains('_hiddenInIndex', $rules->attributes());
    }

    /**
     * `attribute = false` would also drop every document that lacks the attribute, which
     * for a newly added property is every document not yet reindexed.
     */
    public function testARuleExcludesOnlyWhatMatchesItsValue(): void
    {
        $rules = self::withRules(['hiddenFromSearch' => ['attribute' => 'hiddenFromSearch', 'value' => true]], false);

        self::assertSame(['NOT hiddenFromSearch = true'], $rules->terms());
    }

    public function testRuleValuesAreWrittenAsFilterLiterals(): void
    {
        $rules = self::withRules([
            'meetings' => ['attribute' => '__nodeTypeAndSupertypes', 'value' => 'Vendor.Site:Document.Meeting'],
            'quoted' => ['attribute' => 'label', 'value' => 'say "hi" \\ bye'],
            'priority' => ['attribute' => 'priority', 'value' => 3],
            'shown' => ['attribute' => 'shown', 'value' => false],
        ], false);

        self::assertSame([
            'NOT __nodeTypeAndSupertypes = "Vendor.Site:Document.Meeting"',
            'NOT label = "say \\"hi\\" \\\\ bye"',
            'NOT priority = 3',
            'NOT shown = false',
        ], $rules->terms());
    }

    public function testRulesFollowTheHiddenInIndexTermInConfiguredOrder(): void
    {
        $rules = self::withRules([
            'second' => ['attribute' => 'b', 'value' => true],
            'first' => ['attribute' => 'a', 'value' => true],
        ]);

        self::assertSame(['_hiddenInIndex = false', 'NOT b = true', 'NOT a = true'], $rules->terms());
    }

    public function testARuleSetToNullIsSwitchedOff(): void
    {
        $rules = self::withRules(['inherited' => null], false);

        self::assertSame([], $rules->terms());
    }

    public function testAttributesAreListedOnceAndIncludeEveryRule(): void
    {
        $rules = self::withRules([
            'flag' => ['attribute' => 'hiddenFromSearch', 'value' => true],
            'sameAgain' => ['attribute' => 'hiddenFromSearch', 'value' => 'yes'],
            'fixed' => ['attribute' => '_hidden', 'value' => true],
        ]);

        self::assertSame(
            ['__parentPath', '__path', '__dimensionsHash', '_hidden', '_hiddenInIndex', 'hiddenFromSearch'],
            $rules->attributes()
        );
    }

    /**
     * The attribute name is written into the filter as is, so anything that could read
     * as filter syntax must be refused.
     */
    public function testRefusesAnAttributeNameThatCouldCarryFilterSyntax(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1789720070);

        self::withRules(['sneaky' => ['attribute' => 'hidden = true OR x', 'value' => true]]);
    }

    public function testRefusesARuleWithoutAValue(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1789720069);
        $this->expectExceptionMessageMatches('/"incomplete".*the keys attribute/');

        self::withRules(['incomplete' => ['attribute' => 'hiddenFromSearch']]);
    }

    public function testRefusesAValueThatIsNotAScalar(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1789720071);

        self::withRules(['list' => ['attribute' => 'tags', 'value' => ['a', 'b']]]);
    }
}
