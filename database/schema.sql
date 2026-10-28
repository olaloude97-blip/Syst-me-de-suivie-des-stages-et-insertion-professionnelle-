CREATE DATABASE IF NOT EXISTS memotheque CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE memotheque;

CREATE TABLE IF NOT EXISTS filieres (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL UNIQUE,
  cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS utilisateurs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  mot_de_passe VARCHAR(255) NOT NULL,
  role ENUM('standard','etudiant','bibliothecaire','administrateur') NOT NULL DEFAULT 'standard',
  niveau_etudes ENUM('Licence 1','Licence 2','Licence 3','Master 1','Master 2','Doctorat') NULL,
  statut_compte ENUM('valide','en_attente','refuse') NOT NULL DEFAULT 'valide',
  motif_refus TEXT NULL,
  certificat_fichier VARCHAR(255) NULL,
  echecs_connexion TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloque_jusqua DATETIME NULL,
  cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS memoires (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deposant_id BIGINT UNSIGNED NOT NULL,
  filiere_id INT UNSIGNED NOT NULL,
  titre VARCHAR(255) NOT NULL,
  auteur VARCHAR(200) NOT NULL,
  annee_soutenance SMALLINT UNSIGNED NOT NULL,
  resume TEXT NOT NULL,
  mots_cles VARCHAR(500) NOT NULL,
  statut ENUM('en_attente','valide','refuse','retire') NOT NULL DEFAULT 'en_attente',
  motif_refus TEXT NULL,
  lien_partage VARCHAR(64) NOT NULL UNIQUE,
  autorisation_diffusion TINYINT(1) NOT NULL DEFAULT 0,
  absence_confidentialite TINYINT(1) NOT NULL DEFAULT 0,
  fichier_pdf VARCHAR(255) NOT NULL,
  attestation_fichier VARCHAR(255) NOT NULL,
  cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  modifie_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_memoire_deposant FOREIGN KEY (deposant_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_memoire_filiere FOREIGN KEY (filiere_id) REFERENCES filieres(id),
  INDEX idx_memoire_publication (statut, cree_le),
  FULLTEXT INDEX idx_memoire_recherche (titre, auteur, mots_cles)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS telechargements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id BIGINT UNSIGNED NOT NULL,
  memoire_id BIGINT UNSIGNED NOT NULL,
  cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  FOREIGN KEY (memoire_id) REFERENCES memoires(id),
  INDEX idx_telechargement_limite (utilisateur_id, cree_le)
) ENGINE=InnoDB;

INSERT IGNORE INTO filieres (nom) VALUES ('Informatique'), ('Gestion'), ('Droit'), ('Sciences économiques'), ('Lettres et sciences humaines');
-- Compte de démonstration local : demo@memotheque.local / password. À remplacer avant toute mise en ligne.
INSERT IGNORE INTO utilisateurs (id,nom,prenom,email,mot_de_passe,role,statut_compte)
VALUES (1,'Démo','Administrateur','demo@memotheque.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','administrateur','valide');
INSERT IGNORE INTO memoires (id,deposant_id,filiere_id,titre,auteur,annee_soutenance,resume,mots_cles,statut,lien_partage,autorisation_diffusion,absence_confidentialite,fichier_pdf,attestation_fichier)
VALUES
(1,1,1,'Système de gestion des bibliothèques universitaires','Aïcha Mensah',2025,'Conception d’une application web pour faciliter la gestion et la consultation des ressources universitaires.','bibliothèque, web, gestion','valide','demo-gestion-bibliotheque',1,1,'demo-fichier-absent-1.pdf','demo-attestation-absente-1.pdf'),
(2,1,1,'Sécurité des applications web modernes','Koffi Adjovi',2024,'Étude des bonnes pratiques de sécurisation des applications web et de la protection des données.','sécurité, web, données','valide','demo-securite-web',1,1,'demo-fichier-absent-2.pdf','demo-attestation-absente-2.pdf'),
(3,1,2,'Entrepreneuriat numérique et emploi des jeunes','Mariam Dossou',2025,'Analyse du rôle des entreprises numériques dans l’insertion professionnelle des jeunes diplômés.','entrepreneuriat, emploi, numérique','valide','demo-entrepreneuriat',1,1,'demo-fichier-absent-3.pdf','demo-attestation-absente-3.pdf'),
(4,1,3,'Protection des données personnelles en Afrique de l’Ouest','Yao Koudjo',2023,'Étude comparative des cadres juridiques de protection des données personnelles.','droit, données, vie privée','valide','demo-protection-donnees',1,1,'demo-fichier-absent-4.pdf','demo-attestation-absente-4.pdf'),
(5,1,4,'Finance inclusive et développement local','Aminata Soglo',2024,'Évaluation de l’impact de la microfinance sur le développement économique local.','finance, microfinance, développement','valide','demo-finance-inclusive',1,1,'demo-fichier-absent-5.pdf','demo-attestation-absente-5.pdf')
ON DUPLICATE KEY UPDATE id=id;