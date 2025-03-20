<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Create requests in the CL
 *
 * @Flow\Scope("singleton")
 */
class RequestService
{
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
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @return string
     */
    public function getDomain(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): string
    {
        try {
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
            // TODO 9.0 migration: Try to remove the (string) cast and make your code more type-safe.

            $nodePath = (string) $subgraph->findNodePath($node->aggregateId);
            $nodePathSegments = explode('/', $nodePath);

            // Seems hacky, but we need to get the site by the node name here
            // and extract the site name by the node's path
            if (count($nodePathSegments) >= 3) {
                $siteName = $nodePathSegments[2];
                $site = $this->siteRepository->findOneByNodeName($siteName);
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
            }
        } catch (\Exception $e) {

        }

        return '';
    }

    /**
     * Get the controller context
     *
     * @param string|\Neos\ContentRepository\Core\Projection\ContentGraph\Node|null $value
     * @return ControllerContext
     */
    public function getControllerContext(string|\Neos\ContentRepository\Core\Projection\ContentGraph\Node $value = null): ControllerContext
    {
        $actionRequest = $this->createActionRequest($value);
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($actionRequest);
        $uriBuilder->setCreateAbsoluteUri(false);
        $controllerContext = new ControllerContext(
            $actionRequest,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
        return $controllerContext;
    }

    /**
     * Create a action request
     *
     * @param string|\Neos\ContentRepository\Core\Projection\ContentGraph\Node|null $value
     * @return ActionRequest
     */
    public function createActionRequest(string|\Neos\ContentRepository\Core\Projection\ContentGraph\Node $value = null): ActionRequest
    {
        $domain = null;
        if (is_string($value)) {
            $domain = $value;
        }
        if ($value instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
            $domain = $this->getDomain($value);
        }
        if (!$domain) {
            $domain = 'http://domain.dummy';
        }

        $requestUri = $this->uriFactory->createUri($domain);
        $httpRequest = $this->requestFactory->createServerRequest('get', $requestUri);
        $parameters = $httpRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $httpRequest = $httpRequest->withAttribute(
            ServerRequestAttributes::ROUTING_PARAMETERS,
            $parameters->withParameter('requestUriHost', $requestUri->getHost())
        );
        $actionRequest = ActionRequest::fromHttpRequest($httpRequest);
        $actionRequest->setFormat('html');

        return $actionRequest;
    }
}
