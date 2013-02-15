<?php
if (!defined('EE_BREAKOUTS_PATH') )
	exit('NO direct script access allowed');



class EE_Breakouts_Admin {

	/**
	 * holds array of $_REQUEST items.
	 * @var array
	 */
	private $req_data;



	/**
	 * holds the slug for the settings page
	 * @var string
	 */
	private $_wp_slug;




	/**
	 * holds the current settings (if present and if not we set defaults)
	 * @var array
	 */
	private $_settings;



	public function __construct() {
		if ( !is_admin() )
			return; //we should only run this on admin

		$this->_req_date = array_merge( $_GET, $_POST );

		if ( isset( $this->_req_data['page'] ) && $this->_req_data['page'] !== 'ee_breakouts_admin' )
			return; //getout we only need to do stuff when we're on the breakouts page.

		$this->_set_settings();

		$this->_set_admin_page();

	}



	private function _set_settings() {
		$this->_settings = get_option('espresso_breakout_settings');

		//defaults
		if ( empty( $this->_settings ) ) {
			$this->_settings['breakout_page'] = '';
			$this->_settings['breakout_categories'] = array();
			$this->_settings['breakout_main_event'] = '';
		}
	}



	private function _set_admin_page() {
		add_action('admin_menu', array( $this, 'setup_options_page' ), 15 );
	}



	public function setup_options_page() {
		$this->_wp_slug = add_submenu_page('event_espresso', __('EE Breakouts Settings', 'event_espresso'), __('EE Breakouts', 'event_espresso'), 'administrator', 'ee_breakouts_admin', array($this, 'display_options_page') );
		add_action('admin_init', array( $this, 'init' ) );
		add_action( 'load-' . $this->_wp_slug, array( $this, 'ee_breakouts_page_load' ) );
	}


	/**
	 * all stuff for on page load.
	 *
	 * Nothing here at the moment, but leaving this for future if needed.
	 * @return void
	 */
	public function ee_breakouts_page_load() {}


	public function init() {
		register_setting( 'ee_breakout_options', 'espresso_breakout_settings', array( $this, 'validate_settings' ) );
		$breakout_page_title = $this->breakout_page_field_title();
		$breakout_cats_title = $this->breakout_categories_field_title();
		$breakout_main_event_title = $this->breakout_main_event_title();
		add_settings_section('ee_breakout_general_settings', '', array($this, 'general_section'), 'ee_breakouts_admin' );
		add_settings_field( 'ee_breakout_page_fieldset', $breakout_page_title, array( $this, 'general_section_fields'), 'ee_breakouts_admin', 'ee_breakout_general_settings' );
		add_settings_field( 'ee_breakout_categories_fieldset', $breakout_cats_title, array( $this, 'general_cats_fields'), 'ee_breakouts_admin', 'ee_breakout_general_settings');
		add_settings_field( 'ee_breakout_main_event_fieldset', $breakout_main_event_title, array( $this, 'general_main_event_fields'), 'ee_breakouts_admin', 'ee_breakout_general_settings');
	}



	/**
	 * validates the input for the page
	 * @param  array $input $_POST params
	 * @return array        The validated $_POST array.
	 */
	public function validate_settings( $input ) {
		$input = empty($input) ? $_POST : $input;
		$newinput['breakout_page'] = (int) $input['breakout_page'];
		$newinput['breakout_categories'] = array_map( 'absint', $input['breakout_categories'] );
		$newinput['breakout_main_event'] = (int) $input['breakout_main_event'];

		//todo we need to go through the list of saved breakout categories, retrieve the attendee limit for each event assigned to that category and then save that limit somehow in the database reffed by category/event.  That way if an event is assigned to more than one category the limits will be saved PER category.  Or maybe this would be done on registration (which might be the best place to do it).
		return $newinput;
	}
	


	public function general_section() {
		echo __('Please select the breakout page and categories you will use for your breakouts registration', 'event_espresso');
	}


	/**
	 * displays the options page
	 * @return string html for page
	 */
	public function display_options_page() {
		?>
		<div class="wrap ee-breakouts-admin">
			<div id="icon-options-general" class="icon32"></div>
			<h2><?php _e('EE Breakouts Settings', 'event_espresso'); ?></h2>
			<div id="poststuff">
				<div id="post-body-content">
					<div class="form-wrap">
						<form action="options.php" method="post">
							<?php settings_fields('ee_breakout_options'); ?>
							<?php do_settings_sections('ee_breakouts_admin'); ?>
							<span class="submit">
								<input type="submit" class="button primary-button" name="update_ee_breakout_options" value="Save Options" />
							</span>
						</form>
					</div> <!-- end .form-wrap -->
				</div> <!-- end #post-body-content -->
			</div> <!-- end #poststuff -->
		</div> <!-- end .wrap -->
		<?php
	}




