<?php

//=TEMPLATING/DISPLAY===========

function yarpp_set_score_override_flag($q) {
	global $yarpp_time, $yarpp_score_override, $yarpp_online_limit;
	if ($yarpp_time) {
		if ($q->query_vars['orderby'] == 'score')
			$yarpp_score_override = true;
		else
			$yarpp_score_override = false;

		if ($q->query_vars['showposts'] != '') {
			$yarpp_online_limit = $q->query_vars['showposts'];
		} else {
			$yarpp_online_limit = false;
    }

	}
}

function yarpp_join_filter($arg) {
	global $wpdb, $yarpp_time;
	if ($yarpp_time) {
		$arg .= " join {$wpdb->prefix}yarpp_related_cache as yarpp on {$wpdb->posts}.ID = yarpp.ID";
	}
	return $arg;
}

function yarpp_where_filter($arg) {
	global $wpdb, $yarpp_time;
	$threshold = yarpp_get_option('threshold');
	if ($yarpp_time) {
		$arg = str_replace("$wpdb->posts.ID = ","yarpp.score >= $threshold and yarpp.reference_ID = ",$arg);
		if (yarpp_get_option("recent_only"))
			$arg .= " and post_date > date_sub(now(), interval ".yarpp_get_option("recent_number")." ".yarpp_get_option("recent_units").") ";
		//echo "<!--YARPP TEST: $arg-->";
	}
	return $arg;
}

function yarpp_orderby_filter($arg) {
	global $wpdb, $yarpp_time, $yarpp_score_override;
	if ($yarpp_time and $yarpp_score_override) {
		$arg = str_replace("$wpdb->posts.post_date","yarpp.score",$arg);
	}
	return $arg;
}

function yarpp_limit_filter($arg) {
	global $wpdb, $yarpp_time, $yarpp_online_limit;
	if ($yarpp_time and $yarpp_online_limit) {
		return " limit $yarpp_online_limit ";
	}
	return $arg;
}

function yarpp_fields_filter($arg) {
	global $wpdb, $yarpp_time;
	if ($yarpp_time) {
		$arg .= ", yarpp.score";
	}
	return $arg;
}

function yarpp_demo_request_filter($arg) {
	global $wpdb, $yarpp_demo_time, $yarpp_limit;
	if ($yarpp_demo_time) {
		$wpdb->query("set @count = 0;");
		$arg = "SELECT SQL_CALC_FOUND_ROWS ID + $yarpp_limit as ID, post_author, post_date, post_date_gmt, 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.' as post_content,
		concat('".__('Example post ','yarpp')."',@count:=@count+1) as post_title, 0 as post_category, '' as post_excerpt, 'publish' as post_status, 'open' as comment_status, 'open' as ping_status, '' as post_password, concat('example-post-',@count) as post_name, '' as to_ping, '' as pinged, post_modified, post_modified_gmt, '' as post_content_filtered, 0 as post_parent, concat('PERMALINK',@count) as guid, 0 as menu_order, 'post' as post_type, '' as post_mime_type, 0 as comment_count, 'SCORE' as score
		FROM $wpdb->posts
		ORDER BY ID DESC LIMIT 0, $yarpp_limit";
	}
	return $arg;
}

//=CACHING===========

