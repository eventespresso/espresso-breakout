<?php
if (!defined('EE_BREAKOUTS_PATH') )
	exit('NO direct script access allowed');


/**
	 * STEP ONE
	 * ******
	 * user visits a special "breakout" registration page. They will enter a transaction ID for the main event to gain access.
	 * The breakout page is setup by using a new shortcode [ESPRESSO_BREAKOUT];
	 *
	 * STEP TWO
	 * *******
	 * system verifies transaction ID 
	 * Warning message if not verified (or all slots for that id have been used up)
	 * If transaction ID is verified then users will be shown a list of info for all the breakout sessions.
	 * If transation ID is verified, the system will do a "soft lock" on the transaction count to make sure that if a bunch of people register with that id at once they will still decrement counts.  The soft lock will be a transient that expires after 10 minutes (or when its deleted at the end of the complete registration).
	 * A button will float down as they are reading that will take them to the selection page for breakout sessions.
	 *
	 * STEP THREE
	 * ********
	 * There will be a page with a label for each Breakout (Breakout 1, 2, 3, 4)
	 * Underneath will be a dropdown of events assigned to the corresponding breakout category and customers pick from the dropdown which session they want to register for.  NOTE only sessions which still have available spots available will be listed.  
	 * At the bottom is a form for the registration data to be collected and the submit button
	 *
	 * STEP FOUR
	 * *********
	 * On submit.  The system will do one final check to see if all the events the customer selected are still open (i.e. while they were registering someone else didn't take a remaining spot). 
	 * If fail, then they will be returned to their form and they will be given a message indicating which events are no longer available, giving them the option to select from the new dropdown list which they wish to register for.
	 * If success, the system will delete the soft lock for the transaction_id count and permanentlyl record the remaining spots for the transaction id.
	 * The attendee will be registered for the breakout sessions they selected.
	 * An email will be sent to the attendee listing all the breakouts they successfully registered for.
	 *
	 * 
	 */

class EE_Breakouts_Main {


	/**
	 * This property will hold the page id for the breakout page (set via options)
	 * @var int
	 */
	private $_breakout_page;



	/**
	 * holds the url for the breakout page (derived as the permalink from what is in $_breakout_page);
	 * @var string
	 */
	private $_breakout_url;




	/**
	 * This will hold the settings for the plugin.
	 * @var array
	 */
	private $_settings = array();





	/**
	 * This will hold the event categories that hold the events in breakout sessions.
	 * @var array
	 */
	private $_breakout_categories = array();





	/**
	 * This array holds the page routes array (which directs to the appropriate function when an route is requested).
	 * @var array
	 */
	private $_page_routes = array();



	/**
	 * holds the current route
	 * @var string
	 */
	private $_route = '';





	/**
	 * This simply lets us know if we're loading a UI or not.
	 * @var boolean
	 */
	private $_is_UI = TRUE;



	/**
	 * This holds all the values that get used in a template.
	 * defaults are set in _set_props() method.
	 * 
	 * @var array
	 */
	private $_template_args = array();




	/**
	 * This property will hold the output of the template parser for use in replacing the breakout shortcode.
	 * @var string
	 */
	private $_content = '';




	/**
	 * This flags whether this is an AJAX request or not.
	 * @var boolean
	 */
	private $_doing_ajax = FALSE;





	/**
	 * This will hold all the request data incoming
	 * @var array
	 */
	private $_req_data = array();



	/**
	 * This holds the $_SESSION array for easier retrieval through the class.
	 * @var array
	 */
	private $_session = array();




	/**
	 * holds the nonce ref for the current route.
	 * @var string
	 */
	private $_req_nonce = '';
	


	public function __construct() {

		if ( is_admin() ) {
			//pass things off to the admin controller.
			$this->_admin();
			return;
		}
		
		$this->_set_props();

		//check if we need to go any further.
		if ( empty( $this->_settings ) || ( isset($_REQUEST['page_id'] ) && $_REQUEST['page_id'] != $this->_breakout_page ) ) 
			return; //getout no settings or we aren't on a breakout page.


		$this->_init(); //run all hooks that run on load.
		$this->_set_page_routes();

		//todo: remember that for route requests, any UI elements get saved to a var on page load.  Then THAT var gets parsed by the shortcode and displayed.  

		$this->_route_request();


	}



