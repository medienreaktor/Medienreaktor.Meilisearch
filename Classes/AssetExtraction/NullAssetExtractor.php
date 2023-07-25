<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetExtraction;

use Medienreaktor\Meilisearch\Exception;
use Neos\ContentRepository\Search\AssetExtraction\AssetExtractorInterface;
use Neos\ContentRepository\Search\Dto\AssetContent;
use Neos\Media\Domain\Model\AssetInterface;

class NullAssetExtractor implements AssetExtractorInterface
{
    public function extract(AssetInterface $asset): AssetContent
    {
        throw new Exception('AssetExtractor is not implemented in Meilisearch.');
    }
}
