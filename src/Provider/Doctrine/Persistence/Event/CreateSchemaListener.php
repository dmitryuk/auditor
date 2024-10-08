<?php

declare(strict_types=1);

namespace DH\Auditor\Provider\Doctrine\Persistence\Event;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Persistence\Event\CreateSchemaListenerTest;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;

/**
 * @see CreateSchemaListenerTest
 */
final readonly class CreateSchemaListener
{
    public function __construct(private DoctrineProvider $provider) {}

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $metadata = $eventArgs->getClassMetadata();

        // check inheritance type and returns if unsupported
        if (!\in_array($metadata->inheritanceType, [
            ClassMetadata::INHERITANCE_TYPE_NONE,
            ClassMetadata::INHERITANCE_TYPE_JOINED,
            ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE,
        ], true)) {
            throw new \Exception(\sprintf('Inheritance type "%s" is not yet supported', $metadata->inheritanceType));
        }

        $targetEntity = $metadata->name;
        // check if entity or its children are audited
        if (!$this->provider->isAuditable($metadata->name)) {
            $audited = false;
            if (
                $metadata->rootEntityName === $metadata->name
                && ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType
            ) {
                foreach ($metadata->subClasses as $subClass) {
                    if ($this->provider->isAuditable($subClass)) {
                        $audited = true;
                        $targetEntity = $subClass;

                        break;
                    }
                }
            }

            if (!$audited) {
                return;
            }
        }

        $auditingServices = $this->provider->getAuditingServices();
        $storageServices = $this->provider->getStorageServices();

        \assert(array_values($auditingServices)[0] instanceof AuditingService); // helps PHPStan
        \assert(array_values($storageServices)[0] instanceof StorageService);   // helps PHPStan
        $isSameEntityManager = 1 === \count($auditingServices) && 1 === \count($storageServices)
            && array_values($auditingServices)[0]->getEntityManager() === array_values($storageServices)[0]->getEntityManager();

        $updater = new SchemaManager($this->provider);
        $updater->createAuditTable($targetEntity, $isSameEntityManager ? $eventArgs->getSchema() : null);
    }
}
