<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
start_session();
refresh_session_user();
verify_csrf();
$pdo = db();
$page = $_GET['page'] ?? 'accueil';
$user = current_user();
$error = '';
if (in_array($page, ['espace','depot','moderation','administration'], true) && !$user) {
    header('Location: index.php?page=connexion'); exit;
}
if ($page === 'depot' && (!is_role('etudiant') || $user['statut_compte'] !== 'valide' || !in_array($user['niveau_etudes'], ['Licence 3','Master 2','Doctorat'], true))) {
    flash('Le dépôt est réservé aux étudiants validés de Licence 3, Master 2 ou Doctorat.', 'error');
    header('Location: index.php?page=espace'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($page === 'inscription') {
            $nom = trim($_POST['nom'] ?? ''); $prenom = trim($_POST['prenom'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? '')); $password = $_POST['mot_de_passe'] ?? '';
            $student = isset($_POST['etudiant']); $level = $_POST['niveau_etudes'] ?? '';
            if (!$nom || !$prenom || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) throw new RuntimeException('Renseignez tous les champs et un mot de passe d’au moins 10 caractères.');
            if ($student && !in_array($level, ['Licence 1','Licence 2','Licence 3','Master 1','Master 2','Doctorat'], true)) throw new RuntimeException('Choisissez un niveau d’études valide.');
            $proof = $student ? upload_file('certificat', 'certificat') : null;
            if ($student && !$proof) throw new RuntimeException('Le certificat de scolarité est obligatoire pour un étudiant.');
            try {
                $stmt = $pdo->prepare('INSERT INTO utilisateurs (nom,prenom,email,mot_de_passe,role,niveau_etudes,statut_compte,certificat_fichier) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$nom,$prenom,$email,password_hash($password,PASSWORD_DEFAULT),$student?'etudiant':'standard',$student?$level:null,$student?'en_attente':'valide',$proof]);
            } catch (Throwable $ex) { delete_stored($proof); throw $ex; }
            flash($student ? 'Inscription enregistrée. Votre compte étudiant sera activé après vérification.' : 'Compte créé. Vous pouvez vous connecter.');
            header('Location: index.php?page=connexion'); exit;
        }
        if ($page === 'connexion') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM utilisateurs WHERE email=?'); $stmt->execute([$email]); $candidate = $stmt->fetch();
            if ($candidate && $candidate['bloque_jusqua'] && strtotime($candidate['bloque_jusqua']) > time()) throw new RuntimeException('Trop de tentatives. Réessayez dans quelques minutes.');
            if (!$candidate || !password_verify($_POST['mot_de_passe'] ?? '', $candidate['mot_de_passe'])) {
                if ($candidate) { $fails = (int)$candidate['echecs_connexion'] + 1; $pdo->prepare('UPDATE utilisateurs SET echecs_connexion=?, bloque_jusqua=? WHERE id=?')->execute([$fails%5, $fails >= 5 ? date('Y-m-d H:i:s',time()+900) : null,$candidate['id']]); }
                throw new RuntimeException('Adresse e-mail ou mot de passe incorrect.');
            }
            $pdo->prepare('UPDATE utilisateurs SET echecs_connexion=0,bloque_jusqua=NULL WHERE id=?')->execute([$candidate['id']]);
            session_regenerate_id(true); unset($candidate['mot_de_passe'], $candidate['certificat_fichier']); $_SESSION['user']=$candidate; $_SESSION['csrf']=bin2hex(random_bytes(32));
            header('Location: index.php?page=espace'); exit;
        }
        if ($page === 'depot') {
            require_login();
            if (!is_role('etudiant') || $user['statut_compte'] !== 'valide' || !in_array($user['niveau_etudes'], ['Licence 3','Master 2','Doctorat'], true)) throw new RuntimeException('Le dépôt est réservé aux étudiants validés en Licence 3, Master 2 ou Doctorat.');
            $required = ['titre','auteur','annee_soutenance','resume','mots_cles','filiere_id'];
            foreach ($required as $key) if (trim((string)($_POST[$key] ?? '')) === '') throw new RuntimeException('Tous les champs du mémoire sont obligatoires.');
            if (empty($_POST['autorisation_diffusion']) || empty($_POST['absence_confidentialite'])) throw new RuntimeException('Les deux déclarations sont obligatoires.');
            $pdf = upload_file('memoire_pdf','memoire'); $proof = upload_file('attestation','justificatif');
            if (!$pdf || !$proof) { delete_stored($pdf); delete_stored($proof); throw new RuntimeException('Le mémoire PDF et l’attestation de soutenance sont obligatoires.'); }
            try {
                $stmt=$pdo->prepare('INSERT INTO memoires (deposant_id,filiere_id,titre,auteur,annee_soutenance,resume,mots_cles,lien_partage,autorisation_diffusion,absence_confidentialite,fichier_pdf,attestation_fichier) VALUES (?,?,?,?,?,?,?,?,1,1,?,?)');
                $stmt->execute([$user['id'],(int)$_POST['filiere_id'],trim($_POST['titre']),trim($_POST['auteur']),(int)$_POST['annee_soutenance'],trim($_POST['resume']),trim($_POST['mots_cles']),bin2hex(random_bytes(32)),$pdf,$proof]);
            } catch (Throwable $ex) { delete_stored($pdf); delete_stored($proof); throw $ex; }
            flash('Mémoire déposé. Il sera visible après validation par la bibliothèque.'); header('Location: index.php?page=espace'); exit;
        }
        if ($page === 'decision_memoire') {
            require_login(); if (!is_role('bibliothecaire','administrateur')) throw new RuntimeException('Accès réservé à la modération.');
            $id=(int)($_POST['id']??0); $decision=$_POST['decision']??''; $reason=trim($_POST['motif']??'');
            $s=$pdo->prepare('SELECT deposant_id FROM memoires WHERE id=? AND statut="en_attente"'); $s->execute([$id]); $record=$s->fetch();
            if (!$record || (int)$record['deposant_id']===(int)$user['id']) throw new RuntimeException('Décision impossible pour ce mémoire.');
            if (!in_array($decision,['valide','refuse'],true) || ($decision==='refuse' && !$reason)) throw new RuntimeException('Un motif est obligatoire en cas de refus.');
            $pdo->prepare('UPDATE memoires SET statut=?,motif_refus=? WHERE id=?')->execute([$decision,$decision==='refuse'?$reason:null,$id]);
            flash($decision==='valide'?'Mémoire publié.':'Mémoire refusé avec motif.'); header('Location: index.php?page=moderation'); exit;
        }
        if ($page === 'decision_compte') {
            require_login(); if (!is_role('administrateur')) throw new RuntimeException('Accès réservé à l’administration.');
            $id=(int)($_POST['id']??0); $decision=$_POST['decision']??''; $reason=trim($_POST['motif']??'');
            if (!in_array($decision,['valide','refuse'],true) || ($decision==='refuse' && !$reason)) throw new RuntimeException('Un motif est obligatoire en cas de refus.');
            $pdo->prepare('UPDATE utilisateurs SET statut_compte=?,motif_refus=? WHERE id=? AND role="etudiant" AND statut_compte="en_attente"')->execute([$decision,$decision==='refuse'?$reason:null,$id]);
            flash('Décision de compte enregistrée.'); header('Location: index.php?page=administration'); exit;
        }
        if ($page === 'filiere' && $_POST) {
            require_login(); if (!is_role('administrateur')) throw new RuntimeException('Accès réservé à l’administration.');
            $action=$_POST['action']??'ajouter'; $id=(int)($_POST['id']??0); $name=trim($_POST['nom']??'');
            if ($action==='supprimer') { $pdo->prepare('DELETE FROM filieres WHERE id=?')->execute([$id]); flash('Filière supprimée.'); header('Location: index.php?page=administration'); exit; }
            if (!$name) throw new RuntimeException('Le nom de la filière est obligatoire.');
            if ($action==='modifier') $pdo->prepare('UPDATE filieres SET nom=? WHERE id=?')->execute([$name,$id]);
            else $pdo->prepare('INSERT INTO filieres (nom) VALUES (?)')->execute([$name]);
            flash($action==='modifier'?'Filière modifiée.':'Filière ajoutée.'); header('Location: index.php?page=administration'); exit;
        }
    } catch (Throwable $ex) {
        if ($ex instanceof PDOException) {
            error_log((string)$ex);
            $error = $ex->getCode()==='23000' ? 'Cette adresse e-mail ou cette filière existe déjà, ou la filière est encore utilisée par un mémoire.' : 'Une erreur technique est survenue. Réessayez ultérieurement.';
        } else $error = $ex->getMessage();
    }
}

