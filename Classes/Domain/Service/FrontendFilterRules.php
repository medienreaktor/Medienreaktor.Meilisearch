<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Exception;

/**
 * The configurable part of the frontend filter, read from the `frontendFilter` settings.
 *
 * Both the helper that writes the filter into tenant tokens and the index that has to
 * make its attributes filterable build one from the same settings, so the two cannot
 * disagree about which attributes the filter names.
 */
final class FrontendFilterRules
{
    /**
     * What every frontend filter names regardless of configuration: the site and
     * dimension scope, and the hidden flag.
     */
    private const FIXED_ATTRIBUTES = ['__parentPath', '__path', '__dimensionsHash', '_hidden'];

    /**
     * Deliberately narrower than what Meilisearch accepts as an attribute name: the name
     * is written into the filter expression as is, so anything that could read as filter
     * syntax is refused rather than quoted.
     */
    private const ATTRIBUTE_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/';

    /**
     * @var bool
     */
    private $excludeHiddenInIndex;

    /**
     * @var array<string, array{attribute: string, value: bool|int|float|string}>
     */
    private $rules = [];

    /**
     * @param array<string, mixed>|null $settings The `frontendFilter` settings
     * @throws Exception
     */
    public function __construct(?array $settings)
    {
        // Absent configuration keeps nodes hidden in the index out of the search, which
        // is what every site got before the setting existed.
        $this->excludeHiddenInIndex = (bool)($settings['excludeHiddenInIndex'] ?? true);

        foreach ($settings['excludeRules'] ?? [] as $name => $rule) {
            // `~` is how a site switches off a rule it inherited from another package.
            if ($rule === null) {
                continue;
            }
            $this->rules[(string)$name] = self::validatedRule((string)$name, $rule);
        }
    }

    /**
     * The filter terms to append after the fixed ones.
     *
     * A rule excludes documents whose attribute equals the value and keeps every other
     * document, including those that do not have the attribute at all. That is what makes
     * a newly introduced property safe: until a document is reindexed it lacks the
     * attribute and stays searchable, instead of vanishing from every search.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        $terms = [];
        if ($this->excludeHiddenInIndex) {
            $terms[] = '_hiddenInIndex = false';
        }
        foreach ($this->rules as $rule) {
            $terms[] = 'NOT ' . $rule['attribute'] . ' = ' . self::literal($rule['value']);
        }

        return $terms;
    }

    /**
     * Every attribute the frontend filter names, each once.
     *
     * @return list<string>
     */
    public function attributes(): array
    {
        $attributes = self::FIXED_ATTRIBUTES;
        if ($this->excludeHiddenInIndex) {
            $attributes[] = '_hiddenInIndex';
        }
        foreach ($this->rules as $rule) {
            $attributes[] = $rule['attribute'];
        }

        return array_values(array_unique($attributes));
    }

    /**
     * @param mixed $rule
     * @return array{attribute: string, value: bool|int|float|string}
     * @throws Exception
     */
    private static function validatedRule(string $name, $rule): array
    {
        if (!is_array($rule) || !array_key_exists('attribute', $rule) || !array_key_exists('value', $rule)) {
            throw new Exception(sprintf(
                'Frontend filter rule "%s" must be a map with "attribute" and "value", got %s.',
                $name,
                is_array($rule) ? 'the keys ' . implode(', ', array_keys($rule)) : get_debug_type($rule)
            ), 1789720069);
        }

        if (!is_string($rule['attribute']) || preg_match(self::ATTRIBUTE_PATTERN, $rule['attribute']) !== 1) {
            throw new Exception(sprintf(
                'Frontend filter rule "%s" names the attribute %s, which is not a plain attribute name (letters, digits, "_", "." and "-").',
                $name,
                is_string($rule['attribute']) ? '"' . $rule['attribute'] . '"' : get_debug_type($rule['attribute'])
            ), 1789720070);
        }

        if (!is_bool($rule['value']) && !is_int($rule['value']) && !is_float($rule['value']) && !is_string($rule['value'])) {
            throw new Exception(sprintf(
                'Frontend filter rule "%s" needs a boolean, number or string "value", got %s.',
                $name,
                get_debug_type($rule['value'])
            ), 1789720071);
        }

        return ['attribute' => $rule['attribute'], 'value' => $rule['value']];
    }

    /**
     * @param bool|int|float|string $value
     */
    private static function literal($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return (string)$value;
    }
}
