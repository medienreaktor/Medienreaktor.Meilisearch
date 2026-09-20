<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Doctrine\Common\Annotations\AnnotationReader;
use Medienreaktor\Meilisearch\Domain\Service\RuntimeIndexingService;
use Neos\Flow\Annotations as Flow;
use PHPUnit\Framework\TestCase;

class RuntimeIndexingServiceTest extends TestCase
{
    public function testIndexerInjectionIsEagerButStillUsesTheConfiguredInterface(): void
    {
        $property = new \ReflectionProperty(RuntimeIndexingService::class, 'nodeIndexer');
        $injection = (new AnnotationReader())->getPropertyAnnotation($property, Flow\Inject::class);

        // A lazy DependencyProxy fails the concrete type guard before its first method call.
        self::assertInstanceOf(Flow\Inject::class, $injection);
        self::assertFalse($injection->lazy);
        self::assertNull($injection->name);
        self::assertStringContainsString('@var NodeIndexerInterface', (string)$property->getDocComment());
    }
}