$flash = take_flash();
$filiereFilter = (int)($_GET['filiere']??0); $yearFilter=(int)($_GET['annee']??0); $q=trim($_GET['q']??''); $results=[]; $total=0; $pageNo=max(1,(int)($_GET['p']??1));
if ($page==='accueil' && ($q!=='' || $filiereFilter || $yearFilter)) {
    $where=['m.statut="valide"']; $params=[];
    if ($q!=='') { $where[]='(LOWER(m.titre) LIKE LOWER(?) OR LOWER(m.auteur) LIKE LOWER(?) OR LOWER(m.mots_cles) LIKE LOWER(?))'; $needle='%'.$q.'%'; array_push($params,$needle,$needle,$needle); }
    if ($filiereFilter) { $where[]='m.filiere_id=?'; $params[]=$filiereFilter; }
    if ($yearFilter) { $where[]='m.annee_soutenance=?'; $params[]=$yearFilter; }
    $condition=implode(' AND ',$where); $count=$pdo->prepare("SELECT COUNT(*) FROM memoires m WHERE $condition"); $count->execute($params); $total=(int)$count->fetchColumn();
    $stmt=$pdo->prepare("SELECT m.*,f.nom filiere FROM memoires m JOIN filieres f ON f.id=m.filiere_id WHERE $condition ORDER BY m.annee_soutenance DESC,m.cree_le DESC LIMIT 10 OFFSET " . (($pageNo-1)*10)); $stmt->execute($params); $results=$stmt->fetchAll();
}
$filieres=$pdo->query('SELECT id,nom FROM filieres ORDER BY nom')->fetchAll();
$years=$pdo->query('SELECT DISTINCT annee_soutenance FROM memoires WHERE statut="valide" ORDER BY annee_soutenance DESC')->fetchAll(PDO::FETCH_COLUMN);
function render_header(string $title, ?array $flash, string $error): void { $u=current_user(); ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f7f8f3"><title><?=e($title)?> · MemoThèque</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">M</span><span>Memo<span class="brand-light">Thèque</span><small>LA BIBLIOTHÈQUE DES IDÉES</small></span></a><nav><a href="index.php">Explorer</a><?php if($u):?><a href="index.php?page=espace">Mon espace</a><?php if(is_role('bibliothecaire','administrateur')):?><a href="index.php?page=moderation">Modération</a><?php endif;?><?php if(is_role('administrateur')):?><a href="index.php?page=administration">Admin</a><?php endif;?><?php if(is_role('etudiant') && $u['statut_compte']==='valide' && in_array($u['niveau_etudes'],['Licence 3','Master 2','Doctorat'],true)):?><a class="nav-cta" href="index.php?page=depot">Déposer un mémoire <span>↗</span></a><?php endif;?><form method="post" action="index.php?page=deconnexion" class="logout-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="nav-logout">Déconnexion</button></form><?php else:?><a href="index.php?page=connexion">Connexion</a><a class="nav-cta" href="index.php?page=inscription">Créer un compte <span>↗</span></a><?php endif;?></nav></header>
<main><?php if($flash):?><div class="notice <?=e($flash[1])?>"><?=e($flash[0])?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<?php }
function render_footer(): void { ?></main><footer><div class="footer-brand">MemoThèque</div><p>Les idées se transmettent, les recherches avancent.</p><small>© 2026 MemoThèque · Les auteurs conservent leurs droits.</small></footer></body></html><?php }

