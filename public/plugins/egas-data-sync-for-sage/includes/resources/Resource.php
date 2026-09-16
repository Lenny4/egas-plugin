<?php

declare(strict_types=1);

namespace Egas\resources;

use stdClass;

interface Resource
{
    public static function getInstance(): static;

    public static function supports(): bool;

    public static function getDefaultResourceFilter(): array;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getDescription(): string;

    public function setDescription(string $description): static;

    /**
     * Field to request this entity in GraphQL
     */
    public function getEntityName(): string;

    public function setEntityName(string $entityName): static;

    /**
     * Model of this entity in GraphQL
     */
    public function getTypeModel(): string;

    public function setTypeModel(string $typeModel): static;

    public function getDefaultSortField(): string;

    public function setDefaultSortField(string $defaultSortField): static;

    /**
     * Default fields selected in settings wp-admin/admin.php?page=sage_settings&tab=fDocentetes
     */
    public function getDefaultFields(): array;

    public function setDefaultFields(array $defaultFields): static;

    /**
     * Fields that we must request even if they are not selected in the fields to show
     * these fields allow to identify this entity
     */
    public function getMandatoryFields(): array;

    public function setMandatoryFields(array $mandatoryFields): static;

    /**
     * Filter type of this entity in GraphQL
     */
    public function getFilterType(): string;

    public function setFilterType(string $filterType): static;

    public function getTransDomain(): string;

    public function setTransDomain(string $transDomain): static;

    /**
     * Meta key which give the identifier value
     */
    public function getMetaKeyIdentifier(): string;

    public function setMetaKeyIdentifier(string $metaKeyIdentifier): static;

    public function getTable(): string;

    public function setTable(string $table): static;

    /**
     * used for public function removeUpdateApi
     */
    public function getPostType(): ?string;

    public function setPostType(?string $postType): static;

    /**
     * Meta table to use
     */
    public function getMetaTable(): string;

    public function setMetaTable(string $metaTable): static;

    /**
     * Column in the meta table to use to identify
     */
    public function getMetaColumnIdentifier(): string;

    public function setMetaColumnIdentifier(string $metaColumnIdentifier): static;

    /**
     * @return ImportConditionDto[]
     */
    public function getImportCondition(): array;

    /**
     * @param ImportConditionDto[] $importCondition
     */
    public function setImportCondition(array $importCondition): static;

    public function getMandatoryMetadata(): array;

    /**
     * Imports this entity from Sage using $identifier (or the already-fetched $resource, when available,
     * to avoid a duplicate Sage round-trip).
     */
    public function import(?string $identifier, ?stdClass $resource = null): ImportResourceResult;

    /**
     * Fetches the raw Sage entity matching $identifier.
     */
    public function sageEntity(?string $identifier): ?stdClass;

    /**
     * Builds the GraphQL selection set used to query this entity from Sage.
     */
    public function selectionSet(array $options = []): array;

    /**
     * Converts a Sage entity into the WordPress metadata array stored alongside the imported post/user.
     */
    public function metadata(?stdClass $sageEntity = null): array;

    /**
     * Reads the WordPress-side metadata currently stored for $id.
     */
    public function bddMetadata(?int $id, bool $clearCache = false): array;

    /**
     * Further options to show besides "Fields to show" and "Default per page"
     */
    public function options(): array;

    public function postUrl(array $entity): ?string;

    /**
     * Can be used when the Sage entity has multiple columns as id
     */
    public function getIdentifier(array $entity): string;

    /**
     * @return string[] Human-readable reasons preventing the import, empty when the entity can be imported
     */
    public function canImport(stdClass|array|null $entity): array;
}
