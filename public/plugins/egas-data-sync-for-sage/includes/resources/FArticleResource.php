<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\class\SageEntityMetadata;
use Egas\enum\Sage\ArticleTypeEnum;
use Egas\enum\Sage\NomenclatureTypeEnum;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\WoocommerceService;
use Egas\utils\SageTranslationUtils;
use stdClass;

class FArticleResource extends Resource
{
    public const ENTITY_NAME = 'fArticles';
    public const TYPE_MODEL = 'FArticle';
    public const DEFAULT_SORT = 'arRef';
    public const FILTER_TYPE = 'FArticleFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_arRef';

    private static ?FArticleResource $fArticleResource = null;

    private function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->title = __("Articles", 'egas-data-sync-for-sage');
        $this->description = __("Gestion des articles", 'egas-data-sync-for-sage');
        $this->entityName = self::ENTITY_NAME;
        $this->typeModel = self::TYPE_MODEL;
        $this->defaultSortField = self::DEFAULT_SORT;
        $this->defaultFields = [
            'arRef',
            'arDesign',
            'arType',
            Sage::META_DATA_PREFIX . '_last_update',
            Sage::META_DATA_PREFIX . '_postId',
        ];
        $this->mandatoryFields = [
            'arRef',
        ];
        $this->filterType = self::FILTER_TYPE;
        $this->transDomain = SageTranslationUtils::TRANS_FARTICLES;
        $this->options = function (): array {
            $initFilter = self::getDefaultResourceFilter();
            $initFilterJson = json_encode($initFilter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return [
                [
                    'id' => 'sage_create_new_' . self::ENTITY_NAME,
                    'label' => __("Créer l'article dans Sage.", 'egas-data-sync-for-sage'),
                    'description' => __("Créer l'article dans Sage lorsqu'un nouveau produit Woocommerce est crée.", 'egas-data-sync-for-sage'),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
//            [
//                'id' => 'sage_create_old_' . self::ENTITY_NAME,
//                'label' => __("Importe les anciens produits.", 'egas-data-sync-for-sage'),
//                'description' => __("Importe les anciens produits Woocommerce dans Sage.", 'egas-data-sync-for-sage'),
//                'type' => 'checkbox',
//                'default' => 'off',
//            ],
                [
                    'id' => 'sage_update_' . self::ENTITY_NAME,
                    'label' => __("Met à jour l’article Sage.", 'egas-data-sync-for-sage'),
                    'description' => __("Met à jour l’article Sage lorsque le produit WooCommerce qui lui est lié est modifié.", 'egas-data-sync-for-sage'),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
                [
                    'id' => 'website_create_new_' . self::ENTITY_NAME,
                    'label' => __("Créer le produit dans Woocommerce.", 'egas-data-sync-for-sage'),
                    'description' => __("Créer le produit dans Woocommerce lorsqu'un nouvel article Sage est crée.", 'egas-data-sync-for-sage'),
                    'type' => 'resource',
                    'initFilter' => $initFilterJson,
                    'default' => '',
                ],
                [
                    'id' => 'website_create_old_' . self::ENTITY_NAME,
                    'label' => __("Importe les anciens articles.", 'egas-data-sync-for-sage'),
                    'description' => __("Importe les anciens articles Sage dans Woocommerce.", 'egas-data-sync-for-sage'),
                    'type' => 'resource',
                    'initFilter' => json_encode([
                        'values' => [
                            ...$initFilter['values'],
                            [
                                'field' => 'cbCreation',
                                'condition' => 'gte',
                                'value' => '2000-01-01'
                            ]
                        ]
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    'default' => '',
                ],
                [
                    'id' => 'website_update_' . self::ENTITY_NAME,
                    'label' => __("Met à jour le produit Woocommerce.", 'egas-data-sync-for-sage'),
                    'description' => __("Met à jour le produit Woocommerce lorsque l'article Sage qui lui est lié est modifié.", 'egas-data-sync-for-sage'),
                    'type' => 'resource',
                    'initFilter' => $initFilterJson,
                    'default' => '',
                ],
                // todo ajouter une option pour considérer les catalogues comme des catégories
            ];
        };
        $this->metadata = function (?stdClass $obj = null): array {
            $result = [
                ...$this->getMandatoryMetadata(),
                new SageEntityMetadata(field: '_prices', value: static fn(StdClass $stdClass): string => json_encode($stdClass->prices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                new SageEntityMetadata(field: '_max_price', value: static fn(StdClass $stdClass): string => json_encode(WoocommerceService::getInstance()->getMaxPrice($stdClass->prices), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
                new SageEntityMetadata(field: '_canEditArSuiviStock', value: static fn(StdClass $stdClass) => $stdClass->canEditArSuiviStock),
            ];
            return SageService::getInstance()->addSelectionSetAsMetadata(GraphqlService::getInstance()->_getFArticleSelectionSet(), $result, $obj);
        };
        $this->bddMetadata = function (?int $productId, bool $clearCache = false): array {
            if (empty($productId)) {
                return [];
            }
            if ($clearCache) {
                clean_post_cache($productId);
            }
            return SageService::getInstance()->get_post_meta_single($productId);
        };
        $this->sageEntity = fn(?string $arRef): StdClass|null => GraphqlService::getInstance()->getFArticle($arRef);
        $this->importFromSage = fn(?string $arRef, stdClass|string|null $fArticle = null, $showSuccessMessage = true): array => WoocommerceService::getInstance()->importFArticleFromSage($arRef, showSuccessMessage: $showSuccessMessage);
        $this->metaKeyIdentifier = self::META_KEY;
        $this->table = $wpdb->posts;
        $this->metaTable = $wpdb->postmeta;
        $this->metaColumnIdentifier = 'post_id';
        $this->postType = 'product';
        $this->importCondition = [
            new ImportConditionDto(
                field: 'arType',
                value: [
                    ArticleTypeEnum::ArticleTypeStandard->value,
                ],
                condition: 'in',
                message: fn(array $fArticle): string => __("Seuls les articles standard peuvent être importés.", 'egas-data-sync-for-sage') . ' [' . $fArticle["arRef"] . ']'
            ),
            new ImportConditionDto(
                field: 'arNomencl',
                value: NomenclatureTypeEnum::NomenclatureTypeAucun->value,
                condition: 'eq',
                message: fn(array $fArticle): string => __("Seuls les articles ayant une nomenclature Aucun peuvent être importés.", 'egas-data-sync-for-sage') . ' [' . $fArticle["arRef"] . ']'
            ),
        ];
        $this->import = static function (string $identifier) {
            [$response, $responseError, $message, $postId] = WoocommerceService::getInstance()->importFArticleFromSage(
                $identifier,
            );
            return $postId;
        };
        $this->selectionSet = fn(): array => GraphqlService::getInstance()->_getFArticleSelectionSet();
    }

    public static function getDefaultResourceFilter(): array
    {
        return [
            'values' => [
                [
                    'field' => 'arPublie',
                    'condition' => 'eq',
                    'value' => true
                ]
            ]
        ];
    }

    public static function getInstance(): self
    {
        if (self::$fArticleResource === null) {
            self::$fArticleResource = new self();
        }
        return self::$fArticleResource;
    }

    public static function supports(): bool
    {
        return true;
    }
}
