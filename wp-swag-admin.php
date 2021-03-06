<?php
/*
	wp-swag plugin admin functionalities and hooks
*/

require_once __DIR__."/utils.php";
require_once __DIR__."/src/model/SwagUser.php";
require_once __DIR__."/src/utils/Xapi.php";
require_once __DIR__."/src/model/SwagPost.php";
require_once __DIR__."/src/utils/Template.php";
require_once __DIR__."/src/utils/ShortcodeUtil.php";
require_once __DIR__."/src/controller/SettingsPageController.php";

class WP_Swag_admin{
	static $plugins_uri;

	public function init_hooks(){
		self::$plugins_uri = plugins_url()."/wp-swag";

		// initialise the the admin settings
		add_action('admin_init',array(get_called_class(),'ti_admin_init'));
		add_action('admin_menu',array(get_called_class(),'ti_admin_menu'));
		add_shortcode("course", array(get_called_class(), "ti_course"));

		add_shortcode("track-listing",array(get_called_class(), "ti_track_listing"));
		add_shortcode("course-listing",array(get_called_class(),"ti_course_listing"));
		add_action('wp_enqueue_scripts',array(get_called_class(), "ti_enqueue_scripts"));
		add_action('admin_enqueue_scripts',array(get_called_class(), "ti_enqueue_scripts"));

		add_shortcode("swagmap", array(get_called_class(), "ti_swagmap"));

		add_action("h5p-xapi-post-save",array(get_called_class(),"ti_xapi_post_save"));
		add_action("h5p-xapi-pre-save",array(get_called_class(),"ti_xapi_pre_save"));
		add_action("deliverable-xapi-post-save",array(get_called_class(), "ti_xapi_post_save"));
		add_shortcode("my-swag",array(get_called_class(), "ti_my_swag"));

		add_filter("h5p-xapi-auth-settings",array(get_called_class(),"ti_xapi_h5p_auth_settings"));
	}



	/**
	 * Create the admin menu.
	 */
	public function ti_admin_menu() {
		add_options_page(
			'Tunapanda Swag',
			'Tunapanda Swag',
			'manage_options',
			'ti_settings',
			array(get_called_class(), 'ti_create_settings_page')
		);
	}

	/**
	 * Admin init.
	 */
	public function ti_admin_init() {
		register_setting("ti","ti_xapi_endpoint_url");
		register_setting("ti","ti_xapi_username");
		register_setting("ti","ti_xapi_password");
	}

	/**
	 * Create settings page.
	 */
	public function ti_create_settings_page() {
		$settingsPageController=new SettingsPageController();
		$settingsPageController->process();
	}

	/**
	 * Handle the track-listing short_code.
	 */
	public function ti_track_listing() {
		$parentId=get_the_ID();
		$pages=get_pages(array(
			"parent"=>$parentId
		));

		$out = '<div class="masonry-loop">';

		foreach ($pages as $page) {
			if ($page->ID!=$parentId) {
    		$page->swagpaths = count(get_pages(array('child_of'=>$page->ID)));
				$out.=render_tpl(__DIR__."/tpl/tracklisting.php",array(
					"page"=>$page
				));
			}
		}
    $out .= '</div>';
    return $out;
	}


	/**
	 * Handle the course-listing short code.
	 */
	public function ti_course_listing() {
		$swagUser=new SwagUser(wp_get_current_user());
		$parentId=get_the_ID();

		$q=new WP_Query(array(
			"post_type"=>"any",
			"post_parent"=>$parentId,
			"posts_per_page"=>-1
		));

		$pages=$q->get_posts();

		$out = '<div class="masonry-loop">';

		$unpreparedCount=0;
		foreach ($pages as $page) {
			if ($page->ID!=$parentId) {
				$swagPost=new SwagPost($page);
				$prepared=$swagUser->isSwagCompleted($swagPost->getRequiredSwag());
				$completed=$swagUser->isSwagCompleted($swagPost->getProvidedSwag());

				if (!$swagPost->getProvidedSwag())
					$completed=FALSE;

				if (!$prepared)
					$unpreparedCount++;

				$out.=render_tpl(__DIR__."/tpl/courselisting.php",array(
					"page"=>$page,
					"prepared"=>$prepared,
					"completed"=>$completed
				));
			}
		}

		if ($unpreparedCount) {
			$out.=render_tpl(__DIR__."/tpl/afterlisting.php",array(
				"unprepared"=>$unpreparedCount
			));
		}


		$out .= '</div>';

		return $out;
	}

	/**
	 * Scripts and styles in the plugin
	 */
	public function ti_enqueue_scripts() {
		wp_register_style("wp_swag",plugins_url( "/style.css", __FILE__)); //?v=x added to refresh browser cache when stylesheet is updated.
		wp_enqueue_style("wp_swag");

		wp_register_script("d3",plugins_url("/d3.v3.min.js", __FILE__));
		wp_register_script("ti-main",plugins_url("/main.js", __FILE__));

		wp_enqueue_script("ti-main");

		wp_enqueue_script("d3");

	}

