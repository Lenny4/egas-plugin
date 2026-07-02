<?php

namespace App\controllers;

use App\class\SageExpectedOption;
use App\resources\ImportConditionDto;
use App\resources\Resource;
use App\Sage;
use App\services\GraphqlService;
use App\services\SageService;
use App\services\TwigService;

class AdminController
{
    private static array|null $settings = null;

    public static function addSections(): void
    {
        $settings = self::getSettings();
        // Check posted/selected tab.
        $current_section = '';
        if (isset($_POST['tab']) && $_POST['tab']) {
            $current_section = sanitize_text_field(wp_unslash($_POST['tab']));
        } elseif (isset($_GET['tab']) && $_GET['tab']) {
            $current_section = sanitize_text_field(wp_unslash($_GET['tab']));
        }
        foreach ($settings as $section => $data) {

            if ($current_section && $current_section !== $section) {
                continue;
            }

            // Add section to page.
            add_settings_section($section, $data['title'], function (array $section) use ($settings): void {
                $html = '<p>' . esc_attr($settings[$section['id']]['description']) . '</p>' . "\n";
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $html;
            }, Sage::TOKEN . '_settings');

            foreach ($data['fields'] as $field) {

                // Validation callback for field.
                $validation = '';
                if (isset($field['callback'])) {
                    $validation = $field['callback'];
                }

                // Register field.
                $option_name = Sage::TOKEN . '_' . $field['id'];
                register_setting(Sage::TOKEN . '_settings', $option_name, $validation);
                $resource = null;
                if (isset($data["resource"])) {
                    $resource = $data["resource"];
                }
                // Add field to page.
                add_settings_field(
                    $field['id'],
                    $field['label'],
                    function (...$args) use ($resource): void {
                        AdminController::display_field(...$args, resource: $resource);
                    },
                    Sage::TOKEN . '_settings',
                    $section,
                    ['field' => $field, 'prefix' => Sage::TOKEN . '_']
                );
            }

            if (!$current_section) {
                break;
            }
        }
    }

