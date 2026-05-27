<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Controller;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteNodeUtility;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;

/**
 * JSON endpoint that returns short fulltext suggestions for live-search UIs.
 *
 * GET /search/suggest.json?q=foo&limit=8&dimensions[language]=de&dimensions[market]=consumers
 */
class SuggestController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    protected $viewFormatToObjectNameMap = [
        'json' => JsonView::class,
    ];

    protected $supportedMediaTypes = ['application/json'];

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected SiteNodeUtility $siteNodeUtility;

    #[Flow\Inject]
    protected QueryBuilderInterface $queryBuilder;

    /**
     * @param string $q the search term
     * @param int $limit number of hits to return (1..20)
     * @param array $dimensions content dimension values, keyed by dimension name
     * @param int $minChars minimum query length, hits below this return an empty result
     */
    public function suggestAction(string $q = '', int $limit = 8, array $dimensions = [], int $minChars = 2): void
    {
        $q = trim($q);
        $limit = max(1, min($limit, 20));
        $minChars = max(1, $minChars);

        if ($q === '' || mb_strlen($q) < $minChars) {
            $this->view->assign('value', ['hits' => [], 'totalHits' => 0, 'query' => $q]);
            return;
        }

        $siteDetection = SiteDetectionResult::fromRequest($this->request->getHttpRequest());
        $site = $this->siteRepository->findOneByNodeName($siteDetection->siteNodeName);
        if ($site === null) {
            $this->view->assign('value', ['hits' => [], 'totalHits' => 0, 'query' => $q]);
            return;
        }

        $dimensionSpacePoint = $this->resolveDimensionSpacePoint($site, $dimensions);

        $siteNode = $this->siteNodeUtility->findSiteNodeBySite(
            $site,
            WorkspaceName::forLive(),
            $dimensionSpacePoint
        );

        $this->queryBuilder
            ->query($siteNode)
            ->fulltext($q)
            ->nodeType('Neos.Neos:Document')
            ->highlight(['__fulltext.text', '__fulltext.h1', '__fulltext.h2'])
            ->crop(20)
            ->limit($limit);

        $hits = [];
        foreach ($this->queryBuilder->executeRaw() as $hit) {
            $hits[] = $this->formatHit($hit);
        }

        $this->view->assign('value', [
            'hits' => $hits,
            'totalHits' => $this->queryBuilder->count(),
            'query' => $q,
        ]);
    }

    /**
     * Build the dimension space point from the request args, falling back to the site's default
     * for any dimension the caller didn't supply.
     */
    private function resolveDimensionSpacePoint($site, array $dimensions): DimensionSpacePoint
    {
        $defaults = $site->getConfiguration()->defaultDimensionSpacePoint->coordinates ?? [];
        $coordinates = [];
        foreach ($defaults as $name => $defaultValue) {
            $coordinates[$name] = (string)($dimensions[$name] ?? $defaultValue);
        }
        return DimensionSpacePoint::fromArray($coordinates);
    }

    /**
     * Map a raw Meilisearch hit into a compact JSON-friendly structure for the dropdown UI.
     */
    private function formatHit(array $hit): array
    {
        $formatted = $hit['_formatted'] ?? [];
        $snippetParts = [
            $formatted['__fulltext']['h1'] ?? '',
            $formatted['__fulltext']['h2'] ?? '',
            $formatted['__fulltext']['text'] ?? '',
        ];
        $snippet = trim(implode(' … ', array_filter(array_map('trim', $snippetParts))));

        return [
            'aggregateId' => $hit['__aggregateId'] ?? null,
            'title' => $hit['title'] ?? ($hit['heroTitle'] ?? ($hit['uriPathSegment'] ?? '')),
            'uri' => $hit['__uri'] ?? null,
            'nodeType' => $hit['__nodeType'] ?? null,
            'snippet' => $snippet,
        ];
    }
}
