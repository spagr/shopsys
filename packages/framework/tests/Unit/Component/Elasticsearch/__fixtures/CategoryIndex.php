<?php

namespace Tests\FrameworkBundle\Unit\Component\Elasticsearch\__fixtures;

use Shopsys\FrameworkBundle\Component\Elasticsearch\AbstractIndex;
use Symplify\BetterPhpDocParser\Exception\NotImplementedYetException;

class CategoryIndex extends AbstractIndex
{
    public const INDEX_NAME = 'category';

    /**
     * @return string
     */
    public function getName(): string
    {
        return self::INDEX_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount(int $domainId): int
    {
        throw new NotImplementedYetException();
    }

    /**
     * @inheritDoc
     */
    public function getExportDataForIds(int $domainId, array $restrictToIds): array
    {
        throw new NotImplementedYetException();
    }

    /**
     * @inheritDoc
     */
    public function getExportDataForBatch(int $domainId, int $lastProcessedId, int $batchSize): array
    {
        throw new NotImplementedYetException();
    }
}