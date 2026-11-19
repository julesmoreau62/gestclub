<h1 align="center">Fair Gestion</h1>

<p align="center">
  <strong>Le tableau de bord open source des clubs sportifs amateurs.</strong><br>
  Adhésions, vie du bureau et comptabilité, dans un espace commun accessible sur ordinateur et téléphone.
</p>

<p align="center">
  <a href="#essayer-la-démo">Essayer la démo</a> ·
  <a href="docs/installation-ovh.md">Installer chez OVHcloud</a> ·
  <a href="sql/001-install.sql">Consulter le SQL</a> ·
  <a href="LICENSE">Licence MIT</a>
</p>

![Tableau de bord de Fair Gestion pour le club fictif AS des Tilleuls](docs/images/tableau-de-bord.png)

Chaque club héberge sa propre instance sur son compte OVHcloud et partage l’adresse HTTPS avec son bureau. Les membres se connectent avec leur compte et retrouvent les mêmes données depuis leurs appareils. Le dépôt GitHub contient le programme, le schéma et le guide. Les données du club sont stockées dans sa base PostgreSQL.

## Les espaces de Fair Gestion

Dans **Bureau**, le menu affiche les rubriques autorisées pour le compte. Fair Gestion utilise automatiquement le rôle adapté à chaque rubrique.

| Espace | Fonctionnalités |
| --- | --- |
| **Dirigeant** | Suivre les effectifs et les renouvellements, les échéances et les postes bénévoles. Relier une décision à une action et à une note de frais. |
| **Secrétaire** | Tenir le registre, importer les adhésions par CSV, reprendre la saison précédente et suivre les dossiers. Préparer les réunions et les AG, saisir présences, procurations et votes, puis exporter le PV. |
| **Trésorier** | Suivre les cotisations, factures et remboursements. Tenir la trésorerie, rapprocher les relevés, consulter les écritures et la balance, comparer le budget au réalisé et préparer le rapport financier d’AG. |

Une adhésion reprise par le secrétaire apparaît dans les effectifs du dirigeant et ouvre une cotisation à suivre chez le trésorier.

Dans **Matchs et événements**, le compte dirigeant prépare le planning de la saison, ajoute des créneaux à partir des postes bénévoles et suit les places à pourvoir. Sur une instance hébergée, il peut activer un lien d’inscription par événement. Les bénévoles y prennent un créneau sans créer de compte.

Dans **Inscriptions**, le compte secrétaire ouvre le formulaire pour la saison et partage son lien aux familles. Elles déposent leur dossier et suivent son état depuis un lien privé. Le secrétaire consulte les pièces, indique ce qui manque et valide le dossier pour créer ou compléter une adhésion. Les nouvelles adhésions apparaissent aussi dans le suivi des cotisations. Les familles n’ont pas besoin de compte. Le formulaire leur indique l’usage de leurs données et l’adresse à contacter. Une tâche planifiée supprime les dossiers clôturés et leurs pièces après la durée choisie par le club ([détails](docs/installation-ovh.md#7-protéger-les-données-des-familles)).

Dans **Partenaires**, le compte dirigeant suit les sponsors et leurs contacts, les renouvellements à préparer et les engagements du club. Il peut joindre une photo par engagement et reprendre les fiches de la saison précédente.

Dans **Matériel**, le club garde un inventaire commun à toutes les saisons, suit les prêts et les retours partiels, et repère les retours en retard et le matériel à réparer. Le compte dirigeant y a accès. Le rôle **Responsable du matériel** permet de donner accès à cet espace seul.

## Essayer la démo

[Télécharge le dépôt](https://github.com/julesmoreau62/gestclub/archive/refs/heads/main.zip), décompresse-le et ouvre **`index.html`** dans ton navigateur. Choisis **Bureau**, **Matchs et événements**, **Inscriptions**, **Partenaires** ou **Matériel** depuis l’accueil. Le logo **Fair Gestion**, dans la barre de gauche, permet de revenir à l’accueil. Sur une instance hébergée, les cartes affichées dépendent des rôles du compte. La démo contient un club fictif et conserve les modifications dans le navigateur. **Exporter les données locales** permet de récupérer une sauvegarde pour une future installation.

## Installer un espace club

Le guide détaille la configuration d’un hébergement Web OVHcloud avec **PHP 8.4** et d’une base **PostgreSQL managée**. Le club choisit son sous-domaine, initialise la base, crée les comptes du bureau puis vérifie l’accès depuis deux appareils. Le programme s’ouvre ensuite dans un navigateur.

**[Suivre le guide d’installation OVHcloud →](docs/installation-ovh.md)**

| Fichier | Rôle |
| --- | --- |
| [`index.html`](index.html) | Interface commune à la démo et à la version hébergée |
| [`sql/001-install.sql`](sql/001-install.sql) | Schéma PostgreSQL pour les comptes, les données de saison et les justificatifs |
| [`config.example.php`](config.example.php) | Modèle de configuration à copier et à renseigner sur l’hébergement du club |
| [`bin/gestclub.php`](bin/gestclub.php) | Initialisation, gestion des comptes et saisons, import et export |
| [`docs/installation-ovh.md`](docs/installation-ovh.md) | Installation, reprise des données et sauvegardes |

Fair Gestion est au **stade pilote**. Le schéma, les contrôles d’accès et la gestion des modifications concurrentes ont été vérifiés localement. La mise en service complète sur OVHcloud reste à valider sur une instance pilote.

Pour signaler un problème ou proposer une amélioration, [ouvre une issue](https://github.com/julesmoreau62/gestclub/issues). Le code est sous [licence MIT](LICENSE).
