<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\class\dto\ArgumentSelectionSetDto;
use Egas\class\SageEntityMetadata;
use Egas\enum\Sage\ArticleTypeEnum;
use Egas\enum\Sage\NomenclatureTypeEnum;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\WoocommerceService;
use Egas\utils\SageTranslationUtils;
use GraphQL\RawObject;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use WC_Product;
use WP_REST_Response;

class FArticleResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fArticles';
    public const TYPE_MODEL = 'FArticle';
    public const DEFAULT_SORT = 'arRef';
    public const FILTER_TYPE = 'FArticleFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_arRef';

    private function __construct()
    {
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

    public static function supports(): bool
    {
        return true;
    }

    public function options(): array
    {
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
                            'value' => '2000-01-01T00:00:00Z'
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
    }

    public function metadata(?stdClass $sageEntity = null): array
    {
        $result = [
            ...$this->getMandatoryMetadata(),
            new SageEntityMetadata(field: '_prices', value: static fn(StdClass $stdClass): string => json_encode($stdClass->prices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
            new SageEntityMetadata(field: '_max_price', value: static fn(StdClass $stdClass): string => json_encode(WoocommerceService::getInstance()->getMaxPrice($stdClass->prices), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)),
            new SageEntityMetadata(field: '_canEditArSuiviStock', value: static fn(StdClass $stdClass) => $stdClass->canEditArSuiviStock),
        ];
        return SageService::getInstance()->addSelectionSetAsMetadata($this->selectionSet(), $result, $sageEntity);
    }

    public function bddMetadata(?int $id, bool $clearCache = false): array
    {
        if (empty($id)) {
            return [];
        }
        if ($clearCache) {
            clean_post_cache($id);
        }
        return SageService::getInstance()->get_post_meta_single($id);
    }

    public function sageEntity(?string $identifier): ?stdClass
    {
        return GraphqlService::getInstance()->getFArticle($identifier);
    }

    public function selectionSet(array $options = []): array
    {
        if ($options['checkIfExists'] ?? false) {
            return [
                ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                    'arRef',
                ]),
            ];
        }
        $result = [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'arType',
                'arPoidsNet',
                'arPoidsBrut',
                'arNomencl', // enum
                'arSuiviStock', // enum
                'arCondition', // enum U. Vente
                'arPrixTtc',
                'arUniteVen', // Unité de vente
                'canEditArSuiviStock',
                'clNo1',
                'clNo2',
                'clNo3',
                'clNo4',
                'arSommeil',
                'arEscompte',
                'arVteDebit',
                'arSommeil',
                'arContremarque',
                'arFactPoids',
                'arPublie',
                'arHorsStat',
                'arNotImp',
                'arFactForfait',
                'arUnitePoids', // enum UnitePoidsType 0 = tonne, 1 = quintal, 2 = kilogramme, 3 = gramme, 4 =  milligrame
                'arPoidsNet',
                'arPoidsBrut',
                'arCodeBarre',
            ]),
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'arPrixAch',
                'arCoef',
                'arPrixVen',
                'arPunet', // dernier prix d'achat
                'arCoutStd',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'arRef',
                'arDesign',
                'faCodeFamille',
                'arCodeFiscal',
                'arEdiCode',
                'arPays',
                'arRaccourci',
                'arLangue1',
                'arLangue2',
            ]),
            'fArtclients' => new ArgumentSelectionSetDto($this->artclientsSelectionSet(), 'acCategorie', [
                'where' => new RawObject('{ ctNum: { eq: null } }'),
            ]),
            'fArtfournisses' => new ArgumentSelectionSetDto($this->artfournisseSelectionSet(), 'ctNum'),
            'fArtglosses' => new ArgumentSelectionSetDto($this->artglossesSelectionSet(), 'glNo'),
            'fArtstocks' => new ArgumentSelectionSetDto($this->artstocksSelectionSet(), 'deNo'),
            'prices' => [
                ...FTaxeResource::getInstance()->priceSelectionSet(),
                'nCatTarif' => [
                    ...PCattarifResource::getInstance()->nCatTarifSelectionSet(),
                ],
                'nCatCompta' => [
                    ...PCatcomptaResource::getInstance()->nCatComptaSelectionSet(),
                ],
            ],
        ];
        for ($i = 1; $i <= 4; $i++) {
            $result['clNo' . $i . 'Navigation'] = FCatalogueResource::getInstance()->selectionSet();
        }
        return $result;
    }

    private function artclientsSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'acCategorie',
                'acPrixVen',
                'acCoef',
                'acPrixTtc',
                'acRemise',
                'acTypeRem',
                'acQteMont',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'ctNum',
            ]),
        ];
    }

    private function artfournisseSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'afRefFourniss',
                'afPrincipal',
                'afPrixAch',
                'ctNum'
            ]),
            'ctNumNavigation' => [
                ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                    'ctNum',
                    'ctIntitule',
                ]),
            ],
        ];
    }

    private function artglossesSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'glNo',
            ]),
            'glNoNavigation' => FGlossaireResource::getInstance()->selectionSet(),
        ];
    }

    private function artstocksSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'deNo',
                'asQteMini',
                'asQteMaxi',
                'asPrincipal',
            ]),
        ];
    }

    public function import(?string $identifier, ?stdClass $resource = null): ImportResourceResult
    {
        $fArticle = $resource ?? $this->sageEntity($identifier);
        if (is_null($fArticle)) {
            return ImportResourceResult::failure(
                "<div class='error'>" . __("L'article n'a pas pu être importé", 'egas-data-sync-for-sage') . "</div>"
            );
        }
        $canImportFArticle = $this->canImport($fArticle);
        if (!empty($canImportFArticle)) {
            return ImportResourceResult::failure(
                "<div class='error'>" . implode(' ', $canImportFArticle) . "</div>",
                status: Response::HTTP_CONFLICT,
            );
        }
        $woocommerceService = WoocommerceService::getInstance();
        $articlePostId = $woocommerceService->getWooCommerceIdArticle($identifier);
        $article = $woocommerceService->convertSageArticleToWoocommerce($fArticle, $this, $articlePostId);
        $dismissNotice = "<button type='button' class='notice-dismiss " . Sage::TOKEN . "-notice-dismiss'><span class='screen-reader-text'>" . __('Ignorer cet avis.', 'egas-data-sync-for-sage') . "</span></button>";
        $urlArticle = "<strong><span style='display: block; clear: both;'><a href='" . get_admin_url() . "post.php?post=%id%&action=edit'>" . __("Voir l'article", 'egas-data-sync-for-sage') . "</a></span></strong>";
        $status = null;
        if (is_null($articlePostId)) {
            // cannot create an article without request
            // ========================================
            // created with: (new WC_REST_Products_Controller())->create_item($request);
            // woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-crud-controller.php : public function create_item( $request )
            // which extends
            // woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-products-controller.php
            $postArticle = $article;
            $postArticle["categories"] = array_map(fn(int $categoryId): array => ['id' => $categoryId], $postArticle["categories"]);
            [$response, $responseError] = SageService::getInstance()->createResource(
                '/wc/v3/products',
                'POST',
                $postArticle,
                self::META_KEY,
                $identifier,
            );
            if (is_string($responseError)) {
                return ImportResourceResult::failure($responseError);
            }
            /** @var WP_REST_Response $response */
            $status = $response->get_status();
            if ($status !== 201) {
                return ImportResourceResult::failure(
                    json_encode($response->get_data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    status: $status,
                );
            }
            $body = $response->get_data();
            $urlArticle = str_replace('%id%', (string)$body['id'], $urlArticle);
            $articlePostId = $body['id'];
            $message = "<div class='notice notice-success is-dismissible'>
                <p>" . __('Article créé: ', 'egas-data-sync-for-sage') . $body['name'] . "</p>" . $urlArticle . "
                {$dismissNotice}
                        </div>";
        } else {
            $oldMetadata = SageService::getInstance()->get_post_meta_single($articlePostId);
            $allMetadataNames = array_map(static fn(array $meta) => $meta['key'], $article["meta_data"]);
            foreach ($oldMetadata as $key => $value) {
                if (!in_array($key, $allMetadataNames, true) && str_starts_with((string)$key, '_' . Sage::TOKEN)) {
                    delete_post_meta($articlePostId, $key);
                }
            }
            foreach ($article["meta_data"] as $meta) {
                update_post_meta($articlePostId, $meta['key'], $meta['value']);
            }
            $urlArticle = str_replace('%id%', (string)$articlePostId, $urlArticle);
            $message = "<div class='notice notice-success is-dismissible'>
                <p>" . __('Article mis à jour: ', 'egas-data-sync-for-sage') . $article["name"] . "</p>" . $urlArticle . "
                {$dismissNotice}
                        </div>";
        }
        /** @var WC_Product $wcProduct */
        $wcProduct = wc_get_product($articlePostId);
        $wcProduct->set_category_ids($article["categories"]);
        $wcProduct->set_sku($identifier); // for woocommerce to able to search the product
        $wcProduct->save();
        return ImportResourceResult::success($articlePostId, $message, $status);
    }
}
