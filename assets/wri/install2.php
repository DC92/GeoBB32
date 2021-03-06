<style>
p { /* Texte */
	margin: 0;
}
div { /* Vérification OK */
	color: green;
}
label { /* Action à faire */
	display: block;
	font-weight: bold;
}
section { /* Action OK */
	display: block;
	font-weight: bold;
	color: green;
}
strong { /* Erreur */
	display: block;
	margin-top: 1em;
	color: red;
}
input {
	cursor: pointer;
}
</style>

<h3>Installation de refuges.info</h3>
<p>Cet utilitaire gére l'installation ou l'upgrade d'un site refuges.info</p>
<p>à l'exception des tables spécifiques refuges.info</p>
<p>qui doivent être récupérées d'une base existante.</p>
<br/>
<form method='post'>

<?php
//=======================
/* MISE A JOUR DES FICHIERS phpBB DANS LE MASTER WRI GITHUB
(pour Windows/Tortoise)

Supprimer tous les fichiers non versionnés
Tortoise : supprimer les répertoires
	/forum/adm/...
	/forum/assets/...
	/forum/config/...
	/forum/images/... sauf /forum/images/smilies/wri*
	/forum/includes/...
	/forum/install.modele/...
	/forum/langages/...
	/forum/phpbb/...
	/forum/styles/...
	/forum/vendor/...

Décompresser pphBB_FR.zip pack complet dans /forum sauf :
	config.php
	ext/phpbb/... (viglink)
	docs/...
Renommer /forum/install /forum/install en /forum/install.modele

Upgrader si nécéssiare la version compatible de cleantalk dans /forum/ext

Copier dans /forum le contenu de ces répertoires
https://www.phpbb.com/customise/db/translation/german_casual_honorifics/
https://www.phpbb.com/customise/db/translation/italian/
https://www.phpbb.com/customise/db/translation/spanish_casual_honorifics/

Tortoise : /forum/... add all
*/

//================
// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Pour l'utilitaire d'install du forum
setcookie ('lang', 'fr');

//==========================
// Fichier config_privee.php
if (file_exists ('config_privee.php'))
	echo "<div>Fichier config_privee.php présent</div>\n";
else
//NOTE : On peut mettre un config_privee.php personnalisé dans le répertoire en dessous de la racine du site
if (file_exists ('../config_privee.php.modele')) {
	echo "<section>Copie du fichier ../config_privee.php.modele (perso) dans /config_privee.php</section>\n";
	ob_flush(); flush(); // On affiche où on en est avant de partir dans des aventures risquées
	copy ('../config_privee.php.modele', 'config_privee.php');
}
else
	initialise_fichier_par_defaut_ou_meurs ('config_privee.php.modele', 'config_privee.php');

//==================
// Fichier .htaccess
if (file_exists ('.htaccess'))
	echo "<div>Fichier .htaccess présent</div>\n";
else
//NOTE : On peut mettre un htaccess.modele.txt personnalisé dans le répertoire en dessous de la racine du site
if (file_exists ('../htaccess.modele.txt')) {
	echo "<section>Copie du fichier ../htaccess.modele.txt (perso) dans /.htaccess</section>\n";
	ob_flush(); flush();
	copy ('../htaccess.modele.txt', '.htaccess');
}
else
	initialise_fichier_par_defaut_ou_meurs ('htaccess.modele.txt', '.htaccess');

//========================
// Etat des fichiers phpBB
// On ne teste pas l'existence puisqu'il est dans le GIT
ob_flush(); flush();
define ('IN_PHPBB', true);
include ('forum/includes/constants.php');
$phpbb_version_fichiers = PHPBB_VERSION;
echo "<div>Fichiers phpBB version $phpbb_version_fichiers</div>\n";

