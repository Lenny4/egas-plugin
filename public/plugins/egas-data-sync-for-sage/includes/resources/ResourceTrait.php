<?php

declare(strict_types=1);

namespace Egas\resources;

use DateTime;
use Egas\class\SageEntityMetadata;
use Egas\Sage;
use LogicException;
use stdClass;

/**
 * Shared storage + default behaviour for concrete {@see Resource} implementations.
 * Each class using this trait still needs its own private constructor setting up
 * the properties below, and must implement the entity-specific behaviour that has
 * no sensible default (import, sageEntity, selectionSet, metadata, bddMetadata).
 */
trait ResourceTrait
{
    private static ?self $instance = null;

    protected string $title;
    protected string $description;
    protected string $entityName;
    protected string $typeModel;
    protected string $defaultSortField;
    protected array $defaultFields = [];
    protected array $mandatoryFields = [];
    protected string $filterType;
    protected string $transDomain;
    protected string $metaKeyIdentifier;
    protected string $table;
    protected ?string $postType = null;
    protected string $metaTable;
    protected string $metaColumnIdentifier;

    /**
     * @var ImportConditionDto[]
     */
    protected array $importCondition = [];

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public static function supports(): bool
    {
        return false;
    }

    public static function getDefaultResourceFilter(): array
    {
        return ['values' => []];
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getEntityName(): string
    {
        return $this->entityName;
    }

    public function setEntityName(string $entityName): static
    {
        $this->entityName = $entityName;
        return $this;
    }

    public function getTypeModel(): string
    {
        return $this->typeModel;
    }

    public function setTypeModel(string $typeModel): static
    {
        $this->typeModel = $typeModel;
        return $this;
    }

    public function getDefaultSortField(): string
    {
        return $this->defaultSortField;
    }

    public function setDefaultSortField(string $defaultSortField): static
    {
        $this->defaultSortField = $defaultSortField;
        return $this;
    }

    public function getDefaultFields(): array
    {
        return $this->defaultFields;
    }

    public function setDefaultFields(array $defaultFields): static
    {
        $this->defaultFields = $defaultFields;
        return $this;
    }

    public function getMandatoryFields(): array
    {
        return $this->mandatoryFields;
    }

    public function setMandatoryFields(array $mandatoryFields): static
    {
        $this->mandatoryFields = $mandatoryFields;
        return $this;
    }

    public function getFilterType(): string
    {
        return $this->filterType;
    }

    public function setFilterType(string $filterType): static
    {
        $this->filterType = $filterType;
        return $this;
    }

    public function getTransDomain(): string
    {
        return $this->transDomain;
    }

    public function setTransDomain(string $transDomain): static
    {
        $this->transDomain = $transDomain;
        return $this;
    }

    public function getMetaKeyIdentifier(): string
    {
        return $this->metaKeyIdentifier;
    }

    public function setMetaKeyIdentifier(string $metaKeyIdentifier): static
    {
        $this->metaKeyIdentifier = $metaKeyIdentifier;
        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    public function getPostType(): ?string
    {
        return $this->postType;
    }

    public function setPostType(?string $postType): static
    {
        $this->postType = $postType;
        return $this;
    }

    public function getMetaTable(): string
    {
        return $this->metaTable;
    }

    public function setMetaTable(string $metaTable): static
    {
        $this->metaTable = $metaTable;
        return $this;
    }

    public function getMetaColumnIdentifier(): string
    {
        return $this->metaColumnIdentifier;
    }

    public function setMetaColumnIdentifier(string $metaColumnIdentifier): static
    {
        $this->metaColumnIdentifier = $metaColumnIdentifier;
        return $this;
    }

    public function getImportCondition(): array
    {
        return $this->importCondition;
    }

    public function setImportCondition(array $importCondition): static
    {
        $this->importCondition = $importCondition;
        return $this;
    }

    public function getMandatoryMetadata(): array
    {
        return [
            new SageEntityMetadata(field: '_updateApi'),
            new SageEntityMetadata(field: '_postId', showInOptions: true),
            new SageEntityMetadata(field: '_last_update', value: static fn(StdClass $stdClass): string => (new DateTime())->format('Y-m-d H:i:s'), showInOptions: true),
        ];
    }

    public function canImport(stdClass|array|null $entity): array
    {
        $r = [];
        if (empty($entity)) {
            return $r;
        }

        $entity = (array)$entity;
        foreach ($this->importCondition as $importCondition) {
            $v = $entity[$importCondition->getField()];
            if (is_array($importCondition->getValue())) {
                if (!in_array($v, $importCondition->getValue())) {
                    $r[] = $importCondition->getMessage()($entity);
                }
            } elseif ($v !== $importCondition->getValue()) {
                $r[] = $importCondition->getMessage()($entity);
            }
        }

        return $r;
    }

    public function options(): array
    {
        return [];
    }

    public function postUrl(array $entity): ?string
    {
        if (!empty($entity['_' . Sage::TOKEN . '_postId'])) {
            return admin_url('post.php?post=' . $entity['_' . Sage::TOKEN . '_postId']) . '&action=edit';
        }
        return null;
    }

    public function getIdentifier(array $entity): string
    {
        $mandatoryField = $this->mandatoryFields[0] ?? null;
        return (string)($entity[$mandatoryField] ?? '');
    }

    public function import(?string $identifier, ?stdClass $resource = null): ImportResourceResult
    {
        throw new LogicException(static::class . ' does not support import().');
    }

    public function sageEntity(?string $identifier): ?stdClass
    {
        throw new LogicException(static::class . ' does not support sageEntity().');
    }

    public function selectionSet(array $options = []): array
    {
        throw new LogicException(static::class . ' does not support selectionSet().');
    }

    public function metadata(?stdClass $sageEntity = null): array
    {
        throw new LogicException(static::class . ' does not support metadata().');
    }

    public function bddMetadata(?int $id, bool $clearCache = false): array
    {
        throw new LogicException(static::class . ' does not support bddMetadata().');
    }

    protected function formatOperationFilterInput(string $type, array $fields): array
    {
        return array_map(static fn(string $field): array => [
            'name' => $field,
            'type' => $type,
        ], $fields);
    }
}
