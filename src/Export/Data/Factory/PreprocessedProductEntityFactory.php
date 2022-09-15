<?php

declare(strict_types=1);

namespace Omikron\FactFinder\Shopware6\Export\Data\Factory;

use Omikron\FactFinder\Shopware6\Config\ExportSettings;
use Omikron\FactFinder\Shopware6\DataAbstractionLayer\FeedPreprocessorEntryReader;
use Omikron\FactFinder\Shopware6\Export\Data\Entity\ProductEntity as ExportProductEntity;
use Omikron\FactFinder\Shopware6\Export\FeedPreprocessorEntry;
use Omikron\FactFinder\Shopware6\Export\FieldsProvider;
use Omikron\FactFinder\Shopware6\Export\SalesChannelService;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Traversable;

class PreprocessedProductEntityFactory implements FactoryInterface
{
    private SalesChannelService $channelService;
    private FactoryInterface $decoratedFactory;
    private FieldsProvider $fieldsProviders;
    private ExportSettings $exportSettings;
    private FeedPreprocessorEntryReader $feedPreprocessorReader;
    private iterable $cachedFields;

    public function __construct(
        FactoryInterface $decoratedFactory,
        SalesChannelService $channelService,
        FieldsProvider $fieldsProviders,
        ExportSettings $exportSettings,
        FeedPreprocessorEntryReader $feedPreprocessorReader,
        Traversable $cachedFields
    ) {
        $this->channelService         = $channelService;
        $this->decoratedFactory       = $decoratedFactory;
        $this->fieldsProviders        = $fieldsProviders;
        $this->exportSettings         = $exportSettings;
        $this->feedPreprocessorReader = $feedPreprocessorReader;
        $this->cachedFields           = $cachedFields;
    }

    public function handle(Entity $entity): bool
    {
        return in_array(get_class($entity), [SalesChannelProductEntity::class]);
    }

    /**
     * @return ExportProductEntity[]|iterable
     */
    public function createEntities(Entity $entity, string $producedType = ExportProductEntity::class): iterable
    {
        if ($this->exportSettings->isExportCacheEnable() === false) {
            yield from $this->decoratedFactory->createEntities($entity, $producedType);

            return;
        }

        $preprocessedEntries = $this->feedPreprocessorReader->read(
            $entity->getProductNumber(),
            $this->channelService->getSalesChannelContext()->getLanguageId()
        );

        if ($preprocessedEntries === []) {
            yield from $this->decoratedFactory->createEntities($entity, $producedType);

            return;
        }

        if ($entity->getChildCount() === 0) {
            $exportProduct = $this->getExportProduct($entity, $preprocessedEntries);

            if (isset($exportProduct)) {
                yield $exportProduct;
            }

            return;
        }

        foreach ($entity->getChildren() as $child) {
            $exportProduct = $this->getExportProduct($child, $preprocessedEntries);

            if (isset($exportProduct)) {
                yield $exportProduct;
            }
        }
    }

    /**
     * @param FeedPreprocessorEntry[] $preprocessedEntries
     */
    private function getExportProduct(SalesChannelProductEntity $entity, array $preprocessedEntries): ?ExportProductEntity
    {
        $fields = $this->fieldsProviders->getFields(SalesChannelProductEntity::class);
        $cache  = $preprocessedEntries[$entity->getProductNumber()] ?? null;

        if (isset($cache)) {
            $exportProduct = new ExportProductEntity($entity, new \ArrayIterator($fields), $this->cachedFields);
            $exportProduct->setFilterAttributes($cache->getFilterAttributes());
            $exportProduct->setCustomFields($cache->getCustomFields());
            $exportProduct->setAdditionalCache(new \ArrayIterator($cache->getAdditionalCache()));

            return $exportProduct;
        }

        return null;
    }
}
