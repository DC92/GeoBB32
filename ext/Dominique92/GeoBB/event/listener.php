<?php
/**
 *
 * @package GeoBB
 * @copyright (c) 2016 Dominique Cavailhez
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */
//TODO ASPIR ajouter champs enregistrement : ucp_register.html
//TODO revoir nommage fiches
//TODO permutations POSTS dans le template modération
//TODO Voir nommage GeoBB ou GeoBB32 !
//TODO ne pas afficher les points en doublon (flux wri, prc, c2c)
//TODO ASPIR modifier le mail de bienvenue à la connexion
//TODO-ASPIR ??? recherche par département / commune
//TODO mettre dans modération : déplacer les fichiers la permutation des posts => event/mcp_topic_postrow_post_before.html

namespace Dominique92\GeoBB\event;

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
		\phpbb\auth\auth $auth
	) {
		$this->db = $db;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->auth = $auth;
	}

	// Liste des hooks et des fonctions associées
	// On trouve le point d'appel en cherchant dans le logiciel de PhpBB 3.x: "event core.<XXX>"
	static public function getSubscribedEvents() {
		return [
			// All
			'core.user_setup' => 'user_setup',
			'core.page_footer' => 'page_footer',

			// Index
			'core.display_forums_modify_row' => 'display_forums_modify_row',
			'core.index_modify_page_title' => 'index_modify_page_title',

			// Viewtopic
			'core.viewtopic_get_post_data' => 'viewtopic_get_post_data',
			'core.viewtopic_modify_post_data' => 'viewtopic_modify_post_data',
			'core.viewtopic_post_row_after' => 'viewtopic_post_row_after',
			'core.viewtopic_post_rowset_data' => 'viewtopic_post_rowset_data',
			'core.viewtopic_modify_post_row' => 'viewtopic_modify_post_row',

			// Posting
			'core.modify_posting_auth' => 'modify_posting_auth',
			'core.modify_posting_parameters' => 'modify_posting_parameters',
			'core.posting_modify_submission_errors' => 'posting_modify_submission_errors',
			'core.submit_post_modify_sql_data' => 'submit_post_modify_sql_data',
			'core.posting_modify_template_vars' => 'posting_modify_template_vars',
			'core.modify_submit_notification_data' => 'modify_submit_notification_data',

			// Resize images
			'core.download_file_send_to_browser_before' => 'download_file_send_to_browser_before',
		];
	}


	/**
		ALL
	*/
	function user_setup($vars) {
		// For debug, Varnish will not be caching pages where you are setting a cookie
		if (defined('TRACES_DOM'))
			setcookie('disable-varnish', microtime(true), time()+600, '/');
	}
	/*
		return;//TODO DELETE ?
		// Force le style 
		$style_name = request_var ('style', '');
		if ($style_name) {
			$sql = 'SELECT * FROM '.STYLES_TABLE.' WHERE style_name = "'.$style_name.'"';
			$result = $this->db->sql_query ($sql);
			$row = $this->db->sql_fetchrow ($result);
			$this->db->sql_freeresult ($result);
			if ($row)
				$vars['style_id'] =  $row ['style_id'];
		}
	*/

	function page_footer() {
//		ob_start();var_dump($this->template);echo'template = '.ob_get_clean(); // VISUALISATION VARIABLES TEMPLATE

		// For debug, Varnish will not be caching pages where you are setting a cookie
		if (defined('TRACES_DOM'))
			setcookie('disable-varnish', microtime(true), time()+600, '/');

		// list of gis.php arguments for chemineur layer selector
		$sql = "
			SELECT category.forum_id AS category_id,
				category.forum_name AS category_name,
				forum.forum_id, forum.forum_name, forum.forum_desc
			FROM ".FORUMS_TABLE." AS forum
			JOIN ".FORUMS_TABLE." AS category ON category.forum_id = forum.parent_id
			WHERE forum.forum_desc LIKE '%[first=%'
			ORDER BY forum.left_id
		";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			$cats [$row['category_name']] [] = $row['forum_id'];
		$this->db->sql_freeresult($result);

		if($cats)
			foreach($cats AS $k=>$v)
				$this->template->assign_block_vars('chemcat', [
					'CAT' => $k,
					'ARGS' => implode (',', $v),
				]);

		// Inclue les fichiers langages de cette extension
		$ns = explode ('\\', __NAMESPACE__);
		$this->user->add_lang_ext($ns[0].'/'.$ns[1], 'common');

		// Assign post contents to some templates variables
		$mode = $this-> request->variable('mode', '');
		$msgs = [
			'Conditions d\'utilisation' => 'L_TERMS_OF_USE',
			'Politique de confidentialité' => 'L_PRIVACY_POLICY',
			'Bienvenue '.$this->user->style['style_name'] => 'GEO_PRESENTATION',
			'Aide' => 'GEO_URL_AIDE',
			$mode == 'terms' ? 'Conditions d\'utilisation' : 'Politique de confidentialité' => 'AGREEMENT_TEXT',
		];
		foreach ($msgs AS $k=>$v) {
			$sql = 'SELECT post_text, bbcode_uid, bbcode_bitfield FROM '.POSTS_TABLE.' WHERE post_subject = "'.$k.'" ORDER BY post_id';
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($row)
				$this->template->assign_var (
					$v,
					generate_text_for_display($row['post_text'],
					$row['bbcode_uid'],
					$row['bbcode_bitfield'],
					OPTION_FLAG_BBCODE, true)
				);
		}
	}


	/**
		INDEX.PHP
	*/
	// Add a button to create a topic in front of the list of forums
	function display_forums_modify_row ($vars) {
		$row = $vars['row'];

		if ($this->auth->acl_get('f_post', $row['forum_id']) &&
			$row['forum_type'] == FORUM_POST)
			$row['forum_name'] .= ' &nbsp; '.
				'<a class="button" href="./posting.php?mode=post&f='.$row['forum_id'].'" title="Créer un nouveau sujet '.strtolower($row['forum_name']).'">Créer</a>';
		$vars['row'] = $row;
	}

	function index_modify_page_title ($vars) {
		$this->geobb_activate_map('[all=accueil]');

		// Show the most recents posts on the home page
		$news = request_var ('news', 12); // More news count
		$this->template->assign_var ('PLUS_NOUVELLES', $news * 2);

		$sql = "
			SELECT p.post_id, p.post_attachment, p.post_time, p.poster_id,
				t.topic_id, topic_title,topic_first_post_id, t.topic_posts_approved,
				f.forum_id, f.forum_name, f.forum_image,
				u.username, p.post_edit_time,
				IF(post_edit_time > post_time, post_edit_time, post_time) AS post_or_edit_time
			FROM	 ".TOPICS_TABLE." AS t
				JOIN ".FORUMS_TABLE." AS f USING (forum_id)
				JOIN ".POSTS_TABLE." AS p ON (p.post_id = t.topic_last_post_id)
				JOIN ".USERS_TABLE."  AS u ON (p.poster_id = u.user_id)
			WHERE post_visibility = ".ITEM_APPROVED."
			ORDER BY post_or_edit_time DESC
			LIMIT $news
		";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			if ($this->auth->acl_get('f_read', $row['forum_id'])) {
				//TODO BUG compte les posts des forums cachés dans le nb max
				$row ['post_time'] = '<span title="'.$this->user->format_date ($row['post_time']).'">'.date ('j M', $row['post_time']).'</span>';
				$this->template->assign_block_vars('news', array_change_key_case ($row, CASE_UPPER));
			}
		$this->db->sql_freeresult($result);

	}

	// Docs presentation
	/*//TODO DELETE ??? (utilisé dans doc)
	function index_forum_tree($parent, $num) {
		$last_num = 1;
		$sql = "
			SELECT forum_id, forum_name, forum_type
			FROM ".FORUMS_TABLE."
			WHERE parent_id = $parent
			ORDER BY left_id ASC";
		$result_forum = $this->db->sql_query($sql);
		while ($row_forum = $this->db->sql_fetchrow($result_forum)) {
			$forum_num = $num.$last_num++.'.';
			$this->template->assign_block_vars('forum_tree', [
				'FORUM' => $row_forum['forum_type'],
				'LEVEL' => count (explode ('.', $forum_num)) - 1,
				'NUM' => $forum_num,
				'TITLE' => $row_forum['forum_name'],
				'FORUM_ID' => $row_forum['forum_id'],
				'AUTH' => $this->auth->acl_get('m_edit', $row_forum['forum_id']),
			]);
			if($row_forum['forum_type']) { // C'est un forum (pas une catégotie)
				$sql = "
					SELECT post_id, post_subject, post_text, post_attachment, bbcode_uid, bbcode_bitfield, topic_id
					FROM ".POSTS_TABLE." AS p
						JOIN ".TOPICS_TABLE." AS t USING (topic_id)
					WHERE p.post_id = t.topic_first_post_id AND
						p.forum_id = {$row_forum['forum_id']}
					ORDER BY post_subject";
				$result_post = $this->db->sql_query($sql);
				$sub_num = 1;
				while ($row_post = $this->db->sql_fetchrow($result_post)) {
					preg_match ('/^([0-9\.]+) (.*)$/', $row_post['post_subject'], $titles);
					$post_text = generate_text_for_display ($row_post['post_text'],
						$row_post['bbcode_uid'],
						$row_post['bbcode_bitfield'],
						OPTION_FLAG_BBCODE, true);

					$sql = "
						SELECT *
						FROM ".ATTACHMENTS_TABLE."
						WHERE post_msg_id = {$row_post['post_id']}
						ORDER BY filetime DESC";
					$result_attachement = $this->db->sql_query($sql);
					$attachments = [];
					while ($row_attachement = $this->db->sql_fetchrow($result_attachement))
						$attachments[] = $row_attachement;
					$this->db->sql_freeresult($result_attachement);
					$update_count = array();
					if (count($attachments))
						parse_attachments(0, $post_text, $attachments, $update_count);

					$sql = "SELECT COUNT(*) AS nb_posts FROM ".POSTS_TABLE." WHERE topic_id = {$row_post['topic_id']}";
					$result_count = $this->db->sql_query($sql);
					$row_count = $this->db->sql_fetchrow($result_count);
					$this->db->sql_freeresult($result_count);

					//$post_num = $forum_num.$sub_num++.'.';
					$this->template->assign_block_vars('forum_tree', [
						'LEVEL' => count (explode ('.', $forum_num)),
						'NUM' => $forum_num.$sub_num++.'.',
						'TITLE' => count ($titles) ? $titles[2] : $row_post['post_subject'],
						'TEXT' => $post_text,
						'FORUM_ID' => $row_forum['forum_id'],
						'TOPIC_ID' => $row_post['topic_id'],
						'POST_ID' => $row_post['post_id'],
						'NB_COMMENTS' => $row_count['nb_posts'] - 1,
						'AUTH' => $this->auth->acl_get('m_edit', $row_forum['forum_id']),
					]);
				}
				$this->db->sql_freeresult($result_post);
			}
			$this->index_forum_tree ($row_forum['forum_id'], $forum_num);
		}
		$this->db->sql_freeresult($result_forum);
	}*/

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
			$sql_ary['SELECT'] .=
				', ST_AsGeoJSON(geom) AS geojson'.
				', ST_AsText(ST_Centroid(ST_Envelope(geom))) AS centerwkt'.
				', ST_Area(geom) AS area';
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

	// Assign template variables for images attachments
	function viewtopic_modify_post_data($vars) {
		$this->attachments = $vars['attachments'];
	}
	function viewtopic_post_row_after($vars) {
		if (isset ($this->attachments[$vars['row']['post_id']]))
			foreach ($this->attachments[$vars['row']['post_id']] as $attachment)
				if (!strncmp ($attachment['mimetype'], 'image', 5)) {
					$attachment['DATE'] = str_replace (' 00:00', '', $this->user->format_date($attachment['filetime']));
					$attachment['TEXT_SIZE'] = strlen ($vars['row']['post_text']) * count($vars['attachments']);
					$attachment['POSTER'] = $attachment['poster_id']; //TODO rechercher via SQL le vrai nom de l'auteur
					$this->template->assign_block_vars('postrow.image', array_change_key_case ($attachment, CASE_UPPER));
				}
	}

	// Appelé lors de la deuxième passe sur les données des posts qui prépare dans $post_row les données à afficher sur le post du template
	function viewtopic_modify_post_row($vars) {
//TODO DELETE		$this->viewtopic_modify_post_row_2($vars);

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
	function modify_posting_auth($vars) {
		// Popule le sélecteur de forum
		preg_match ('/\[view=[a-z]+/i', $vars['post_data']['forum_desc'], $view);
		$sql = "SELECT forum_id, forum_name, parent_id, forum_type, forum_flags, forum_options, left_id, right_id, forum_desc
			FROM ".FORUMS_TABLE."
			WHERE forum_desc LIKE '%{$view[0]}%'
			ORDER BY left_id ASC";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
			$forum_list [] = '<option value="' . $row['forum_id'] . '"' .($row['forum_id'] == $vars['forum_id'] ? ' selected="selected"' : ''). '>' . $row['forum_name'] . '</option>';
		$this->db->sql_freeresult($result);
		if (isset ($forum_list))
			$this->template->assign_var ('S_FORUM_SELECT', implode ('', $forum_list));

		// Assigne le nouveau forum pour la création
		$vars['forum_id'] = request_var('to_forum_id', $vars['forum_id']);

		// Le bouge
		if ($vars['mode'] == 'edit' && // S'il existe déjà !
			$vars['forum_id'] != $vars['forum_id'])
			move_topics([$vars['post_id']], $vars['forum_id']);
	}

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

	// Called when display post page
	function posting_modify_template_vars($vars) {
		$page_data = $vars['page_data'];
		$post_data = $vars['post_data'];

		// Récupère la traduction des données spaciales SQL
		if (isset ($post_data['geom'])) {
			$sql = 'SELECT ST_AsGeoJSON(geom) AS geojson'.
				' FROM '.POSTS_TABLE.
				' WHERE post_id = '.$post_data['post_id'];
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			$page_data['GEOJSON'] = $post_data['geojson'] = $row['geojson'];
		}

		// Unhide geojson field
		$this->request->enable_super_globals();
		$page_data['GEOJSON_TYPE'] = $_GET['role'] == 'dev' ? 'text' : 'hidden';
		$this->request->disable_super_globals();

		// Create a log file with the existing data if there is none
		$this->save_post_data($post_data, $vars['message_parser']->attachment_data, $post_data, true);

		// To prevent an empty title invalidate the full page and input.
		if (!$post_data['post_subject'])
			$page_data['DRAFT_SUBJECT'] = $this->post_name ?: 'Nom';

		$page_data['EDIT_REASON'] = 'Modération'; // For display in news
		$page_data['TOPIC_ID'] = $post_data['topic_id'] ?: 0;
		$page_data['POST_ID'] = $post_data['post_id'] ?: 0;
		$page_data['TOPIC_FIRST_POST_ID'] = $post_data['topic_first_post_id'] ?: 0;

		// Assign the phpbb-posts SQL data to the template
		/*//TODO DELETE
		foreach ($post_data AS $k=>$v)
			if (!strncmp ($k, 'geo', 3)
				&& is_string ($v))
				$page_data[strtoupper ($k)] =
					strstr($v, '~') == '~' ? null : $v; // Clears fields ending with ~ for automatic recalculation
					*/

		$this->topic_fields('info', $post_data, $post_data['forum_desc'], $post_data['forum_name'], true);
		$this->geobb_activate_map($post_data['forum_desc'], $post_data['post_id'] == $post_data['topic_first_post_id']);

		// HORRIBLE phpbb hack to accept geom values //TODO-ARCHI : check if done by PhpBB (supposed 3.2)
		$file_name = "phpbb/db/driver/driver.php";
		$file_tag = "\n\t\tif (is_null(\$var))";
		$file_patch = "\n\t\tif (strpos(\$var, 'GeomFrom') !== false)\n\t\t\treturn \$var;";
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
				$ks = explode ('-', $k);

				// Create or modify the SQL column
				if (count ($ks) == 3)
					$ks[2] = '('.$ks[2].')';
				$this->db->sql_query(
					'ALTER TABLE '.POSTS_TABLE.
					(in_array ($ks[0], $special_columns) ? ' CHANGE '.$ks[0].' ' : ' ADD ').
					implode (' ', $ks)
				);

				// Retrieves the values of the geometry, includes them in the phpbb_posts table
				if ($ks[1] == 'geometry' && $v)
					$v = "ST_GeomFromGeoJSON('$v')";
					//TODO TEST est-ce qu'il optimise les linestring en multilinestring (ne devrait pas)

				// Retrieves the values of the questionnaire, includes them in the phpbb_posts table
				$sql_data[POSTS_TABLE]['sql'][$ks[0]] = utf8_normalize_nfc($v) ?: null; // null allows the deletion of the field
			}
		$this->request->disable_super_globals();

		$vars['sql_data'] = $sql_data; // return data
		$this->modifs = $sql_data[POSTS_TABLE]['sql']; // Save change
		$this->modifs['geojson'] = str_replace (['ST_GeomFromGeoJSON(\'','\')'], '', $this->modifs['geom']);
	}

	// Call after the post validation
	function modify_submit_notification_data($vars) {
		$this->save_post_data($vars['data_ary'], $vars['data_ary']['attachment_data'], $this->modifs);
	}

	// Save changes
	function save_post_data($post_data, $attachment_data, $geo_data, $create_if_null = false) {
		$this->request->enable_super_globals();
		$to_save = [
			$this->user->data['username'].' '.date('r').' '.$_SERVER['REMOTE_ADDR'],
			$_SERVER['REQUEST_URI'],
			'forum '.$post_data['forum_id'].' = '.$post_data['forum_name'],
			'topic '.$post_data['topic_id'].' = '.$post_data['topic_title'],
			'post_subject = '.$post_data['post_subject'],
			'post_text = '.$post_data['post_text'],
			'geojson = '.$geo_data['geojson'],
		];
		foreach ($geo_data AS $k=>$v)
			if ($v && !strncmp ($k, 'geo_', 4))
				$to_save [] = "$k = $v";

		// Save attachment_data
		$attach = [];
		if ($attachment_data)
			foreach ($attachment_data AS $att)
				$attach[] = $att['attach_id'].' : '.$att['real_filename'];
		if (isset ($attach))
			$to_save[] = 'attachments = '.implode (', ', $attach);

		$file_name = 'LOG/'.$post_data['post_id'].'.txt';
		if (!$create_if_null || !file_exists($file_name))
			file_put_contents ($file_name, implode ("\n", $to_save)."\n\n", FILE_APPEND);

		$this->request->disable_super_globals();
	}


	/**
		COMMON FUNCTIONS
	*/
	function geobb_activate_map($forum_desc, $first_post = true) {
		global $geo_keys; // Private / defined in config.php

		preg_match ('/\[(first|all)=([a-z]+)(\:|\])/i', html_entity_decode ($forum_desc), $regle);
		preg_match ('/\[view=([a-z]+)(\:|\])/i', html_entity_decode ($forum_desc), $view);
		switch (@$regle[1]) {
			case 'first': // Régle sur le premier post seulement
				if (!$first_post)
					break;

			case 'all': // Régle sur tous les posts
				$ns = explode ('\\', __NAMESPACE__);
				$this->template->assign_vars([
					'GEO_MAP_TYPE' => str_replace(
						['point','ligne','line','surface',],
						['Point','LineString','LineString','Polygon',],
						@$regle[2]),
					'GEO_KEYS' => json_encode($geo_keys),
				]);
				if ($geo_keys)
					$this->template->assign_vars (array_change_key_case ($geo_keys, CASE_UPPER));
				default:
				$this->template->assign_vars([
					'META_ROBOTS' => defined('META_ROBOTS') ? META_ROBOTS : '',
					'BODY_CLASS' => @$view[1],
					'EXT_DIR' => 'ext/'.$ns[0].'/'.$ns[1].'/', // Répertoire de l'extension
//TODO DELETE					'STYLE_NAME' => $this->user->style['style_name'],
				]);
		}
	}

	// Calcul des données automatiques
	//TODO Automatiser : Année où la fiche de l'alpage a été renseignée ou actualisée
	function get_automatic_data(&$row) {
		if (!$row['geojson'])
			return;

		// Calculation of the center for all actions
		preg_match_all ('/([0-9\.]+)/', $row['centerwkt'], $center);
		$row['center'] = $center[0];

		$update = []; // Datas to be updated

		// Dans quel alpage est contenu (lors de la première init)
		if (array_key_exists ('geo_contains', $row) &&
			(!$row['geo_contains'] || $row['geo_contains'] == 'null')) {
			// Search points included in a surface
			$sql = "
				SELECT polygon.topic_id
				FROM	 ".POSTS_TABLE." AS polygon
					JOIN ".POSTS_TABLE." AS point ON (point.topic_id = {$row['topic_id']})
				WHERE
					ST_Contains (polygon.geom, point.geom)
					AND ST_Dimension(polygon.geom) > 0
					LIMIT 1
				";
			$result = $this->db->sql_query($sql);
			if ($row_contain = $this->db->sql_fetchrow($result))
				$update['geo_contains'] = $row_contain['topic_id'];
			$this->db->sql_freeresult($result);
		}

		// Calcul de la surface en ha
		if (array_key_exists ('geo_surface', $row) &&
			!$row['geo_surface'] &&
			$row['area'] && $row['center']
		) {
			$update['geo_surface'] =
				round ($row['area']
					* 1111 // hm par ° delta latitude
					* 1111 * sin ($row['center'][1] * M_PI / 180) // hm par ° delta longitude
				);
		}

		// Calcul de l'altitude avec IGN
		if (array_key_exists ('geo_altitude', $row) &&
			!$row['geo_altitude'] &&
			$row['center']
		) {
			global $geo_keys;
			$api = "http://wxs.ign.fr/{$geo_keys['IGN']}/alti/rest/elevation.json?lon={$row['center'][0]}&lat={$row['center'][1]}&zonly=true";
			preg_match ('/([0-9]+)/', @file_get_contents($api), $altitude);
			if ($altitude)
				$update['geo_altitude'] = $altitude[1];
		}

		// Infos refuges.info
		if ((array_key_exists('geo_massif', $row) && !$row['geo_massif'] && $row['center']) ||
			(array_key_exists('geo_reserve', $row) && !$row['geo_reserve'] && $row['center']) ||
			(array_key_exists('geo_ign', $row) && !$row['geo_ign'] && $row['center'])) {
			$update['geo_massif'] = null;
			$update['geo_reserve'] = null;
			$igns = [];
			$url = "http://www.refuges.info/api/polygones?type_polygon=1,3,12&bbox={$row['center'][0]},{$row['center'][1]},{$row['center'][0]},{$row['center'][1]}";
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
					<gml:pos>{$row['center'][1]} {$row['center'][0]}</gml:pos>
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
					"https://nominatim.openstreetmap.org/reverse?format=json&lon={$row['center'][0]}&lat={$row['center'][1]}",
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
			if (!$row[$k] &&
				array_key_exists($k, $row))
				$update[$k] .= '~';
			else
				unset ($update[$k]);
		// Pour affichage
		$row = array_merge ($row, $update);

		if ($update)
			$this->db->sql_query (
				'UPDATE '.POSTS_TABLE.
				' SET '.$this->db->sql_build_array('UPDATE',$update).
				' WHERE post_id = '.$row['post_id']
		);

		if(defined('TRACES_DOM') && count($update))
			echo"<pre style='background-color:white;color:black;font-size:14px;'>AUTOMATIC DATA = ".var_export($update,true).'</pre>';
	}

	// Form management
	function topic_fields ($block_name, $post_data, $forum_desc, $forum_name, $posting = false) {
		// Get form fields from the relative post
		preg_match ('/\[form=([^\]]+)(\:|\])/i', $forum_desc, $form); // Try in forum_desc = [form=Post title][/form]
		$sql = "
			SELECT post_text FROM ".POSTS_TABLE."
			WHERE post_subject = '".str_replace ("'", "\'", $form ? $form[1] : $forum_name)."'
			ORDER BY post_id
		";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) // No form then !
			return;

		$def_forms = explode ("\n", $row['post_text']);
		foreach ($def_forms AS $kdf=>$df) {
			$dfs = explode ('|', preg_replace ('/[[:cntrl:]]|<[^>]+>/', '', $df.'|||||'));
			$block_vars = $attaches = [];
			$sql_id = 'geo_'.$dfs[0];

			// Clears fields ending with ~ for automatic recalculation
			if($posting &&
				strstr($post_data[$sql_id], '~') == '~')
				$post_data[$sql_id] = '';
			else
				$post_data[$sql_id] = str_replace ('~', '', $post_data[$sql_id]);

			// Default tags
			$block_vars['TAG1'] = 'p';
			$block_vars['INNER'] = $dfs[1];
			$block_vars['TYPE'] = $dfs[2];
			$block_vars['SQL_TYPE'] = 'text';
			$block_vars['DISPLAY_VALUE'] =
			$block_vars['VALUE'] = $post_data[$sql_id];
			$options = explode (',', ','.$dfs[2]); // One empty at the beginning
			$block_vars['PLACEHOLDER'] = str_replace('"', "''", $dfs[3]);
			$block_vars['POSTAMBULE'] = $dfs[4] .($posting ? ' '.$dfs[5] : '');

			// {|1.1 Title
			// {|Text
			if ($dfs[0] == '{' || !$dfs[0]) {
				$dfs1s = explode (' ', $dfs[1]);

				// Title tag <h2>..<h4>
				preg_match_all ('/[0-9]+/', $dfs1s[0], $title);
				$block_vars['TAG1'] = 'h'.(count($title[0]) ? count($title[0]) + 1 : 4);

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
				preg_match_all ('/(geo_[a-z_0-9]+)\|[^\|]+\|([a-z]+)/', substr ($ndf, 0, $c), $name);
				foreach ($name[1] AS $k=>$m)
					if (isset ($post_data[$m]) &&
						($name[2][$k] != 'confidentiel' || $this->user->data['is_registered']))
						$block_vars['DISPLAY'] = true; // Decide to display the title
			}

			// End of block(s)
			elseif ($dfs[0][0] == '}')
				;

			// sql_id incorrect
			else if ($dfs[0] && !preg_match ('/^[a-z_0-8]+$/', $dfs[0])) {
				$block_vars['TAG1'] = 'p style="color:red"';
				$block_vars['INNER'] = 'Identifiant incorrect : "'.$dfs[0].'"';
			}
			elseif ($dfs[0]) {
				$options = explode (',', ','.$dfs[2]); // With a first line empty

				// sql_id|titre|choix,choix|invite|postambule|commentaire saisie
				if (count($options) > 2) {
					$length = 0;
					foreach ($options AS $o)
						$length = max ($length, strlen ($o) + 1);
					$block_vars['TAG'] = 'select';
					$block_vars['SQL_TYPE'] = 'text';
					$block_vars['SQL_TYPE'] = 'varchar-'.$length;
				}

				// sql_id|titre|proches||postambule|commentaire saisie
				elseif (!strcasecmp ($dfs[2], 'proches')) {
					if ($post_data['post_id']) {
						$block_vars['TAG'] = 'select';

						// Search surfaces closest to a point
						preg_match_all ('/([0-9\.]+)/', $post_data['geojson'], $point);
						if(count($point[0])) {
							$km = 3; // Search maximum distance
							$bbox = ($point[0][0]-.0127*$km).' '.($point[0][1]-.009*$km).",".($point[0][0]+.0127*$km).' '.($point[0][1]+.009*$km);
							$sql = "
								SELECT post_subject, topic_id, ST_AsText(ST_Centroid(ST_Envelope(geom))) AS center
								FROM ".POSTS_TABLE."
								WHERE ST_Dimension(geom) > 0 AND
									MBRIntersects(geom, ST_GeomFromText('LINESTRING($bbox)',4326))
								";
							$result = $this->db->sql_query($sql);
							$options = ['d0' => []]; // First line empty
							while ($row = $this->db->sql_fetchrow($result)) {
								preg_match_all ('/([0-9\.]+)/', $row['center'], $row['center']);
								$dist2 = 1 + pow ($row['center'][0][0] - $point[0][0], 2) + pow ($row['center'][0][1] - $point[0][1], 2) * 2;
								$options['d'.$dist2] = $row;
								if ($row['topic_id'] == $block_vars['VALUE']) {
									$block_vars['VALUE'] = // For posting.pgp initial select
									$block_vars['DISPLAY_VALUE'] = // For viewtopic.php display
										$row['post_subject'];
									$block_vars['HREF'] = 'viewtopic.php?t='.$row['topic_id'];
								}
							}
							ksort ($options); //TODO BEST trier en fonction du bord le plus prés / pas du center
							$this->db->sql_freeresult($result);
						} else
							$block_vars['STYLE'] = 'display:none'; // Hide at posting
					} else
						$block_vars['STYLE'] = 'display:none'; // Hide at posting
				}

				// sql_id|titre|attaches||postambule|commentaire saisie
				//TODO-BEST-ASPIR faire effacer le bloc {} quand il n'y a pas d'attaches
				elseif (!strcasecmp ($dfs[2], 'attaches')) {
					$block_vars['TAG'] = 'input';
					$block_vars['TYPE'] = 'hidden';

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
							$block_vars['ATT_STYLE_TAG1'] = ' style="display:none"';
					}
				}

				// sql_id|titre|automatique||postambule
				elseif (!strcasecmp ($dfs[2], 'automatique')) {
					$block_vars['TAG'] = 'input';
					$block_vars['STYLE'] = 'display:none'; // Hide at posting
					$block_vars['TYPE'] = 'hidden';
					$block_vars['VALUE'] = null; // Set the value to null to ask for recalculation
				}

				// sql_id|titre|0||postambule|commentaire saisie
				elseif (is_numeric ($dfs[2])) {
					$block_vars['TAG'] = 'input';
					$block_vars['TYPE'] = 'number';
					$block_vars['SQL_TYPE'] = 'int-5';
				}

				// sql_id|titre|date||postambule|commentaire saisie
				elseif (!strcasecmp ($dfs[2], 'date')) {
					$block_vars['TAG'] = 'input';
					$block_vars['TYPE'] = 'date';
					$block_vars['SQL_TYPE'] = 'date';
				}

				// sql_id|titre|long|invite|invite|postambule|commentaire saisie
				elseif (!strcasecmp ($dfs[2], 'long')) {
					$block_vars['TAG'] = 'textarea';
				}

				// sql_id|titre|confidentiel|invite|invite|postambule|commentaire saisie
				// sql_id|titre|court|invite|invite|postambule|commentaire saisie
				else {
					$block_vars['TAG'] = 'input';
					$block_vars['SIZE'] = '40';
					$block_vars['CLASS'] = 'inputbox autowidth';
					if ($dfs[2] == 'confidentiel' && !$this->user->data['is_registered'])
						$block_vars['DISPLAY_VALUE'] = null;
				}
			} //TODO-ARCHI DELETE pourquoi as-t-on besoin du test précédent ?

			$block_vars['NAME'] = $sql_id.'-'.$block_vars['SQL_TYPE'];

			$vs = $block_vars;
			foreach ($vs AS $k=>$v)
				if ($v)
					$block_vars['ATT_'.$k] = ' '.strtolower($k).'="'.str_replace('"','\\\"', $v).'"';

			$this->template->assign_block_vars($block_name, $block_vars);

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


	/**
		RESIZE IMAGES
	*/
	// Insère des miniatures des liens.jpg insérés dans les messages
	/*//TODO DELETE ??
	function viewtopic_modify_post_row_2($vars) {
		global $db;
		$post_row = $vars['post_row'];
		preg_match_all('/href="(http[^"]*\.(jpe?g|png))"[^>]*>([^<]*\.(jpe?g|png))<\/a>/i', $post_row['MESSAGE'], $imgs); // Récupère les urls d'images

		foreach ($imgs[1] AS $k=>$href) {
			$sql_rch = "SELECT * FROM ".ATTACHMENTS_TABLE." WHERE real_filename = '".addslashes($href)."'";
			$result = $this->db->sql_query_limit($sql_rch, 1);
			$r = $this->db->sql_fetchrow($result);
			if(!$r) { // L'image n'est pas dans la base
				$sql_ary = array(
					'physical_filename'	=> $href,
					'attach_comment'	=> $href,
					'real_filename'		=> $href,
					'extension'			=> 'jpg',
					'mimetype'			=> 'image/jpeg',
					'filesize'			=> 0,
					'filetime'			=> time(),
					'thumbnail'			=> 0,
					'is_orphan'			=> 0,
					'in_message'		=> 0,
					'post_msg_id'		=> $vars['row']['post_id'],
					'topic_id'			=> $vars['row']['topic_id'],
					'poster_id'			=> $vars['poster_id'],
				);
				$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
				$result = $this->db->sql_query_limit($sql_rch, 1);
				$r = $this->db->sql_fetchrow($result);
			}

			$post_row['MESSAGE'] = str_replace (
				$href.'">'.$imgs[3][$k].'<',
				$href.'"><img title="'.$href.'" alt="'.$href.'" style="border:5px solid #F3E358" src="download/file.php?id='.$r['attach_id'].'&s=200&'.time().'"><',
				$post_row['MESSAGE']
			);
		}
		$vars['post_row'] = $post_row;
	}*/

	function download_file_send_to_browser_before($vars) {
		$attachment = $vars['attachment'];
		if (!is_dir ('../cache/geobb/'))
			mkdir ('../cache/geobb/');

		// Images externes
		$purl = parse_url ($attachment ['real_filename']);
		if (isset ($purl['host'])) { // le fichier est distant
			$local = '../cache/geobb/'.str_replace ('/', '-', $purl['path']);
			if (!file_exists ($local) || !filesize ($local)) {
				// Recuperation du contenu
				$url_cache = file_get_contents ($attachment['real_filename']);

				if (ord ($url_cache) == 0xFF) // Si c'est une image jpeg
					file_put_contents ($local, $url_cache); // Ecrit le fichier
				else { // Message d'erreur sinon
					$nbcligne = 40;
					$cs = [];
					if (!$url_cache)
						$err_msg = $user->lang('FILE_GET_CONTENTS_ERROR', $attachment['real_filename']);
					foreach (explode ("\n", strip_tags ($err_msg)) AS $v)
						if ($v)
							$cs = array_merge ($cs, str_split (strip_tags ($v), $nbcligne));
					$im = imagecreate  ($nbcligne * 7 + 10, 12 * count ($cs) + 8);
					ImageColorAllocate ($im, 0, 0, 200);
					foreach ($cs AS $k => $v)
						ImageString ($im, 3, 5, 3 + 12 * $k, $v, ImageColorAllocate ($im, 255, 255, 255)); 
					imagejpeg ($im, $local);
					ImageDestroy ($im); 
				}
			}
			$attachment ['physical_filename'] = $local;
		}
		else if (is_file('../'.$attachment['real_filename'])) // Fichier relatif à la racine du site
			$attachment ['physical_filename'] = '../'.$attachment ['real_filename']; // script = download/file.php

		if ($exif = @exif_read_data ('../files/'.$attachment['physical_filename'])) {
			$fls = explode ('/', $exif ['FocalLength']);
			if (count ($fls) == 2)
				$info[] = round($fls[0]/$fls[1]).'mm';

			$aps = explode ('/', $exif ['FNumber']);
			if (count ($aps) == 2)
				$info[] = 'f/'.round($aps[0]/$aps[1], 1).'';

			$exs = explode ('/', $exif ['ExposureTime']);
			if (count ($exs) == 2)
				$info[] = '1/'.round($exs[1]/$exs[0]).'s';

			if ($exif['ISOSpeedRatings'])
				$info[] = $exif['ISOSpeedRatings'].'ASA';

			if ($exif ['Model']) {
				if ($exif ['Make'] &&
					strpos ($exif ['Model'], $exif ['Make']) === false)
					$info[] = $exif ['Make'];
				$info[] = $exif ['Model'];
			}

			$this->db->sql_query (implode (' ', [
				'UPDATE '.ATTACHMENTS_TABLE,
				'SET exif = "'.implode (' ', $info ?: ['~']).'",',
					'filetime = '.(strtotime($exif['DateTimeOriginal']) ?: $exif['FileDateTime'] ?: $attachment['filetime']),
				'WHERE attach_id = '.$attachment['attach_id']
			]));
		}

		// Reduction de la taille de l'image
		if ($max_size = request_var('size', 0)) {
			$img_size = @getimagesize ('../files/'.$attachment['physical_filename']);
			$isx = $img_size [0]; $isy = $img_size [1]; 
			$reduction = max ($isx / $max_size, $isy / $max_size);
			if ($reduction > 1) { // Il faut reduire l'image
				$pn = pathinfo ($attachment['physical_filename']);
				$temporaire = '../cache/geobb/'.$pn['basename'].'.'.$max_size.$pn['extension'];

				// Si le fichier temporaire n'existe pas, il faut le creer
				if (!is_file ($temporaire)) {
					$mimetype = explode('/',$attachment['mimetype']);

					// Get source image
					$imgcreate = 'imagecreatefrom'.$mimetype[1]; // imagecreatefromjpeg / imagecreatefrompng / imagecreatefromgif
					$image_src = $imgcreate ('../files/'.$attachment['physical_filename']);

					// Detect orientation
					$angle = [
						3 => 180,
						6 => -90,
						8 =>  90,
					];
					$a = $angle [$exif ['Orientation']];
					if ($a)
						$image_src = imagerotate ($image_src, $a, 0);
					if (abs ($a) == 90) {
						$tmp = $isx;
						$isx = $isy;
						$isy = $tmp;
					}

					// Build destination image
					$image_dest = imagecreatetruecolor ($isx / $reduction, $isy / $reduction); 
					imagecopyresampled ($image_dest, $image_src, 0,0, 0,0, $isx / $reduction, $isy / $reduction, $isx, $isy);

					// Convert image
					$imgconv = 'image'.$mimetype[1]; // imagejpeg / imagepng / imagegif
					$imgconv ($image_dest, $temporaire); 

					// Cleanup
					imagedestroy ($image_dest); 
					imagedestroy ($image_src);
				}
				$attachment['physical_filename'] = $temporaire;
			}
		}

		$vars['attachment'] = $attachment;
	}

}
