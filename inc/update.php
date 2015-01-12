<?php
/**
 * Contains functions and tools for transitioning between Largo 0.3 and Largo 0.4
 */

/**
 * Returns current version of largo
 */
function largo_version() {
	$theme = wp_get_theme();
	$parent = $theme->parent();
	if (!empty($parent))
		return $parent->get('Version');
	return $theme->get('Version');
}

/**
 * Checks if widgets need to be placed by checking old theme settings
 */
function largo_need_updates() {
	// try to figure out which versions of the options are stored. Implemented in 0.3
	if (of_get_option('largo_version')) {
		$compare = version_compare(largo_version(), of_get_option('largo_version'));
		if ($compare == 1)
			return true;
		else
			return false;
	}

	// if 'largo_version' isn't present, the settings are old!
	return true;
}

/**
 * Performs various database updates upon Largo version change. Fairly primitive as of 0.3
 *
 * @since 0.3
 */
function largo_perform_update() {
	if (largo_need_updates()) {
		largo_update_widgets();
		largo_home_transition();
		largo_transition_nav_menus();
		of_set_option('single_template', 'classic');
		of_set_option('largo_version', largo_version());
	}

	if ( is_admin() ) {
		largo_check_deprecated_widgets();
	}
}
add_action( 'widgets_init', 'largo_perform_update', 20 );

/**
 * Upgrades for moving from 0.3 to 0.4
 * In which many theme options became widgets
 * And homepage templates are implemented
 */

/**
 * Convert old theme option of 'homepage_top' to new option of 'home_template'
 */
function largo_home_transition() {
	$old_regime = of_get_option('homepage_top', 0);
	$new_regime = of_get_option('home_template', 0);

	// we're using the old system and the new one isn't in place, act accordingly
	// this should ALWAYS happen when this function is called, as there's a separate version check before this is invoked
	// the home template sidebars have same names as old regime so that *shouldn't* be an issue
	if ($old_regime && !$new_regime) {
		if ($old_regime == 'topstories')
			$home_template = 'TopStories';
		if ($old_regime == 'slider')
			$home_template = 'HomepageBlog';
		if ($old_regime == 'blog')
			$home_template = 'HomepageBlog';
		of_set_option('home_template', $home_template);
	} else
		of_set_option('home_template', 'HomepageBlog');
}

/**
 * Puts new widgets into sidebars as appropriate based on old theme options
 */
function largo_update_widgets() {

	/* checks and adds if necessary:
		social_icons_display ('btm' or 'both')
		add series widget
		show_tags
		show_author_box
		show_related_content
		show_next_prev_nav_single
	*/
	$checks = array();

	$checks['social_icons_display'] = array(
		'values' => array('btm', 'both'),
		'widget' => 'largo-follow',
		'settings' => array( 'title' => '' ),
	);

	//this is a dummy check
	$checks['in_series'] = array(
		'values' => NULL,
		'widget' => 'largo-post-series-links',
		'settings' => array( 'title' => __( 'Related Series', 'largo' ) ),
	);

	$checks['show_tags'] = array(
		'values' => array(1),
		'widget' => 'largo-tag-list',
		'settings' => array('title' => __( 'Filed Under:', 'largo' ), 'tag_limit' => 20),
	);

	$checks['show_author_box'] = array(
		'values' => array('1'),
		'widget' => 'largo-author-bio',
		'settings' => array('title' => __( 'Author', 'largo' ) ),
	);

	$checks['show_related_content'] = array(
		'values' => array('1'),
		'widget' => 'largo-explore-related',
		'settings' => array('title' => __( 'More About', 'largo' ), 'topics' => 6, 'posts' => 3),
	);

	$checks['show_next_prev_nav_single'] = array(
		'values' => array('1'),
		'widget' => 'largo-prev-next-post-links',
		'settings' => array(),
	);

	//loop thru, see if value is present, then see if widget exists, if not, create one
	foreach( $checks as $option => $i ) {
		$opt = of_get_option( $option );
		if ( $i['values'] === NULL || in_array($opt, $i['values']) ) {
			//we found an option that suggests we need to add a widget.
			//if there's not aleady one present, add it
			if ( !largo_widget_in_region( $i['widget'] ) ) {
				largo_instantiate_widget( $i['widget'], $i['settings'], 'article-bottom');
			}
		}
	}
}

/**
 * Checks to see if a given widget is in a given region already
 */
function largo_widget_in_region( $widget_name, $region = 'article-bottom' ) {

	$widgets = get_option( 'sidebars_widgets ');

	if ( !isset( $widgets[$region] ) ) {
		return new WP_Error( 'region-missing', __('Invalid region specified.', 'largo' ) );
	}

	foreach( $widgets[$region] as $key => $widget ) {
		if ( stripos( $widget, $widget_name ) === 0 ) return true;	//we found a copy of this widget! Note this may return a false positive if the widget we're checking is the same name (but shorter) as another kind of widget
	}
	return false;	// the widget wasn't there

}

/**
 * Inserts a widget programmatically.
 * This is slightly dangerous as it makes some assumptions about existing plugins
 * if $instance_settings are wrong, bad things might happen
 */
