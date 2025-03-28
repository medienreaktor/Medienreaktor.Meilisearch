<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;
use Neos\Neos\Service\LinkingService;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\ContentRepository\Domain\Service\Context;
use Psr\Http\Message\UriInterface;

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
     * Get the node uri
     *
     * @param Node $node
     * @return string|null
     */
    public function getNodeUri(Node $node): ?UriInterface
    {
        $actionRequest = $this->requestService->createActionRequest($node);
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($actionRequest);
        $nodeAddress = NodeAddress::fromNode($node);
        $uri = $nodeUriBuilder->uriFor($nodeAddress, Options::createForceAbsolute());
        return $uri;
    }

}
