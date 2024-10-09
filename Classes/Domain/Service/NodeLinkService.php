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
     * @param NodeInterface $node
     * @param Context|null $context
     * @return string|null
     */
    public function getNodeUri(NodeInterface $node, ?Context $context = null): ?string
    {
        if ($context instanceof Context) {
            $siteNode = $context->getCurrentSiteNode();
        }
        if (!$siteNode instanceof NodeInterface) {
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
     * @param NodeInterface $node
     * @return NodeInterface
     */
    public function getSiteNodeFromNode(NodeInterface $node): NodeInterface
    {
        $flowQuery = new FlowQuery([$node]);
        $nodes = $flowQuery->parents('[instanceof Neos.Neos:Document]')->get();

        return end($nodes);
    }
}
