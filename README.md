# MemoThèque

MVP de bibliothèque de mémoires académiques, conçu pour PHP 8, MySQL et XAMPP. Le dépôt démarre à partir d'une base vierge ; cette première version couvre le catalogue public, l'inscription/connexion, le dépôt étudiant, les validations administratives et la modération bibliothécaire.

## Démarrage sous XAMPP

1. Copier le projet dans `C:\xampp\htdocs\memotheque` (ou le répertoire `htdocs` de votre installation).
2. Démarrer Apache et MySQL depuis le panneau XAMPP.
3. Ouvrir `http://localhost/phpmyadmin`, créer la base `memotheque` en `utf8mb4`, puis importer `database/schema.sql`.
4. Vérifier les paramètres dans `config/config.php` (valeurs par défaut XAMPP : `root`, mot de passe vide).
5. Créer le dossier `storage` à côté de `public` (il est normalement déjà présent via `.gitkeep`) et vérifier que PHP peut y écrire. Il ne doit pas être servi directement par Apache. Le fichier `public/.htaccess` empêche l'accès direct si `storage` est déplacé par erreur sous le document root ; garder le stockage hors de `htdocs` reste la protection recommandée.
6. Ouvrir `http://localhost/memotheque/public/`.

## Première configuration

Compte local préchargé : `demo@memotheque.local` / `password`. C'est un mot de passe de démonstration volontairement faible : changez-le avant tout usage partagé. Vous pouvez aussi créer un compte via le formulaire d'inscription, puis lui attribuer le rôle administrateur dans MySQL :

```sql
UPDATE utilisateurs SET role = 'administrateur', statut_compte = 'valide'
WHERE email = 'admin@exemple.fr';
```

Créer de même des comptes bibliothécaires par l'interface SQL, ou donner temporairement le rôle à un compte existant. Pour créer un étudiant, s'inscrire avec la case « Je suis étudiant » et joindre un certificat ; l'administrateur valide ensuite son compte.

## Fonctionnalités du MVP

- Recherche publique sur titre, auteur et mots-clés, avec filtres filière/année et pagination ; seules les fiches validées s'affichent.
- Fiche publique et partage par URL ; le PDF n'est délivré qu'aux utilisateurs connectés.
- Inscription standard ou étudiant, connexion avec limitation basique des tentatives, statut du compte et espace personnel.
- Dépôt étudiant réservé aux comptes validés de Licence 3, Master 2 ou Doctorat ; consentement de diffusion, déclaration de confidentialité, PDF et attestation exigés.
- File de modération, accès aux pièces justificatives et décision motivée par bibliothécaire (ou administrateur).
- Validation/refus des comptes, gestion des filières, téléchargement protégé, CSRF, sessions durcies, PDO préparé et échappement HTML.

Les fonctionnalités identifiées Lot 2/Lot 3 (recours, demandes de retrait/modification, aperçu PDF, antivirus, notifications, etc.) ne sont pas encore implémentées.

## Fichiers et sauvegardes

Les documents sont stockés sous `storage/` hors de `public/`, sous des noms aléatoires, et servis uniquement par `public/file.php` après vérification de session, rôle et statut. Mémoire PDF : 50 Mo maximum ; justificatif : 5 Mo maximum. Les formats sont vérifiés avec `finfo` et signature PDF/PNG/JPEG. Le compteur limite les PDF publiés à 20 téléchargements/heure/compte. Pour autoriser un mémoire de 50 Mo, configurer aussi `upload_max_filesize=50M` et `post_max_size=60M` dans `php.ini`, puis redémarrer Apache.

Sauvegarde manuelle : exporter la base `memotheque` depuis phpMyAdmin et copier le dossier `storage` sur un support distinct. Restauration : recréer/importer la base, remettre le dossier `storage`, vérifier les permissions puis tester la connexion et le téléchargement d'un document publié. Les fichiers PDF de démo ne sont pas fournis : le schéma charge cinq fiches de démonstration sans pièce jointe.

## Sécurité et déploiement

Configurer le serveur web pour exposer uniquement le dossier `public/` en production ; hors XAMPP, définir `APP_BASE_URL` dans l'environnement pour les URL de partage. Utiliser HTTPS, un compte MySQL non privilégié, une configuration de stockage non publique et une politique de sauvegarde/effacement validée par l'établissement. Le MVP applique le plafond de 20 téléchargements publiés/heure/compte. L'expiration automatique des justificatifs et la suppression des dépôts refusés après 6 mois restent à implémenter avant usage réel.

## Vérifications

Le projet vise PHP 8 avec PDO MySQL, Fileinfo et sessions. Depuis un environnement avec PHP installé :

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```