<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Meilisearch\Client;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Eel helper for public Meilisearch frontend configuration.
 */
class MeilisearchHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * @Flow\InjectConfiguration(path="indexName", package="Medienreaktor.Meilisearch")
     * @var string
     */
    protected $indexName = '';

    /**
     * @Flow\InjectConfiguration(path="client", package="Medienreaktor.Meilisearch")
     * @var array
     */
    protected $clientSettings = [];

    /**
     * @Flow\InjectConfiguration(path="tenantToken", package="Medienreaktor.Meilisearch")
     * @var array
     */
    protected $tenantTokenSettings = [];

    /**
     * Generate a Meilisearch tenant token for frontend searches in the current site and dimension context.
     *
     * @param NodeInterface $siteNode
     * @param array<string, mixed> $dimensions
     * @param string|null $indexName
     * @param integer|null $expiresIn
     * @param array<int, string> $additionalFilters
     * @return string
     */
    public function tenantToken(
        NodeInterface $siteNode,
        array $dimensions = [],
        ?string $indexName = null,
        ?int $expiresIn = null,
        array $additionalFilters = []
    ): string {
        $apiKey = $this->getTenantTokenApiKey();
        $apiKeyUid = $this->getTenantTokenApiKeyUid();

        if ($apiKey === '' || $apiKeyUid === '' || strlen($apiKey) <= 8) {
            return '';
        }

        $indexName = $indexName ?: $this->indexName;
        if ($indexName === '') {
            return '';
        }

        $client = new Client($this->getClientEndpoint(), $apiKey);

        return $client->generateTenantToken(
            $apiKeyUid,
            $this->searchRules($siteNode, $dimensions, $indexName, $additionalFilters),
            [
                'apiKey' => $apiKey,
                'expiresAt' => new \DateTimeImmutable('+' . ($expiresIn ?? $this->getTenantTokenExpiresIn()) . ' seconds'),
            ]
        );
    }

    /**
     * Build Meilisearch tenant search rules for the current site and dimension context.
     *
     * @param NodeInterface $siteNode
     * @param array<string, mixed> $dimensions
     * @param string|null $indexName
     * @param array<int, string> $additionalFilters
     * @return array<string, array<string, string>>
     */
    public function searchRules(
        NodeInterface $siteNode,
        array $dimensions = [],
        ?string $indexName = null,
        array $additionalFilters = []
    ): array {
        $indexName = $indexName ?: $this->indexName;

        return [
            $indexName => [
                'filter' => $this->frontendFilter($siteNode, $dimensions, $additionalFilters),
            ],
        ];
    }

    /**
     * Build the public frontend search filter for the given site and dimension context.
     *
     * @param NodeInterface $siteNode
     * @param array<string, mixed> $dimensions
     * @param array<int, string> $additionalFilters
     * @return string
     */
    public function frontendFilter(NodeInterface $siteNode, array $dimensions = [], array $additionalFilters = []): string
    {
        $siteNodePath = (string)$siteNode->getPath();
        $dimensionsHash = $this->dimensionsService->hash($dimensions ?: $siteNode->getContext()->getDimensions());
        $filters = [
            '(__parentPath = "' . $this->escapeFilterValue($siteNodePath) . '" OR __path = "' . $this->escapeFilterValue($siteNodePath) . '")',
            '__dimensionsHash = "' . $this->escapeFilterValue($dimensionsHash) . '"',
            '_hidden = false',
            '_hiddenInIndex = false',
        ];

        return implode(' AND ', array_merge($filters, array_filter($additionalFilters)));
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }

    private function getTenantTokenApiKey(): string
    {
        return (string)($this->tenantTokenSettings['apiKey'] ?? $this->clientSettings['readonlyApiKey'] ?? '');
    }

    private function getTenantTokenApiKeyUid(): string
    {
        return (string)($this->tenantTokenSettings['apiKeyUid'] ?? $this->clientSettings['readonlyApiKeyUid'] ?? '');
    }

    private function getTenantTokenExpiresIn(): int
    {
        $expiresIn = (int)($this->tenantTokenSettings['expiresIn'] ?? 3600);
        return $expiresIn > 0 ? $expiresIn : 3600;
    }

    private function getClientEndpoint(): string
    {
        $endpoint = (string)($this->clientSettings['endpoint'] ?? '');
        return $endpoint !== '' ? $endpoint : 'http://localhost';
    }

    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