//=========================
// Fichier forum/config.php
if (file_exists ('forum/config.php')) {
	include ('forum/config.php');
	if ($dbms && $table_prefix)
		echo "<div>Fichier forum/config.php présent</div>\n";
	else {
		echo "<strong>Le fichier forum/config.php ne contient pas les paramètres d'accès à SQL</strong>";
		exit;
	}
} else {
	include ('config_privee.php');
	// Cas ou le forum est déjà installé et les paramètres SQL sont dans config_privee.php
	if (isset ($config_wri['serveur_pgsql']) &&
		$config_wri['serveur_pgsql'] &&
		$config_wri['serveur_pgsql'] != '???')
		initialise_fichier_par_defaut_ou_meurs ('forum/config.modele.php', 'forum/config.php');

	// Récupération ou install de la base phpBB
	else {
		echo "<strong>Le fichier config_privee.php ne contient pas les paramètres d'accès à SQL</strong><br/>\n".
			"<label>Si vous avez déjà une base SQL refuges.info : renseignez les paramètres dans config_privee.php</label><br/>\n";

		// On propose de créer les tables SQL phpBB
		if (!is_dir('forum/install'))
			recurse_copy ('forum/install.modele', 'forum/install');
		echo "<label>Sinon, créez la base phpBB : ".
			"<input type='submit' formaction='forum/install/app.php/install' value='Installer phpBB'></label>\n".
			"<p>Puis <b>Installer</b> puis suivre les instructions</p>\n".
			"<p>Puis revenir à cet utilitaire <b>/install.php</b></p>";
		exit;
	}
}

//=========================
// Connexion au serveur SQL
if (strpos ($dbms, 'postgres')) {
	$config_wri['debug']=true;
	include ('includes/bdd.php');
	ob_flush(); flush();
	if (@$pdo->errorCode() === null) {
		echo "<strong>Les paramètres de config_privee.php ou forum/config.php ne permettent pas de se connecter à la base PGSQL</strong>";
		exit;
	}
	echo "<div>Connexion à PGSQL OK</div>\n";
}
elseif (strpos ($dbms, 'mysqli')) {
	ob_flush(); flush();
	if (@mysqli_connect($dbhost, $dbuser, $dbpasswd))
		echo "<div>Connexion à MYSQLI OK</div>\n";
	else {
		echo "<strong>Les paramètres de config_privee.php ou forum/config.php ne permettent pas de se connecter à la base MYSQLI</strong>";
		exit;
	}
}
elseif (strpos ($dbms, 'mysql')) {
	ob_flush(); flush();
	if (@mysqli_connect($dbhost, $dbuser, $dbpasswd))
		echo "<div>Connexion à MYSQL OK</div>\n";
	else {
		echo "<strong>Les paramètres de config_privee.php ou forum/config.php ne permettent pas de se connecter à la base MYSQL</strong>";
		exit;
	}
}
else
	echo "<p><b>Connexion SQL via $dbms non testée</b></p>\n";

//======================
// Etat de la base phpBB
if (file_exists ('forum/config.php')) {
	echo "<p id='test_base'>Test de la base phpBB . . .</p>\n";
	ob_flush(); flush();
	echo "<style>#test_base{display:none}</style>\n";
	$phpbb_root_path = 'forum/';
	$phpEx = 'php';
	include ('forum/common.php');
	$sql = "SELECT config_value FROM {$table_prefix}config WHERE config_name = 'version'";
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if ($row) {
		$phpbb_version_bdd = $row['config_value'];
		echo "<div>Base phpBB version $phpbb_version_bdd</div>\n";
	}
	else {
		$phpbb_version_bdd = null;
		echo "<strong>Pas de base phpBB instalée</strong>\n".
			"<label>Vérifiez les paramètres de la base SQL dans config_privee.php ou forum/config.php</label>";
		exit;
	}
}

//=========================
// Upgrade de la base phpBB
if ($phpbb_version_bdd != $phpbb_version_fichiers) {
	if (!is_dir('forum/install'))
		recurse_copy ('forum/install.modele', 'forum/install');

	echo "<strong>La base SQL phpBB n'est pas à la version des fichiers phpBB</strong>\n".
		"<label>Upgrader la base phpBB en version $phpbb_version_fichiers ? ".
		"<input type='submit' formaction='forum/install/app.php/update' value='Upgrader'></label>\n".
		"<p>Puis <b>Update</b> puis <b>Update database only</b> puis <b>Submit</b></p>\n".
		"<p>(Ne pas tenir compte de : No valid update directory was found...)</p>\n".
		"<p>Puis revenir à cet utilitaire <b>/install.php</b></p>";
	exit;
}

