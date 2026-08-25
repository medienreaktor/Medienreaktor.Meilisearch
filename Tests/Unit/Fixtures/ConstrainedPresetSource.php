<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;

/**
 * A preset source with real dimension presets and a list of preset combinations its
 * constraints reject, which is the only part of this interface the combination logic
 * consults besides the presets themselves.
 */
final class ConstrainedPresetSource implements ContentDimensionPresetSourceInterface
{
    /**
     * @var array<string, array{presets: array<string, array{values: array<int, string>}>}>
     */
    private array $presets;

    /**
     * @var list<array<string, string>>
     */
    private array $forbiddenPresetCombinations;

    /**
     * @param array<string, array{presets: array<string, array{values: array<int, string>}>}> $presets
     * @param list<array<string, string>> $forbiddenPresetCombinations
     */
    public function __construct(array $presets, array $forbiddenPresetCombinations = [])
    {
        $this->presets = $presets;
        $this->forbiddenPresetCombinations = $forbiddenPresetCombinations;
    }

    public function getAllPresets()
    {
        return $this->presets;
    }

    public function isPresetCombinationAllowedByConstraints(array $dimensionsNamesAndPresetIdentifiers)
    {
        return !in_array($dimensionsNamesAndPresetIdentifiers, $this->forbiddenPresetCombinations, true);
    }

    public function getDefaultPreset($dimensionName)
    {
        $presets = $this->presets[$dimensionName]['presets'] ?? [];

        return $presets === [] ? null : reset($presets);
    }

    public function findPresetByDimensionValues($dimensionName, array $dimensionValues)
    {
        // The interface declares an array return, so "not found" is the empty one.
        return [];
    }

    public function getAllowedDimensionPresetsAccordingToPreselection($dimensionName, array $preselectedDimensionPresets)
    {
        return [];
    }

    public function findPresetsByTargetValues(array $targetValues)
    {
        return [];
    }
}
