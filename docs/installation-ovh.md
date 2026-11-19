# Installer Fair Gestion chez OVHcloud

Chaque club ouvre son propre compte OVHcloud, choisit son domaine et invite son bureau à utiliser l’adresse du club, par exemple `https://gestion.exemple.fr`. OVHcloud exploite l’hébergement Web et le service PostgreSQL. Le club garde la main sur les comptes du bureau, les mises à jour de Fair Gestion et ses sauvegardes.

## 1. Préparer les services OVHcloud

1. Prends un **hébergement Web Pro ou supérieur** pour disposer de SSH. Active **PHP 8.4**, profil **Stable64**. Fair Gestion utilise le pilote PHP `pdo_pgsql`. Configure le domaine ou sous-domaine du club et active le certificat HTTPS.
2. Crée une instance **Web Cloud Databases** et choisis **PostgreSQL**. Sélectionne la localisation française souhaitée parmi celles proposées par OVHcloud, et vérifie la localisation du service et de ses sauvegardes dans les conditions de l’offre. Dans l’espace client, crée une base dédiée à Fair Gestion et un utilisateur ayant le droit de créer les tables dans cette base.
3. Sur **Web Cloud Databases → IPs autorisées**, active l’accès depuis les hébergements Web OVHcloud. Relève le nom d’hôte PostgreSQL, le port, le nom de la base et les identifiants. Utilise le port indiqué dans l’espace client.

