<?php

/*

Plugin Name: Fetch Twitter Posts
Plugin URI: http://framecreative.com.au
Version: 1.0.6
Author: Frame
Author URI: http://framecreative.com.au
Description: Fetch latest posts from Twitter and save them in WP

Bitbucket Plugin URI: https://bitbucket.org/framecreative/fetch-twitter-posts
Bitbucket Branch: master

*/

require_once('vendor/autoload.php');

use Abraham\TwitterOAuth\TwitterOAuth;


class Fetch_Twitter_Posts {

	protected static $_instance = null;

	private $consumer_key = 'WnSrb7w8qgl1fBHlgGRwUc606';
	private $consumer_secret = 'J5PtYUeJ6YTdRUPIABPcplTVr0nrCfKchOMndIljBeSLSnHIOB';
	private $post_type = 'twitter-post';
	private $settings_slug = 'twitter';
	private $access_token_option = 'fetch_twitter_posts_token';
	private $token;

	function __construct() {

		self::$_instance = $this;

		$this->settingsPage = admin_url( 'options-general.php?page=' . $this->settings_slug );

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'initiate_actions' ) );

		add_filter( 'cron_schedules', array( $this, 'setup_qtr_hour_schedule' ) );
		add_action( 'init', array( $this, 'schedule_fetch' ) );
		add_action( 'fetch_twitter_posts', array( $this, 'cron_fetch_posts' ) );

	}

	static function instance() {
		if ( is_null( self::$_instance ) ) self::$_instance = new self();
		return self::$_instance;
	}

	function register_post_type() {

		$labels = array(
			'name'               => 'Twitter Posts',
			'singular_name'      => 'Twitter Post',
			'menu_name'          => 'Twitter Posts',
			'name_admin_bar'     => 'Twitter Post',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Twitter Post',
			'new_item'           => 'New Twitter Post',
			'edit_item'          => 'Edit Twitter Post',
			'view_item'          => 'View Twitter Post',
			'all_items'          => 'All Twitter Posts',
			'search_items'       => 'Search Twitter Posts',
			'parent_item_colon'  => 'Parent Twitter Posts:',
			'not_found'          => 'No Twitter Posts found.',
			'not_found_in_trash' => 'No Twitter Posts found in Trash.'
		);

		$args = array(
			'public'             => false,
			'labels'             => $labels,
			'capability_type'    => 'post',
			'hierarchical'       => false,
			'menu_icon'          => $this->custom_admin_icon('icons/twitter.svg'),
			'supports'           => array( 'title', 'editor', 'custom-fields' ),
			'menu_position'		 => 51,
			'has_archive'        => false,
			'show_ui'			 => true
		);

		register_post_type( $this->post_type, $args );

	}

	function custom_admin_icon( $path )
	{

		$path = plugin_dir_path( __FILE__ ) . '/' . $path;

		if (!file_exists($path)) {
			return '';
		}

		$icon = file_get_contents($path);
		$icon = 'data:image/svg+xml;base64,' . base64_encode($icon);

		return $icon;
	}

	function add_settings_page() {

		add_options_page( 'Twitter', 'Twitter', 'manage_options', 'twitter', array( $this, 'draw_settings_page' ) );

	}

	function draw_settings_page() {

		if ( $this->token ) {

			$connection = new TwitterOAuth( $this->consumer_key, $this->consumer_secret, $this->token['oauth_token'], $this->token['oauth_token_secret']);
			$account = $connection->get("account/verify_credentials");

		}

		$fetched = new WP_Query(array(
			'post_type' => $this->post_type,
			'posts_per_page' => 20
		));

		include( 'twitter-settings.php' );

	}

	function initiate_actions() {

		if ( isset($_GET['page']) && $_GET['page'] == $this->settings_slug ) {

			$action = isset($_GET['action']) ? $_GET['action'] : false;
			$this->token = get_option( $this->access_token_option );

			switch ( $action ) {

				case 'set-account' :
					$this->set_account();
					break;

				case 'verify-account' :
					$this->verify_account();
					break;

				case 'remove-account' :
					$this->remove_account();
					break;

				case 'fetch-posts' :
					if ( !$this->token ) wp_redirect( add_query_arg( 'feedback', 'needs-authentication', $this->settingsPage ) );
					$this->fetch_posts();
					wp_redirect( $this->settingsPage );
					break;

			}

		}

	}


	function set_account() {

		session_start();

		$connection = new TwitterOAuth( $this->consumer_key, $this->consumer_secret );
		$callbackURL = add_query_arg( 'action', 'verify-account', $this->settingsPage );

		$request_token = $connection->oauth('oauth/request_token', array( 'oauth_callback' => $callbackURL ) );

		$_SESSION['request_token'] = $request_token;

		$url = $connection->url( 'oauth/authorize', array('oauth_token' => $request_token['oauth_token']) );

		wp_redirect($url);

	}

	function verify_account() {

		session_start();

		if ( !isset($_SESSION['request_token']) || ( $_SESSION['request_token']['oauth_token'] !== $_REQUEST['oauth_token'] ) ) {
			wp_redirect( add_query_arg( 'feedback', 'authentication-error', $this->settingsPage ) );
		}

		$connection = new TwitterOAuth( $this->consumer_key, $this->consumer_key, $_SESSION['request_token']['oauth_token'], $_SESSION['request_token']['oauth_token_secret']);

		$access_token = $connection->oauth( "oauth/access_token", [ "oauth_verifier" => $_REQUEST['oauth_verifier'] ] );

		update_option( $this->access_token_option, $access_token );

		wp_redirect( add_query_arg( 'feedback', 'authentication-success', $this->settingsPage ) );

	}

	function remove_account() {

		delete_option( $this->access_token_option );
		wp_redirect( $this->settingsPage );

	}

	function fetch_posts() {

		$connection = new TwitterOAuth( $this->consumer_key, $this->consumer_secret, $this->token['oauth_token'], $this->token['oauth_token_secret']);

		$latestPost = get_posts( array(
			'post_type' => $this->post_type,
			'posts_per_page' => 1,
			'post_status' => [ 'publish', 'trash' ],
			'orderby' => 'date',
			'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => 'twitter_id',
                    'compare' => '!=',
                    'type' => 'BINARY',
                    'value' => false
                ]
            ]
		) );

		$latestPostID = ( isset($latestPost[0]) ) ? get_post_meta( $latestPost[0]->ID, 'twitter_id', true ) : false;

		$args = [
			'exclude_replies' => true,
			'include_rts' => false,
			'count' => 200,
			'include_entities' => true,
			'tweet_mode' => 'extended'
		];

		if ( $latestPostID ) $args['since_id'] = $latestPostID;

		$statuses = $connection->get( 'statuses/user_timeline', $args );

		foreach ( $statuses as $status ) {
			$this->create_post( $status );
		}

	}

	function create_post( $status ) {

		$createdTime = new DateTime( $status->created_at );
		$content = $this->replace_entities( $status->full_text, $status->entities );

		$args = array(
			'post_title' => strip_tags($content),
			'post_status' => 'publish',
			'post_type' => $this->post_type,
			'post_date_gmt' => $createdTime->format('Y-m-d H:i:s'),
			'post_content' => $content
		);

		$id = wp_insert_post( $args );

		if ( $id ) {

			update_post_meta( $id, 'twitter_id', $status->id );
			update_post_meta( $id, 'twitter_text', $status->full_text );
			update_post_meta( $id, 'twitter_entities', $status->entities );

			update_post_meta( $id, 'twitter_user_id', $status->user->id );
			update_post_meta( $id, 'twitter_user_name', $status->user->name );
			update_post_meta( $id, 'twitter_user_screen_name', $status->user->screen_name );
			update_post_meta( $id, 'twitter_user_image', $status->user->profile_image_url );

			if ( isset( $status->extended_entities->media ) ) {

				$images = array_map( function( $mediaItem ){
					if ( $mediaItem->type ) return $mediaItem;
				}, $status->extended_entities->media );

				update_post_meta( $id, 'twitter_media', $images );
			}

			do_action( 'fetch_twitter_inserted_post', $id, $status );

		}

	}

	function replace_entities( $text, $entities ) {

		$replacements = [];

		if ( isset($entities->urls) ) {
			foreach ($entities->urls as $e) {
				$temp = [];
				$temp["start"] = $e->indices[0];
				$temp["end"] = $e->indices[1];
				$temp["replacement"] = "<a href='" . $e->expanded_url . "' target='_blank'>" . $e->display_url . "</a>";
				$replacements[] = $temp;
			}
		}

		if ( isset($entities->user_mentions) ) {
			foreach ($entities->user_mentions as $e) {
				$temp = [];
				$temp["start"] = $e->indices[0];
				$temp["end"] = $e->indices[1];
				$temp["replacement"] = "<a href='https://twitter.com/" . $e->screen_name . "' target='_blank'>@" . $e->screen_name . "</a>";
				$replacements[] = $temp;
			}
		}

		if ( isset($entities->hashtags) ) {
			foreach ($entities->hashtags as $e) {
				$temp = [];
				$temp["start"] = $e->indices[0];
				$temp["end"] = $e->indices[1];
				$temp["replacement"] = "<a href='https://twitter.com/hashtag/" . $e->text . "?src=hash' target='_blank'>#" . $e->text . "</a>";
				$replacements[] = $temp;
			}
		}

		if ( isset($entities->media) ) {
			foreach ($entities->media as $e) {
				$temp = [];
				$temp["start"] = $e->indices[0];
				$temp["end"] = $e->indices[1];
				$temp["replacement"] = '';
				$replacements[] = $temp;
			}
		}

		usort( $replacements, function( $a, $b ) {
			return( $b["start"] - $a["start"] );
		});

		foreach( $replacements as $item ) {
			$text = $this->utf8_substr_replace( $text, $item["replacement"], $item["start"], $item["end"] - $item["start"] );
		}

		return $text;

	}

	function utf8_substr_replace($original, $replacement, $position, $length) {

		$startString = mb_substr($original, 0, $position, "UTF-8");
		$endString = mb_substr($original, $position + $length, mb_strlen($original), "UTF-8");

		$out = $startString . $replacement . $endString;

		return $out;
	}

	function setup_qtr_hour_schedule( $schedules ) {

		if ( isset( $schedules['qtr-hour'] ) ) return $schedules;

		$schedules['qtr-hour'] = array(
			'interval' => 15 * 60, // 15 minutes * 60 seconds
			'display' => 'Qtr Hour'
		);

		return $schedules;
	}

	function schedule_fetch() {

		if ( !wp_next_scheduled('fetch_twitter_posts') ) {
			wp_schedule_event( time(), 'qtr-hour', 'fetch_twitter_posts' );
		}

	}

	function cron_fetch_posts() {

		$this->token = get_option( $this->access_token_option );

		if ( !$this->token ) return;

		$this->fetch_posts();


	}


}


function Fetch_Twitter_Posts() {
	return Fetch_Twitter_Posts::instance();
}

Fetch_Twitter_Posts();