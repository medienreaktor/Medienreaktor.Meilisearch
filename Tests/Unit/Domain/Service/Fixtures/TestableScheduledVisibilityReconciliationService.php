<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Domain\Service\ScheduledVisibilityReconciliationService;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;

class TestableScheduledVisibilityReconciliationService extends ScheduledVisibilityReconciliationService
{
    public function injectContextFactory(ContextFactoryInterface $contextFactory): void
    {
        $this->contextFactory = $contextFactory;
    }

    public function injectDimensionsService(DimensionsService $dimensionsService): void
    {
        $this->dimensionsService = $dimensionsService;
    }

    /**
     * @return array<string, array{action: string, node: NodeInterface}>
     */
    public function collectOperations(NodeData $nodeData, \DateTimeInterface $now): array
    {
        $operations = [];
        $this->collectOperationsForScheduledNode($nodeData, $now, $operations);
        return $operations;
    }
}