    private static function getSettings(): ?array
    {
        if (is_null(self::$settings)) {
            $url = wp_parse_url(get_site_url());
            $defaultWordpressUrl = $url["scheme"] . '://' . $url["host"];
            global $wpdb;
            $devFields = [];
            if (WP_DEBUG) {
                $devFields = [
                    [
                        'id' => 'wordpress_db_host',
                        'label' => __('Adresse du serveur de base de données WordPress', 'egas'),
                        'description' => __("Renseignez l'adresse IP ou le nom d'hôte permettant à l'API Sage d'accéder à la base de données WordPress.", 'egas'),
                        'type' => 'text',
                        'default' => $wpdb->dbhost,
                        'placeholder' => $wpdb->dbhost
                    ],
                    [
                        'id' => 'wordpress_db_name',
                        'label' => __('Nom de la base de données WordPress', 'egas'),
                        'description' => __('Renseignez le nom de la base de données WordPress.', 'egas'),
                        'type' => 'text',
                        'default' => $wpdb->dbname,
                        'placeholder' => $wpdb->dbname
                    ],
                    [
                        'id' => 'wordpress_db_username',
                        'label' => __("Nom d'utilisateur de la base de données WordPress", 'egas'),
                        'description' => __("Renseignez le nom d'utilisateur utilisé pour accéder à la base de données WordPress.", 'egas'),
                        'type' => 'text',
                        'default' => $wpdb->dbuser,
                        'placeholder' => $wpdb->dbuser
                    ],
                    [
                        'id' => 'wordpress_db_password',
                        'label' => __('Mot de passe de la base de données WordPress', 'egas'),
                        'description' => __('Renseignez le mot de passe utilisé pour accéder à la base de données WordPress.', 'egas'),
                        'type' => 'text',
                        'default' => $wpdb->dbpassword,
                        'placeholder' => $wpdb->dbpassword
                    ],
                ];
            }
            $settings = [
                'api' => [
                    'title' => __('Api', 'egas'),
                    'description' => '',
                    'fields' => [
                        [
                            'id' => 'api_key',
                            'label' => __('Clé API', 'egas'),
                            'description' => __('Ouvrez l’application Sage API Manager et renseignez la clé API disponible dans le détail de votre configuration.', 'egas'),
                            'type' => 'text',
                            'default' => '',
                            'placeholder' => __('XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX', 'egas')
                        ],
                        [
                            'id' => 'api_host_url',
                            'label' => __("Adresse de l'API Sage", 'egas'),
                            'description' => __("Renseignez le nom de domaine (s'il est configuré) ou l'adresse de votre API Sage.", 'egas'),
                            'type' => 'text',
                            'default' => '',
                            'placeholder' => __('https://monsite-exemple.fr', 'egas'),
                            // https://windows
                        ],
                        [
                            'id' => 'activate_https_verification_graphql',
                            'label' => __('Vérifier le certificat HTTPS de l’API', 'egas'),
                            'description' => __("Décochez cette case si vous obtenez l'erreur : « cURL error 60: SSL certificate problem: self-signed certificate ». ", 'egas'),
                            'type' => 'checkbox',
                            'default' => 'on'
                        ],
                        [
                            'id' => 'wordpress_host_url',
                            'label' => __('Adresse de WordPress', 'egas'),
                            'description' => __("Renseignez l'adresse à laquelle l'API Sage peut joindre WordPress.", 'egas'),
                            'type' => 'text',
                            'default' => $defaultWordpressUrl,
                            'placeholder' => $defaultWordpressUrl
                        ],
                        [
                            'id' => 'activate_https_verification_wordpress',
                            'label' => __('Vérifier le certificat HTTPS de WordPress', 'egas'),
                            'description' => __("Décochez cette case si vous obtenez l'erreur : « The SSL connection could not be established, see inner exception ». ", 'egas'),
                            'type' => 'checkbox',
                            'default' => 'on'
                        ],
                        ...$devFields,
                        [
                            'id' => 'nb_threads',
                            'label' => __("Nombre d'opérations simultanées", 'egas'),
                            'description' => __("Définissez le nombre maximal d'opérations exécutées en parallèle. Augmentez cette valeur uniquement si votre serveur dispose de ressources suffisantes.", 'egas'),
                            'type' => 'number',
                            'default' => '1',
                            'placeholder' => __('1', 'egas')
                        ],
                    ]
                ],
            ];
            $sageService = SageService::getInstance();
            foreach ($sageService->getResources() as $resource) {
                $showFields = [];
                $filterFields = [];
                foreach ($sageService->getFieldsForEntity($resource) as $key => $fieldOption) {
                    $showFields[$key] = $fieldOption["label"];
                    if ($fieldOption['isFilter']) {
                        $filterFields[$key] = $fieldOption["label"];
                    }
                }
                $defaultFields = $resource->getDefaultFields();
                $options = [
                    [
                        'id' => $resource->getEntityName() . '_show_fields',
                        'label' => __('Champs à montrer', 'egas'),
                        'description' => __('Veuillez sélectionner les champs à afficher sur le tableau.', 'egas'),
                        'type' => '2_select_multi',
                        'options' => $showFields,
                        'default' => $defaultFields,
                    ],
                    [
                        'id' => $resource->getEntityName() . '_filter_fields',
                        'label' => __('Champs pouvant être filtrés', 'egas'),
                        'description' => __('Veuillez sélectionner les champs pouvant servir à filter vos résultats.', 'egas'),
                        'type' => '2_select_multi',
                        'options' => array_filter($filterFields, static fn(string $key): bool => !str_starts_with($key, Sage::PREFIX_META_DATA), ARRAY_FILTER_USE_KEY),
                        'default' => array_filter($defaultFields, static fn(string $v): bool => !str_starts_with($v, Sage::PREFIX_META_DATA)),
                    ],
                    [
                        'id' => $resource->getEntityName() . '_perPage',
                        'label' => __("Nombre d'élément par défaut par page", 'egas'),
                        'description' => __('Veuillez sélectionner le nombre de lignes à afficher sur le tableau.', 'egas'),
                        'type' => 'select',
                        'options' => array_combine(Sage::$paginationRange, Sage::$paginationRange),
                        'default' => (string)Sage::$defaultPagination,
                    ],
                    ...$resource->getOptions()(),
                ];
                $resource->setOptions(fn() => $options);
                $settings[$resource->getEntityName()] = [
                    'title' => $resource->getTitle(),
                    'description' => $resource->getDescription(),
                    'fields' => $options,
                    'resource' => $resource,
                ];
            }
            self::$settings = $settings;
        }
        return self::$settings;
    }

