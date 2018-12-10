<?php
/**
 *
 * @package GeoBB
 * @copyright (c) 2016 Dominique Cavailhez
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace Dominique92\GeoBB\event;

define('SQL_PRE', ''); //TODO-ARCHI MySQL 5.7+ 'ST_'

if (!defined('IN_PHPBB'))
{
	exit;
}

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\request\request_interface $request,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		\phpbb\extension\manager $extension_manager,
		$root_path
	) {
		$this->db = $db;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->auth = $auth;
//TODO-BEST		$this->extension_manager = $extension_manager;
//TODO-BEST		$this->root_path = $root_path;
//TODO-ASPIR ??? recherche par département / commune
	}

	// Liste des hooks et des fonctions associées
	// On trouve le point d'appel en cherchant dans le logiciel de PhpBB 3.x: "event core.<XXX>"
	static public function getSubscribedEvents() {
		return [
			// All
			'core.page_footer' => 'page_footer',

			// Index
			'core.display_forums_modify_row' => 'display_forums_modify_row',
			'core.index_modify_page_title' => 'index_modify_page_title',

			// Viewtopic
			'core.viewtopic_get_post_data' => 'viewtopic_get_post_data',
			'core.viewtopic_post_rowset_data' => 'viewtopic_post_rowset_data',
			'core.viewtopic_modify_post_row' => 'viewtopic_modify_post_row',

			// Posting
			'core.modify_posting_parameters' => 'modify_posting_parameters',
			'core.posting_modify_submission_errors' => 'posting_modify_submission_errors',
			'core.submit_post_modify_sql_data' => 'submit_post_modify_sql_data',
			'core.posting_modify_template_vars' => 'posting_modify_template_vars',
			'core.submit_post_end' => 'submit_post_end',
		];
	}

	/**
		ALL
	*/
	function page_footer() {
//		ob_start();var_dump($this->template);echo'template = '.ob_get_clean(); // VISUALISATION VARIABLES TEMPLATE

		// Inclue les fichiers langages de cette extension
		$ns = explode ('\\', __NAMESPACE__);
		$this->user->add_lang_ext($ns[0].'/'.$ns[1], 'common');

		// Assign post contents to some templates variables
		$mode = $this-> request->variable('mode', '');
		$msgs = [
			'Conditions d\'utilisation' => 'L_TERMS_OF_USE',
			'Politique de confidentialité' => 'L_PRIVACY_POLICY',
			'Bienvenue' => 'GEO_PRESENTATION',
			'Aide' => 'GEO_URL_AIDE',
			$mode == 'terms' ? 'Conditions d\'utilisation' : 'Politique de confidentialité' => 'AGREEMENT_TEXT',
		];
		foreach ($msgs AS $k=>$v) {
			$sql = 'SELECT post_text, bbcode_uid, bbcode_bitfield FROM '.POSTS_TABLE.' WHERE post_subject = "'.$k.'" ORDER BY post_id';
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($row) {
				$this->template->assign_var (
					$v,
					generate_text_for_display($row['post_text'],
					$row['bbcode_uid'],
					$row['bbcode_bitfield'],
					OPTION_FLAG_BBCODE, true)
				);
			}
		}
	}


	/**
		INDEX.PHP
	*/
	// Ajoute un bouton créer un point en face de la liste des forums
	function display_forums_modify_row ($vars) {
		$row = $vars['row'];

		if ($this->auth->acl_get('f_post', $row['forum_id']) &&
			$row['forum_type'] == FORUM_POST)
			$row['forum_name'] .= ' &nbsp; '.
				'<a class="button" href="./posting.php?mode=post&f='.$row['forum_id'].'" title="Créer un nouveau sujet '.strtolower($row['forum_name']).'">Créer</a>';
		$vars['row'] = $row;
	}

	// Affiche les post les plus récents sur la page d'accueil
	function index_modify_page_title ($vars) {
		$this->geobb_activate_map('[all=accueil]');

		// More news count
		$news = request_var ('news', 20);
		$this->template->assign_var ('PLUS_NOUVELLES', $news * 2);

		// Display news
		$sql = "
			SELECT p.post_id, p.post_attachment, p.post_time, p.poster_id,
				t.topic_id, topic_title,topic_first_post_id, t.topic_posts_approved,
				f.forum_id, f.forum_name, f.forum_image,
				u.username
			FROM	 ".TOPICS_TABLE." AS t
				JOIN ".FORUMS_TABLE." AS f USING (forum_id)
				JOIN ".POSTS_TABLE." AS p ON (p.post_id = t.topic_last_post_id)
				JOIN ".USERS_TABLE."  AS u ON (p.poster_id = u.user_id)
			WHERE post_visibility = ".ITEM_APPROVED."
			ORDER BY post_time DESC
			LIMIT $news
		";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			if ($this->auth->acl_get('f_read', $row['forum_id'])) {
				$row ['post_time'] = '<span title="'.$this->user->format_date ($row['post_time']).'">'.date ('j M', $row['post_time']).'</span>';
//TODO				$row ['geo_massif'] = str_replace ('~', '', $row ['geo_massif']);
				$this->template->assign_block_vars('news', array_change_key_case ($row, CASE_UPPER));
			}
		$this->db->sql_freeresult($result);
	}

	/**
		VIEWTOPIC.PHP
	*/
	// Appelé avant la requette SQL qui récupère les données des posts
	function viewtopic_get_post_data($vars) {
		$sql = 'SHOW columns FROM '.POSTS_TABLE.' LIKE "geom"';
		$result = $this->db->sql_query($sql);
		if ($this->db->sql_fetchrow($result)) {
			// Insère la conversion du champ geom en format WKT dans la requette SQL
			$sql_ary = $vars['sql_ary'];
			$sql_ary['SELECT'] .= ', AsText(geom) AS geomwkt';
			$vars['sql_ary'] = $sql_ary;
		}
		$this->db->sql_freeresult($result);
	}

	// Appelé lors de la première passe sur les données des posts qui lit les données SQL de phpbb-posts
	function viewtopic_post_rowset_data($vars) {
		// Mémorise les données SQL du post pour traitement plus loin (viewtopic procède en 2 fois)
		$post_id = $vars['row']['post_id'];
		$this->all_post_data [$post_id] = $vars['row'];
	}

	// Appelé lors de la deuxième passe sur les données des posts qui prépare dans $post_row les données à afficher sur le post du template
	function viewtopic_modify_post_row($vars) {
		$post_id = $vars['row']['post_id'];
		$this->template->assign_vars ([
			'TOPIC_FIRST_POST_ID' => $vars['topic_data']['topic_first_post_id'],
			'TOPIC_AUTH_EDIT' =>
				$this->auth->acl_get('m_edit', $vars['row']['forum_id']) ||
				$vars['topic_data']['topic_poster'] == $this->user->data['user_id'],
		]);
		$this->geobb_activate_map($vars['topic_data']['forum_desc']);

		// Assign the geo values to the template
		if (isset ($this->all_post_data[$post_id])) {
			$post_data = $this->all_post_data[$post_id]; // Récupère les données SQL du post
			$post_row = $vars['post_row'];

			// Convert the geom info in geoJson format
			preg_match ('/\[(first|all)=([a-z]+)\]/i', $vars['topic_data']['forum_desc'], $regle);
			if (count ($regle) == 3 && (
					($regle[1] == 'all') ||
					($regle[1] == 'first' && ($post_data['post_id'] == $vars['topic_data']['topic_first_post_id']))
				) &&
				@$post_data['geomwkt']
			) {
				include_once('assets/geoPHP/geoPHP.inc');
				$geophp = \geoPHP::load($post_data['geomwkt'],'wkt');
				$post_data['geojson'] = $geophp->out('json');
				$this->get_bounds($geophp);
			}

			if ($post_data['post_id'] == $vars['topic_data']['topic_first_post_id']) {
				$this->get_automatic_data($post_data);
				$this->topic_fields('info', $post_data, $vars['topic_data']['forum_desc'], $vars['topic_data']['forum_name']);

				// Assign geo_ vars to template for these used out of topic_fields
				foreach ($post_data AS $k=>$v)
					if (strstr ($k, 'geo')
						&& is_string ($v))
						$this->template->assign_var (strtoupper ($k), str_replace ('~', '', $v));

				$vars['post_row'] = $post_row;
			}
		}
	}


	/**
		POSTING.PHP
	*/
	// Appelé au début pour ajouter des parametres de recherche sql
	function modify_posting_parameters($vars) {
		// Création topic avec le nom d'image
		$forum_image = $this->request->variable('type', '');
		$sql = 'SELECT forum_id FROM '.FORUMS_TABLE.' WHERE forum_image LIKE "%/'.$forum_image.'.%"';
		$result = $this->db->sql_query ($sql);
		$row = $this->db->sql_fetchrow ($result);
		$this->db->sql_freeresult ($result);
		if ($row) // Force le forum
			$vars['forum_id'] = $row ['forum_id'];
	}

	// Permet la saisie d'un POST avec un texte vide
	function posting_modify_submission_errors($vars) {
		$error = $vars['error'];

		foreach ($error AS $k=>$v)
			if ($v == $this->user->lang['TOO_FEW_CHARS'])
				unset ($error[$k]);

		$vars['error'] = $error;
	}

	// Appelé lors de l'affichage de la page posting
	function posting_modify_template_vars($vars) {
		$page_data = $vars['page_data'];
		$post_data = $vars['post_data'];

		// Récupère la traduction des données spaciales SQL
		if (isset ($post_data['geom'])) {
			// Conversion WKT <-> geoJson
			$sql = 'SELECT AsText(geom) AS geomwkt
				FROM '.POSTS_TABLE.'
				WHERE post_id = '.$post_data['post_id'];
			$result = $this->db->sql_query($sql);
			$post_data['geomwkt'] = $this->db->sql_fetchfield('geomwkt');
			$this->db->sql_freeresult($result);

			// Traduction en geoJson
			include_once('assets/geoPHP/geoPHP.inc');
			$geophp = \geoPHP::load($post_data['geomwkt'],'wkt');
			$this->get_bounds($geophp);
			$gp = json_decode ($geophp->out('json')); // On transforme le GeoJson en objet PHP
//TODO-BEST			$this->optim ($gp, 0.0001); // La longueur min des segments de lignes & surfaces sera de 0.0001 ° = 10 000 km / 90° * 0.0001 = 11m
			$post_data['geojson'] = json_encode ($gp);
		}

		// Pour éviter qu'un titre vide invalide la page et toute la saisie graphique.
		if (!$post_data['post_subject'])
			$page_data['DRAFT_SUBJECT'] = $this->post_name ?: 'Nom';

		$page_data['TOPIC_ID'] = $post_data['topic_id'] ?: 0;
		$page_data['POST_ID'] = $post_data['post_id'] ?: 0;
		$page_data['TOPIC_FIRST_POST_ID'] = $post_data['topic_first_post_id'] ?: 0;

		// Assign the phpbb-posts SQL data to the template
		foreach ($post_data AS $k=>$v)
			if (!strncmp ($k, 'geo', 3)
				&& is_string ($v))
				$page_data[strtoupper ($k)] =
					strstr($v, '~') == '~' ? null : $v; // Clears fields ending with ~ for automatic recalculation

		$this->topic_fields('info', $post_data, $post_data['forum_desc'], $post_data['forum_name']);
		$this->geobb_activate_map($post_data['forum_desc'], $post_data['post_id'] == $post_data['topic_first_post_id']);

		// HORRIBLE phpbb hack to accept geom values //TODO-BEST : check if done by PhpBB (supposed 3.2)
		$file_name = "phpbb/db/driver/driver.php";
		$file_tag = "\n\t\tif (is_null(\$var))";
		$file_patch = "\n\t\tif (strpos (\$var, 'GeomFromText') !== false) //GeoBB\n\t\t\treturn \$var;";
		$file_content = file_get_contents ($file_name);
		if (strpos($file_content, '{'.$file_tag))
			file_put_contents ($file_name, str_replace ('{'.$file_tag, '{'.$file_patch.$file_tag, $file_content));

		$vars['page_data'] = $page_data;
	}

	// Call when validating the data to be saved
	function submit_post_modify_sql_data($vars) {
		$sql_data = $vars['sql_data'];

		// Get special columns list
		$special_columns = [];
		$sql = 'SHOW columns FROM '.POSTS_TABLE.' LIKE "geo%"';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			$special_columns [] = $row['Field'];
		$this->db->sql_freeresult($result);

		// Treat specific data
		$this->request->enable_super_globals(); // Allow access to $_POST & $_SERVER
		foreach ($_POST AS $k=>$v)
			if (!strncmp ($k, 'geo', 3)) {
				// <Input name="..."> : <sql colomn name>-<sql colomn type>-[<sql colomn size>]
				$ks = split ('-', $k);

				// Create or modify the SQL column
				if (count ($ks) == 3)
					$ks[2] = '('.$ks[2].')';
				$this->db->sql_query(
					'ALTER TABLE '.POSTS_TABLE.
					(in_array ($ks[0], $special_columns) ? ' CHANGE '.$ks[0].' ' : ' ADD ').
					implode (' ', $ks)
				);

				// Retrieves the values of the geometry, includes them in the phpbb_posts table
				if ($ks[1] == 'geometry' && $v) {
					include_once('assets/geoPHP/geoPHP.inc');
					$geophp = \geoPHP::load (html_entity_decode($v), 'json');
					if ($geophp)
						$v = 'GeomFromText("'.$geophp->out('wkt').'")';
				}

				// Retrieves the values of the questionnaire, includes them in the phpbb_posts table
				$sql_data[POSTS_TABLE]['sql'][$ks[0]] = utf8_normalize_nfc($v) ?: null; // null allows the deletion of the field
			}
		$this->request->disable_super_globals();

		$vars['sql_data'] = $sql_data; // return data
		$this->modifs = $sql_data[POSTS_TABLE]['sql']; // Save change
	}

	// Call after the post validation
	function submit_post_end($vars) {
		// Save change
		$this->request->enable_super_globals();
		$to_save = [
			$this->user->data['username'].' '.date('r').' '.$_SERVER['REMOTE_ADDR'],
			$_SERVER['REQUEST_URI'],
			'post_subject = '.$this->modifs['post_subject'],
			'post_text = '.$this->modifs['post_text'],
			'geom = '.str_replace (['GeomFromText("','")'], '', $this->modifs['geom']),
		];
		$this->request->disable_super_globals();
		foreach ($this->modifs AS $k=>$v)
			if ($v && !strncmp ($k, 'geo_', 4))
				$to_save [] = substr ($k, 4).' = '.$v;

		// Save attachment_data
		$attach = [];
		if ($vars['data']['attachment_data'])
			foreach ($vars['data']['attachment_data'] AS $att)
				$attach[] = $att['attach_id'].':'.$att['real_filename'];
		if (isset ($attach))
			$to_save[] = 'attachments = '.implode (', ', $attach);

		file_put_contents ('LOG/'.$vars['data']['post_id'].'.txt', implode ("\n", $to_save)."\n\n", FILE_APPEND);
	}


	/**
		COMMON FUNCTIONS
	*/
	function geobb_activate_map($forum_desc, $first_post = true) {
		global $geo_keys; // Private / defined in config.php

		preg_match ('/\[(first|all)=([a-z]+)\]/i', html_entity_decode ($forum_desc), $regle);
		switch (@$regle[1]) {
			case 'first': // Régle sur le premier post seulement
				if (!$first_post)
					break;

			case 'all': // Régle sur tous les posts
				$ns = explode ('\\', __NAMESPACE__);
				$this->template->assign_vars([
					'META_ROBOTS' => META_ROBOTS,
					'EXT_DIR' => 'ext/'.$ns[0].'/'.$ns[1].'/', // Répertoire de l'extension
					'GEO_MAP_TYPE' => $regle[2],
					'GEO_KEYS' => json_encode($geo_keys),
//					'STYLE_NAME' => $this->user->style['style_name'],
				]);
		}
	}

	// Calcul des données automatiques
	function get_automatic_data(&$row) {
		if (!$row['geomwkt'])
			return;

		$update = []; // Datas to be updated

		// Calcul du centre pour toutes les actions
		include_once('assets/geoPHP/geoPHP.inc');
		$geophp = \geoPHP::load($row['geomwkt'],'wkt');
		$centre = $geophp->getCentroid()->coords;

		// Dans quel alpage est contenu (lors de la première init)
		if (array_key_exists ('geo_contains', $row) &&
			(!$row['geo_contains'] || $row['geo_contains'] == 'null')) {
			// Search points included in a surface
			$sql = "
				SELECT polygon.topic_id
				FROM	 ".POSTS_TABLE." AS polygon
					JOIN ".POSTS_TABLE." AS point ON (point.topic_id = {$row['topic_id']})
				WHERE
					".SQL_PRE."Contains (polygon.geom, point.geom)
					AND ".SQL_PRE."Dimension(polygon.geom) > 0
					LIMIT 1
				";
			$result = $this->db->sql_query($sql);
			if ($row_contain = $this->db->sql_fetchrow($result))
				$update['geo_contains'] = $row_contain['topic_id'];
			$this->db->sql_freeresult($result);
		}

		// Calcul de la surface en ha avec geoPHP
		if (array_key_exists ('geo_surface', $row) && !$row['geo_surface']) {
			$update['geo_surface'] =
				round ($geophp->getArea()
					* 1111 // hm par ° delta latitude
					* 1111 * sin ($centre[1] * M_PI / 180) // hm par ° delta longitude
				);
		}

		// Calcul de l'altitude avec mapquest
		if (array_key_exists ('geo_altitude', $row) && !$row['geo_altitude']) {
			global $geo_keys;
			$mapquest = file_get_contents (
				'http://open.mapquestapi.com/elevation/v1/profile'.
				'?key='.$geo_keys['mapquest'].
				'&callback=handleHelloWorldResponse'.
				'&shapeFormat=raw'.
				'&latLngCollection='.$centre[1].','.$centre[0]
			);
			if ($mapquest) {
				preg_match ('/"height":([0-9]+)/', $mapquest, $match);
				$update['geo_altitude'] = @$match[1];
			}
		}

		// Infos refuges.info
		if ((array_key_exists('geo_massif', $row) && !$row['geo_massif']) ||
			(array_key_exists('geo_reserve', $row) && !$row['geo_reserve']) ||
			(array_key_exists('geo_ign', $row) && !$row['geo_ign'])) {
			$update['geo_massif'] = null;
			$update['geo_reserve'] = null;
			$igns = [];
			$url = "http://www.refuges.info/api/polygones?type_polygon=1,3,12&bbox={$centre[0]},{$centre[1]},{$centre[0]},{$centre[1]}";
			$wri_export = @file_get_contents($url);
			if ($wri_export) {
				$fs = json_decode($wri_export)->features;
				foreach($fs AS $f)
					switch ($f->properties->type->type) {
						case 'massif':
							if (array_key_exists('geo_massif', $row))
								$update['geo_massif'] = $f->properties->nom;
							break;
						case 'zone réglementée':
							if (array_key_exists('geo_reserve', $row))
								$update['geo_reserve'] = $f->properties->nom;
							break;
						case 'carte':
							$ms = explode(' ', str_replace ('-', ' ', $f->properties->nom));
							$nom_carte = str_replace ('-', ' ', str_replace (' - ', ' : ', $f->properties->nom));
							$igns[] = "<a target=\"_BLANK\" href=\"https://ignrando.fr/boutique/catalogsearch/result/?q={$ms[1]}\">$nom_carte</a>";
							break;
					}
			}
			$update['geo_ign'] = implode ('<br/>', $igns);
		}

		// Calcul de la commune (France)
		if (array_key_exists ('geo_commune', $row) && !$row['geo_commune']) {
{/*//TODO-CHEM DELETE ???			$ch = curl_init ();
			curl_setopt ($ch, CURLOPT_URL,
				'http://wxs.ign.fr/d27mzh49fzoki1v3aorusg6y/geoportail/ols?'.
				http_build_query ( array(
					'output' => 'json',
					'xls' =>
<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<XLS
	xmlns:xls="http://www.opengis.net/xls"
	xmlns:gml="http://www.opengis.net/gml"
	xmlns="http://www.opengis.net/xls"
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.2"	xsi:schemaLocation="http://www.opengis.net/xls
	http://schemas.opengis.net/ols/1.2/olsAll.xsd">
	<RequestHeader/>
	<Request requestID="" version="1.2" methodName="LocationUtilityService" maximumResponses="10">
		<ReverseGeocodeRequest>
			<Position>
				<gml:Point>
					<gml:pos>{$centre[1]} {$centre[0]}</gml:pos>
				</gml:Point>
			</Position>
			<ReverseGeocodePreference>CadastralParcel</ReverseGeocodePreference>
		</ReverseGeocodeRequest>
	</Request>
</XLS>
XML
				))
			);
			ob_start();
			curl_exec($ch);
			$json = ob_get_clean();
			preg_match ('/Municipality\\\">([^<]+)/', $json, $commune);

			if ($commune[1]) {
				curl_setopt ($ch, CURLOPT_URL,
					'http://wxs.ign.fr/d27mzh49fzoki1v3aorusg6y/geoportail/ols?'.
					http_build_query ( array(
						'output' => 'json',
						'xls' =>
<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<xls:XLS version="1.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xls="http://www.opengis.net/xls" xmlns:gml="http://www.opengis.net/gml" xsi:schemaLocation="http://www.opengis.net/xls http://schemas.opengis.net/ols/1.2/olsAll.xsd">
    <xls:RequestHeader srsName="EPSG:4326" />
    <xls:Request maximumResponses="25" methodName="GeocodeRequest" requestID="282b6805-48af-4e8f-83dc-3c55b2d311b0" version="1.2">
        <xls:GeocodeRequest returnFreeForm="false">
            <xls:Address countryCode="StreetAddress">
		<xls:freeFormAddress>{$commune[1]}</xls:freeFormAddress>
            </xls:Address>
        </xls:GeocodeRequest>
    </xls:Request>
</xls:XLS>
XML
					))
				);
				ob_start();
				curl_exec($ch);
				$json = ob_get_clean();
				preg_match ('/PostalCode>([^<]+)/', $json, $postalcode);

				if ($postalcode[1])
					$update['geo_commune'] = $postalcode[1].' '.$commune[1];
			}
			if (!$update['geo_commune']) {*/
				$nominatim = json_decode (@file_get_contents (
					"https://nominatim.openstreetmap.org/reverse?format=json&lon={$centre[0]}&lat={$centre[1]}",
					false,
					stream_context_create (array ('http' => array('header' => "User-Agent: StevesCleverAddressScript 3.7.6\r\n")))
				));
				$update['geo_commune'] = $nominatim->address->postcode.' '.(
					$nominatim->address->town ?:
					$nominatim->address->city ?:
					$nominatim->address->suburb  ?:
					$nominatim->address->village ?:
					$nominatim->address->hamlet ?:
					$nominatim->address->neighbourhood ?:
					$nominatim->address->quarter 
				);
			}
		}

		// Update de la base
		foreach ($update AS $k=>$v)
			if (array_key_exists($k, $row))
				$update[$k] .= '~';
			else
				unset ($update[$k]);

if(defined('TRACES_DOM'))/*DCMM*/echo"<pre style='background-color:white;color:black;font-size:14px;'>AUTOMATIC DATA = ".var_export($update,true).'</pre>';
		// Pour affichage
		$row = array_merge ($row, $update);

		if ($update)
			$this->db->sql_query (
				'UPDATE '.POSTS_TABLE.
				' SET '.$this->db->sql_build_array('UPDATE',$update).
				' WHERE post_id = '.$row['post_id']
		);
	}

	// Form management
	function topic_fields ($block_name, $post_data, $forum_desc, $forum_name) {
		// Get form fields from the relative post
		preg_match ('/\[fiche=([^\]]+)\]/i', $forum_desc, $match); // Try in forum_desc [fiche=Alpages][/fiche]
		$sql = "
			SELECT post_text FROM ".POSTS_TABLE."
			WHERE post_subject = '".str_replace("'","\'",$match[1] ?: $forum_name)."'
			ORDER BY post_id
		";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) // No form then !
			return;

		$def_forms = explode ("\n", $row['post_text']);
		foreach ($def_forms AS $kdf=>$df) {
			$dfs = explode ('|', preg_replace ('/[[:cntrl:]]|<[^>]+>/', '', $df.'|||'));
			$vars = $attaches = [];

			// Default tags
			$vars['TAG1'] = $sql_id = 'p';
			$sql_id = 'geo_'.$dfs[0];
			$vars['INNER'] = $dfs[1];
			$vars['TYPE'] = $dfs[2];
			$vars['SQL_TYPE'] = 'text';
			$vars['DISPLAY_VALUE'] =
			$vars['POST_VALUE'] =
				str_replace ('~', '', $post_data[$sql_id]);
			$options = explode (',', ','.$dfs[2]); // One empty at the beginning

			// {|1.1 Title
			// {|Text
			if ($dfs[0] == '{' || !$dfs[0]) {
				$dfs1s = explode (' ', $dfs[1]);

				// Title tag <h2>..<h4>
				preg_match_all ('/[0-9]+/', $dfs1s[0], $match);
				$vars['TAG1'] = 'h'.(count($match[0]) ? count($match[0]) + 1 : 4);

				// Block visibility
				$ndf = implode (' geo_', array_slice ($def_forms, $kdf)); // Find the block beginning
				$c = $n = 1;
				$ndfl = strlen ($ndf);
				while ($n && $c < $ndfl) // Find the block end
					switch ($ndf[$c++]) {
						case '{': $n++; break;
						case '}': $n--;
					}
				// Check if any value there
				preg_match_all ('/(geo_[a-z_0-9]+)\|[^\|]+\|([a-z]+)/', substr ($ndf, 0, $c), $match);
				foreach ($match[1] AS $k=>$m)
					if ($post_data[$m] &&
						($match[2][$k] != 'confidentiel' || $this->user->data['is_registered']))
						$vars['DISPLAY'] = true; // Decide to display the title
			}

			// End of block(s)
			elseif ($dfs[0][0] == '}')
				;

			// sql_id incorrect
			else if ($dfs[0] && !preg_match ('/^[a-z_0-8]+$/', $dfs[0])) {
				$vars['TAG1'] = 'p style="color:red"';
				$vars['INNER'] = 'Identifiant incorrect : "'.$dfs[0].'"';
			}
			elseif ($dfs[0]) {
				$options = explode (',', ','.$dfs[2]); // With a first line empty

				// sql_id|titre|choix,choix
				if (count($options) > 2) {
					$length = 0;
					foreach ($options AS $o)
						$length = max ($lengt, strlen ($o) + 1);
					$vars['TAG'] = 'select';
					$vars['SQL_TYPE'] = 'varchar-'.$length;
				}

				// sql_id|titre|proches
				elseif (!strcasecmp ($dfs[2], 'proches')) {
					if ($post_data['post_id']) {
						$vars['TAG'] = 'select';

						// Search surfaces closest to a point
						preg_match_all ('/([0-9\.]+)/', $post_data['geomwkt'], $point);
						$km = 3;
						$bbox = ($point[0][0]-.0127*$km).' '.($point[0][1]-.009*$km).",".($point[0][0]+.0127*$km).' '.($point[0][1]+.009*$km);
						$sql = "
							SELECT post_subject, topic_id, ".SQL_PRE."AsText(".SQL_PRE."Centroid(geom)) AS centre
							FROM ".POSTS_TABLE."
							WHERE ".SQL_PRE."Dimension(geom) > 0 AND
								MBRIntersects(geom, ".SQL_PRE."GeomFromText('LineString($bbox)'))
							";
						$result = $this->db->sql_query($sql);
						$options = ['d0' => []]; // First line empty
						while ($row = $this->db->sql_fetchrow($result)) {
							preg_match_all ('/([0-9\.]+)/', $row['centre'], $centre);
							$dist2 = 1 + pow ($centre[0][0] - $point[0][0], 2) + pow ($centre[0][1] - $point[0][1], 2) * 2;
							$options['d'.$dist2] = $row;
							if ($row['topic_id'] == $vars['POST_VALUE']) {
								$vars['POST_VALUE'] = // For posting.pgp initial select
								$vars['DISPLAY_VALUE'] = // For viewtopic.php display
									$row['post_subject'];
								$vars['HREF'] = 'viewtopic.php?t='.$row['topic_id'];
							}
						}
						ksort ($options);
						$this->db->sql_freeresult($result);
					} else
						$vars['STYLE'] = 'display:none'; // Hide at creation
				}

				// sql_id|titre|attaches
				//TODO-BEST-ASPIR faire effacer le bloc {} quand il n'y a pas d'attaches
				elseif (!strcasecmp ($dfs[2], 'attaches')) {
					$vars['TAG'] = 'input';
					$vars['TYPE'] = 'hidden';
					$vars['INNER'] = $dfs[1];
					$vars['DISPLAY_VALUE'] = ' ';

					if (array_key_exists ($sql_id, $post_data)) {
						$sql = "
							SELECT *
							FROM ".POSTS_TABLE."
								JOIN ".FORUMS_TABLE." USING (forum_id)
							WHERE forum_image LIKE '%{$dfs[3]}.png' AND
								($sql_id = '{$post_data['topic_id']}' OR
								 $sql_id = '{$post_data['topic_id']}~')
							";
						$result = $this->db->sql_query($sql);
						while ($row = $this->db->sql_fetchrow($result))
							$attaches[] = $row;

						$this->db->sql_freeresult($result);
						if (!count ($attaches))
							$vars['ATT_STYLE_TAG1'] = ' style="display:none"';
					}
				}

				// sql_id|titre|automatique
				elseif (!strcasecmp ($dfs[2], 'automatique')) {
					$vars['TAG'] = 'input';
					$vars['STYLE'] = 'display:none'; // Hide at posting
					$vars['TYPE'] = 'hidden';
					$vars['POSTAMBULE'] = $dfs[3];
					$vars['POST_VALUE'] = null; // Set the value to null to ask for recalculation
				}

				// sql_id|titre|0
				elseif (is_numeric ($dfs[2])) {
					$vars['TAG'] = 'input';
					$vars['TYPE'] = 'number';
					$vars['SQL_TYPE'] = 'int-5';
					$vars['POSTAMBULE'] = $dfs[3];
				}

				// sql_id|titre|date
				elseif (!strcasecmp ($dfs[2], 'date')) {
					$vars['TAG'] = 'input';
					$vars['TYPE'] = 'date';
					$vars['SQL_TYPE'] = 'date';
				}

				// sql_id|titre|long|invite
				elseif (!strcasecmp ($dfs[2], 'long')) {
					$vars['TAG'] = 'textarea';
					$vars['PLACEHOLDER'] = str_replace('"', "''", $dfs[3]);
				}

				// sql_id|titre|confidentiel|invite
				// sql_id|titre|court|invite
				else {
					$vars['TAG'] = 'input';
					$vars['SIZE'] = '40';
					$vars['CLASS'] = 'inputbox autowidth';
					$vars['PLACEHOLDER'] = str_replace('"', "''", $dfs[3]);
					if ($dfs[2] == 'confidentiel' && !$this->user->data['is_registered'])
						$vars['DISPLAY_VALUE'] = null;
				}
			} //TODO-ARCHI DELETE pourquoi as-ton besoin du test précédent ?
//else/*DCMM*/echo"<pre style='background-color:white;color:black;font-size:14px;'> = ".var_export($_COOKIE,true).'</pre>';

			$vars['NAME'] = $sql_id.'-'.$vars['SQL_TYPE'];

			$vs = $vars;
			foreach ($vs AS $k=>$v)
				if ($v)
					$vars['ATT_'.$k] = ' '.strtolower($k).'="'.str_replace('"','\\\"', $v).'"';

			$this->template->assign_block_vars($block_name, $vars);

			if (count($options) &&
				count (explode ('.', $block_name)) == 1) {
				foreach ($options AS $v)
					$this->template->assign_block_vars($block_name.'.options', [
						'OPTION' => gettype($v) == 'string' ? $v : $v['post_subject'],
						'VALUE' => gettype($v) == 'string' ? $v : $v['topic_id'],
					]);

				foreach ($attaches AS $v) {
					$this->template->assign_block_vars($block_name.'.attaches', array_change_key_case ($v, CASE_UPPER));
					if (count (explode ('.', $block_name)) == 1)
						$this->topic_fields ($block_name.'.attaches.detail', $v, null, $v['forum_name']);
				}
			}
		}
	}

	/*//TODO-BEST geophp simplify : https://github.com/phayes/geoPHP/issues/24
    $oGeometry = geoPHP::load($skt,'wkt');
    $reducedGeom = $oGeometry->simplify(1.5);
    $skt = $reducedGeom->out('wkt');Erradiquer geoPHP ? si SQL >= version 5.7 (inclue JSON) -> Phpbb 3.2
	*/
	function optim (&$g, $granularity) { // Fonction récursive d'optimisation d'un objet PHP contenant des objets géographiques
if(defined('TRACES_DOM'))/*DCMM*/echo"<pre style='background-color:white;color:black;font-size:14px;'> = ".var_export('optim',true).'</pre>';
/*
		if (isset ($g->geometries)) // On recurse sur les Collection, ...
			foreach ($g->geometries AS &$gs)
				$this->optim ($gs, $granularity);

		if (isset ($g->features)) // On recurse sur les Feature, ...
			foreach ($g->features AS &$fs)
				$this->optim ($fs, $granularity);

		if (preg_match ('/multi/i', $g->type)) {
			foreach ($g->coordinates AS &$gs)
				$this->optim_coordinate_array ($gs, $granularity);
		} elseif (isset ($g->coordinates)) // On a trouvé une liste de coordonnées à optimiser
			$this->optim_coordinate_array ($g->coordinates, $granularity);
*/
	}
	function optim_coordinate_array (&$cs, $granularity) { // Fonction d'optimisation d'un tableau de coordonnées
if(defined('TRACES_DOM'))/*DCMM*/echo"<pre style='background-color:white;color:black;font-size:14px;'> = ".var_export('optim_coordinate_array',true).'</pre>';
/*
		if (count ($cs) > 2) { // Pour éviter les "Points" et "Poly" à 2 points
			$p = $cs[0]; // On positionne le point de référence de mesure de distance à une extrémité
			$r = []; // La liste de coordonnées optimisées
			foreach ($cs AS $k=>$v)
				if (!$k || // On garde la première extrémité
					$k == count ($cs) - 1) // Et la dernière
					$r[] = $v;
				elseif (hypot ($v[0] - $p[0], $v[1] - $p[1]) > $granularity)
					$r[] = // On copie ce point
					$p = // On repositionne le point de référence
						$v;
			$cs = $r; // On écrase l'ancienne
		}
*/
	}

	// Calcul de la bbox englobante
	function get_bounds($g) {
		$b = $g->getBBox();
		$m = 0.005; // Marge autour d'un point simple (en °)
		foreach (['x','y'] AS $xy) {
			if ($b['min'.$xy] == $b['max'.$xy]) {
				$b['min'.$xy] -= $m;
				$b['max'.$xy] += $m;
			}
			foreach (['max','min'] AS $mm)
				$this->bbox['geo_bbox_'.$mm.$xy] =
					isset ($this->bbox['geo_bbox_'.$mm.$xy])
					? $mm ($this->bbox['geo_bbox_'.$mm.$xy], $b[$mm.$xy])
					: $b[$mm.$xy];
		}
		$this->template->assign_vars (array_change_key_case ($this->bbox, CASE_UPPER));
	}

}