function yarpp_sql($type,$args,$giveresults = true,$reference_ID=false,$domain='website') {
	global $wpdb, $post, $yarpp_debug;

	if (is_object($post) and !$reference_ID) {
		$reference_ID = $post->ID;
	}
	
	// set the "domain prefix", used for all the preferences.
	if ($domain == 'rss')
		$domainprefix = 'rss_';
	else
		$domainprefix = '';

	$options = array('limit'=>"${domainprefix}limit",
		'threshold'=>'threshold',
		'show_pass_post'=>'show_pass_post',
		'past_only'=>'past_only',
		'cross_relate'=>'cross_relate',
		'body'=>'body',
		'title'=>'title',
		'tags'=>'tags',
		'categories'=>'categories',
		'distags'=>'distags',
		'discats'=>'discats',
		'recent_only'=>'recent_only',
		'recent_number'=>'recent_number',
		'recent_units'=>'recent_units');
	$optvals = array();
	foreach (array_keys($options) as $option) {
		if (isset($args[$option])) {
			$optvals[$option] = stripslashes($args[$option]);
		} else {
			$optvals[$option] = stripslashes(stripslashes(yarpp_get_option($options[$option])));
		}
	}

	extract($optvals);

	// Fetch keywords
    $body_terms = yarpp_get_cached_keywords($reference_ID,'body');
    $title_terms = yarpp_get_cached_keywords($reference_ID,'title');
    
    if ($yarpp_debug) echo "<!--TITLE TERMS: $title_terms-->"; // debug
    if ($yarpp_debug) echo "<!--BODY TERMS: $body_terms-->"; // debug
	
	// get weights
	
	$bodyweight = (($body == 3)?3:(($body == 2)?1:0));
	$titleweight = (($title == 3)?3:(($title == 2)?1:0));
	$tagweight = (($tags != 1)?1:0);
	$catweight = (($categories != 1)?1:0);
	$weights = array();
	$weights['body'] = $bodyweight;
	$weights['title'] = $titleweight;
	$weights['cat'] = $catweight;
	$weights['tag'] = $tagweight;
	
	$totalweight = $bodyweight + $titleweight + $tagweight + $catweight;
	
	// get disallowed categories and tags
	
	$disterms = implode(',', array_filter(array_merge(explode(',',$discats),explode(',',$distags)),'is_numeric'));

	$usedisterms = count(array_filter(array_merge(explode(',',$discats),explode(',',$distags)),'is_numeric'));

	$criteria = array();
	if ($bodyweight)
		$criteria['body'] = "(MATCH (post_content) AGAINST ('".$wpdb->escape($body_terms)."'))";
	if ($titleweight)
		$criteria['title'] = "(MATCH (post_title) AGAINST ('".$wpdb->escape($title_terms)."'))";
	if ($tagweight)
		$criteria['tag'] = "COUNT( DISTINCT tagtax.term_taxonomy_id )";
	if ($catweight)
		$criteria['cat'] = "COUNT( DISTINCT cattax.term_taxonomy_id )";

	$newsql = "SELECT $reference_ID, ID, "; //post_title, post_date, post_content, post_excerpt, 

	//foreach ($criteria as $key => $value) {
	//	$newsql .= "$value as ${key}score, ";
	//}

	$newsql .= '(0';
	foreach ($criteria as $key => $value) {
		$newsql .= "+ $value * ".$weights[$key];
	}
	$newsql .= ') as score';
	
	$newsql .= "\n from $wpdb->posts \n";

	if ($usedisterms)
		$newsql .= " left join $wpdb->term_relationships as blockrel on ($wpdb->posts.ID = blockrel.object_id)
		left join $wpdb->term_taxonomy as blocktax using (`term_taxonomy_id`)
		left join $wpdb->terms as blockterm on (blocktax.term_id = blockterm.term_id and blockterm.term_id in ($disterms))\n";

	if ($tagweight)
		$newsql .= " left JOIN $wpdb->term_relationships AS thistag ON (thistag.object_id = $reference_ID ) 
		left JOIN $wpdb->term_relationships AS tagrel on (tagrel.term_taxonomy_id = thistag.term_taxonomy_id
		AND tagrel.object_id = $wpdb->posts.ID)
		left JOIN $wpdb->term_taxonomy AS tagtax ON ( tagrel.term_taxonomy_id = tagtax.term_taxonomy_id
		AND tagtax.taxonomy = 'post_tag')\n";

	if ($catweight)
		$newsql .= " left JOIN $wpdb->term_relationships AS thiscat ON (thiscat.object_id = $reference_ID ) 
		left JOIN $wpdb->term_relationships AS catrel on (catrel.term_taxonomy_id = thiscat.term_taxonomy_id
		AND catrel.object_id = $wpdb->posts.ID)
		left JOIN $wpdb->term_taxonomy AS cattax ON ( catrel.term_taxonomy_id = cattax.term_taxonomy_id
		AND cattax.taxonomy = 'category')\n";

	// WHERE
	
	$newsql .= " where (post_status IN ( 'publish',  'static' ) and ID != '$reference_ID')";

	if ($past_only) { // 3.1.8: revised $past_only option
    if ( is_object($post) && $reference_ID == $post->ID )
	    $reference_post_date = $post->post_date;
	  else
	    $reference_post_date = $wpdb->get_var("select post_date from $wpdb->posts where ID = $reference_ID");
		$newsql .= " and post_date <= '$reference_post_date' ";
	}
	if (!$show_pass_post)
		$newsql .= " and post_password ='' ";
	if ($recent_only)
		$newsql .= " and post_date > date_sub(now(), interval $recent_number $recent_units) ";

  if ($type == array('page') && !$cross_relate)
    $newsql .= " and post_type = 'page'";
  else
    $newsql .= " and post_type = 'post'";

	// GROUP BY
	$newsql .= "\n group by id \n";
	// HAVING
	// safethreshold is so the new calibration system works.
	// number_format fix suggested by vkovalcik! :) 
	$safethreshold = number_format(max($threshold,0.1), 2, '.', '');
	$newsql .= " having score >= $safethreshold";
	if ($usedisterms)
		$newsql .= " and count(blockterm.term_id) = 0";

	$newsql .= (($categories == 3)?' and '.$criteria['cat'].' >= 1':'');
	$newsql .= (($categories == 4)?' and '.$criteria['cat'].' >= 2':'');
	$newsql .= (($tags == 3)?' and '.$criteria['tag'].' >= 1':'');
	$newsql .= (($tags == 4)?' and '.$criteria['tag'].' >= 2':'');
	$newsql .= " order by score desc limit ".$limit;

	if (!$giveresults) {
		$newsql = "select count(t.ID) from ($newsql) as t";
	}

  // if we're looking for a X related entries, make sure we get at most X posts and X pages if
  // we cross-relate
	if ($cross_relate) $newsql = "($newsql) union (".str_replace("post_type = 'post'","post_type = 'page'",$newsql).")";

	if ($yarpp_debug) echo "<!--$newsql-->";
	return $newsql;
}

