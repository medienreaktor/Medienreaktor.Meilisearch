<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\LinkingService;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\ContentRepository\Domain\Service\Context;

/**
 * Get links from nodes in the CLI
 *
 * @Flow\Scope("singleton")
 */
class NodeLinkService
{

     /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
    * @Flow\Inject
    * @var RequestService
    */
    protected $requestService;

    /**
     * Get the node uri
     *
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub|null $context
     * @return string|null
     */
    public function getNodeUri(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, ?\Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context = null): ?string
    {
        if ($context instanceof \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub) {
            // TODO 9.0 migration: !! ContentContext::getCurrentSiteNode() is removed in Neos 9.0. Use Subgraph and traverse up to "Neos.Neos:Site" node.

            $siteNode = $context->getCurrentSiteNode();
        }
        if (!$siteNode instanceof \Neos\ContentRepository\Core\Projection\ContentGraph\Node) {
            $siteNode = $this->getSiteNodeFromNode($node);
        }
        $domain = $this->requestService->getDomain($siteNode);
        $controllerContext = $this->requestService->getControllerContext($domain);

        try {
            return $this->linkingService->createNodeUri($controllerContext, $node, $siteNode, 'html', !!$domain);
        } catch (\Exception $e) {
        }
        return null;
    }

    /**
     * Get the site node from a node
     *
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     */
    public function getSiteNodeFromNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): \Neos\ContentRepository\Core\Projection\ContentGraph\Node
    {
        $flowQuery = new FlowQuery([$node]);
        $nodes = $flowQuery->parents('[instanceof Neos.Neos:Document]')->get();

        return end($nodes);
    }
}
