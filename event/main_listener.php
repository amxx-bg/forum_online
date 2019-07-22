<?php
/**
 *
 * Forum Online. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2019, Evil
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace evilsystem\forum_online\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/**
	* Constructor of event listener
	* @param \phpbb\user							$user			User object
	* @param \phpbb\config							$config			Config object
	* @param \phpbb\db\driver\driver_interface		$db				Database object
	* @param \phpbb\cache\driver\driver_interface 	$cache			Cache driver object
	*/

	public function __construct(\phpbb\user $user, \phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, \phpbb\cache\driver\driver_interface $cache)
	{
		$this->user = $user;
		$this->config = $config;
		$this->db = $db;
		$this->cache = $cache;
	}

	static public function getSubscribedEvents()
	{
		return array(
			'core.display_forums_before'				=> 'displayForumsBefore',
			'core.display_forums_modify_template_vars'	=> 'onlineForumusers',
		);
	}

	/** Data request viewforum */
	public function displayForumsBefore($event)
	{
		$forum_id = array();
		foreach ($event['forum_rows'] as $row)
		{
			if ($row['forum_type'] != FORUM_CAT && $row['forum_type'] != FORUM_LINK)
			{
				$forum_id[] = $row['forum_id'];
			}
		}

		if (sizeof($forum_id))
		{
			$this->guests_online = array();
			$this->visible_online = array();

			// a little discrete magic to cache this for 30 seconds
			$time = (time() - (intval($this->config['load_online_time']) * 60));

			if (($this->guests_online = $this->cache->get("_guests_online")) === false)
			{
				$sql = 'SELECT COUNT(session_user_id) AS count_online, session_user_id, session_forum_id
					FROM ' . SESSIONS_TABLE . '
					WHERE ' . $this->db->sql_in_set('session_forum_id', $forum_id) . '
						AND session_time >= ' . ($time - ((int) ($time % 30))) . '
						AND session_user_id = ' . ANONYMOUS . '
						GROUP BY session_forum_id';
				$result = $this->db->sql_query($sql);
				if ($row = $this->db->sql_fetchrow($result))
				{
					do
					{
						$this->guests_online[$row['session_forum_id']] = $row['count_online'];
					}
					while ($row = $this->db->sql_fetchrow($result));
				}
				$this->db->sql_freeresult($result);

				$this->cache->put("_guests_online", $this->guests_online, 60*$this->config['load_online_time']);
			}

			if (($this->visible_online = $this->cache->get("_visible_online")) === false)
			{
				$sql = 'SELECT COUNT(session_user_id) AS count_online, session_user_id, session_forum_id
					FROM ' . SESSIONS_TABLE . '
					WHERE ' . $this->db->sql_in_set('session_forum_id', $forum_id) . '
						AND session_time >= ' . ($time - ((int) ($time % 30))) . '
						AND session_user_id <> ' . ANONYMOUS . '
						GROUP BY session_forum_id';
				$result = $this->db->sql_query($sql);
				if ($row = $this->db->sql_fetchrow($result))
				{
					do
					{
						$this->visible_online[$row['session_forum_id']] = $row['count_online'];
					}
					while ($row = $this->db->sql_fetchrow($result));
				}
				$this->db->sql_freeresult($result);

				$this->cache->put("_visible_online", $this->visible_online, 60*$this->config['load_online_time']);
			}
		}
	}

	/* Forum Online Users */
	public function onlineForumusers($event)
	{
		$row = $event['row'];

		if ($row['forum_type'] != FORUM_CAT && $row['forum_type'] != FORUM_LINK)
		{
			$forum_online_users = (isset($this->visible_online[$row['forum_id']])) ? $this->visible_online[$row['forum_id']] : 0;
			$forum_online_users = $this->user->lang('REG_USERS_TOTAL', (int) $forum_online_users);

			$forum_online_guest = (isset($this->guests_online[$row['forum_id']])) ? $this->guests_online[$row['forum_id']] : 0;
			$forum_online_guest = $this->user->lang('GUEST_USERS_TOTAL', (int) $forum_online_guest);

			$forum_row = $event['forum_row'];

			$forum_row['FORUM_DESC'] .= '<div class="inner"><div class="post bg4 left-box"><i class="icon fa-group fa-fw" aria-hidden="true"></i> ' . $forum_online_users . '  <i class="icon fa-eye fa-fw" aria-hidden="true"></i> ' . $forum_online_guest . '</div></div>'; // No event

			$forum_row['FORUM_ONLINE_USERS'] = $forum_online_users;
			$forum_row['FORUM_ONLINE_GUEST'] = $forum_online_guest;
			$event['forum_row'] = $forum_row;
		}
	}
}