//=====================
// Purge fichiers phpBB
echo "</br><div>Purge des fichiers phpBB install & cache</div>\n";
ob_flush(); flush();
recurse_delete ('forum/install');
recurse_delete ('forum/docs');
recurse_delete ('forum/cache/installer');
recurse_delete ('forum/cache/production');

//=================================
// Tables refuges.info dans la base
ob_flush(); flush();
$sql = "select column_name, data_type, character_maximum_length
from INFORMATION_SCHEMA.COLUMNS where table_name = 'phpbb_posts'";
$sql1 = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'phpbb_posts'";
$sql2 = "CREATE TABLE public.polygone_type (
    id_polygone_type integer DEFAULT nextval('public.polygone_type_id_polygone_type_seq'::regclass) NOT NULL,
    art_dem_poly character varying(6) NOT NULL,
    art_def_poly character varying(6) NOT NULL,
    type_polygone character varying(32) NOT NULL,
    ordre_taille integer DEFAULT 0 NOT NULL,
    categorie_polygone_type character varying(255)
);
";
$result = $db->sql_query($sql);
//*DCMM*/echo"<pre style='background:white;color:black;font-size:14px'> = ".var_export($_COOKIE,true).'</pre>';
$row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);
/*DCMM*/echo"<pre style='background:white;color:black;font-size:14px'> = ".var_export($row,true).'</pre>';
//if ($row)
	echo "<div>Base des points de refuges.info présente</div>\n";
if(0) {

//////////////////////////////////////////
$sql = "SELECT config_value FROM {$table_prefix}config WHERE config_name = 'version'";
$result = $db->sql_query($sql);
$row = $db->sql_fetchrow($result);
$db->sql_freeresult($result);





//////////////////////////////////////////

	echo "<strong>Données refuges.info non présentes dans la base</strong>\n".
		"<p>Cet utilitaire ne gère pas l'initialisation de la base de données refuges.info,</p>\n".
		"<p>vous devez récupérer une base existante</p>";
	exit;
}

//=============
// C'est fini !
if ($phpbb_version_bdd == $phpbb_version_fichiers) {
	echo "<h3 style='color:green'>Votre installation est à jour : \n".
		"<a style='color:green' href='.'>Aller sur le site</a></h3>";
}

//=============================================
function initialise_fichier_par_defaut_ou_meurs ($modele, $fichier) {
	if (file_exists ($modele)) {
		echo "<section>Copie du fichier /$modele dans /$fichier</section>\n";
		ob_flush(); flush();
		copy ($modele, $fichier);
	}
	else {
		echo "<strong>Fichier $modele manquant</strong>\n".
			"<strong>Vous devez installer les fichiers de ".
			"<a href='https://github.com/RefugesInfo/www.refuges.info'>".
			"https://github.com/RefugesInfo/www.refuges.info</a></strong>";
		exit;
	}
}

//===================
function recurse_copy ($src, $dst, $recurse = false) {
	if (!$recurse)
		echo "<section>Copie du répertoire /$src dans /$dst</section>\n";
	ob_flush(); flush();
    $dir = opendir ($src);
    @mkdir ($dst);
    while (false !== ($file = readdir ($dir)))
        if (($file != '.') && ($file != '..')) {
            if (is_dir ($src . '/' . $file))
                recurse_copy ($src . '/' . $file,$dst . '/' . $file, true);
            else
                copy ($src . '/' . $file,$dst . '/' . $file);
        }
    closedir ($dir);
}

//===================================
function recurse_delete ($dir) {
	ob_flush(); flush();
	if (is_dir ($dir)) {
		$files = array_diff (scandir ($dir), ['.','..']);
		foreach ($files as $file)
			(is_dir ("$dir/$file") && !is_link ($dir))
				? recurse_delete ("$dir/$file")
				: unlink ("$dir/$file");
		rmdir ($dir);
	}
}
?>
</form>
