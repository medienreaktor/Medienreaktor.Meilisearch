<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;

/**
 * Creates the requests that routing needs where there is none, e.g. on the CLI
 *
 * @Flow\Scope("singleton")
 */
class RequestService
{
    /**
     * Host of the request built for a node whose site has no active primary domain.
     * Routing needs a host; URIs built against it are rendered relative.
     */
    public const DUMMY_DOMAIN = 'http://domain.dummy';

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * The base URI of the node's site: its active primary domain with a scheme, or an
     * empty string when the site is offline or has no active primary domain.
     *
     * @param Node $node
     * @return string
     */
    public function getDomain(Node $node): string
    {
        $site = $this->getSite($node);
        if ($site === null || !$site->isOnline()) {
            return '';
        }

        // Neos falls back to the first active domain, which is null for a site
        // without any.
        /** @var Domain|null $domain */
        $domain = $site->getPrimaryDomain();
        if ($domain === null || !$domain->getActive()) {
            return '';
        }

        $uri = (string)$domain;
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        return 'https://' . $uri;
    }

    /**
     * The site node the given node belongs to, or null when it is not below a site
     * (e.g. the sites root itself).
     */
    public function getSiteNode(Node $node): ?Node
    {
        return $this->contentRepositoryRegistry->subgraphForNode($node)->findClosestNode(
            $node->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );
    }

    /**
     * Get a controller context for the given node or domain
     *
     * @param string|Node|null $value
     * @return ControllerContext
     */
    public function getControllerContext(string|Node|null $value = null): ControllerContext
    {
        $actionRequest = $this->createActionRequest($value);
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($actionRequest);
        $uriBuilder->setCreateAbsoluteUri(false);

        return new ControllerContext(
            $actionRequest,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }

    /**
     * Create an action request
     *
     * A node yields a request for its site's domain, carrying the site detection result
     * that Neos' node routing reads. A string is taken as the domain. Without either,
     * the request is made for a dummy domain and is only good for routes that do not
     * depend on the site.
     *
     * @param string|Node|null $value
     * @return ActionRequest
     */
    public function createActionRequest(string|Node|null $value = null): ActionRequest
    {
        $domain = '';
        if (is_string($value)) {
            $domain = $value;
        } elseif ($value instanceof Node) {
            $domain = $this->getDomain($value);
        }

        $httpRequest = new ServerRequest('GET', $domain !== '' ? $domain : self::DUMMY_DOMAIN);

        if ($value instanceof Node) {
            $siteNode = $this->getSiteNode($value);
            if ($siteNode?->name !== null) {
                $httpRequest = SiteDetectionResult::create(SiteNodeName::fromNodeName($siteNode->name), $value->contentRepositoryId)
                    ->storeInRequest($httpRequest);
            }
        }

        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
        $actionRequest->setFormat('html');

        return $actionRequest;
    }

    private function getSite(Node $node): ?Site
    {
        $siteNode = $this->getSiteNode($node);
        if ($siteNode === null) {
            return null;
        }

        try {
            return $this->siteRepository->findSiteBySiteNode($siteNode);
        } catch (\Neos\Neos\Domain\Exception) {
            return null;
        }
    }
}
