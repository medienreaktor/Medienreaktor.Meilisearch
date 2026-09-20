<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;

/**
 * Replaces the extraction from the live workspace with a table of what each dimension
 * combination resolves to there.
 *
 * Which combinations the indexer asks for, and what it deletes around them, is the
 * behaviour under test. Resolving a node aggregate in a combination is the content
 * repository's job, so the table stands in for it: a combination missing from it
 * resolves to nothing, as a hidden, removed or absent variant does in live.
 */
final class LiveWorkspaceNodeIndexer extends NodeIndexer
{
    /**
     * @var array<string, bool> Live visibility, keyed by the first value of a combination
     */
    public array $visibleInLive = [];

    /**
     * @var list<array> Every combination extraction was asked for, in order
     */
    public array $extractedCombinations = [];

    protected function extractNodeVariant(string $nodeIdentifier, array $dimensionCombination = []): ?array
    {
        $this->extractedCombinations[] = $dimensionCombination;

        $key = $dimensionCombination === [] ? 'default' : (string) reset($dimensionCombination)[0];
        if (!($this->visibleInLive[$key] ?? false)) {
            return null;
        }

        return [
            'id' => $this->generateDocumentIdentifier($nodeIdentifier, $this->dimensionsService->hash($dimensionCombination)),
            '__identifier' => $nodeIdentifier,
        ];
    }
}
