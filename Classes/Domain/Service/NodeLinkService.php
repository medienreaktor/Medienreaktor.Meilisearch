<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\LinkingService;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Neos\Domain\Service\ContentContext;

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
     * @param NodeInterface&TraversableNodeInterface $node
     * @param Context|null $context
     * @return string|null
     */
    public function getNodeUri(NodeInterface $node, ?Context $context = null): ?string
    {
        // Only a ContentContext knows its site; a bare Context does not declare
        // getCurrentSiteNode() at all, so calling it there would be a fatal.
        $siteNode = $context instanceof ContentContext ? $context->getCurrentSiteNode() : null;
        if ($siteNode === null) {
            $siteNode = $this->getSiteNodeFromNode($node);
        }
        if ($siteNode === null) {
            return null;
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
     * Get the site node from a node.
     *
     * Walks the rootline and keeps the outermost document ancestor, which is the site
     * node. This deliberately does not read the site off the context: the indexer
     * builds its contexts from a workspace name alone, so currentSite is null there
     * and getCurrentSiteNode() would return null for every node being indexed.
     *
     * Replaces a FlowQuery parents('[instanceof Neos.Neos:Document]')->get() followed
     * by end(), which produced the same node — parents() collects closest-first and
     * stops at /sites, so the last match was the outermost. FlowQuery dispatched that
     * through __call, so no static analysis could see it. One behavioural difference:
     * the node itself counts as a candidate here, so passing a site node returns that
     * node rather than nothing.
     *
     * @param NodeInterface&TraversableNodeInterface $node
     * @return NodeInterface|null
     */
    public function getSiteNodeFromNode(NodeInterface $node): ?NodeInterface
    {
        $siteNode = null;
        $currentNode = $node;

        while ($currentNode !== null) {
            if ($currentNode instanceof NodeInterface && $currentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                $siteNode = $currentNode;
            }

            $currentNode = $this->findParentOrNull($currentNode);
        }

        return $siteNode;
    }

    /**
     * findParentNode() signals the top of the rootline by throwing. Turning that into
     * null keeps the walk above an ordinary loop with a real termination condition.
     *
     * @param TraversableNodeInterface $node
     * @return TraversableNodeInterface|null
     */
    private function findParentOrNull(TraversableNodeInterface $node): ?TraversableNodeInterface
    {
        try {
            return $node->findParentNode();
        } catch (NodeException $exception) {
            return null;
        }
    }
}