[SSH sur l’hébergement Web](https://docs.ovhcloud.com/fr/guides/web-cloud/web-hosting/ssh-on-webhosting) · [Configuration PHP](https://docs.ovhcloud.com/fr/guides/web-cloud/web-hosting/configure-your-web-hosting) · [Premiers pas PostgreSQL](https://docs.ovhcloud.com/fr/guides/web-cloud/databases/db-getting-started-postgresql) · [Configuration des accès à la base](https://docs.ovhcloud.com/fr/guides/web-cloud/databases/db-configure-optimise-database-server)

## 2. Déposer les fichiers

1. Récupère une version de Fair Gestion sur GitHub. Décompresse-la et envoie le dossier complet par SFTP dans un répertoire dédié nommé `gestclub`. Conserve les dossiers `public`, `server`, `bin` et `sql`, ainsi que `index.html` et les fichiers cachés.
2. Dans **Hébergements → Multisite**, configure le sous-domaine du club avec **`gestclub/public` comme dossier racine**. Ce dossier contient les deux points d’entrée Web. Place `config.php` dans `gestclub/`, à côté de `index.html`. Vérifie aussi le dossier racine des autres domaines du même hébergement.
3. Connecte-toi en SSH. Depuis la racine de l’hébergement, prépare la configuration privée.

```sh
cd gestclub
cp config.example.php config.php
chmod 600 config.php
php -v
php -m
```

Vérifie que `php -v` affiche PHP 8.4 et que `php -m` contient `pdo_pgsql` et `fileinfo`. En SSH, si `php` pointe vers une autre version, utilise le binaire PHP 8.4 proposé par ton hébergement, par exemple `/usr/local/php8.4/bin/php`. [Choisir une version PHP en SSH](https://docs.ovhcloud.com/fr/guides/web-cloud/web-hosting/ssh-on-webhosting)

Dans `config.php`, renseigne le nom du club, l’adresse HTTPS exacte du site dans `origin`, ainsi que le nom d’hôte, le port, la base, l’utilisateur et le mot de passe de PostgreSQL. Garde `sslmode=require` dans la chaîne de connexion. Le fichier est stocké exclusivement sur l’espace privé du club.

[Configurer le dossier racine du domaine dans Multisite](https://docs.ovhcloud.com/fr/guides/web-cloud/web-hosting/multisites-configure-multisite)

## 3. Initialiser la base et les comptes

Lance la commande suivante en SSH depuis `gestclub/`. Elle exécute le schéma SQL et initialise la saison courante et la précédente. Une nouvelle exécution conserve les données déjà créées.

```sh
php bin/gestclub.php install
php bin/gestclub.php account direction@exemple.fr director
php bin/gestclub.php account tresorerie@exemple.fr treasurer
php bin/gestclub.php account secretariat@exemple.fr secretary
```

Chaque commande `account` génère un mot de passe et l’affiche une fois dans le terminal. Remets-le à la personne concernée par un canal privé. Pour une personne qui cumule plusieurs fonctions, indique les rôles séparés par des virgules, par exemple `director,treasurer`. Relancer `account` pour une adresse renouvelle son mot de passe et invalide ses sessions. Pour un départ, lance `php bin/gestclub.php disable adresse@exemple.fr`.

Pour un compte réservé à l’inventaire, utilise `php bin/gestclub.php account materiel@exemple.fr equipment_manager`. Ce rôle donne accès aux fiches de matériel, aux prêts et à leurs retours. Les comptes dirigeants ont aussi accès à l’espace **Matériel**.

## 4. Vérifier depuis deux appareils

1. Ouvre l’adresse HTTPS du club sur ordinateur avec le compte secrétaire. Ajoute une adhésion de test.
2. Ouvre la même adresse en navigation privée ou sur téléphone avec le compte trésorier. Dans **Cotisations**, vérifie que l’adhésion est disponible.
3. Fais un essai dans le compte dirigeant. Il voit les effectifs et les synthèses. Le trésorier accède aux écritures et aux justificatifs financiers. Le secrétaire gère le registre et les réunions et peut joindre la synthèse financière au PV.
4. Pour un écran resté ouvert, utilise **Actualiser**. Si deux personnes modifient le même état depuis une version ancienne, Fair Gestion recharge la dernière version et demande de reprendre la modification.
5. Vérifie qu’une visite en navigation privée sur le site affiche la connexion et que `/config.php`, `/server/state.php` et `/sql/001-install.sql` répondent par une erreur 404.

Pour tester les inscriptions sur l’instance pilote, ouvre **Inscriptions** avec le compte secrétaire et active le formulaire de la saison. Dépose un dossier fictif avec une pièce en navigation privée et conserve son lien de suivi. Marque le dossier incomplet depuis le compte secrétaire, consulte le message et ajoute une pièce depuis le lien privé. Valide ensuite le dossier et vérifie sa présence dans le registre, puis dans les cotisations. Vérifie aussi que les comptes sans rôle secrétaire ne peuvent pas télécharger les pièces d’inscription.

## 5. Reprendre la démo ou créer une saison

La base démarre vide. Si le club a déjà travaillé dans la démo locale, utilise **Exporter les données locales** dans cette démo, puis transfère le JSON dans un dossier privé de l’hébergement, hors de `public`. L’administration SSH permet d’importer cet export dans une installation vide.

```sh
php bin/gestclub.php import sauvegarde-locale.json
php bin/gestclub.php season 2027
```

L’import reprend les adhésions, la comptabilité, les données du bureau et les justificatifs. La commande `season` prend l’année de début. Elle crée la saison correspondante si elle est absente. Retire la copie d’import de l’hébergement une fois la reprise vérifiée.

## 6. Sauvegarder et mettre à jour

- **Sauvegarde complète.** Depuis **Web Cloud Databases**, télécharge régulièrement une sauvegarde PostgreSQL. Elle contient les tables, les comptes, les données et les justificatifs. Garde une copie chiffrée hors de l’hébergement Web et vérifie la restauration sur une autre base de test.
- **Export portable.** La commande `php bin/gestclub.php export` produit un JSON des données et des pièces du club. Conserve cet export dans un emplacement privé. Crée de nouveaux comptes dans l’installation de destination.
- **Mise à jour.** Fais une sauvegarde, remplace les fichiers du programme par la nouvelle version et conserve le `config.php` du club. Consulte les instructions de la version avant de relancer la commande d’installation.

[Importer ou restaurer une base Web Cloud Databases](https://docs.ovhcloud.com/fr/guides/web-cloud/databases/db-restore-import-database)

## Publier le kit sur GitHub

Crée le dépôt public au nom du porteur de Fair Gestion. Publie le code, le schéma, le guide, la licence et la configuration d’exemple. Les identifiants, les exports et les justificatifs restent dans l’espace privé du club. Pour une démonstration publique, publie `index.html` avec le dossier `public/assets`. Les données fictives sont intégrées à la démo.

[Créer un dépôt sur GitHub](https://docs.github.com/fr/repositories/creating-and-managing-repositories/creating-a-new-repository)
