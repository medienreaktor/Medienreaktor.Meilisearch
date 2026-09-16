<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;

final class RecordingContextFactory implements ContextFactoryInterface
{
    /** @var Context[] */
    public array $contexts = [];

    /** @var array[] */
    public array $configurations = [];

    public function create(array $contextConfiguration = []): Context
    {
        $this->configurations[] = $contextConfiguration;
        $context = array_shift($this->contexts);
        if ($context === null) {
            throw new \LogicException('No context was prepared for this call.');
        }
        return $context;
    }

    public function reset(): void
    {
        $this->contexts = [];
        $this->configurations = [];
    }

    public function getInstances(): array
    {
        return $this->contexts;
    }
}
