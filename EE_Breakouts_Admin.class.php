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
		}
	}



	private function _set_admin_page() {
		add_action('admin_menu', array( $this, 'setup_options_page' ) );
	}



	public function setup_options_page() {
		$this->_wp_slug = add_submenu_page('event_espresso', __('EE Breakouts Settings', 'event_espresso'), __('EE Breakouts', 'event_espresso', 'manage_options', 'ee_breakouts_admin', array($this, 'display_options_page') ) );
		add_action('admin_init', arraY( $this, 'init' ) );
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
		add_settings_section('ee_breakout_general_settings', '', array($this, 'general_section'), 'ee_breakouts_admin' );
		add_settings_field( 'ee_breakout_general_settings_fieldset', '', array( $this, 'general_section_fields'), 'ee_breakouts_admin', 'ee_breakout_general_settings' );
	}



	/**
	 * validates the input for the page
	 * @param  array $input $_POST params
	 * @return array        The validated $_POST array.
	 */
	public function validate_settings( $input ) {
		$newinput['breakout_page'] = (int) $input['breakout_page'];
		$newinput['breakout_categories'] = array_map( 'absint', $input['breakout_categories'] );
		return $newinput;
	}
	


	public function general_section() {
		return;
	}


	/**
	 * displays the options page
	 * @return string html for page
	 */
	public function display_options_page() {
		?>
		<div class="wrap ee-breakouts-admin">
			<div id="icon-options-general" class="icon32"></div>
			<h2><?php __('EE Breakouts Settings', 'event_espresso'); ?></h2>
			<div id="poststuff">
				<div id="post-body-content">
					<div class="form-wrap">
						<form action="options.php" method="post">
							<?php settings_fields('ee_breakout_options'); ?>
							<?php do_settings_sections('ee_breakout_general_settings'); ?>
							<span class="submit">
								<input type="submit" name="update_ee_breakout_options" value="Save Options" />
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

		
		//get all categories and setup category checkboxes
		$sql = "SELECT * FROM " . EVENTS_CATEGORY_TABLE . " c";
		$categories = $wpdb->get_results($sql . " ORDER BY c.id ASC");

		if ( !empty($categories) ) {
			$c_fields['breakout_categories'] = array(
				'type' => 'checkbox',
				'default' => $this->_settings['breakout_categories']
				);
			foreach ( $categories as $category ) {
				$c_fields['breakout_categories']['labels'][] = $category->name;
				$c_fields['breakout_categories']['values'][] = $category->id;
			}
		}

		$category_checks = EE_Form_Fields::get_form_fields_array($c_fields);
		
		//output the html for the forms
		?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row">
						<?php _e('Breakout Page', 'event_espresso'); ?>
						<p><?php _e('Please select which page you wish to use for the Breakout Session registration. Note that on this page you will need to add the <strong>[EE_BREAKOUTS]</strong> shortcode', 'event_espresso'); ?></p>
					</th>
					<td>
						<?php echo $page_select; ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<?php _e('Breakout Categories', 'event_espresso'); ?>
						<p><?php _e('Select which Event Categories you want us to display on the breakout registration page.  Typically you would create a category for each session block, and then assign breakout sessions created as events to each category they are offered in', 'event_espresso'); ?></p>
					</th>
				</tr>
			</tbody>
		</table> <!-- end .form-table -->
		<?php
	}

}// end class EE_Breakouts_Admin