	private function _admin() {
		require_once EE_BREAKOUTS_PATH . 'EE_Breakouts_Admin.class.php';
		$admin = new EE_Breakouts_Admin();
	}




	private function _set_props() {
		$this->_settings = get_option('espresso_breakout_settings');
		if ( empty( $this->_settings ) ) 
			return; //get out because we don't have any saved settings yet.

		//this sets up the props for the class.
		$this->_breakout_page = $this->_settings['breakout_page'];
		$this->_breakout_categories = $this->_settings['breakout_categories'];
		$this->_breakout_url = get_permalink( $this->_breakout_page );


		//request data (in favor of posts).
		$this->_req_data = array_merge( $_GET, $_POST );


		//set UI
		$this->_is_UI = isset( $this->_req_data['noheader'] ) ? FALSE : TRUE;

		//template_args defaults.
		$this->_template_args = array(
			'main_header' => '',
			'main_content' => '',
			'main_footer' => '',
			'form_fields' => array(),
			'submit_button' => ''
			);

		//ajax?
		$this->_doing_ajax = defined( 'DOING_AJAX' ) ? TRUE : FALSE;
	}





	private function _init() {
		add_action('init', array( $this, 'start_session' ) );
		add_shortcode( 'EE_BREAKOUTS', array( $this, 'parse_shortcode' ) );
	}




	private function _set_page_routes() {
		$this->_page_routes = array(
			'default' => '_load_registration_check_form',
			'registration_verify' => array(
				'func' => '_verify_registration_id',
				'noheader' => TRUE
				),
			'breakout_info' => '_display_breakouts',
			'breakout_registration' => '_display_breakout_registration',
			'breakout_process' => array(
				'func' => '_process_breakout_registration',
				'noheader' => TRUE
				 ),
			'breakout_complete' => '_breakout_finished'
			);

		//set the current route
		$this->_route = isset( $this->_req_data['route'] ) ? $this->_req_data['route'] : 'default';
		$this->_req_nonce = $this->_route . '_nonce';
	}




	private function _route_request() {

		//nonce check (but only when not on default);
		if ( $this->_route != 'default' ) {
			$nonce = isset( $this->_req_data['_wpnonce'] ) ? $this->_req_data['_wpnonce'] : '';
			if ( !wp_verify_nonce( $nonce, $this->_req_nonce ) ) {
				wp_die( sprintf(__('%sNonce Fail.%s' , 'event_espresso'), '<a href="http://www.youtube.com/watch?v=56_S0WeTkzs">', '</a>' ) );
			}

			//we also check if valid registration access has been set in the session
			if ( !isset( $this->_session['registration_valid'] ) )
				wp_die( sprintf( __('<strong>Invalid Session</strong> This page cannot be accessed without a valid registration id entered', 'event_espresso') ) );
		}

		//made it here so let's do the route
		$this->_do_route();
	}



	private function _do_route() {
		$func = FALSE;
		$args = array();

		//check that the requested page route exists
		if ( !array_key_exists( $this->_route, $this->_page_routes ) ) {
			wp_die(__('Sorry, but the url you are trying to access is not correct', 'event_espresso') ); //todo: proper error handling.
		}

		//let's set is_UI based on what is in the route array.
		$this->_is_UI = is_array($this->_page_routes[$this->_route]) && isset( $this->_page_routes[$this->_route]['noheader'] ) && $this->_page_routes[$this->_route]['noheader'] ? FALSE : $this->_is_UI;


		//check if callback has args
		if ( is_array( $this->_page_routes[$this->_route] ) ) {
			$func = $this->_page_routes[$this->_route]['func'];
			$args = isset( $this->_page_routes[$this->_route]['args'] ) ? $this->_page_routes[$this->_route]['args'] : array();
		} else {
			$func = $this->_page_routes[$this->_route];
		}

		if ( $func ) {
			// and finally,  try to access page route
			if ( call_user_func_array( array( $this, &$func  ), $args ) === FALSE ) {
				// user error msg
				$error_msg =  __( 'An error occured. The  requested page route could not be found. Please notify support.', 'event_espresso' );
				// developer error msg
				$error_msg .= '||' . sprintf( __( 'Page route "%s" could not be called. Check that the spelling for method names and actions in the "_page_routes" array are all correct.', 'event_espresso' ), $func );
				wp_die ( $error_msg ); //todo proper error handling.
			}
		}
	}



