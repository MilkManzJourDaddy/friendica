<?php
function add_thread($itemid, $onlyshadow = false) {
	$items = q("SELECT `uid`, `created`, `edited`, `commented`, `received`, `changed`, `wall`, `private`, `pubmail`,
			`moderated`, `visible`, `spam`, `starred`, `bookmark`, `contact-id`, `gcontact-id`,
			`deleted`, `origin`, `forum_mode`, `mention`, `network`, `author-id`, `owner-id`
		FROM `item` WHERE `id` = %d AND (`parent` = %d OR `parent` = 0) LIMIT 1", intval($itemid), intval($itemid));

	if (!$items)
		return;

	$item = $items[0];
	$item['iid'] = $itemid;

	if (!$onlyshadow) {
		$result = dbq("INSERT INTO `thread` (`"
				.implode("`, `", array_keys($item))
				."`) VALUES ('"
				.implode("', '", array_values($item))
				."')");

		logger("Add thread for item ".$itemid." - ".print_r($result, true), LOGGER_DEBUG);
	}
}

/**
 * @brief Add a shadow entry for a given item id that is a thread starter
 *
 * We store every public item entry additionally with the user id "0".
 * This is used for the community page and for the search.
 * It is planned that in the future we will store public item entries only once.
 *
 * @param integer $itemid Item ID that should be added
 */
function add_shadow_thread($itemid) {
	$items = q("SELECT `uid`, `wall`, `private`, `moderated`, `visible`, `contact-id`, `deleted`, `network`
		FROM `item` WHERE `id` = %d AND (`parent` = %d OR `parent` = 0) LIMIT 1", intval($itemid), intval($itemid));

	if (!dbm::is_result($items)) {
		return;
	}

	$item = $items[0];

	// is it already a copy?
	if (($itemid == 0) OR ($item['uid'] == 0)) {
		return;
	}

	// Is it a visible public post?
	if (!$item["visible"] OR $item["deleted"] OR $item["moderated"] OR $item["private"]) {
		return;
	}

	// is it an entry from a connector? Only add an entry for natively connected networks
	if (!in_array($item["network"], array(NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS, ""))) {
		return;
	}

	// Only do these checks if the post isn't a wall post
	if (!$item["wall"]) {
		// Check, if hide-friends is activated - then don't do a shadow entry
		$r = q("SELECT `hide-friends` FROM `profile` WHERE `is-default` AND `uid` = %d AND NOT `hide-friends`",
			$item['uid']);

		if (!dbm::is_result($r)) {
			return;
		}

		// Check if the contact is hidden or blocked
		$r = q("SELECT `id` FROM `contact` WHERE NOT `hidden` AND NOT `blocked` AND `id` = %d",
			$item['contact-id']);

		if (!dbm::is_result($r)) {
			return;
		}
	}

	// Only add a shadow, if the profile isn't hidden
	$r = q("SELECT `uid` FROM `user` where `uid` = %d AND NOT `hidewall`", $item['uid']);
	if (!dbm::is_result($r)) {
		return;
	}

	$item = q("SELECT * FROM `item` WHERE `id` = %d", intval($itemid));

	if (count($item) AND ($item[0]["allow_cid"] == '')  AND ($item[0]["allow_gid"] == '') AND
		($item[0]["deny_cid"] == '') AND ($item[0]["deny_gid"] == '')) {

		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1",
			dbesc($item['uri']));

		if (!dbm::is_result($r)) {
			// Preparing public shadow (removing user specific data)
			require_once("include/items.php");
			require_once("include/Contact.php");

			unset($item[0]['id']);
			$item[0]['uid'] = 0;
			$item[0]['origin'] = 0;
			$item[0]['wall'] = 0;
			$item[0]['contact-id'] = get_contact($item[0]['author-link'], 0);

			if (in_array($item[0]['type'], array("net-comment", "wall-comment"))) {
				$item[0]['type'] = 'remote-comment';
			} elseif ($item[0]['type'] == 'wall') {
				$item[0]['type'] = 'remote';
			}

			$public_shadow = item_store($item[0], false, false, true);

			logger("Stored public shadow for thread ".$itemid." under id ".$public_shadow, LOGGER_DEBUG);
		}
	}
}

/**
 * @brief Add a shadow entry for a given item id that is a comment
 *
 * This function does the same like the function above - but for comments
 *
 * @param integer $itemid Item ID that should be added
 */
function add_shadow_entry($itemid) {

	$items = q("SELECT * FROM `item` WHERE `id` = %d", intval($itemid));

	if (!dbm::is_result($items)) {
		return;
	}

	$item = $items[0];

	// Is it a toplevel post?
	if ($item['id'] == $item['parent']) {
		add_shadow_thread($itemid);
		return;
	}

	// Is this a shadow entry?
	if ($item['uid'] == 0)
		return;

	// Is there a shadow parent?
	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1", dbesc($item['parent-uri']));
	if (!dbm::is_result($r))
		return;

	// Is there already a shadow entry?
	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = 0 LIMIT 1", dbesc($item['uri']));
	if (dbm::is_result($r))
		return;

	// Preparing public shadow (removing user specific data)
	require_once("include/items.php");
	require_once("include/Contact.php");

	unset($item['id']);
	$item['uid'] = 0;
	$item['origin'] = 0;
	$item['wall'] = 0;
	$item['contact-id'] = get_contact($item['author-link'], 0);

	if (in_array($item['type'], array("net-comment", "wall-comment"))) {
		$item['type'] = 'remote-comment';
	} elseif ($item['type'] == 'wall') {
		$item['type'] = 'remote';
	}

	$public_shadow = item_store($item, false, false, true);

	logger("Stored public shadow for comment ".$item['uri']." under id ".$public_shadow, LOGGER_DEBUG);
}

function update_thread_uri($itemuri, $uid) {
	$messages = q("SELECT `id` FROM `item` WHERE uri ='%s' AND uid=%d", dbesc($itemuri), intval($uid));

	if (dbm::is_result($messages))
		foreach ($messages as $message)
			update_thread($message["id"]);
}

function update_thread($itemid, $setmention = false) {
	$items = q("SELECT `uid`, `guid`, `title`, `body`, `created`, `edited`, `commented`, `received`, `changed`, `wall`, `private`, `pubmail`, `moderated`, `visible`, `spam`, `starred`, `bookmark`, `contact-id`, `gcontact-id`,
			`deleted`, `origin`, `forum_mode`, `network`, `rendered-html`, `rendered-hash` FROM `item` WHERE `id` = %d AND (`parent` = %d OR `parent` = 0) LIMIT 1", intval($itemid), intval($itemid));

	if (!dbm::is_result($items))
		return;

	$item = $items[0];

	if ($setmention)
		$item["mention"] = 1;

	$sql = "";

	foreach ($item AS $field => $data)
		if (!in_array($field, array("guid", "title", "body", "rendered-html", "rendered-hash"))) {
			if ($sql != "")
				$sql .= ", ";

			$sql .= "`".$field."` = '".dbesc($data)."'";
		}

	$result = q("UPDATE `thread` SET ".$sql." WHERE `iid` = %d", intval($itemid));

	logger("Update thread for item ".$itemid." - guid ".$item["guid"]." - ".print_r($result, true)." ".print_r($item, true), LOGGER_DEBUG);

	// Updating a shadow item entry
	$items = q("SELECT `id` FROM `item` WHERE `guid` = '%s' AND `uid` = 0 LIMIT 1", dbesc($item["guid"]));

	if (!$items)
		return;

	$result = q("UPDATE `item` SET `title` = '%s', `body` = '%s', `rendered-html` = '%s', `rendered-hash` = '%s' WHERE `id` = %d",
			dbesc($item["title"]),
			dbesc($item["body"]),
			dbesc($item["rendered-html"]),
			dbesc($item["rendered-hash"]),
			intval($items[0]["id"])
		);
	logger("Updating public shadow for post ".$items[0]["id"]." - guid ".$item["guid"]." Result: ".print_r($result, true), LOGGER_DEBUG);
}

function delete_thread_uri($itemuri, $uid) {
	$messages = q("SELECT `id` FROM `item` WHERE uri ='%s' AND uid=%d", dbesc($itemuri), intval($uid));

	if(count($messages))
		foreach ($messages as $message)
			delete_thread($message["id"], $itemuri);
}

function delete_thread($itemid, $itemuri = "") {
	$item = q("SELECT `uid` FROM `thread` WHERE `iid` = %d", intval($itemid));

	$result = q("DELETE FROM `thread` WHERE `iid` = %d", intval($itemid));

	logger("delete_thread: Deleted thread for item ".$itemid." - ".print_r($result, true), LOGGER_DEBUG);

	if ($itemuri != "") {
		$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND NOT (`uid` IN (%d, 0))",
				dbesc($itemuri),
				intval($item["uid"])
			);
		if (!dbm::is_result($r)) {
			$r = q("DELETE FROM `item` WHERE `uri` = '%s' AND `uid` = 0",
				dbesc($itemuri)
			);
			logger("delete_thread: Deleted shadow for item ".$itemuri." - ".print_r($result, true), LOGGER_DEBUG);
		}
	}
}

function update_threads() {
	logger("update_threads: start");

	$messages = dba::p("SELECT `id` FROM `item` WHERE `id` = `parent`");

	logger("update_threads: fetched messages: ".dba::num_rows($messages));

	while ($message = dba::fetch($messages)) {
		add_thread($message["id"]);
		add_shadow_thread($message["id"]);
	}
	dba::close($messages);
}

function update_threads_mention() {
	$users = q("SELECT `uid`, `nickname` FROM `user` ORDER BY `uid`");

	foreach ($users AS $user) {
		$self = normalise_link(App::get_baseurl() . '/profile/' . $user['nickname']);
		$selfhttps = str_replace("http://", "https://", $self);
		$parents = q("SELECT DISTINCT(`parent`) FROM `item` WHERE `uid` = %d AND
				((`owner-link` IN ('%s', '%s')) OR (`author-link` IN ('%s', '%s')))",
				$user["uid"], $self, $selfhttps, $self, $selfhttps);

		foreach ($parents AS $parent)
			q("UPDATE `thread` SET `mention` = 1 WHERE `iid` = %d", $parent["parent"]);
	}
}


function update_shadow_copy() {
	logger("start");

	$messages = dba::p("SELECT `iid` FROM `thread` WHERE `uid` != 0 AND `network` IN ('', ?, ?, ?)
				AND `visible` AND NOT `deleted` AND NOT `moderated` AND NOT `private` ORDER BY `created`",
				NETWORK_DFRN, NETWORK_DIASPORA, NETWORK_OSTATUS);

	logger("fetched messages: ".dba::num_rows($messages));
	while ($message = dba::fetch($messages))
		add_shadow_thread($message["iid"]);

	dba::close($messages);
}
?>