/* new in 2.1! the domain argument refers to {website,widget,rss}, though widget is not used yet. */

/* new in 3.0! new query-based approach: EXTREMELY HACKY! */

function yarpp_related($type,$args,$echo = true,$reference_ID=false,$domain = 'website') {
	global $wpdb, $post, $userdata, $yarpp_time, $yarpp_demo_time, $wp_query, $id, $page, $pages, $authordata, $day, $currentmonth, $multipage, $more, $numpages;
	
	if ($domain != 'demo_web' and $domain != 'demo_rss') {
		if ($yarpp_time) // if we're already in a YARPP loop, stop now.
			return false;
		
		if (is_object($post) and !$reference_ID)
			$reference_ID = $post->ID;
	} else {
		if ($yarpp_demo_time) // if we're already in a YARPP loop, stop now.
			return false;
	}
	
	get_currentuserinfo();

	// set the "domain prefix", used for all the preferences.
	if ($domain == 'rss' or $domain == 'demo_rss')
		$domainprefix = 'rss_';
	else
		$domainprefix = '';

	// get options
	// note the 2.1 change... the options array changed from what you might call a "list" to a "hash"... this changes the structure of the $args to something which is, in the long term, much more useful
	$options = array(
    'limit'=>"${domainprefix}limit",
		'use_template'=>"${domainprefix}use_template",
		'order'=>"${domainprefix}order",
		'template_file'=>"${domainprefix}template_file",
		'promote_yarpp'=>"${domainprefix}promote_yarpp");
	$optvals = array();
	foreach (array_keys($options) as $option) {
		if (isset($args[$option])) {
			$optvals[$option] = stripslashes($args[$option]);
		} else {
			$optvals[$option] = stripslashes(stripslashes(yarpp_get_option($options[$option])));
		}
	}
	extract($optvals);
	
  yarpp_cache_enforce($type,$reference_ID);
	
  $output = '';
	
	if ($domain != 'demo_web' and $domain != 'demo_rss')
		$yarpp_time = true; // get ready for YARPP TIME!
	else
		$yarpp_demo_time = true;
	// just so we can return to normal later
	$current_query = $wp_query;
	$current_post = $post;
	$current_id = $id;
	$current_page = $page;
	$current_pages = $pages;
	$current_authordata = $authordata;
	$current_numpages = $numpages;
	$current_multipage = $multipage;
	$current_more = $more;
	$current_pagenow = $pagenow;
	$current_day = $day;
	$current_currentmonth = $currentmonth;

	$related_query = new WP_Query();
	$orders = split(' ',$order);
	if ($domain != 'demo_web' and $domain != 'demo_rss')
		$related_query->query(array('p'=>$reference_ID,'orderby'=>$orders[0],'order'=>$orders[1],'showposts'=>$limit,'post_type'=>$type));
	else
		$related_query->query('');

	$wp_query = $related_query;
	$wp_query->in_the_loop = true;
  $wp_query->is_feed = $current_query->is_feed;
  // make sure we get the right is_single value
  // (see http://wordpress.org/support/topic/288230)
	$wp_query->is_single = false;
	
	if ($domain == 'metabox') {
		include(YARPP_DIR.'/template-metabox.php');
	} elseif ($use_template and file_exists(STYLESHEETPATH . '/' . $template_file) and $template_file != '') {
		ob_start();
		include(STYLESHEETPATH . '/' . $template_file);
		$output = ob_get_contents();
		ob_end_clean();
	} elseif ($domain == 'widget') {
		include(YARPP_DIR.'/template-widget.php');
	} else {
		include(YARPP_DIR.'/template-builtin.php');
	}
		
	unset($related_query);
	if ($domain != 'demo_web' and $domain != 'demo_rss')
		$yarpp_time = false; // YARPP time is over... :(
	else
		$yarpp_demo_time = false;
	
	// restore the older wp_query.
	$wp_query = null; $wp_query = $current_query; unset($current_query);
	$post = null; $post = $current_post; unset($current_post);
  $authordata = null; $authordata = $current_authordata; unset($current_authordata);
	$pages = null; $pages = $current_pages; unset($current_pages);
	$id = $current_id; unset($current_id);
	$page = $current_page; unset($current_page);
	$numpages = null; $numpages = $current_numpages; unset($current_numpages);
	$multipage = null; $multipage = $current_multipage; unset($current_multipage);
	$more = null; $more = $current_more; unset($current_more);
	$pagenow = null; $pagenow = $current_pagenow; unset($current_pagenow);
  $day = null; $day = $current_day; unset($current_day);
  $currentmonth = null; $currentmonth = $current_currentmonth; unset($current_currentmonth);

	if ($promote_yarpp and $domain != 'metabox')
		$output .= "\n<p>".__("Related posts brought to you by <a href='http://mitcho.com/code/yarpp/'>Yet Another Related Posts Plugin</a>.",'yarpp')."</p>";
	
	if ($echo) echo $output; else return ((!empty($output))?"\n\n":'').$output;
}