	/**
	 * start the breakout session.
	 *
	 * @access public
	 * @return void 
	 */
	public function start_session() {
		
		if ( !isset($_SESSION) ) {
			session_start();
		}


		//check to make sure we don't already have a session.  If we don't then init vars.
		if ( !isset( $_SESSION['espresso_breakout_session']['id'] ) || empty( $_SESSION['espresso_breakout_session']['id'] ) ) {
			$_SESSION['espresso_breakout_session'] = array();
			$_SESSION['espresso_breakout_session']['id'] = session_id() . '-' . uniqid('', true);
			$_SESSION['espresso_breakout_session']['registration_valid'] = FALSE;
		}
		
		$this->_session = $_SESSION['espresso_breakout_session'];
	}


	/** ROUTE HANDLING **/

	//todo: finish filling out these methods.
	private function _load_registration_check_form() {
		$this->_set_form_tags();

		$this->_set_submit( __('Go', 'event_espresso' ), 'registration_verify' );

		//set up fields
		$form_fields['ee_breakout_registration_id'] = array(
			'label' => __('Registration ID', 'event_espresso'),
			'extra_desc' => __('Enter in the transaction ID for the main event registration', 'event_espresso'),
			'type' => 'text',
			'class' => 'normal-text-field'
			);

		$this->_form_fields($form_fields);
		$template_path = EE_BREAKOUTS_TEMPLATE_PATH . 'ee_breakouts_transaction_id_request_form.template.php';
		$this->_template_args['main_content'] = $this->display_template( $template_path, $this->_template_args );
		$this->_set_content();
	}