	/**
	 * outputs the fields for the settings page
	 * @return string html
	 */
	public function general_section_fields() {
		global $wpdb;
		//require form helper
		require_once EE_BREAKOUTS_PATH . 'helpers/EE_Form_Fields.helper.php';
		$settings_ref = 'espresso_breakout_settings';

		//get all wp_pages and setup page selector.
		$pages = get_pages();
		$page_values = array();
		foreach ( $pages as $page ) {
			$page_values[] = array(
				'text' => $page->post_title,
				'id' => $page->ID
				);
		}

		$page_select = EE_Form_Fields::select_input( 'breakout_page', $page_values, $this->_settings['breakout_page'] );

		
		
		
		//output the html for the forms
		echo $page_select;
	}


	public function general_cats_fields() {
		global $wpdb;
		//require form helper
		require_once EE_BREAKOUTS_PATH . 'helpers/EE_Form_Fields.helper.php';
		$settings_ref = 'espresso_breakout_settings';

		//get all categories and setup category checkboxes
		$sql = "SELECT * FROM " . EVENTS_CATEGORY_TABLE . " c";
		$categories = $wpdb->get_results($sql . " ORDER BY c.id ASC");

		//setup categories for form_field helper
		foreach ( $categories as $category ) {
			$cats[] = $category->id;
			$catlabs[] = $category->category_name;
		}

		if ( !empty($categories) ) {
			$c_fields['breakout_categories'] = array(
				'type' => 'checkbox',
				'value' => $cats,
				'labels' => $catlabs,
				'default' => $this->_settings['breakout_categories']
				);
			foreach ( $categories as $category ) {
				$c_fields['breakout_categories']['labels'][] = $category->name;
				$c_fields['breakout_categories']['values'][] = $category->id;
			}
		}

		$category_checks = EE_Form_Fields::get_form_fields_array($c_fields);

		$cats = $category_checks['breakout_categories'];

		//output the html
		?>
		<ul class="ee-breakout-categories-list">
		<?php
			for ( $i = 0; $i < count($cats['label']); $i++ ) {
				echo '<li>';
				echo $cats['field'][$i];
				echo '</li>';
			}
		?>
		</ul>
		<?php
	}




	public function general_main_event_fields() {
		global $wpdb;
		//require form helper
		require_once EE_BREAKOUTS_PATH . 'helpers/EE_Form_Fields.helper.php';
		$settings_ref = 'espresso_breakout_settings';

		//query to get all events
		$SQL = "SELECT e.id as ID, e.event_name as event_name FROM " . EVENTS_DETAIL_TABLE . " AS e";

		$events = $wpdb->get_results( $SQL );

		$event_values = array();
		foreach ( $events as $event ) {
			$event_values[] = array(
				'text' => $event->event_name,
				'id' => $event->ID
				);
		}

		$event_select = EE_Form_Fields::select_input( 'breakout_main_event', $event_values, $this->_settings['breakout_main_event'] );

		//output the html for the forms
		echo $event_select;
	}


	public function breakout_page_field_title() {
		$content = '<h3>' . __('Breakout Page', 'event_espresso') . '</h3>';
		$content .= '<p>' . __('Please select which page you wish to use for the Breakout Session registration. Note that on this page you will need to add the <strong>[EE_BREAKOUTS]</strong> shortcode', 'event_espresso') . '</p>';
		return $content;
	}


	public function breakout_categories_field_title() {
		$content = '<h3>' . __('Breakout Categories', 'event_espresso') . '</h3>';
		$content .= '<p>' .  __('Select which Event Categories you want us to display on the breakout registration page.  Typically you would create a category for each session block, and then assign breakout sessions created as events to each category they are offered in', 'event_espresso') . '</p>';
		return $content;
	}

	public function breakout_main_event_title() {
		$content = '<h3>' . __('Breakout Main Event', 'event_espresso') . '</h3>';
		$content .= '<p>' .  __('Select from the list of events, which one is the "Main Event" that users have registered with.  This is the event that all the breakout sessions happen at.', 'event_espresso') . '</p>';
		return $content;
	}

}// end class EE_Breakouts_Admin