    /**
     * Generate HTML for displaying fields.
     *
     * @param array $data Data array.
     * @param object|null $post Post object.
     * @param boolean $echo Whether to echo the field HTML or return it.
     */
    public static function display_field(array $data = [], object $post = null, bool $echo = true, ?Resource $resource = null): string
    {

        // Get field info.
        $field = $data['field'] ?? $data;

        // Check for prefix on option name.
        $option_name = '';
        if (isset($data['prefix'])) {
            $option_name = $data['prefix'];
        }

        // Get saved data.
        $data = '';
        $option_name .= $field['id'];
        if ($post !== null) {

            // Get saved field data.
            $option = get_post_meta($post->ID, $field['id'], true);

            // Get data to display in field.
        } else {

            // Get saved option.
            $option = get_option($option_name);

            // Get data to display in field.
        }

        if (isset($option)) {
            $data = $option;
        }

        // Show default data if no option saved and default is supplied.
        if (false === $data && isset($field['default'])) {
            $data = $field['default'];
        } elseif (false === $data) {
            $data = '';
        }

        $html = '';

        switch ($field['type']) {

            case 'text':
            case 'url':
            case 'email':
                $html .= '<input id="' . esc_attr($field['id']) . '" type="text" name="' . esc_attr($option_name) . '" placeholder="' . esc_attr($field['placeholder']) . '" value="' . esc_attr($data) . '" />' . "\n";
                break;
            case 'date':
                $html .= '<input id="' . esc_attr($field['id']) . '" type="date" name="' . esc_attr($option_name) . '" placeholder="' . esc_attr($field['placeholder']) . '" value="' . esc_attr($data) . '" />' . "\n";
                break;
            case 'password':
            case 'number':
            case 'hidden':
                $min = '';
                if (isset($field['min'])) {
                    $min = ' min="' . esc_attr($field['min']) . '"';
                }

                $max = '';
                if (isset($field['max'])) {
                    $max = ' max="' . esc_attr($field['max']) . '"';
                }

                $html .= '<input id="' . esc_attr($field['id']) . '" type="' . esc_attr($field['type']) . '" name="' . esc_attr($option_name) . '" placeholder="' . esc_attr($field['placeholder']) . '" value="' . esc_attr($data) . '"' . $min . $max . '/>' . "\n";
                break;
            case 'text_secret':
                $html .= '<input id="' . esc_attr($field['id']) . '" type="text" name="' . esc_attr($option_name) . '" placeholder="' . esc_attr($field['placeholder']) . '" value="" />' . "\n";
                break;
            case 'textarea':
                $html .= '<textarea id="' . esc_attr($field['id']) . '" rows="5" cols="50" name="' . esc_attr($option_name) . '" placeholder="' . esc_attr($field['placeholder']) . '">' . $data . '</textarea><br/>' . "\n";
                break;
            case 'checkbox':
                $checked = '';
                if ('on' === $data) {
                    $checked = 'checked="checked"';
                }

                $html .= '<input id="' . esc_attr($field['id']) . '" type="' . esc_attr($field['type']) . '" name="' . esc_attr($option_name) . '" ' . $checked . '/>' . "\n";
                break;
            case 'checkbox_multi':
                foreach ($field['options'] as $k => $v) {
                    $checked = false;
                    if (in_array($k, (array)$data, true)) {
                        $checked = true;
                    }

                    $html .= '<p><label for="' . esc_attr($field['id'] . '_' . $k) . '" class="checkbox_multi"><input type="checkbox" ' . checked($checked, true, false) . ' name="' . esc_attr($option_name) . '[]" value="' . esc_attr($k) . '" id="' . esc_attr($field['id'] . '_' . $k) . '" /> ' . $v . '</label></p> ';
                }

                break;
            case 'radio':
                foreach ($field['options'] as $k => $v) {
                    $checked = false;
                    if ($k === $data) {
                        $checked = true;
                    }

                    $html .= '<label for="' . esc_attr($field['id'] . '_' . $k) . '"><input type="radio" ' . checked($checked, true, false) . ' name="' . esc_attr($option_name) . '" value="' . esc_attr($k) . '" id="' . esc_attr($field['id'] . '_' . $k) . '" /> ' . $v . '</label> ';
                }

                break;
            case 'select':
                $html .= '<select name="' . esc_attr($option_name) . '" id="' . esc_attr($field['id']) . '">';
                foreach ($field['options'] as $k => $v) {
                    $selected = false;
                    if ((string)$k === (string)$data) {
                        $selected = true;
                    }

                    $html .= '<option ' . selected($selected, true, false) . ' value="' . esc_attr($k) . '">' . $v . '</option>';
                }

                $html .= '</select> ';
                break;
            case 'select_multi':
                $html .= '<select name="' . esc_attr($option_name) . '[]" id="' . esc_attr($field['id']) . '" multiple="multiple">';
                foreach ($field['options'] as $k => $v) {
                    $selected = false;
                    if (in_array($k, (array)$data, true)) {
                        $selected = true;
                    }

                    $html .= '<option ' . selected($selected, true, false) . ' value="' . esc_attr($k) . '">' . $v . '</option>';
                }

                $html .= '</select> ';
                break;
            case 'image':
                $image_thumb = '';
                if ($data) {
                    $image_thumb = wp_get_attachment_thumb_url($data);
                }

                $html .= '<img id="' . $option_name . '_preview" alt="' . $option_name . '" class="image_preview" src="' . $image_thumb . '" /><br/>' . "\n";
                $html .= '<input id="' . $option_name . '_button" type="button" data-uploader_title="' . __('Upload an image', 'egas') . '" data-uploader_button_text="' . __('Use image', 'egas') . '" class="image_upload_button button" value="' . __('Upload new image', 'egas') . '" />' . "\n";
                $html .= '<input id="' . $option_name . '_delete" type="button" class="image_delete_button button" value="' . __('Remove image', 'egas') . '" />' . "\n";
                $html .= '<input id="' . $option_name . '" class="image_data_field" type="hidden" name="' . $option_name . '" value="' . $data . '"/><br/>' . "\n";
                break;
            case 'color':
                ?>
                <div class="color-picker" style="position:relative;">
                    <label>
                        <input type="text" name="<?php esc_attr($option_name); ?>" class="color"
                               value="<?php esc_attr($data); ?>"/>
                    </label>
                    <div style="position:absolute;background:#FFF;z-index:99;border-radius:100%;"
                         class="colorpicker"></div>
                </div>
                <?php
                break;
            case 'editor':
                wp_editor(
                    $data,
                    $option_name,
                    ['textarea_name' => $option_name]
                );
                break;
            case '2_select_multi':
                $html .= TwigService::getInstance()->render('common/form/2_select_multi.html.twig', [
                    'data' => [
                        'optionName' => $option_name,
                        'field' => $field,
                        'values' => $data,
                    ]
                ]);
                break;
            case 'resource':
                $checked = '';
                if (!empty($data)) {
                    $checked = 'checked="checked"';
                } else {
                    if (array_key_exists('initFilter', $field)) {
                        $data = $field["initFilter"];
                    }
                }
                [
                    $data2,
                    $showFields,
                    $filterFields,
                    $hideFields,
                    $perPage,
                    $queryParams,
                ] = GraphqlService::getInstance()->getResourceWithQuery($resource, getData: false, allFilterField: true, withMetadata: false);
                $html .= '
                <div data-resource-filter="' . htmlspecialchars(json_encode(self::getResourceFilter($resource, $filterFields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') . '">
                    <input type="text" class="hidden" id="' . esc_attr($field['id']) . '" name="' . esc_attr($option_name) . '" value="" data-init-filter="' . esc_attr($data) . '" />
                    <input id="' . esc_attr($field['id']) . '_select" type="checkbox" ' . $checked . '/>
                    <label for="' . esc_attr($field['id']) . '"><span class="description">' . $field['description'] . '</span></label>
                    <div data-react-resource></div>
                </div>
                ' . "\n";
                break;
        }

        switch ($field['type']) {
            case 'resource':
                break;
            case 'checkbox_multi':
            case 'radio':
            case 'select_multi':
            case '2_select_multi':
                $html .= '<br/><span class="description">' . $field['description'] . '</span>';
                break;
            default:
                if ($post === null) {
                    $html .= '<label for="' . esc_attr($field['id']) . '">' . "\n";
                }

                $html .= '<span class="description">' . esc_attr($field['description']) . '</span>' . "\n";

                if ($post === null) {
                    $html .= '</label>' . "\n";
                }

                break;
        }

        if (!$echo) {
            return $html;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        return '';
    }

    private static function getResourceFilter(Resource $resource, array $filterFields): array
    {
        return [
            'importCondition' => array_map(fn(ImportConditionDto $importCondition): array => [
                'field' => $importCondition->getField(),
                'value' => $importCondition->getValue(),
                'condition' => $importCondition->getCondition(),
            ], $resource->getImportCondition()),
            'allFilterType' => SageService::getInstance()->getAllFilterType(),
            'filterFields' => $filterFields,
        ];
    }

    public static function registerMenu(): void
    {
        $resources = SageService::getInstance()->getResources();
        $args = apply_filters(
            Sage::TOKEN . '_menu_settings',
            [
                [
                    'location' => 'menu',
                    // Possible settings: options, menu, submenu.
                    'page_title' => __('Egas', 'egas'),
                    'menu_title' => __('Egas', 'egas'),
                    'capability' => 'manage_options',
                    'menu_slug' => Sage::TOKEN . '_settings',
                    'function' => null,
                    'icon_url' => 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(__DIR__ . '/../../dist/images/icon.svg')),
                    'position' => 55.5,
                ],
                [
                    'location' => 'submenu',
                    // Possible settings: options, menu, submenu.
                    'parent_slug' => Sage::TOKEN . '_settings',
                    'page_title' => __('Settings', 'egas'),
                    'menu_title' => __('Settings', 'egas'),
                    'capability' => 'manage_options',
                    'menu_slug' => Sage::TOKEN . '_settings',
                    'function' => function (): void {
                        // Build page HTML.
                        $html = TwigService::getInstance()->render('base.html.twig');
                        $html .= '<div class="wrap" id="' . Sage::TOKEN . '_settings">' . "\n";
                        $html .= '<h2>' . __('Egas', 'egas') . '</h2>' . "\n";

                        $tab = '';
                        if (isset($_GET['tab']) && $_GET['tab']) {
                            $tab .= $_GET['tab'];
                        }

                        $settings = self::getSettings();
                        // Show page tabs.
                        if (1 < count($settings)) {

                            $html .= '<h2 class="nav-tab-wrapper">' . "\n";

                            $c = 0;
                            foreach ($settings as $section => $data) {

                                // Set tab class.
                                $class = 'nav-tab';
                                if (!isset($_GET['tab'])) {
                                    if (0 === $c) {
                                        $class .= ' nav-tab-active';
                                    }
                                } elseif ($section == $_GET['tab']) {
                                    $class .= ' nav-tab-active';
                                }

                                // Set tab link.
                                $tab_link = add_query_arg(['tab' => $section]);
                                if (isset($_GET['settings-updated'])) {
                                    $tab_link = remove_query_arg('settings-updated', $tab_link);
                                }

                                // Output tab.
                                $html .= '<a href="' . $tab_link . '" class="' . esc_attr($class) . '">' . esc_html($data['title']) . '</a>' . "\n";

                                ++$c;
                            }

                            $html .= '</h2>' . "\n";
                        }

                        $html .= '<form method="post" id="form_settings_' . Sage::TOKEN . '" action="options.php" enctype="multipart/form-data">';

                        // Get settings fields.
                        ob_start();
                        settings_fields(Sage::TOKEN . '_settings');
                        do_settings_sections(Sage::TOKEN . '_settings');
                        $html .= ob_get_clean();

                        $html .= '<p class="submit">' . "\n";
                        $html .= '<input type="hidden" name="tab" value="' . esc_attr($tab) . '" />' . "\n";
                        $html .= '<input name="Submit" type="submit" class="button-primary" value="' . esc_attr(__('Sauvegarder', 'egas')) . '" />' . "\n";
                        $html .= '</p>' . "\n";
                        $html .= '</form>' . "\n";
                        $html .= '</div>' . "\n";

                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $html;
                    },
                    'position' => null,
                ],
                ...array_map(static fn(Resource $resource): array => [
                    'location' => 'submenu',
                    // Possible settings: options, menu, submenu.
                    'parent_slug' => Sage::TOKEN . '_settings',
                    'page_title' => $resource->getTitle(),
                    'menu_title' => $resource->getTitle(),
                    'capability' => 'manage_options',
                    'menu_slug' => Sage::TOKEN . '_' . $resource->getEntityName(),
                    'function' => static function () use ($resource): void {
                        [
                            $data,
                            $showFields,
                            $filterFields,
                            $hideFields,
                            $perPage,
                            $queryParams,
                        ] = GraphqlService::getInstance()->getResourceWithQuery($resource, getData: false);
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo TwigService::getInstance()->render('sage/list.html.twig', [
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'showFields' => $showFields,
                            'resourceFilter' => [
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                ...self::getResourceFilter($resource, $filterFields),
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                'initFilter' => $resource::getDefaultResourceFilter(),
                            ],
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'perPage' => $perPage,
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'hideFields' => $hideFields,
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'mandatoryFields' => $resource->getMandatoryFields(),
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'sageEntityName' => $resource->getEntityName(),
                        ]);
                    },
                    'position' => null,
                ], $resources),
                [
                    'location' => 'submenu',
                    // Possible settings: options, menu, submenu.
                    'parent_slug' => Sage::TOKEN . '_settings',
                    'page_title' => __('À propos', 'egas'),
                    'menu_title' => __('À propos', 'egas'),
                    'capability' => 'manage_options',
                    'menu_slug' => Sage::TOKEN . '_about',
                    'function' => static function (): void {
                        echo 'about page';
                    },
                    'position' => null,
                ],
                [
                    'location' => 'submenu',
                    // Possible settings: options, menu, submenu.
                    'parent_slug' => Sage::TOKEN . '_settings',
                    'page_title' => __('Logs', 'egas'),
                    'menu_title' => __('Logs', 'egas'),
                    'capability' => 'manage_options',
                    'menu_slug' => Sage::TOKEN . '_log',
                    'function' => static function (): void {
                        echo 'logs page';
                    },
                    'position' => null,
                ],
            ]
        );
        foreach ($args as $arg) {
            // Do nothing if wrong location key is set.
            if (is_array($arg) && isset($arg['location']) && function_exists('add_' . $arg['location'] . '_page')) {
                switch ($arg['location']) {
                    case 'options':
                    case 'submenu':
                        $page = add_submenu_page(
                            $arg['parent_slug'],
                            $arg['page_title'],
                            $arg['menu_title'],
                            $arg['capability'],
                            $arg['menu_slug'],
                            $arg['function'],
                        );
                        break;
                    case 'menu':
                        $page = add_menu_page(
                            $arg['page_title'],
                            $arg['menu_title'],
                            $arg['capability'],
                            $arg['menu_slug'],
                            $arg['function'],
                            $arg['icon_url'],
                            $arg['position'],
                        );
                        break;
                    default:
                        return;
                }
            }
        }
    }

