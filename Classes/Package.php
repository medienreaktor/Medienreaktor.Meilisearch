<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch;

use Medienreaktor\Meilisearch\Domain\Service\RuntimeIndexingService;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Booting\Step;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        $bootstrap->getSignalSlotDispatcher()->connect(Sequence::class, 'afterInvokeStep', function (Step $step) use ($bootstrap) {
            if ($step->getIdentifier() !== 'neos.flow:objectmanagement:runtime') {
                return;
            }
            $settings = $bootstrap->getObjectManager()->get(ConfigurationManager::class)
                ->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepository.Search');
            if (($settings['realtimeIndexing']['enabled'] ?? false) !== true) {
                return;
            }
            $dispatcher = $bootstrap->getSignalSlotDispatcher();
            $dispatcher->connect(Workspace::class, 'beforeNodePublishing', RuntimeIndexingService::class, 'beforeNodePublishing', false);
            $dispatcher->connect(Workspace::class, 'afterNodePublishing', RuntimeIndexingService::class, 'afterNodePublishing', false);
            $dispatcher->connect(Node::class, 'nodePathChanged', RuntimeIndexingService::class, 'nodePathChanged', false);
            $dispatcher->connect(PersistenceManager::class, 'allObjectsPersisted', RuntimeIndexingService::class, 'flush', false);
        });
    }
}
