<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\SiteNodeName;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Create requests in the CL
 *
 * @Flow\Scope("singleton")
 */
class RequestService {
    /**
     * @Flow\Inject
     * @var UriFactoryInterface
     */
    protected $uriFactory;

    /**
     * @Flow\Inject
     * @var ServerRequestFactoryInterface
     */
    protected $requestFactory;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Get the domain from a node
     *
     * @param Node $node
     * @return string
     */
    public function getDomain(Node $node): string {
        try {
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
            $siteNode = $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
            $site = $this->siteRepository->findSiteBySiteNode($siteNode);
            if ($site && $site->isOnline()) {
                $domain = $site->getPrimaryDomain();
                if ($domain && $domain->getActive()) {
                    $uri = $domain->__toString();
                    if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
                        return $uri;
                    }
                    return 'https://' . $uri;
                }
            }

        } catch (\Exception $e) {

        }

        return '';
    }

    /**
     * Create a action request
     *
     * @param string|Node|null $value
     * @return ActionRequest
     */
    public function createActionRequest(string|Node $value = null): ActionRequest {
        $domain = null;
        if (is_string($value)) {
            $domain = $value;
        }
        if ($value instanceof Node) {
            $domain = $this->getDomain($value);
        }
        if (!$domain) {
            $domain = 'http://domain.dummy';
        }

        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($value);
        $siteNode = $subgraph->findClosestNode($value->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
        $siteNodeName = $siteNode->name;
        $siteNodeName = SiteNodeName::fromNodeName($siteNodeName);
        $httpRequest = new ServerRequest('GET', $domain);
        $httpRequest = (SiteDetectionResult::create($siteNodeName, $value->contentRepositoryId))->storeInRequest($httpRequest);
        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
        $actionRequest->setFormat('html');

        return $actionRequest;
    }
}
