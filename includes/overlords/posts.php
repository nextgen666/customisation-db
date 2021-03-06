<?php
/**
*
* This file is part of the phpBB Customisation Database package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

use phpbb\titania\ext;
use phpbb\titania\message\message;

class posts_overlord
{
	/**
	* Posts array
	* Stores [id] => post row
	*
	* @var array
	*/
	public static $posts = array();

	public static $sort_by = array(
		't' => array('POST_TIME', 'p.post_time', true),
		's' => array('SUBJECT', 'p.post_subject'),
	);

	/**
	 * Generate the permissions stuff for sql queries to the posts table (handles post_access, post_deleted, post_approved)
	 *
	 * @param <string> $prefix prefix for the query
	 * @param <bool> $where true to use WHERE, false if you already did use WHERE
	 * @return <string>
	 */
	public static function sql_permissions($prefix = 'p.', $where = false)
	{
		$access = phpbb::$container->get('phpbb.titania.access');
		$sql = ($where) ? ' WHERE' : ' AND';

		$sql .= " ({$prefix}post_access >= " . $access->get_level() . " OR {$prefix}post_user_id = " . phpbb::$user->data['user_id'] . ')';

		if (!phpbb::$auth->acl_get('u_titania_mod_post_mod'))
		{
			$sql .= " AND {$prefix}post_approved = 1";
			$sql .= " AND ({$prefix}post_deleted = 0 OR {$prefix}post_deleted = " . phpbb::$user->data['user_id'] . ')';
		}

		return $sql;
	}

	/**
	 * Load a post
	 *
	 * @param <int|array> $post_id The post_id or array of post_id's
	 */
	public static function load_post($post_id)
	{
		if (!is_array($post_id))
		{
			$post_id = array($post_id);
		}

		// Only get the rows for those we have not gotten already
		$post_id = array_diff($post_id, array_keys(self::$posts));

		if (!sizeof($post_id))
		{
			return;
		}

		$sql_ary = array(
			'SELECT'	=> '*',

			'FROM'		=> array(
				TITANIA_POSTS_TABLE	=> 'p',
			),

			'WHERE'		=> phpbb::$db->sql_in_set('p.post_id', array_map('intval', $post_id)) .
				self::sql_permissions('p.'),
		);

		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		$result = phpbb::$db->sql_query($sql);

		while($row = phpbb::$db->sql_fetchrow($result))
		{
			self::$posts[$row['post_id']] = $row;
		}

		phpbb::$db->sql_freeresult($result);
	}

	/**
	 * Get the post object
	 *
	 * @param <int> $post_id
	 * @return <object|bool> False if the post does not exist in the self::$posts array (load it first!) post object if it exists
	 */
	public static function get_post_object($post_id)
	{
		if (!isset(self::$posts[$post_id]))
		{
			return false;
		}

		// One can hope...
		$topic = topics_overlord::get_topic_object(self::$posts[$post_id]['topic_id']);

		$post = new titania_post(self::$posts[$post_id]['post_type'], $topic);
		$post->__set_array(self::$posts[$post_id]);

		return $post;
	}

