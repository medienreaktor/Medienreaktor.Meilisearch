<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Command;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

/**
 * CLI commands for index building and flushing
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeIndexer
     */
    protected $nodeIndexer;

    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var integer
     */
    protected $indexedNodes = 0;


    /**
     * Create the index.
     *
     * @return void
     * @throws Exception
     */
    public function createIndexCommand(): void
    {
        $this->indexClient->createIndex();
        $this->outputLine('Index created successfully.');
    }

    /**
     * Delete the index.
     *
     * @return void
     * @throws Exception
     */
    public function deleteIndexCommand(): void
    {
        $this->indexClient->deleteIndex();
        $this->outputLine('The index has been deleted.');
    }

    /**
     * Index all nodes.
     *
     * @return void
     * @throws Exception
     */
    public function buildCommand(): void
    {
        $this->indexClient->createIndex();

        // Get all dimension presets and create dimension combinations
        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        $dimensionCombinations = $this->buildDimensionCombinations($dimensionPresets);

        if (empty($dimensionCombinations)) {
            // No dimensions configured, index without dimensions
            $this->outputLine('No dimension combinations found, indexing without dimensions');
            $context = $this->contextFactory->create(['workspaceName' => 'live']);
            $rootNode = $context->getRootNode();
            $this->traverseNodes($rootNode);
        } else {
            // Index for each dimension combination
            foreach ($dimensionCombinations as $dimensions) {
                $dimensionLabel = implode(', ', array_map(fn($k, $v) => "$k: " . implode(',', $v), array_keys($dimensions), $dimensions));
                $this->outputLine('Indexing dimension: ' . $dimensionLabel);

                $context = $this->contextFactory->create([
                    'workspaceName' => 'live',
                    'dimensions' => $dimensions
                ]);
                $rootNode = $context->getRootNode();
                $this->traverseNodes($rootNode, false, $dimensions);
            }
        }

        $this->outputLine('Finished indexing ' . $this->indexedNodes . ' nodes.');
    }

    /**
     * Build all dimension combinations from presets
     *
     * @param array $dimensionPresets
     * @return array
     */
    protected function buildDimensionCombinations(array $dimensionPresets): array
    {
        $combinations = [[]];

        foreach ($dimensionPresets as $dimensionName => $dimensionConfig) {
            $newCombinations = [];
            foreach ($combinations as $combination) {
                foreach ($dimensionConfig['presets'] as $presetKey => $preset) {
                    $newCombination = $combination;
                    $newCombination[$dimensionName] = $preset['values'];
                    $newCombinations[] = $newCombination;
                }
            }
            $combinations = $newCombinations;
        }

        return $combinations;
    }

    /**
     * @param NodeInterface $currentNode
     * @param bool $indexAllDimensions Whether to index all dimension combinations for each node
     * @param array $targetDimensionCombination Optional: Force indexing with this dimension (for shine-through)
     * @throws Exception
     */
    protected function traverseNodes(NodeInterface $currentNode, bool $indexAllDimensions = true, array $targetDimensionCombination = []): void
    {
        if (self::isFulltextRoot($currentNode)) {
            try {
                $this->nodeIndexer->indexNode($currentNode, null, $indexAllDimensions, false, $targetDimensionCombination);
            } catch (NodeException|IndexingException $exception) {
                throw new Exception(sprintf('Error during indexing of node %s (%s)', $currentNode->findNodePath(), (string) $currentNode->getNodeAggregateIdentifier()), 1690288327, $exception);
            }
            $this->indexedNodes++;
        }

        foreach ($currentNode->findChildNodes() as $childNode) {
            $this->traverseNodes($childNode, $indexAllDimensions, $targetDimensionCombination);
        }
    }

    /**
     * Delete all documents from the index.
     */
    public function flushCommand(): void
    {
        $this->indexClient->deleteAllDocuments();
        $this->outputLine('All documents flushed from the index.');
    }

    /**
     * Whether the node is configured as fulltext root. Copied from AbstractIndexerDriver::isFulltextRoot().
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected static function isFulltextRoot(NodeInterface $node): bool
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $searchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($searchSettingsForNode['fulltext']['isRoot']) && $searchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }
}
