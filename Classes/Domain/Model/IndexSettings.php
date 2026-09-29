<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Model;

use Medienreaktor\Meilisearch\Exception;

/**
 * The index settings sent to Meilisearch, built from the package configuration.
 *
 * Attribute sets that several packages extend are configured as maps
 * (`attributeName: true`) instead of lists. Flow merges YAML lists by position, so
 * two packages adding list entries overwrite each other depending on load order;
 * map keys merge by name. `false` or `null` switches an attribute off again.
 */
final class IndexSettings
{
    /**
     * Attributes whose order carries no meaning and which packages commonly extend.
     */
    private const ATTRIBUTE_MAPS = ['filterableAttributes', 'sortableAttributes'];

    /**
     * @param array<string,mixed> $settings
     */
    private function __construct(private readonly array $settings)
    {
    }

    /**
     * @param array<string,mixed> $configuration the `Medienreaktor.Meilisearch.settings` configuration
     * @throws Exception if an attribute map is still configured as a list
     */
    public static function fromConfiguration(array $configuration): self
    {
        foreach (self::ATTRIBUTE_MAPS as $key) {
            if (array_key_exists($key, $configuration)) {
                $configuration[$key] = self::enabledAttributes($key, $configuration[$key]);
            }
        }
        return new self($configuration);
    }

    /**
     * @return array<string,mixed> the payload for Meilisearch's settings endpoint
     */
    public function toArray(): array
    {
        return $this->settings;
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private static function enabledAttributes(string $key, mixed $attributes): array
    {
        if (!is_array($attributes)) {
            throw new Exception(sprintf('Setting "Medienreaktor.Meilisearch.settings.%s" must be a map of attribute names to true/false, got %s.', $key, get_debug_type($attributes)), 1759132800);
        }

        $enabled = [];
        foreach ($attributes as $name => $isEnabled) {
            if (is_int($name)) {
                $entry = is_string($isEnabled) ? $isEnabled : get_debug_type($isEnabled);
                throw new Exception(sprintf('Setting "Medienreaktor.Meilisearch.settings.%s" contains the list entry "%s". Since 3.0 this setting is a map; write "%s: true" instead.', $key, $entry, $entry), 1759132801);
            }
            if ($isEnabled === true) {
                $enabled[] = $name;
                continue;
            }
            if ($isEnabled !== false && $isEnabled !== null) {
                throw new Exception(sprintf('Setting "Medienreaktor.Meilisearch.settings.%s.%s" must be true, false or null, got %s.', $key, $name, get_debug_type($isEnabled)), 1759132802);
            }
        }
        return $enabled;
    }
}