/*
user_post_show_days
user_post_sortby_type
user_post_sortby_dir

$sort_dir_text = array('a' => $user->lang['ASCENDING'], 'd' => $user->lang['DESCENDING']);

// Post ordering options
$limit_post_days = array(0 => $user->lang['ALL_POSTS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);

$sort_by_post_text = array('a' => $user->lang['AUTHOR'], 't' => $user->lang['POST_TIME'], 's' => $user->lang['SUBJECT']);
$sort_by_post_sql = array('a' => 'u.username_clean', 't' => 'p.post_id', 's' => 'p.post_subject');

*/

	/**
	* Do everything we need to display the forum like page
	*
	* @param object $topic the topic object
	*/
	public static function display_topic_complete($topic)
	{
		phpbb::$user->add_lang('viewtopic');

		// Setup the sort tool
		$sort = self::build_sort();
		$sort->request();

		// if a post_id was given we must start from the appropriate page
		$post_id = phpbb::$request->variable('p', 0);
		if ($post_id)
		{
			$sql = 'SELECT COUNT(p.post_id) as start FROM ' . TITANIA_POSTS_TABLE . ' p
				WHERE p.post_id < ' . $post_id . '
					AND p.topic_id = ' . $topic->topic_id .
					self::sql_permissions('p.') . '
				ORDER BY ' . $sort->get_order_by();
			phpbb::$db->sql_query($sql);
			$start = phpbb::$db->sql_fetchfield('start');
			phpbb::$db->sql_freeresult();

			$sort->start = ($start > 0) ? (floor($start / $sort->limit) * $sort->limit) : 0;
		}

		// check to see if they want to view the latest unread post
		if (phpbb::$request->variable('view', '') == 'unread')
		{
			$tracking = phpbb::$container->get('phpbb.titania.tracking');
			$mark_time = $tracking->get_track(ext::TITANIA_TOPIC, $topic->topic_id);

			if ($mark_time > 0)
			{
				$sql = 'SELECT COUNT(p.post_id) as start FROM ' . TITANIA_POSTS_TABLE . ' p
					WHERE p.post_time <= ' . $mark_time . '
						AND p.topic_id = ' . $topic->topic_id .
						self::sql_permissions('p.') . '
					ORDER BY post_time ASC';
				phpbb::$db->sql_query($sql);
				$start = phpbb::$db->sql_fetchfield('start');
				phpbb::$db->sql_freeresult();

				$sort->start = ($start > 0) ? (floor($start / $sort->limit) * $sort->limit) : 0;
			}
		}

/*
user_topic_show_days

$limit_topic_days = array(0 => $user->lang['ALL_TOPICS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);
*/

		self::display_topic($topic, $sort);
		self::assign_common();

		// Build Quick Actions
		if ($topic->topic_type != ext::TITANIA_QUEUE)
		{
			self::build_quick_actions($topic);
		}

		// Display the Quick Reply
		$post_object = new titania_post($topic->topic_type, $topic);
		if ($post_object->acl_get('reply'))
		{
			$message = phpbb::$container->get('phpbb.titania.message');
			$message->set_parent($topic);
			$message->display_quick_reply();
		}

		phpbb::$template->assign_vars(array(
			'S_IS_LOCKED'		=> (bool) $topic->topic_locked,
		));
	}

	/**
	* Display topic section for support/tracker/etc
	*
	* @param object $topic The topic object
	* @param \phpbb\titania\sort $sort The sort object
	*/
	public static function display_topic($topic, $sort = false)
	{
		$tracking = phpbb::$container->get('phpbb.titania.tracking');

		if ($sort === false)
		{
			// Setup the sort tool
			$sort = self::build_sort();
		}

		$sort->request();
		$total_posts = $topic->get_postcount();

		// Make sure the start parameter falls within the post count limit
		if ($total_posts <= $sort->start)
		{
			$sort->start = (ceil($total_posts / $sort->limit) - 1) * $sort->limit;
		}

		$sql_ary = array(
			'SELECT'	=> 'p.*',

			'FROM'		=> array(
				TITANIA_POSTS_TABLE => 'p',
			),

			'WHERE'		=> 'p.topic_id = ' . (int) $topic->topic_id .
				self::sql_permissions('p.'),

			'ORDER_BY'	=> $sort->get_order_by(),
		);

		// Main SQL Query
		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		// Handle pagination
		if (!$sort->sql_count($sql_ary, 'p.post_id'))
		{
			// No results...no need to query more...
			return;
		}

		$topic_action = (isset($sort->url_parameters['action'])) ? $sort->url_parameters['action'] : false;
		unset($sort->url_parameters['action']);
		$sort->build_pagination($topic->get_url($topic_action));

		// Get the data
		$post_ids = $user_ids = array();
		$last_post_time = 0;  // tracking
		$result = phpbb::$db->sql_query_limit($sql, $sort->limit, $sort->start);
		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			self::$posts[$row['post_id']] = $row;
			self::$posts[$row['post_id']]['attachments'] = array();

			$post_ids[] = $row['post_id'];
			$user_ids[] = $row['post_user_id'];
			$user_ids[] = $row['post_edit_user'];
			$user_ids[] = $row['post_delete_user'];

			$last_post_time = $row['post_time']; // to set tracking
		}
		phpbb::$db->sql_freeresult($result);

		// Grab the tracking data
		$last_mark_time = $tracking->get_track(ext::TITANIA_TOPIC, $topic->topic_id);

		// Store tracking data
		$tracking->track(ext::TITANIA_TOPIC, $topic->topic_id, $last_post_time);

		// load the user data
		users_overlord::load($user_ids);

		$cp = phpbb::$container->get('profilefields.manager');
		$post = new titania_post($topic->topic_type, $topic);
		$attachments = phpbb::$container->get('phpbb.titania.attachment.operator');

		// Grab all attachments
		$attachments_set = $attachments
			->configure($topic->topic_type, false)
			->load_attachments_set($post_ids)
		;

		// Loop de loop
		$prev_post_time = 0;
		foreach ($post_ids as $post_id)
		{
			$post->__set_array(self::$posts[$post_id]);
			$post->unread = $post->post_time > $last_mark_time;

			$attachments->clear_all();

			if (isset($attachments_set[$post_id]))
			{
				$attachments->store($attachments_set[$post_id]);
			}

			// Parse attachments before outputting the message
			$message = $post->generate_text_for_display();
			$parsed_attachments = $attachments->parse_attachments($message);

			// Prepare message text for use in javascript
			$message_decoded = censor_text($post->post_text);
			message::decode($message_decoded, $post->post_text_uid);
			$message_decoded = bbcode_nl2br($message_decoded);

			// Build CP Fields
			$cp_row = array();
			if (isset(users_overlord::$cp_fields[$post->post_user_id]))
			{
				$cp_row = $cp->generate_profile_fields_template_data(users_overlord::$cp_fields[$post->post_user_id]);
			}
			$cp_row['row'] = (isset($cp_row['row']) && sizeof($cp_row['row'])) ? $cp_row['row'] : array();

			// Display edit info
			$display_username = get_username_string('full', $post->post_user_id, users_overlord::get_user($post->post_user_id, 'username'), users_overlord::get_user($post->post_user_id, 'user_colour'), false, phpbb::append_sid('memberlist', 'mode=viewprofile'));
			$l_edited_by = ($post->post_edit_time) ? sprintf(phpbb::$user->lang['EDITED_MESSAGE'], $display_username, phpbb::$user->format_date($post->post_edit_time)) : '';

			phpbb::$template->assign_block_vars('posts', array_merge(
				$post->assign_details(false),
				users_overlord::assign_details($post->post_user_id),
				$cp_row['row'],
				array(
					'POST_TEXT'				=> $message,
					'POST_TEXT_DECODED'		=> $message_decoded,
					'EDITED_MESSAGE'		=> $l_edited_by,
					'U_MINI_POST'			=> $topic->get_url(false, array('p' => $post_id, '#' => 'p' . $post_id)),
					'MINI_POST_IMG'			=> ($post->unread) ? phpbb::$user->img('icon_post_target_unread', 'NEW_POST') : phpbb::$user->img('icon_post_target', 'POST'),
					'S_FIRST_UNREAD'		=> ($post->unread && $prev_post_time <= $last_mark_time) ? true : false,
				)
			));

			$contact_fields = array(
				array(
					'ID'		=> 'pm',
					'NAME' 		=> phpbb::$user->lang['SEND_PRIVATE_MESSAGE'],
					'U_CONTACT'	=> users_overlord::get_user($post->post_user_id, '_u_pm'),
				),
				array(
					'ID'		=> 'email',
					'NAME'		=> phpbb::$user->lang['SEND_EMAIL'],
					'U_CONTACT'	=> users_overlord::get_user($post->post_user_id, '_u_email'),
				),
				array(
					'ID'		=> 'jabber',
					'NAME'		=> phpbb::$user->lang['JABBER'],
					'U_CONTACT'	=> users_overlord::get_user($post->post_user_id, '_jabber'),
				),
			);

			foreach ($contact_fields as $field)
			{
				if ($field['U_CONTACT'])
				{
					phpbb::$template->assign_block_vars('posts.contact', $field);
				}
			}

			// Output CP Fields
			if (!empty($cp_row['blockrow']))
			{
				foreach ($cp_row['blockrow'] as $field_data)
				{
					phpbb::$template->assign_block_vars('posts.custom_fields', $field_data);

					if ($field_data['S_PROFILE_CONTACT'])
					{
						phpbb::$template->assign_block_vars('posts.contact', array(
							'ID'		=> $field_data['PROFILE_FIELD_IDENT'],
							'NAME'		=> $field_data['PROFILE_FIELD_NAME'],
							'U_CONTACT'	=> $field_data['PROFILE_FIELD_CONTACT'],
						));
					}
				}
			}
	//S_IGNORE_POST
	//POST_ICON_IMG
	//MINI_POST_IMG

			foreach ($parsed_attachments as $attachment)
			{
				phpbb::$template->assign_block_vars('posts.attachment', array(
					'DISPLAY_ATTACHMENT'	=> $attachment,
				));
			}

			$prev_post_time = $post->post_time;
		}

		unset($post, $attachments);

		// Increment the topic view count
		$sql = 'UPDATE ' . TITANIA_TOPICS_TABLE . '
			SET topic_views = topic_views + 1
			WHERE topic_id = ' . (int) $topic->topic_id;
		phpbb::$db->sql_query($sql);
	}

	/**
	* Build the quick moderation actions for output for this topic
	*
	* @param mixed $topic
	*/
	public static function build_quick_actions($topic)
	{
		// Auth check
		$is_authed = $is_moderator = false;
		if (phpbb::$auth->acl_get('u_titania_mod_post_mod'))
		{
			$is_authed = $is_moderator = true;
		}
		else if (phpbb::$auth->acl_get('u_titania_post_mod_own'))
		{
			if (is_object(titania::$contrib) && titania::$contrib->contrib_id == $topic->parent_id && titania::$contrib->is_author || titania::$contrib->is_active_coauthor)
			{
				$is_authed = true;
			}
			else if (!is_object(titania::$contrib) || !titania::$contrib->contrib_id == $topic->parent_id)
			{
				$contrib = new titania_contribution();
				$contrib->load((int) $topic->parent_id);
				if ($contrib->is_author || $contrib->is_active_coauthor)
				{
					$is_authed = true;
				}
			}
		}

		if (!$is_authed)
		{
			return;
		}

		$actions = array(
			'MAKE_NORMAL'		=> ($topic->topic_sticky) ? $topic->get_url('unsticky_topic') : false,
			'MAKE_STICKY'		=> (!$topic->topic_sticky) ? $topic->get_url('sticky_topic') : false,
			'LOCK_TOPIC'		=> (!$topic->topic_locked) ? $topic->get_url('lock_topic') : false,
			'UNLOCK_TOPIC'		=> ($topic->topic_locked) ? $topic->get_url('unlock_topic') : false,
			'SPLIT_TOPIC'		=> ($is_moderator && $topic->topic_type == ext::TITANIA_SUPPORT) ? $topic->get_url('split_topic') : false,
			'MERGE_POSTS'		=> ($is_moderator && $topic->topic_type == ext::TITANIA_SUPPORT) ? $topic->get_url('move_posts') : false,
			'SOFT_DELETE_TOPIC'	=> $topic->get_url('delete_topic'),
			'UNDELETE_TOPIC'	=> $topic->get_url('undelete_topic'),
		);

		if (phpbb::$auth->acl_get('u_titania_post_hard_delete'))
		{
			$actions = array_merge($actions, array(
				'HARD_DELETE_TOPIC'	=> $topic->get_url('hard_delete_topic'),
			));
		}

		foreach ($actions as $title => $link)
		{
			if (!$link)
			{
				continue;
			}

			phpbb::$template->assign_block_vars('quickmod', array(
				'TITLE'		=> phpbb::$user->lang($title),
				'LINK'		=> $link,
			));
		}
	}

	public static function assign_common()
	{
		phpbb::$template->assign_vars(array(
			'REPORT_IMG'		=> phpbb::$user->img('icon_post_report', 'REPORT_POST'),
			'REPORTED_IMG'		=> phpbb::$user->img('icon_topic_reported', 'TOPIC_REPORTED'),
			'UNAPPROVED_IMG'	=> phpbb::$user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
			'WARN_IMG'			=> phpbb::$user->img('icon_user_warn', 'WARN_USER'),

			'EDIT_IMG' 			=> phpbb::$user->img('icon_post_edit', 'EDIT_POST'),
			'DELETE_IMG' 		=> phpbb::$user->img('icon_post_delete', 'DELETE_POST'),
			'INFO_IMG' 			=> phpbb::$user->img('icon_post_info', 'VIEW_INFO'),
			'PROFILE_IMG'		=> phpbb::$user->img('icon_user_profile', 'READ_PROFILE'),
			'SEARCH_IMG' 		=> phpbb::$user->img('icon_user_search', 'SEARCH_USER_POSTS'),
			'PM_IMG' 			=> phpbb::$user->img('icon_contact_pm', 'SEND_PRIVATE_MESSAGE'),
			'EMAIL_IMG' 		=> phpbb::$user->img('icon_contact_email', 'SEND_EMAIL'),
			'WWW_IMG' 			=> phpbb::$user->img('icon_contact_www', 'VISIT_WEBSITE'),
			'JABBER_IMG'		=> phpbb::$user->img('icon_contact_jabber', 'JABBER') ,
		));
	}

	/**
	 * Find the next or previous post id in a topic
	 *
	 * @param <type> $topic_id the topic_id of the current item
	 * @param <type> $post_id the post_id of the current item
	 * @param <string> $dir the direction (next, prev)
	 * @param <bool> $try_other_dir Try the other direction if we can not find one
	 * @return <int> $post_id the requested id
	 */
	public static function next_prev_post_id($topic_id, $post_id, $dir = 'next', $try_other_dir = true)
	{
		$sql_ary = array(
			'SELECT'	=> 'post_id',

			'FROM'		=> array(
				TITANIA_POSTS_TABLE	=> 'p',
			),

			'WHERE'		=> 'p.topic_id = ' . (int) $topic_id . '
				AND p.post_id ' . (($dir == 'next') ? '> ' : '< ') . (int) $post_id .
				self::sql_permissions('p.'),

			'ORDER_BY'	=> 'p.post_id ' . (($dir == 'next') ? 'ASC' : 'DESC'),
		);

		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		phpbb::$db->sql_query_limit($sql, 1);
		$post_id = phpbb::$db->sql_fetchfield('post_id');
		phpbb::$db->sql_freeresult();

		if ($post_id == false && $try_other_dir)
		{
			// Could not find one in the direction we were going...try the other direction...
			return self::next_prev_post_id($topic_id, $post_id, (($dir == 'next') ? 'prev' : 'next'), false);
		}

		return $post_id;
	}

	/**
	* Setup the sort tool and return it for posts display
	*
	* @return \phpbb\titania\sort
	*/
	public static function build_sort()
	{
		// Setup the sort and set the sort keys
		$sort = phpbb::$container->get('phpbb.titania.sort');
		$sort->set_sort_keys(self::$sort_by);

		if (isset(self::$sort_by[phpbb::$user->data['user_post_sortby_type']]))
		{
			$sort->default_sort_key = phpbb::$user->data['user_post_sortby_type'];
		}
		$sort->default_sort_dir = phpbb::$user->data['user_post_sortby_dir'];
		$sort->default_limit = phpbb::$config['posts_per_page'];

		$sort->result_lang = 'NUM_POSTS';

		return $sort;
	}
}