	/**
	 * Handle the course shortcode.
	 */
	function ti_course($args, $content) {
		if (!$args)
			$args=array();

		$swagPost=SwagPost::getCurrent();
		$swagUser=SwagUser::getCurrent();

		$selectedItem=$swagPost->getSelectedItem();
		$selectedItem->preShow();
		//print_r($selectedItem);

		$template=new Template(__DIR__."/tpl/course.php");
		$template->set("swagUser",$swagUser);
		$template->set("swagPost",$swagPost);

		/** Leson Plan Functionality */

		$template->set("showLessonPlan",FALSE);
		if (array_key_exists("lessonplan",$args) AND is_user_logged_in()) {
		$template->set("lessonPlan",get_home_url().'/wp-content/uploads'.$args["lessonplan"]);
		$template->set("showLessonPlan",TRUE);}

		if ($swagUser->isSwagCompleted($swagPost->getProvidedSwag())) {
			$template->set("lessonplanAvailable",TRUE);
		}
		else if  (current_user_can('edit_others_pages') || get_the_author_id() == get_current_user_id()) {
			$template->set("lessonplanAvailable",TRUE);
		}
		else {
			$template->set("lessonplanAvailable",FALSE);
		}


		$template->set("showHintInfo",FALSE);
		if (!$swagUser->isSwagCompleted($swagPost->getRequiredSwag())) {
			$template->set("showHintInfo",TRUE);

			$uncollected=$swagUser->getUncollectedSwag($swagPost->getRequiredSwag());
			$uncollectedFormatted=array();

			foreach ($uncollected as $swag)
				$uncollectedFormatted[]="<b>$swag</b>";

			$swagpaths=SwagPost::getPostsProvidingSwag($uncollected);
			$swagpathsFormatted=array();

			foreach ($swagpaths as $swagpath)
				$swagpathsFormatted[]=
					"<a href='".get_post_permalink($swagpath->ID)."'>".
					$swagpath->post_title.
					"</a>";

			$template->set("uncollectedSwag",join(", ",$uncollectedFormatted));
			$template->set("uncollectedSwagpaths",join(", ",$swagpathsFormatted));
		}

		return $template->render();
	}

	/**
	 * Render swagmap.
	 */
	public function ti_swagmap() {
		$plugins_uri = self::$plugins_uri;
		return "<div id='swagmapcontainer'>
		<div id='swag_description_container'>A swagmap is gamified display of performance. The green hollow nodes indicate the swagpath is not completed or attempted while non-hollow green nodes indicate the swagpaths is completed and questions answered.
		</div>
		<script>
		var PLUGIN_URI = '$plugins_uri';
		</script>
		</div>";
	}

	/**
	 * Here we have the chance to modify the statement before it is saved.
	 */
	public function ti_xapi_pre_save($statement) {
		return $statement;
	}

	/**
	 * Act on completed xapi statements.
	 * Save xapi statement for swag if applicable.
	 */
	public function ti_xapi_post_save($statement) {
		if ($statement["verb"]["id"]!="http://adlnet.gov/expapi/verbs/completed")
			return;

		foreach ($statement["context"]["contextActivities"]["grouping"] as $groupingActivity) {
			$id=url_to_postid($groupingActivity["id"]);
			if ($id)
				$postId=$id;
		}

		foreach ($statement["context"]["contextActivities"]["category"] as $categoryActivity) {
			$id=url_to_postid($categoryActivity["id"]);
			if ($id)
				$postId=$id;
		}

		if (!$postId)
			return;

		$post=get_post($postId);

		if (!$post)
			return;

		$swagUser=SwagUser::getByEmail($statement["actor"]["mbox"]);
		$swagPost=new SwagPost($post);
		$swagPost->saveProvidedSwagIfCompleted($swagUser);
	}

	public function ti_my_swag() {
		$plugins_uri = self::$plugins_uri;
		$swagUser=new SwagUser(wp_get_current_user());
		$completedSwag=$swagUser->getCompletedSwag();

		$out="";

		foreach ($completedSwag as $swag) {
			$out.="<div class='swag-badge-container'>\n";
			$out.="<img class='swag-badge-image' src='$plugins_uri/img/badge.png'>\n";
			$out.="<div class='swag-badge-label'>$swag</div>\n";
			$out.="</div>\n";
		}

		return $out;
	}

	/**
	 * Provide settings for wp-h5p-xapi
	 */
	public function ti_xapi_h5p_auth_settings($arg) {
		return array(
			"endpoint_url"=>get_option("ti_xapi_endpoint_url"),
			"username"=>get_option("ti_xapi_username"),
			"password"=>get_option("ti_xapi_password")
		);
	}
}