	/**
	 * verifies the entered registration id and if it is valid then we set continue the session and go to the breakout registration form.	
	 *
	 * @access private
	 * @return void
	 */
	private function _verify_registration_id() {
		$success = TRUE;
		if ( !isset( $this->_req_data['ee_breakout_registration_id']) ) {
			$msg = __('There is no value for the registration id check.  Something went wrong', 'event_espresso');
			EE_Error::add_error( $msg, __FILE__, __FUNCTION__, __LINE__ );
			$route = 'default';
			$success = FALSE;
		} else {
			//check that the registration id exists in the database!
			$quantity = $this->_check_reg_id( $this->_req_data['ee_breakout_registration_id'] );

			//pass or fail?
			if ( !$quantity ) {
				EE_Error::add_error( __('Sorry but the registration id you entered is invalid. Please doublecheck and make sure you are entering it correctly', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__ );
				$route = 'default'; 
				$success = FALSE;
			}

			//made it here. Let's just quickly set the session for registration id
			$this->_session['registration_id'] = $this->_req_data['ee_breakout_registration_id'];

			//we pass. but we also need to verify that the count of people who have already registered using this registration id has not been exceeded. 
			$can_register = $this->_check_reg_count( $quantity );

			if ( !$can_register ) {
				EE_Error::add_error( __('Sorry, although your registration id is valid. The purchased tickets attached to that id have all been registered for breakout sessions. If you feel this is in error, please contact us.', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__ );
				$route = 'default';
				$success = FALSE;
			}

			//hey made it here so let's set the route and continue!
			EE_Error::add_success( __('Your registration ID is validated, please make your selections for the breakout sessions using this form and then submit to register.', 'event_espresso') );
			$route = 'breakout_info';

			//let's make sure that we set the valid session item
			$this->_session['registration_valid'] = TRUE;
		}

		//if there's an error then we can clear the session and it can be reset on reload.
		if ( !$success )
			$this->_clear_session();

		$this->_redirect_page($route);
	}





	/**
	 * This displays the breakout details.
	 *
	 * @todo: we're not going to do this page initially.  It can be added later. For now we'll just load the _display_breakout_registration.
	 *
	 * @access private
	 * @return void
	 */
	private function _display_breakouts() {
		$this->_route = 'breakout_registration';
		$this->_display_breakout_registration();
	}




	/**
	 * This displays the breakout registration form
	 * NOTE: we're just going to collect basic info on the breakout registration and for now this is not customizable.
	 *
	 * @access private
	 * @return string html form for breakout registration.
	 */
	private function _display_breakout_registration() {

		//todo left off here
		
	}




	private function _process_breakout_registration() {}
	private function _breakout_finished() {}

	/** end route handling **/


	/** TEMPLATING / HELPERS **/

	/**
	 * This takes care of clearing the ee_breakouts session
	 *
	 * @access private
	 * @return void 
	 */
	private function _clear_session() {
		if ( isset( $_SESSION['espresso_breakout_session'] ) ) {
			unset( $_SESSION['espresso_breakout_session'] );
			$this->_session = NULL;
		}
	}


	/**
	 * All this does is do the html for the form open close tags and sets them to the $_template_args['main_header'] and $_template_args['main_footer'] properties
	 *
	 * @param array $vals Sent array containing the required values for the form
	 * array(
	 * 	'query_args' => array(), //an array of args for the form url (we'll also attach them to hidden ids)
	 * 	'id' => 'some_id', //send an id for the form
	 * 	'name' => 'some_name', //used for the name of the form
	 * 	'method' => 'POST', //get or post?  default post
	 * );
	 *
	 * @access private
	 * @return void 
	 */
	private function _set_form_tags( $vals = array() ) { 
		$hidden_fields = $hidden_fields_send = array();

		$defaults = array(
			'query_args' => array(),
			'id' => $this->_route . '_form',
			'name' => $this->_route . '_form_data',
			'method' => 'POST'
			);

		$vals = wp_parse_args($vals, $defaults);

		extract($vals, EXTR_SKIP);

		//setup hidden fields
		foreach ( $query_args as $name => $value ) {
			$hidden_fields_send[$name] = array(
				'type' => 'hidden',
				'value' => $value,
				); 
		}

		$hidden_fields = $this->_form_fields( $hidden_fields_send, TRUE );

		//set form action
		$action = wp_nonce_url( add_query_arg( $query_args, $this->_breakout_url ), $this->_req_nonce );

		$this->_template_args['main_header'] = '<form id="' . $id . '" method="' . $method . '" name="' . $name . '" action="' . $action . '">';

		//let's add in the hidden fields
		foreach ( $hidden_fields as $name => $field_data ) {
			$this->_template_args['main_header'] .= $field_data['field'];
		}

		$this->_template_args['main_footer'] = '</form>';
	}


	/**
	 * All this does is take a properly formatted incoming array and assigns the generated fields to the $_template_args['form_fields'] property.  See EE_Form_Fields class for docs on how to format the array.
	 *
	 * @uses   EE_Form_Fields helper class
	 * @param  array  $fields formatted array for field generator
	 * @param bool $return if true we return the generated fields.  If false we set them to the _template_args['form_fields'] property.
	 * @return void         
	 */
	private function _form_fields( $fields = array(), $return = FALSE ) {
		require_once EE_BREAKOUTS_PATH . 'helpers/EE_Form_Fields.helper.php';
		$form_fields = EE_Form_Fields::get_form_fields_array($fields);

		if ( $return ) 
			return $form_fields;

		$this->_template_args['form_fields'] = $form_fields;
	}




	/**
	 * sets the $_template_args['submit_button'] argument
	 *
	 * @param string $label What to use as the label for the button
	 * @param string $to_route the route we want to redirect to after submit.
	 *
	 * @access private
	 * @return void
	 */
	private function _set_submit( $label, $to_route) {
		$id = $this->_route . '_submit_button';
		$name = 'submit_form';
		$value = $label;
		$redirect = wp_nonce_url( add_query_arg( array( 'route' => $to_route ), $this->_breakout_url ), $to_route . '_nonce' );

		//hidden field for redirect
		$hidden_fields['ee_breakout_redirect'] = array(
			'type' => 'hidden',
			'value' => $redirect
			);
		$field = $this->_form_fields($hidden_fields);
		$hidden_field = $field[0]['field'];

		//button
		$button = '<input class="ee-breakouts-button" type="submit" id="' . $id . '" name="' . $name . '" value="' . $value . '" />';

		$this->_template_args['submit_button'] = $hidden_field . $button;
	}



	/**
	 * All this does is set the _content property that will be used when the shortcode is parsed.
	 *
	 * @access private
	 * @return void
	 */
	private function _set_content() {

		$template_path = EE_BREAKOUTS_TEMPLATE_PATH . 'ee-breakouts-main-wrapper.template.php';
		$this->_template_args['notices'] = EE_Error::get_notices();
		$this->_content = $this->_display_template( $template_path, $this->_template_args );

	}


	/**
	 * This method just parses the EE_BREAKOUTS shortcode, replacing it with the _content property
	 * @return string contents of _content
	 */
	public function parse_shortcode($atts) {
		return $this->_content;
	}


	/**
	 * Load and return a template
	 *
	 * @param string $path_to_file server path to the file to be loaded, including the file name and extension
	 * @param array $template_args an array of arguments to be extracted for use in the template
	 * @param boolean $return_string whether to send output immediately to screen, or capture and return as a string.	
	 * @access private
	 * @return void
	 */
	private function _display_template( $path_to_file = FALSE, $template_args = FALSE, $return_string = TRUE ) {
		if (!$path_to_file) {
		return FALSE;
		}
		// if $template_args are not in an array, then make it so
		if (!is_array($template_args)) {
			$template_args = array($template_args);
		}

		extract($template_args);

		if ($return_string) {
			// becuz we want to return a string, we are going to capture the output
			ob_start();
			include( $path_to_file );
			$output = ob_get_clean();
			return $output;
		} else {
			include( $path_to_file );
		}
	}



	private function _redirect_page( $route = FALSE ) {
		if ( !isset( $this->_req_data['ee_breakout_redirect'] ) && !$route )
			wp_die( __('Something went wrong with a redirect. Please contact support', 'event_espresso' ) );

		//if we have notices let's include them
		$notices = EE_Error::get_notices();

		// if we have a given route then let's setup the url for it
		if ( $route ) {
			$redirect_url = wp_nonce_url( add_query_arg( array('route' => $route, 'notices' => $notices ) ), $route . '_nonce' );
		}
		$redirect_url = $route ? $redirect_url : $this->_req_data['ee_breakout_redirect'];

		$this->_update_session();

		wp_safe_redirect( $redirect_url );
		exit();
	}



	/**
	 * The purpose of this method is just to update the $_SESSION with any details that may have been added in the current route process
	 *
	 * @access private
	 * @return void
	 */
	private function _update_session() {
		$_SESSION['espresso_breakout_session'] = $this->_session;
	}


	

	/**
	 * This will just return a boolean depending on whether the given reg id is in the database or not.
	 *
	 * Note we are not only checking that the registration id matches one in the system but it also matches a registration attached to the MAIN event (set in the admin)!!
	 *
	 * @access private
	 * @param int $reg_id  The reg id to check. 
	 * @return mixed (bool|int) if false no match, otherwise we'll return the number of attendees registered with the id.
	 */
	private function _check_reg_id($reg_id) {
		global $wpdb;
		$main_event_id = $this->_settings['breakout_main_event'];
		$sql = "SELECT e.quantity FROM " . EVENTS_ATTENDEE_TABLE . " AS e WHERE e.registration_id = %s AND e.event_id = %s";
		$quantity = $wpdb->get_var( $wpdb->prepare( $sql, $reg_id, $main_event_id ) );

		if ( (int) $quantity > 0 ) 
			return $quantity;
		else 
			return FALSE;
	}



	/**
	 * This just checks the quantity of tickets attached to the main event registration id against the total of people who have ALREADY registered with that id and returns a boolean to indicate whether to continue things or not.
	 * @param  int $quantity quantity already retrieved for the current person registering
	 * @return bool           TRUE = okey dokey, FALSE = no go
	 */
	private function _check_reg_count( $quantity ) {
		//any registered already?
		$reg_count = (int) get_option( $this->_session['registration_id'] . '_breakout_count' );

		$happy = $quantity - $reg_count;
		if ( !$happy || $happy === '0' ) 
			return FALSE;

		return TRUE;
	}

}