<?php

namespace App\resources;

use App\class\SageEntityMetadata;
use App\enum\Sage\DomaineTypeEnum;
use App\Sage;
use App\services\GraphqlService;
use App\services\SageService;
use App\services\WoocommerceService;
use App\utils\FDocenteteUtils;
use App\utils\SageTranslationUtils;
use stdClass;
use WC_Meta_Data;
use WC_Order;

class FDocenteteResource extends Resource
{
    public const ENTITY_NAME = 'fDocentetes';
    public const TYPE_MODEL = 'FDocentete';
    public const DEFAULT_SORT = 'doDate';
    public const FILTER_TYPE = 'FDocenteteFilterInput';
    public final const META_KEY = '_' . Sage::TOKEN . '_identifier';

    private static ?FDocenteteResource $instance = null;

    private function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->title = __("Documents", Sage::TOKEN);
        $this->description = __("Gestion Commerciale / Menu Traitement / Documents des ventes, des achats, des stocks et internes / Fenêtre Document", Sage::TOKEN);
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
        $this->options = function (): array {
            // region journal
            $fJournauxs = GraphqlService::getInstance()->getFJournauxs();
            $fJournauxsOptions = [];
            $defaultFJournaux = "";
            if (!empty($fJournauxs)) {
                $defaultFJournaux = $fJournauxs[0]->joNum;
                foreach ($fJournauxs as $fJournaux) {
                    $fJournauxsOptions[$fJournaux->joNum] = "[$fJournaux->joNum] $fJournaux->joIntitule";
                }
            }
            // endregion
            // region pReglements
            $pReglements = GraphqlService::getInstance()->getPReglements();
            usort($pReglements, function (stdClass $a, stdClass $b) {
                $word = 'carte';
                similar_text($a->rIntitule, $word, $percA);
                similar_text($b->rIntitule, $word, $percB);
                return $percB <=> $percA;
            });
            $pReglementsOptions = [];
            $defaultPReglement = "";
            if (!empty($pReglements)) {
                $defaultPReglement = $pReglements[0]->cbIndice;
                foreach ($pReglements as $pReglement) {
                    $pReglementsOptions[$pReglement->cbIndice] = "$pReglement->rIntitule";
                }
            }
            // endregion
            return [
                [
                    'id' => 'sage_create_new_' . self::ENTITY_NAME,
                    'label' => __("Créer le document de vente dans Sage.", Sage::TOKEN),
                    'description' => __("Créer le document de vente dans Sage lorsqu'une nouveaulle commande Wordpress est crée.", Sage::TOKEN),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
                [
                    'id' => 'sage_create_old_' . self::ENTITY_NAME,
                    'label' => __('Importe les anciennes commandes.', Sage::TOKEN),
                    'description' => __("Importe les anciennes commandes Woocommerce dans Sage.", Sage::TOKEN),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
                [
                    'id' => 'sage_update_' . self::ENTITY_NAME,
                    'label' => __("Met à jour le document de vente Sage.", Sage::TOKEN),
                    'description' => __("Met à jour le document de vente Sage lorsque la commande WooCommerce qui lui est lié est modifiée.", Sage::TOKEN),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
                [
                    'id' => 'website_create_new_' . self::ENTITY_NAME,
                    'label' => __("Créer la commande dans Woocommerce.", Sage::TOKEN),
                    'description' => __("Créer la commande dans Woocommerce lorsqu'un nouveau document de vente Sage est crée.", Sage::TOKEN),
                    'type' => 'resource',
                    'default' => '',
                ],
                [
                    'id' => 'website_create_old_' . self::ENTITY_NAME,
                    'label' => __("Importe les anciens documents de vente Sage.", Sage::TOKEN),
                    'description' => __("Importe les anciens documents de vente Sage dans WooCommerce.", Sage::TOKEN),
                    'type' => 'resource',
                    'default' => '',
                ],
                [
                    'id' => 'website_update_' . self::ENTITY_NAME,
                    'label' => __("Met à jour la commande Woocommerce.", Sage::TOKEN),
                    'description' => __("Met à jour la commande Woocommerce lorsque le document de vente Sage qui lui est lié est modifié.", Sage::TOKEN),
                    'type' => 'checkbox',
                    'default' => 'off',
                ],
                [
                    'id' => 'journal_payment_' . self::ENTITY_NAME,
                    'label' => __("Journal comptable", Sage::TOKEN),
                    'description' => __('Journal comptable dans lequel il faut écrire les paiements.', Sage::TOKEN),
                    'type' => 'select',
                    'options' => $fJournauxsOptions,
                    'default' => $defaultFJournaux,
                ],
                [
                    'id' => 'reglement_payment_' . self::ENTITY_NAME,
                    'label' => __("Type de règlement", Sage::TOKEN),
                    'description' => __('Type de règlement pour les paiements sur le site.', Sage::TOKEN),
                    'type' => 'select',
                    'options' => $pReglementsOptions,
                    'default' => $defaultPReglement,
                ],
            ];
        };
        $this->metadata = function (?stdClass $obj = null): array {
            // /!\ attention pour les documents cette fonction n'est pas utilisé pour cela on utilise function applyTasksSynchronizeOrder
            $result = [
                ...$this->getMandatoryMetadata(),
            ];
            return SageService::getInstance()->addSelectionSetAsMetadata(GraphqlService::getInstance()->_getFDocenteteSelectionSet(), $result, $obj);
        };
        $this->bddMetadata = function (?int $orderId, bool $clearCache = false): array {
            if (empty($orderId)) {
                return [];
            }
            $order = new WC_Order($orderId);
            if ($clearCache) {
                $order->init_meta_data();
            }
            $result = [];
            /** @var WC_Meta_Data $item */
            foreach ($order->get_meta_data() as $item) {
                $data = $item->get_data();
                $result[$data["key"]] = $data["value"];
            }
            return $result;
        };
        $this->sageEntity = function (?string $identifier): StdClass|null {
            $data = json_decode($identifier, false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $results = GraphqlService::getInstance()->getFDocentetes($data->doPiece, [$data->doType]);
            if (empty($results)) {
                return null;
            }
            return $results[0];
        };
        $this->importFromSage = function (?string $identifier, stdClass|string|null $fDocentete = null, $showSuccessMessage = true): array|string {
            $data = json_decode($identifier, false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return WoocommerceService::getInstance()->importFDocenteteFromSage($data->doPiece, $data->doType);
        };
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
                message: fn(array $fDocentete): string => __("Seuls les documents de ventes peuvent être importés.", Sage::TOKEN) . ' [' . $fDocentete["doPiece"] . '][' . $fDocentete["doType"] . ']'),
            new ImportConditionDto(
                field: 'doType',
                value: FDocenteteUtils::DO_TYPE_MAPPABLE,
                condition: 'in',
                message: fn(array $fDocentete): string => __("Seuls les documents ayant ces doType peuvent être importés.", Sage::TOKEN) . ' [' . implode(',', FDocenteteUtils::DO_TYPE_MAPPABLE) . '][' . $fDocentete["doPiece"] . '][' . $fDocentete["doType"] . ']'),
        ];
        $this->import = static function (string $identifier) {
            $data = json_decode(stripslashes($identifier), false, 512, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            [$result, $errorMessage, $message, $order] = WoocommerceService::getInstance()->importFDocenteteFromSage($data->doPiece, $data->doType);
            return $order->get_id();
        };
        $this->selectionSet = fn(): array => GraphqlService::getInstance()->_getFDocenteteSelectionSet();
        $this->getIdentifier = static fn(array $fDocentete) => json_encode(['doPiece' => $fDocentete["doPiece"], 'doType' => $fDocentete["doType"]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function supports(): bool
    {
        return true;
    }
}
