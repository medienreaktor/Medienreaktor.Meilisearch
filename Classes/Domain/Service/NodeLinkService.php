<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\UriInterface;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;

/**
 * Get links from nodes in the CLI
 *
 * @Flow\Scope("singleton")
 */
class NodeLinkService
{
    /**
     * @Flow\Inject
     * @var RequestService
     */
    protected $requestService;

    /**
     * @Flow\Inject
     * @var NodeUriBuilderFactory
     */
    protected $nodeUriBuilderFactory;

    /**
     * The public URI of a document node: absolute when its site has an active primary
     * domain, otherwise relative.
     *
     * Only documents have a route. A node that cannot be routed throws rather than
     * returning null, so that a broken document is noticed instead of being indexed
     * without a URI.
     *
     * @param Node $node
     * @return UriInterface|null Null for a node that is not below a site
     * @throws NoMatchingRouteException
     */
    public function getNodeUri(Node $node): ?UriInterface
    {
        if ($this->getSiteNodeFromNode($node) === null) {
            return null;
        }

        $options = $this->requestService->getDomain($node) !== ''
            ? Options::createForceAbsolute()
            : Options::createEmpty();

        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($this->requestService->createActionRequest($node));

        return $nodeUriBuilder->uriFor(NodeAddress::fromNode($node), $options);
    }

    /**
     * The site node the given node belongs to; a site node is its own.
     *
     * @param Node $node
     * @return Node|null Null for a node that is not below a site
     */
    public function getSiteNodeFromNode(Node $node): ?Node
    {
        return $this->requestService->getSiteNode($node);
    }
}
