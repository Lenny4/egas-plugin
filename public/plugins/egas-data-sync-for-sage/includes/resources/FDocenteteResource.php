<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\class\dto\ArgumentSelectionSetDto;
use Egas\enum\Sage\DomaineTypeEnum;
use Egas\Sage;
use Egas\services\GraphqlService;
use Egas\services\SageService;
use Egas\services\WoocommerceService;
use Egas\utils\FDocenteteUtils;
use Egas\utils\SageTranslationUtils;
use stdClass;
use WC_Meta_Data;
use WC_Order;

class FDocenteteResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fDocentetes';
    public const TYPE_MODEL = 'FDocentete';
    public const DEFAULT_SORT = 'doDate';
    public const FILTER_TYPE = 'FDocenteteFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_identifier';

    private function __construct()
    {
        global $wpdb;
        $this->title = __("Documents", 'egas-data-sync-for-sage');
        $this->description = __("Gestion Commerciale / Menu Traitement / Documents des ventes, des achats, des stocks et internes / Fenêtre Document", 'egas-data-sync-for-sage');
        $this->entityName = self::ENTITY_NAME;
        $this->typeModel = self::TYPE_MODEL;
        $this->defaultSortField = self::DEFAULT_SORT;
        $this->defaultFields = [
            'doDomaine',
            'doPiece',
            'doType',
            'doDate',
            Sage::META_DATA_PREFIX . '_last_update',
            Sage::META_DATA_PREFIX . '_postId',
        ];
        $this->mandatoryFields = [
            'doPiece',
            'doType',
        ];
        $this->filterType = self::FILTER_TYPE;
        $this->transDomain = SageTranslationUtils::TRANS_FDOCENTETES;
        $this->metaKeyIdentifier = self::META_KEY;
        $this->table = $wpdb->posts;
        $this->metaTable = $wpdb->prefix . 'wc_orders_meta';
        $this->metaColumnIdentifier = 'order_id';
        $this->postType = null;
        $this->importCondition = [
            new ImportConditionDto(
                field: 'doDomaine',
                value: DomaineTypeEnum::DomaineTypeVente->value,
                condition: 'eq',
                message: fn(array $fDocentete): string => __("Seuls les documents de ventes peuvent être importés.", 'egas-data-sync-for-sage') . ' [' . $fDocentete["doPiece"] . '][' . $fDocentete["doType"] . ']'),
            new ImportConditionDto(
                field: 'doType',
                value: FDocenteteUtils::DO_TYPE_MAPPABLE,
                condition: 'in',
                message: fn(array $fDocentete): string => __("Seuls les documents ayant ces doType peuvent être importés.", 'egas-data-sync-for-sage') . ' [' . implode(',', FDocenteteUtils::DO_TYPE_MAPPABLE) . '][' . $fDocentete["doPiece"] . '][' . $fDocentete["doType"] . ']'),
        ];
    }

    public static function supports(): bool
    {
        return true;
    }

    public function options(): array
    {
        // region journal
        $fJournauxs = GraphqlService::getInstance()->getFJournauxs();
        $fJournauxsOptions = [];
        $defaultFJournaux = "";
        if (!empty($fJournauxs)) {
            $defaultFJournaux = $fJournauxs[0]->joNum;
            foreach ($fJournauxs as $fJournaux) {
                $fJournauxsOptions[$fJournaux->joNum] = sprintf('[%s] %s', $fJournaux->joNum, $fJournaux->joIntitule);
            }
        }
        // endregion
        // region pReglements
        $pReglements = GraphqlService::getInstance()->getPReglements();
        if (is_array($pReglements)) {
            usort($pReglements, function (stdClass $a, stdClass $b): int {
                $word = 'carte';
                similar_text((string)$a->rIntitule, $word, $percA);
                similar_text((string)$b->rIntitule, $word, $percB);
                return $percB <=> $percA;
            });
        }
        $pReglementsOptions = [];
        $defaultPReglement = "";
        if (!empty($pReglements)) {
            $defaultPReglement = $pReglements[0]->cbIndice;
            foreach ($pReglements as $pReglement) {
                $pReglementsOptions[$pReglement->cbIndice] = $pReglement->rIntitule;
            }
        }
        // endregion
        return [
            [
                'id' => 'sage_create_new_' . self::ENTITY_NAME,
                'label' => __("Créer le document de vente dans Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Créer le document de vente dans Sage lorsqu'une nouveaulle commande Wordpress est crée.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_create_old_' . self::ENTITY_NAME,
                'label' => __('Importe les anciennes commandes.', 'egas-data-sync-for-sage'),
                'description' => __("Importe les anciennes commandes Woocommerce dans Sage.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'sage_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour le document de vente Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Met à jour le document de vente Sage lorsque la commande WooCommerce qui lui est lié est modifiée.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'off',
            ],
            [
                'id' => 'website_create_new_' . self::ENTITY_NAME,
                'label' => __("Créer la commande dans Woocommerce.", 'egas-data-sync-for-sage'),
                'description' => __("Créer la commande dans Woocommerce lorsqu'un nouveau document de vente Sage est crée.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_create_old_' . self::ENTITY_NAME,
                'label' => __("Importe les anciens documents de vente Sage.", 'egas-data-sync-for-sage'),
                'description' => __("Importe les anciens documents de vente Sage dans WooCommerce.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'website_update_' . self::ENTITY_NAME,
                'label' => __("Met à jour la commande Woocommerce.", 'egas-data-sync-for-sage'),
                'description' => __("Met à jour la commande Woocommerce lorsque le document de vente Sage qui lui est lié est modifié.", 'egas-data-sync-for-sage'),
                'type' => 'resource',
                'default' => '',
            ],
            [
                'id' => 'journal_payment_' . self::ENTITY_NAME,
                'label' => __("Journal comptable", 'egas-data-sync-for-sage'),
                'description' => __('Journal comptable dans lequel il faut écrire les paiements.', 'egas-data-sync-for-sage'),
                'type' => 'select',
                'options' => $fJournauxsOptions,
                'default' => $defaultFJournaux,
            ],
            [
                'id' => 'reglement_payment_' . self::ENTITY_NAME,
                'label' => __("Type de règlement", 'egas-data-sync-for-sage'),
                'description' => __('Type de règlement pour les paiements sur le site.', 'egas-data-sync-for-sage'),
                'type' => 'select',
                'options' => $pReglementsOptions,
                'default' => $defaultPReglement,
            ],
            [
                'id' => 'document_acompte_payment' . self::ENTITY_NAME,
                'label' => __("Créer un document d'acompte pour les paiements et remboursements.", 'egas-data-sync-for-sage'),
                'description' => __("Lorsqu’un paiement est effectué sur le site pour une commande associée à un document de vente dans Sage, et que ce document est à un stade antérieur à celui de facture, un document d’acompte est alors créé dans Sage afin de refléter le paiement ou le remboursement.", 'egas-data-sync-for-sage'),
                'type' => 'checkbox',
                'default' => 'on',
            ],
        ];
    }

    /**
     * /!\ attention pour les documents cette fonction n'est pas utilisée pour cela on utilise WoocommerceService::applyTasksSynchronizeOrder
     */
    public function metadata(?stdClass $sageEntity = null): array
    {
        $result = [
            ...$this->getMandatoryMetadata(),
        ];
        return SageService::getInstance()->addSelectionSetAsMetadata($this->selectionSet(), $result, $sageEntity);
    }

    public function bddMetadata(?int $id, bool $clearCache = false): array
    {
        if (empty($id)) {
            return [];
        }
        $wcOrder = new WC_Order($id);
        if ($clearCache) {
            $wcOrder->init_meta_data();
        }
        $result = [];
        /** @var WC_Meta_Data $item */
        foreach ($wcOrder->get_meta_data() as $item) {
            $data = $item->get_data();
            $result[$data["key"]] = $data["value"];
        }
        return $result;
    }

    public function sageEntity(?string $identifier): ?stdClass
    {
        $data = json_decode((string)$identifier, false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $results = GraphqlService::getInstance()->getFDocentetes($data->doPiece, [$data->doType]);
        if (empty($results)) {
            return null;
        }
        return $results[0];
    }

    public function getIdentifier(array $entity): string
    {
        return json_encode(['doPiece' => $entity["doPiece"], 'doType' => $entity["doType"]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function selectionSet(array $options = []): array
    {
        $result = [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', ['doType', 'doDomaine']),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'doPiece',
                'doTiers',
                'doStatut',
                'doStatutString',
                'doRef',
                'nCatCompta', // catégorie comptable
                'doTarif', // catégorie tarifaire
            ]),
        ];
        if ($options['getExpedition'] ?? false) {
            $result['doExpeditNavigation'] = PExpeditionResource::getInstance()->selectionSet();
            $result['fraisExpedition'] = $this->fraisExpeditionSelectionSet();
        }
        if ($options['getFDoclignes'] ?? false) {
            $result['fDoclignes'] = new ArgumentSelectionSetDto($this->docligneSelectionSet($options['getLotSerie'] ?? false), 'dlNo');
        }
        if ($options['getUser'] ?? false) {
            $result['doTiersNavigation'] = FComptetResource::getInstance()->selectionSet();
        }
        if ($options['getLivraison'] ?? false) {
            $result['liNoNavigation'] = FComptetResource::getInstance()->livraisonSelectionSet();
        }
        if ($options['getFDocregls'] ?? false) {
            $result['fDocregls'] = $this->docreglSelectionSet();
        }
        return $result;
    }

    private function fraisExpeditionSelectionSet(): array
    {
        return [
            ...FTaxeResource::getInstance()->priceSelectionSet(),
        ];
    }

    private function docligneSelectionSet(bool $getLotSerie = false): array
    {
        $mandatoryFields = FArticleResource::getInstance()->getMandatoryFields();
        $fArticleSelectionSet = array_filter(FArticleResource::getInstance()->selectionSet(), fn(array|ArgumentSelectionSetDto $selectionSet): bool => is_array($selectionSet) && array_key_exists('name', $selectionSet) && in_array($selectionSet['name'], $mandatoryFields));
        $r = [
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'dlMontantHt',
                // 'dlMontantTtc', // don't use dlMontantTtc because it applies ignored taxe
            ]),
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                ...array_map(static fn(string $field): string => 'dlCodeTaxe' . $field, FDocenteteUtils::ALL_TAXES),
                ...array_map(static fn(string $field): string => 'dlMontantTaxe' . $field, FDocenteteUtils::ALL_TAXES),
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'dlNo',
                'dlLigne',
                'doType',
                'dlQte',
                ...array_map(static fn(string $field): string => 'dlQte' . $field, FDocenteteUtils::FDOCLIGNE_MAPPING_DO_TYPE),
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'doPiece',
                'arRef',
                'dlDesign',
                ...array_map(static fn(string $field): string => 'dlPiece' . $field, FDocenteteUtils::FDOCLIGNE_MAPPING_DO_TYPE),
            ]),
            'arRefNavigation' => $fArticleSelectionSet,
        ];
        if ($getLotSerie) {
            $r['fLotseriesOut'] = [
                ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                    'lsNoSerie',
                ]),
                ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                    'cbMarq',
                    'dlNoIn',
                    'dlNoOut',
                    'lsQte',
                ]),
            ];
        }
        return $r;
    }

    private function docreglSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'drNo',
            ]),
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'drMontant',
            ]),
            'fRegleches' => $this->reglecheSelectionSet(),
        ];
    }

    private function reglecheSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'rcMontant',
            ]),
            'fCreglement' => $this->creglementSelectionSet(),
        ];
    }

    private function creglementSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'rgNo',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'ctNumPayeur',
                'rgDate',
                'rgReference',
                'rgLibelle',
                'joNum',
                'cgNum',
            ]),
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'rgMontant',
            ]),
        ];
    }

    public function import(?string $identifier, ?stdClass $resource = null): ImportResourceResult
    {
        $data = json_decode(stripslashes((string)$identifier), false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_' . Sage::TOKEN . '_doPiece',
                    'value' => $data->doPiece,
                ],
                [
                    'key' => '_' . Sage::TOKEN . '_doType',
                    'value' => $data->doType,
                ],
            ],
        ]);
        $order = empty($orders) ? new WC_Order() : $orders[0];
        return WoocommerceService::getInstance()->importFDocenteteIntoOrder($data->doPiece, $data->doType, $order);
    }
}