function largo_instantiate_widget( $kind, $instance_settings, $region ) {

	$defaults = array(
		'widget_class' => 'default',
		'hidden_desktop' => 0,
		'hidden_tablet' => 0,
		'hidden_phone' => 0,
		'title_link' => ''
	);

	$instance_id = 2; 	// default, not sure why it always seems to start at 2
	$full_kind = 'widget_' . str_replace("_", "-", $kind) . '-widget';

	// step 1: add the widget instance to the database and get the ID
	$widget_instances = get_option( $kind );

	// no instances of this exist, yay
	if ( !$widget_instances ) {
		update_option( $full_kind,
			array(
				2 => wp_parse_args( $instance_settings, $defaults ),
				'_multiwidget' => 1,
			)
		);

	} else {
		//figure out what ID we're creating. Don't just use count() as things might get deleted or something...
		//there's probably a smarter way to do this...
		while ( array_key_exists( $instance_id, $widget_instances) ) {
			$instance_id++;
		}

		//pop off _multiwidget, add our element to the end, then add _multiwidget back
		$new_instances = array_pop( $widget_instances );
		$new_instances[ $instance_id ] = wp_parse_args( $instance_settings, $defaults );
		$new_instances[ '_multiwidget' ] = 1;
		update_option( $full_kind, $new_instances );
	}

	// step 2: add the widget instance we just created to the region; this isn't so bad
	$region_widgets = get_option( 'sidebars_widgets' );
	$region_widgets[ $region ][] = $kind . '-widget-' . $instance_id;
	update_option( 'sidebars_widgets', $region_widgets );

}

/**
 * Checks for use of deprecated widgets and posts an alert
 */
function largo_check_deprecated_widgets() {

	$deprecated = array(
		'largo-footer-featured' => 'largo_deprecated_footer_widget',
		'largo-sidebar-featured' => 'largo_deprecated_sidebar_widget'
	);

	$widgets = get_option( 'sidebars_widgets ');
	foreach ( $widgets as $region => $widgets ) {
		if ( $region != 'wp_inactive_widgets' && $region != 'array_version' && is_array($widgets) ) {
			foreach ( $widgets as $widget_instance ) {
				foreach ( $deprecated as $widget_name => $callback ) {
					if (strpos($widget_instance, $widget_name) === 0) {
						add_action( 'admin_notices', $callback );
						unset( $deprecated[$widget_name] ); //no need to flag the same widget multiple times
					}
				}
			}
		}
	}
}

/**
 * Admin notices of older widgets
 */
function largo_deprecated_footer_widget() { ?>
	<div class="update-nag"><p>
	<?php printf( __('You are using the <strong>Largo Footer Featured Posts</strong> widget, which is deprecated and will be removed from future versions of Largo. Please <a href="%s">change your widget settings</a> to use its replacement, <strong>Largo Featured Posts</strong>.', 'largo' ), admin_url( 'widgets.php' ) ); ?>
	</p></div>
	<?php
}

function largo_deprecated_sidebar_widget() { ?>
	<div class="update-nag"><p>
	<?php printf( __( 'You are using the <strong>Largo Sidebar Featured Posts</strong> widget, which is deprecated and will be removed from future versions of Largo. Please <a href="%s">change your widget settings</a> to use its replacement, <strong>Largo Featured Posts</strong>.', 'largo' ), admin_url( 'widgets.php' ) ); ?>
	</p></div>
	<?php
}

function largo_transition_nav_menus() {
	$locations = get_nav_menu_locations();
	$main_nav = wp_get_nav_menu_object('Main Navigation');
	if (!$main_nav) {
		$main_nav_id = wp_create_nav_menu('Main Navigation');
		$locations['main-nav'] = $main_nav_id;
	} else {
		$locations['main-nav'] = $main_nav->term_id;
	}

	// Get the menu items for each menu
	$existing_items = array();
	foreach ($locations as $location => $id)
		$existing_items[$location] = wp_get_nav_menu_items($id);

	// These nav menu locations/menus get folded into main-nav menu
	$transition = array('navbar-categories', 'navbar-supplemental', 'sticky-nav');

	// Move all the category, supplemental items to main-nav.
	// Remove category and supplemental navs.
	foreach ($transition as $location_slug) {
		if (isset($existing_items[$location_slug])) {
			$items = $existing_items[$location_slug];
			if ($items) {
				foreach ($items as $idx => $item) {
					$meta = get_metadata('post', $item->ID);
					$attrs = array(
						'menu-item-type' => $meta['_menu_item_type'][0],
						'menu-item-menu-item-parent' => $meta['_menu_item_menu_item_parent'][0],
						'menu-item-parent-id' => $meta['_menu_item_menu_item_parent'][0],
						'menu-item-object-id' => $meta['_menu_item_object_id'][0],
						'menu-item-object' => $meta['_menu_item_object'][0],
						'menu-item-target' => $meta['_menu_item_target'][0],
						'menu-item-classes' => $meta['_menu_item_classes'][0],
						'menu-item-xfn' => $meta['_menu_item_xfn'][0],
						'menu-item-url' => $meta['_menu_item_url'][0],
						'menu-item-title' => $item->post_title,
						'menu-item-attr-title' => $item->post_excerpt
					);
					wp_update_nav_menu_item($locations['main-nav'], $item->ID, $attrs);
				}
			}
			// Get rid of the menu
			wp_delete_nav_menu($locations[$location_slug]);
			unset($locations[$location_slug]);
		}
	}

	set_theme_mod('nav_menu_locations', $locations);
}