    public static function showErrors(array|null|string $data): bool
    {
        if (is_string($data) || is_null($data)) {
            if (is_string($data) && is_admin() /*on admin page*/) {
                ?>
                <div class="error"><?php echo
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    $data ?></div>
                <?php
            }
            return true;
        }
        return false;
    }

    public static function adminNotices(?string $message): void
    {
        if (is_admin()) {
            add_action('admin_notices', static function () use ($message): void {
                if (!empty($message)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $message;
                }
            });
        }
    }

    public static function getWrongOptions(): string|null
    {
        $pDossier = GraphqlService::getInstance()->getPDossier();
        $sageExpectedOptions = [
            new SageExpectedOption(
                optionName: 'woocommerce_enable_guest_checkout',
                optionValue: 'no',
                trans: __('Permettre aux clients de passer des commandes sans créer de compte.', 'egas'),
                description: __("Lorsque cette option est activée vos clients ne sont pas obligés de se connecter à leurs comptes pour passer commande et il est donc impossible de créer automatiquement la commande passé dans Woocommerce dans Sage.", 'egas'),
            ),
            new SageExpectedOption(
                optionName: 'woocommerce_calc_taxes',
                optionValue: 'yes',
                trans: __('Activer les taux de taxe et le calcul des taxes.', 'egas'),
                description: __("Cette option doit être activé pour que le plugin Egas fonctionne correctement afin de récupérer les taxes directement renseignées dans Sage.", 'egas'),
            ),
        ];
        if (!is_null($pDossier?->nDeviseCompteNavigation?->dCodeIso)) {
            $sageExpectedOptions[] = new SageExpectedOption(
                optionName: 'woocommerce_currency',
                optionValue: $pDossier->nDeviseCompteNavigation->dCodeIso,
                trans: __('Devise', 'egas'),
                description: __("La devise dans Woocommerce n'est pas la même que dans Sage.", 'egas'),
            );
        }
        /** @var SageExpectedOption[] $changes */
        $changes = [];
        foreach ($sageExpectedOptions as $sageExpectedOption) {
            $optionName = $sageExpectedOption->getOptionName();
            $expectedOptionValue = $sageExpectedOption->getOptionValue();
            $value = get_option($optionName);
            $sageExpectedOption->setCurrentOptionValue($value);
            if ($value !== $expectedOptionValue) {
                $changes[] = $sageExpectedOption;
            }
        }
        if ($changes !== []) {
            $result = "<div class='error''>";
            ob_start();
            settings_fields(Sage::TOKEN . '_settings');
            $fieldsForm = ob_get_clean();
            $optionNames = [];
            foreach ($changes as $sageExpectedOption) {
                $optionValue = $sageExpectedOption->getOptionValue();
                $result .= "<div>" . __('Le plugin Egas a besoin de modifier l\'option', 'egas') . " <code>" .
                    $sageExpectedOption->getTrans() . "</code> " . __('pour lui donner la valeur', 'egas') . " <code>" .
                    $optionValue . "</code>
<div class='tooltip'>
        <span class='dashicons dashicons-info' style='padding-right: 22px'></span>
        <div class='tooltiptext' style='right: 0'>" . $sageExpectedOption->getDescription() . "</div>
    </div>
</div>";
                $optionName = $sageExpectedOption->getOptionName();
                $fieldsForm .= '<input type="hidden" name="' . $optionName . '" value="' . $optionValue . '">';
                $optionNames[] = $optionName;
            }
            return $result . ('<form method="post" action="options.php" enctype="multipart/form-data">'
                    . $fieldsForm
                    . '<input type="hidden" name="page_options" value="' . esc_attr(implode(',', $optionNames)) . '"/>
                       <input type="hidden" name="_wp_http_referer" value="' . esc_attr($_SERVER["REQUEST_URI"]) . '">'
                    . '<p class="submit">
                <input name="Update" type="submit" class="button-primary" value="' . esc_attr(__('Mettre à jour', 'egas')) . '">
                </p>
                </form>
                </div>');
        }
        return null;
    }
}
