# Egas – Synchronization Tool For Sage

Plugin WordPress qui synchronise les données Sage ERP avec un site WordPress / boutique WooCommerce.

Lancer l'environnement :

```bash
docker compose -f compose.yaml -f compose-windows.yaml up
```

Ouvrir Chrome en ignorant les erreurs de certificat (nécessaire avec les hosts ci-dessus) :

```bash
google-chrome --ignore-certificate-errors
```

## Développement

### Rector

```bash
./runc php vendor/bin/rector process --debug --clear-cache
```

Sur un dossier précis :

```bash
./runc php vendor/bin/rector process includes/controllers/ --debug --clear-cache
```

Ou via la config dédiée :

```bash
./runc vendor/bin/rector process --config=rector.php
```

> On ne met pas le garde `if (!defined('ABSPATH')) { exit; }` dans les fichiers PHP car ça bloque Rector. À voir pour le rajouter automatiquement dans le build.

### Traductions (i18n)

Quand on ajoute une nouvelle entité, utiliser la fonction `private function settings_fields` avec le debugger pour récupérer tous les champs à traduire.

Générer le fichier `.pot` :

```bash
vendor/wp-cli/wp-cli/bin/wp i18n make-pot . lang/sage.pot
```

Doc : https://wordpress.stackexchange.com/questions/149212/how-to-create-pot-files-with-poedit

### Qualité / conformité

Utiliser **Plugin Check** pour vérifier que le plugin est conforme aux standards WordPress.

## Liens utiles

- Board Trello : https://trello.com/b/t64T4Swz/sage-api
- Application Passwords (REST API) : https://developer.wordpress.org/rest-api/reference/application-passwords/#create-a-application-password
- Ajouter des endpoints REST custom : https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- Ajouter des cron jobs WordPress : https://www.elegantthemes.com/blog/tips-tricks/how-to-add-cron-jobs-to-wordpress

## Outils pour se faire connaître

- https://ahrefs.com/
- https://www.apollo.io/
- https://useartemis.co/
- https://www.kaspr.io/fr/