function yarpp_related_exist($type,$args,$reference_ID=false) {
	global $wpdb, $post, $yarpp_time;

	if (is_object($post) and !$reference_ID)
		$reference_ID = $post->ID;
	
	if ($yarpp_time) // if we're already in a YARPP loop, stop now.
		return false;
	
  yarpp_cache_enforce($type,$reference_ID);
	
  $yarpp_time = true; // get ready for YARPP TIME!
	$related_query = new WP_Query();
  $related_query->query(array('p'=>$reference_ID,'showposts'=>10000,'post_type'=>$type));
  $return = $related_query->have_posts();
  $yarpp_time = false; // YARPP time is over. :(
  unset($related_query);

	return $return;
}

function yarpp_save_cache($post_ID,$force=true) {
	global $wpdb;

  $sql = "select post_parent, post_type from $wpdb->posts where ID='$post_ID'";
	$parent_ID = $wpdb->get_var($sql,0);

	if ($parent_ID != $post_ID and $parent_ID)
		$post_ID = $parent_ID;

	$post_type = $wpdb->get_var($sql,1);
	if (yarpp_get_option('cross_relate'))
		$type = array('post','page');
	elseif ($post_type == 'page')
		$type = array('page');
	else
		$type = array('post');
  // TODO: support other post types? maybe?
  // TODO: fix this bug... we should be getting the post type from the parent, if there is one.

  yarpp_cache_enforce($type,$post_ID,$force);
	
}

function yarpp_cache_clear($reference_IDs) {
  global $wpdb;
  if (is_array($reference_IDs) && count($reference_IDs))
    $wpdb->query("delete from {$wpdb->prefix}yarpp_related_cache where reference_ID in (".implode(',',$reference_IDs).")");
}

function yarpp_cache_enforce($type=array('post'),$reference_ID,$force=false) {
	global $wpdb, $yarpp_debug;
	
	if ($reference_ID === '' || $reference_ID === false)
	  return false;
	
	if (!$force) {
		if ($wpdb->get_var("select count(*) as count from {$wpdb->prefix}yarpp_related_cache where reference_ID = $reference_ID")) {
      // 3.1.3: removed the cache timeout
      // and date > date_sub(now(),interval 600 minute)
			if ($yarpp_debug) echo "<!--YARPP is using the cache right now.-->";
			return false;
		}
	}
	
	yarpp_cache_keywords($reference_ID);
	
	// clear out the cruft
  yarpp_cache_clear(array($reference_ID));
	
	// let's update the related posts
	$wpdb->query("insert into {$wpdb->prefix}yarpp_related_cache (reference_ID,ID,score) ".yarpp_sql($type,array(),true,$reference_ID)." on duplicate key update date = now()");
	
	$affected = $wpdb->rows_affected;
	
	if ($affected and $yarpp_debug) echo "<!--YARPP just set the cache for post $reference_ID-->";

	// if changes were made, let's find out which ones are new. We'll want to clear their caches
	// so that they will be rebuilt when they're hit next.
	if ($affected && !yarpp_get_option('past_only')) {
		$new_relations = $wpdb->get_col("select ID from {$wpdb->prefix}yarpp_related_cache where reference_ID = $reference_ID and ID != 0");
		yarpp_cache_clear($new_relations);
	}
	
	if (!$affected) {
		$wpdb->query("insert into {$wpdb->prefix}yarpp_related_cache (reference_ID,ID,score) values ($reference_ID,0,0) on duplicate key update date = now()");
		if (!$wpdb->rows_affected)
			return false;
	}
	
	return true;
	
}