if ($page==='deconnexion' && $_SERVER['REQUEST_METHOD']==='POST') { session_destroy(); header('Location: index.php'); exit; }
if ($page==='deconnexion') { http_response_code(405); exit('Méthode non autorisée.'); }
if ($page==='fichier') { header('Location: file.php?id=' . rawurlencode($_GET['id'] ?? '') . '&type=memoire'); exit; }
if ($page==='memoire') {
    $token=$_GET['id']??''; $stmt=$pdo->prepare('SELECT m.*,f.nom filiere FROM memoires m JOIN filieres f ON f.id=m.filiere_id WHERE m.lien_partage=? AND (m.statut="valide" OR m.deposant_id=?)'); $stmt->execute([$token,$user['id']??0]); $memo=$stmt->fetch();
    if (!$memo) { http_response_code(404); $memo=null; }
    render_header($memo['titre']??'Mémoire introuvable',$flash,$error);
    if($memo):?><section class="detail-head"><a class="back" href="index.php">← Retour au catalogue</a><span class="eyebrow"><?=e($memo['filiere'])?> <i>·</i> <?=e((string)$memo['annee_soutenance'])?></span><h1><?=e($memo['titre'])?></h1><p class="author">Par <?=e($memo['auteur'])?></p></section><article class="detail-card"><h2>Résumé</h2><p><?=nl2br(e($memo['resume']))?></p><div class="tags"><?php foreach(explode(',',$memo['mots_cles']) as $tag):?><span><?=e(trim($tag))?></span><?php endforeach;?></div><div class="detail-actions"><a class="button primary" href="index.php?page=fichier&id=<?=e($memo['lien_partage'])?>">Télécharger le mémoire <span>↓</span></a><button class="button outline" onclick="navigator.clipboard.writeText(location.href);this.textContent='Lien copié ✓'">Partager la fiche ↗</button></div><p class="copyright">Le téléchargement est réservé aux membres connectés. Le document reste protégé par le droit d’auteur : citez-le et ne le revendez pas.</p></article><?php else:?><div class="empty"><h2>Cette fiche est introuvable</h2><a href="index.php">Retour à l’accueil</a></div><?php endif; render_footer(); exit;
}
render_header(match($page){'inscription'=>'Créer un compte','connexion'=>'Connexion','espace'=>'Mon espace','depot'=>'Déposer un mémoire','moderation'=>'Modération','administration'=>'Administration',default=>'Explorer les mémoires'},$flash,$error);
?>
<?php if($page==='accueil'):?>
<section class="hero"><div class="hero-copy"><span class="eyebrow"><i class="dot"></i> LE SAVOIR EN PARTAGE</span><h1>Chaque mémoire<br>ouvre <em>une porte.</em></h1><p>Explorez les travaux de recherche de notre communauté universitaire. Trouvez l’inspiration pour votre prochaine idée.</p><div class="hero-stats"><div><strong>01.</strong><span>Explorer<br>les recherches</span></div><div><strong>02.</strong><span>Trouver votre<br>inspiration</span></div><div><strong>03.</strong><span>Faire avancer<br>le savoir</span></div></div></div><div class="hero-art"><div class="sun"></div><div class="art-label">UN SAVOIR<br>QUI CIRCULE</div><div class="art-lines"></div><div class="art-caption">LE SAVOIR<br>EN MOUVEMENT<br><span>✳</span></div><div class="art-counter">01 — 05</div></div><div class="hero-index">BIBLIOTHÈQUE NUMÉRIQUE<br>ÉDITION 2026</div></section>
<section class="catalog"><div class="section-kicker"><span>01 / LE CATALOGUE</span><span>COLLECTION OUVERTE · 2026</span></div><div class="catalog-heading"><div><h2>Des idées qui<br><em>font leur chemin.</em></h2><p>Parcourez les recherches, trouvez votre prochaine piste.</p></div><span class="catalog-count"><?=number_format($total)?> <small>RÉSULTATS</small></span></div>
<form class="search-form" action="index.php" method="get"><input type="hidden" name="page" value="accueil"><label class="search-field"><span>⌕</span><input name="q" value="<?=e($q)?>" placeholder="Un titre, un auteur, un mot-clé…"></label><select name="filiere"><option value="0">Toutes les filières</option><?php foreach($filieres as $f):?><option value="<?=$f['id']?>" <?=$filiereFilter===$f['id']?'selected':''?>><?=e($f['nom'])?></option><?php endforeach;?></select><select name="annee"><option value="0">Toutes les années</option><?php foreach($years as $year):?><option value="<?=$year?>" <?=$yearFilter===(int)$year?'selected':''?>><?=$year?></option><?php endforeach;?></select><button class="button primary" type="submit">Rechercher <span>↗</span></button></form>
<?php if(!$q&&!$filiereFilter&&!$yearFilter):?><div class="search-hint">Recherchez par titre, auteur ou mot-clé, ou choisissez un filtre pour découvrir les mémoires.</div><?php elseif(!$results):?><div class="empty"><span>✳</span><h3>Aucun mémoire ne correspond à votre recherche</h3><p>Vérifiez l’orthographe ou retirez un filtre pour élargir les résultats.</p><a class="button outline" href="index.php">Réinitialiser la recherche</a></div><?php else:?><div class="result-meta"><span><?=number_format($total)?> MÉMOIRE<?=($total>1?'S':'')?> · <?=min(10,$total-(($pageNo-1)*10))?> AFFICHÉ<?=($total>1?'S':'')?></span><span>DU PLUS RÉCENT AU PLUS ANCIEN ↓</span></div><div class="memo-grid"><?php foreach($results as $memo):?><a class="memo-card" href="index.php?page=memoire&id=<?=e($memo['lien_partage'])?>"><div class="memo-card-top"><span><?=e($memo['filiere'])?></span><span><?=e((string)$memo['annee_soutenance'])?></span></div><h3><?=e($memo['titre'])?></h3><p><?=e($memo['resume'])?></p><div class="memo-card-bottom"><span><?=e($memo['auteur'])?></span><b>↗</b></div></a><?php endforeach;?></div><?php if($total>10):?><div class="pagination"><?php for($i=1;$i<=ceil($total/10);$i++):?><a class="<?=$pageNo===$i?'active':''?>" href="<?=e('index.php?'.http_build_query(['q'=>$q,'filiere'=>$filiereFilter,'annee'=>$yearFilter,'p'=>$i]))?>"><?=$i?></a><?php endfor;?></div><?php endif;?><?php endif;?></section>
<?php elseif($page==='inscription'||$page==='connexion'):?><section class="form-page"><div class="form-intro"><span class="eyebrow">MEMOTHÈQUE · VOTRE ESPACE</span><h1><?=$page==='inscription'?'Les idées grandissent<br><em>quand on partage.</em>':'Ravi de vous<br><em>retrouver.</em>'?></h1><p><?=$page==='inscription'?'Rejoignez une communauté qui fait circuler les idées.':'Connectez-vous pour retrouver vos mémoires et votre espace.'?></p></div><form class="panel-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><h2><?=$page==='inscription'?'Créer mon compte':'Connexion'?></h2><?php if($page==='inscription'):?><div class="form-row"><label>Prénom<input name="prenom" required autocomplete="given-name"></label><label>Nom<input name="nom" required autocomplete="family-name"></label></div><?php endif;?><label>Adresse e-mail<input type="email" name="email" required autocomplete="email"></label><label>Mot de passe<input type="password" name="mot_de_passe" required minlength="10" autocomplete="<?=$page==='connexion'?'current-password':'new-password'?>"></label><?php if($page==='inscription'):?><label class="checkline"><input type="checkbox" name="etudiant" value="1" onchange="document.getElementById('student-fields').hidden=!this.checked"> Je suis étudiant(e) et je souhaite déposer un mémoire</label><div id="student-fields" hidden><label>Niveau d’études<select name="niveau_etudes"><option value="">Choisir un niveau</option><?php foreach(['Licence 1','Licence 2','Licence 3','Master 1','Master 2','Doctorat'] as $l):?><option><?=e($l)?></option><?php endforeach;?></select></label><label>Certificat de scolarité (PDF, JPG ou PNG, 5 Mo max)<input type="file" name="certificat" accept=".pdf,.jpg,.jpeg,.png"></label></div><?php endif;?><button class="button primary full" type="submit"><?=$page==='inscription'?'Créer mon compte':'Se connecter'?> <span>↗</span></button><p class="form-switch"><?=$page==='inscription'?'Déjà inscrit(e) ? <a href="index.php?page=connexion">Se connecter</a>':'Pas encore de compte ? <a href="index.php?page=inscription">Créer un compte</a>'?></p></form></section>
<?php elseif($page==='espace'):require_login();?><section class="dashboard"><span class="eyebrow">VOTRE ESPACE PERSONNEL</span><h1>Bonjour, <em><?=e($user['prenom'])?>.</em></h1><p>Votre compte : <strong><?=e($user['statut_compte'])?></strong><?=!empty($user['motif_refus'])?' · '.e($user['motif_refus']):''?></p><?php if($user['role']==='etudiant'&&$user['statut_compte']==='valide'&&in_array($user['niveau_etudes'],['Licence 3','Master 2','Doctorat'],true)):?><a class="button primary" href="index.php?page=depot">Déposer un mémoire ↗</a><?php endif;?><h2>Mes mémoires</h2><?php $stmt=$pdo->prepare('SELECT m.*,f.nom filiere FROM memoires m JOIN filieres f ON f.id=m.filiere_id WHERE deposant_id=? ORDER BY cree_le DESC');$stmt->execute([$user['id']]);$own=$stmt->fetchAll();if($own):?><div class="table-wrap"><table><thead><tr><th>Mémoire</th><th>Filière</th><th>Statut</th><th>Décision</th></tr></thead><tbody><?php foreach($own as $m):?><tr><td><?=e($m['titre'])?></td><td><?=e($m['filiere'])?></td><td><span class="badge"><?=e($m['statut'])?></span></td><td><?=e($m['motif_refus']??'—')?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="empty"><p>Vous n’avez pas encore déposé de mémoire.</p></div><?php endif;?></section>
<?php elseif($page==='depot'):require_login();?><section class="form-page wide"><div class="form-intro"><span class="eyebrow">CONTRIBUER À LA COLLECTION</span><h1>Votre recherche,<br><em>une nouvelle piste.</em></h1><p>Après dépôt, votre mémoire est examiné par un bibliothécaire avant sa publication.</p></div><form class="panel-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><h2>Déposer un mémoire</h2><label>Titre du mémoire<input name="titre" required maxlength="255"></label><div class="form-row"><label>Auteur du mémoire<input name="auteur" required></label><label>Année de soutenance<input type="number" name="annee_soutenance" min="1990" max="<?=date('Y')?>" required></label></div><label>Filière<select name="filiere_id" required><option value="">Choisir une filière</option><?php foreach($filieres as $f):?><option value="<?=$f['id']?>"><?=e($f['nom'])?></option><?php endforeach;?></select></label><label>Résumé<textarea name="resume" rows="5" required></textarea></label><label>Mots-clés (séparés par des virgules)<input name="mots_cles" required></label><label>Fichier du mémoire (PDF, 50 Mo max)<input type="file" name="memoire_pdf" accept="application/pdf,.pdf" required></label><label>Attestation de soutenance (PDF, 5 Mo max)<input type="file" name="attestation" accept="application/pdf,.pdf" required></label><label class="checkline"><input type="checkbox" name="autorisation_diffusion" required> Je certifie être l’auteur, détenir les droits nécessaires et autoriser la diffusion de ce mémoire.</label><label class="checkline"><input type="checkbox" name="absence_confidentialite" required> Je déclare que ce document ne contient pas d’information confidentielle non autorisée.</label><button class="button primary full">Envoyer pour validation <span>↗</span></button></form></section>
<?php elseif($page==='moderation'):require_login();if(!is_role('bibliothecaire','administrateur')):?><div class="empty"><h2>Accès réservé</h2></div><?php else:?><section class="dashboard"><span class="eyebrow">ESPACE BIBLIOTHÈQUE</span><h1>À examiner <em>ensemble.</em></h1><?php $pending=$pdo->query('SELECT m.*,f.nom filiere,u.nom deposant_nom,u.prenom deposant_prenom FROM memoires m JOIN filieres f ON f.id=m.filiere_id JOIN utilisateurs u ON u.id=m.deposant_id WHERE m.statut="en_attente" ORDER BY m.cree_le')->fetchAll();if(!$pending):?><div class="empty"><p>Aucun mémoire en attente.</p></div><?php endif;foreach($pending as $m):?><article class="review-card"><span class="eyebrow"><?=e($m['filiere'])?> · <?=e((string)$m['annee_soutenance'])?></span><h2><?=e($m['titre'])?></h2><p>Par <?=e($m['auteur'])?> · Déposé par <?=e($m['deposant_prenom'].' '.$m['deposant_nom'])?></p><p><?=e($m['resume'])?></p><p class="tags"><?=e($m['mots_cles'])?></p><a href="file.php?id=<?=e($m['lien_partage'])?>&type=memoire" class="button outline">Voir le PDF</a> <a href="file.php?id=<?=e($m['lien_partage'])?>&type=attestation" class="button outline">Attestation</a><form class="decision" method="post" action="index.php?page=decision_memoire"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$m['id']?>"><input name="motif" placeholder="Motif obligatoire en cas de refus"><button class="button primary" name="decision" value="valide">Valider</button><button class="button danger" name="decision" value="refuse" onclick="if(!this.form.motif.value){alert('Indiquez le motif de refus.');return false}">Refuser</button></form></article><?php endforeach;?></section><?php endif;?>
<?php elseif($page==='administration'):require_login();if(!is_role('administrateur')):?><div class="empty"><h2>Accès réservé</h2></div><?php else:?><section class="dashboard"><span class="eyebrow">ADMINISTRATION</span><h1>Gérer la <em>plateforme.</em></h1><h2>Comptes étudiants en attente</h2><?php $people=$pdo->query('SELECT * FROM utilisateurs WHERE role="etudiant" AND statut_compte="en_attente" ORDER BY cree_le')->fetchAll();if(!$people):?><div class="empty"><p>Aucun compte en attente.</p></div><?php endif;foreach($people as $person):?><article class="review-card"><h2><?=e($person['prenom'].' '.$person['nom'])?></h2><p><?=e($person['email'])?> · <?=e($person['niveau_etudes'])?></p><?php if($person['certificat_fichier']):?><a class="button outline" href="file.php?user=<?=$person['id']?>&type=certificat">Voir le certificat</a><?php endif;?><form class="decision" method="post" action="index.php?page=decision_compte"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$person['id']?>"><input name="motif" placeholder="Motif obligatoire en cas de refus"><button class="button primary" name="decision" value="valide">Valider le compte</button><button class="button danger" name="decision" value="refuse" onclick="if(!this.form.motif.value){alert('Indiquez le motif de refus.');return false}">Refuser</button></form></article><?php endforeach;?><h2>Filières</h2><?php foreach($filieres as $f):?><form class="inline-form" method="post" action="index.php?page=filiere"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="action" value="modifier"><input name="nom" value="<?=e($f['nom'])?>" required><button class="button outline">Renommer</button><button class="button danger" name="action" value="supprimer" onclick="return confirm('Supprimer cette filière ?')">Supprimer</button></form><?php endforeach;?><form class="inline-form" method="post" action="index.php?page=filiere"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input name="nom" placeholder="Nouvelle filière" required><button class="button primary">Ajouter</button></form></section><?php endif;?>
<?php else:?><div class="empty"><h2>Page introuvable</h2><a href="index.php">Retour à l’accueil</a></div><?php endif;?>
<?php render_footer(); ?>