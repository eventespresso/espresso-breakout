<?php
if (!defined('EE_BREAKOUTS_PATH') )
	exit('NO direct script access allowed');


/**
	 * STEP ONE
	 * ******
	 * user visits a special "breakout" registration page. They will enter a registration ID for the main event (that breakouts are attached to) to gain access.
	 * The breakout page is setup by using a new shortcode [EE_BREAKOUTS] and making sure the related options on the new options page are completed (instructions on the options page);
	 *
	 * STEP TWO
	 * *******
	 * system verifies registration ID 
	 * Warning message if not verified (or all slots for that id have been used up)
	 * If registration ID is verified then user will be sent to the next step.
	 *
	 * STEP THREE
	 * ********
	 * There will be a page with a label for each Breakout (Breakout 1, 2, 3, 4 et. The actual labels are the names of the categories the user selected as event categories breakout sessions (events) are assigned to)
	 * Beside is a dropdown of events assigned to the corresponding breakout category and customers pick from the dropdown which session they want to register for.  NOTE only sessions which still have available spots available will be listed.  
	 * At the bottom is a form for the registration data to be collected and the submit button
	 *
	 * STEP FOUR
	 * *********
	 * On submit.  The system does some validation checks. 
	 * If fail, then they will be returned to their form and they will be given a message indicating what didn't validate and inputs highlighted that they need to fix..
	 * If success, the system records and updates all counts for the breakout, increments the registrations for the registration_id.
	 * Session is also cleared.
	 * The attendee will be registered for the breakout sessions they selected.
	 * An email will be sent to the attendee listing all the breakouts they successfully registered for.
	 *
	 * NOTE: The session process used in this addon is fairly well protected and locked down.  If a user browses back or reloads the page in their browser, that will cancel their existing session and they will need to start over again.  This prevents multiple registrations while authed with a valid main event registration id.
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
	 * This just holds any transient data passed between routes
	 * @var array
	 */
	private $_route_transient = array();





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



	


	public function __construct() {

		if ( is_admin() ) {
			//pass things off to the admin controller.
			$this->_admin();
			return;
		}
		
		$this->_set_props();

		add_action( 'wp', array( $this, 'load_page' ), 10 );

	}


	public function load_page() {
		//check if we need to go any further.
		if ( empty( $this->_settings ) || ( get_the_ID() != $this->_breakout_page ) )
			return; //getout no settings or we aren't on a breakout page.

		$this->_set_page_routes();
		$this->_init(); //run all hooks that run on load.
		

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
		$this->_start_session();
		add_shortcode( 'EE_BREAKOUTS', array( $this, 'parse_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array($this, 'frontend_styles_scripts' ), 10 );
	}





	public function frontend_styles_scripts() {
		//styles
		wp_register_style( 'ee-breakouts-main', EE_BREAKOUTS_URL . 'assets/ee-breakouts-main.css', array(), EE_BREAKOUTS_VERSION );
		wp_enqueue_style( 'ee-breakouts-main' );
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
	}




	private function _route_request() {

		//session check but only when on default or registration verify
		if ( $this->_route != 'default' && $this->_route != 'registration_verify' ) {
	
			//we also check if valid registration access has been set in the session
			if ( ! $this->_session['registration_valid'] ) {
				wp_die( sprintf( __('<strong>Invalid Session</strong> This page cannot be accessed without a valid registration id entered. %sClick Here to start over%s', 'event_espresso'), '<a href="' . $this->_breakout_url . '">', '</a>' ), __('Error', 'event_espresso' ) );
			}

		}

		//do a check prevent refreshes on page routes (and duplicate registrations);
		if ( $this->_session['visited'] ) {
			wp_die( sprintf( __('<strong>Invalid Page Load</strong> It looks like you hit the back key or tried to refresh the page. To prevent possible duplicate registrations for breakout sessions, we have error controls in place.  You\'ll need to start again by %sclicking this link%s.', 'event_espresso'), '<a href="' . $this->_breakout_url . '">', '</a>' ), __('Error', 'event_espresso' ) );
		} else {
			$this->_session['visited'] = $this->_route;
			$this->_update_session();
		}

		//made it here so let's do the route
		$this->_do_route();
	}



	private function _do_route() {
		$func = FALSE;
		$args = array();

		//check that the requested page route exists
		if ( !array_key_exists( $this->_route, $this->_page_routes ) ) {
			wp_die(__('Sorry, but the url you are trying to access is not correct', 'event_espresso') ); 
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
				wp_die( $error_msg ); 
			}
		}
	}



	/**
	 * start the breakout session.
	 *
	 * @access public
	 * @return void 
	 */
	private function _start_session() {

		//first let's see if the user has not been active, we'll give them one hour.  If they are inactive after one hour then we remove their session.
		$this->_check_auto_end_session();
		
		$this->_session = isset($_SESSION['espresso_breakout_session']) ? $_SESSION['espresso_breakout_session'] : array();

		
		//only do this if _session isn't empty
		if ( !empty($this->_session ) ) {
			//we always get notices first (and any transient data).. then if this is default route we clear the session because default ALWAYS clears session
			$this->_template_args['notices'] = $this->_display_notices();
			$this->_route_transient = $this->_get_transient();


			if ( $this->_route == 'default' ) {
				$this->_clear_session();
			}
		}
		

		//check to make sure we don't already have a session.  If we don't then init vars.
		if ( !isset( $_SESSION['espresso_breakout_session']['id'] ) || empty( $_SESSION['espresso_breakout_session']['id'] ) ) {
			$_SESSION['espresso_breakout_session'] = array();
			$_SESSION['espresso_breakout_session']['id'] = session_id() . '-' . uniqid('', true);
			$_SESSION['espresso_breakout_session']['transient_id'] = uniqid();
			$_SESSION['espresso_breakout_session']['registration_valid'] = FALSE;
			$_SESSION['espresso_breakout_session']['expiry'] = time();
		}
		
		$this->_session = $_SESSION['espresso_breakout_session'];
		$this->_session['visited'] = isset($this->_session['visited']) && $this->_session['visited'] == $this->_route ? TRUE : FALSE;
	}


	/** ROUTE HANDLING **/

	private function _load_registration_check_form() {
		$this->_set_form_tags( array( 'query_args' => array('route' => 'registration_verify' ) ) );

		//chck if we've got any transported data from a previous session
		$data = $this->_route_transient;

		$this->_set_submit( __('Go', 'event_espresso' ), 'registration_verify' );

		//set up fields
		$form_fields['ee_breakout_registration_id'] = array(
			'label' => __('Registration ID', 'event_espresso'),
			'extra_desc' => '<br /><span class="description">' . __('Enter in the transaction ID for the main event registration', 'event_espresso') . '</span><br />',
			'type' => 'text',
			'class' => 'normal-text-field',
			'value' => isset( $data['registration_id'] ) ? $data['registration_id'] : ''
			);

		$this->_form_fields($form_fields);
		$template_path = EE_BREAKOUTS_TEMPLATE_PATH . 'ee_breakouts_transaction_id_request_form.template.php';
		$this->_template_args['main_content'] = $this->_display_template( $template_path, $this->_template_args );
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
		if ( empty( $this->_req_data['ee_breakout_registration_id']) ) {
			$msg = __('There is no value for the registration id check.  Something went wrong. Are you SURE you entered something in the field?', 'event_espresso');
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

			if ( $success ) {
				//made it here. Let's just quickly set the session for registration id
				$this->_session['registration_id'] = $this->_req_data['ee_breakout_registration_id'];

				//we pass. but we also need to verify that the count of people who have already registered using this registration id has not been exceeded. 
				$can_register = $this->_check_reg_count( $quantity );

				 if ( !$can_register ) {
					EE_Error::add_error( __('Sorry, although your registration id is valid, the purchased tickets attached to that id have all been registered for breakout sessions. If you feel this is in error, please contact us.', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__ );
					$route = 'default';
					$success = FALSE;
				}
			}


			//if STILL no error let's set the route and continue!
			if ( $success ) {
				EE_Error::add_success( __('Your registration ID is validated, please make your selections for the breakout sessions using this form and then submit to register.', 'event_espresso') );
				$route = 'breakout_info';

				//let's make sure that we set the valid session item
				$this->_session['registration_valid'] = TRUE;
			}
		}

		//if there's an error then we can clear the session and it can be reset on reload.
		if ( !$success ) {
			$this->_add_transient( 'default', array( 'registration_id' => $this->_req_data['ee_breakout_registration_id'] ));
		}

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
		$this->_set_form_tags( array( 'query_args' => array('route' => 'breakout_process' ) ) );

		$this->_set_submit( __('Register', 'event_espresso' ), 'breakout_process' );

		//do we have any transient from the process route (validation errors)?
		$errors = $this->_route_transient;

		//setup fields
		//need to get the breakout categories and then get the breakout events in each category
		$category_name = '';
		$breakout_fields = array();
		$options = array();
		foreach ( $this->_breakout_categories as $category ) {
			$events = $this->_get_events_for_cat( $category );
			$options = array();
			if ( !empty( $events ) ) {
				foreach ( $events as $event ) {
					$category_name = $event->category_name;
					//first let's make sure the number registered for this event and category haven't been exceeded.
					if ( !$this->_check_event_reg_limit( $event->event_id, $category_name ) )
						continue;

					$options[] = array(
						'text' => $event->event_name,
						'id' => $event->event_id
						);
				}

				//don't show assemble field for the current breakout if there are no options.
				if ( empty($options) ) continue;

				//let's ADD a "select none" option
				$select_none = array(
					'text' => __('Choose this to not register for this session', 'event_espresso'),
					'id' => 'none'
					);
				array_unshift($options, $select_none);

				$select_fields_array = array(
					'name' => 'category_registration[' . $category . ']',
					'values' => $options,
					'default' => isset( $errors['breakout_selections'][$category]['value'] ) ? $errors['breakout_selections'][$category]['value'] : ''
				);

				$breakout_fields[$category] = array(
					'label' => $category_name,
					'select_field' => $this->_form_fields( $select_fields_array, TRUE, 'select' )
					);
			}
		}

		$this->_template_args['breakout_fields'] = $breakout_fields;

		//next we need to get registration details. first name, last name, email address.
		$registration_fields = array(
			'registration_info[first_name]' => array(
				'label' => __( 'First Name', 'event_espresso'),
				'type' => 'text',
				'class' => isset( $errors['first_name']['msg'] ) ? 'validate-error normal-text-field' : 'normal-text-field',
				'value' => isset( $errors['first_name']['value'] ) ? $errors['first_name']['value'] : ''
				),
			'registration_info[last_name]' => array(
				'label' => __( 'Last Name', 'event_espresso'),
				'type' => 'text',
				'class' => isset( $errors['last_name']['msg'] ) ? 'validate-error normal-text-field' : 'normal-text-field',
				'value' => isset( $errors['last_name']['value'] ) ? $errors['last_name']['value'] : ''
				),
			'registration_info[church_name]' => array(
				'label' => __('Church Name', 'event_espresso'),
				'type' => 'text',
				'class' => isset( $errors['church_name']['msg'] ) ? 'validate-error normal-text-field' : 'normal-text-field',
				'value' => isset( $errors['church_name']['value'] ) ? $errors['church_name']['value'] : ''
				),
			'registration_info[city]' => array(
				'label' => __('City/Town', 'event_espresso'),
				'type' => 'text',
				'class' => isset( $errors['city']['msg'] ) ? 'validate-error normal-text-field' : 'normal-text-field',
				'value' => isset( $errors['city']['value'] ) ? $errors['city']['value'] : ''
				),
			'registration_info[email_address]' => array(
				'label' => __( 'Email Address', 'event_espresso'),
				'type' => 'text',
				'class' => isset( $errors['email_address']['msg'] ) ? 'validate-error normal-text-field' : 'normal-text-field',
				'value' => isset( $errors['email_address']['value'] ) ? $errors['email_address']['value'] : ''
				)
			);

		$this->_template_args['registration_fields'] = $this->_form_fields( $registration_fields, TRUE );
		$template_path = EE_BREAKOUTS_TEMPLATE_PATH . 'ee-breakouts-selection-registration-form.template.php';
		$this->_template_args['main_content'] = $this->_display_template( $template_path, $this->_template_args );
		$this->_set_content();
	}



	/**
	 * processed breakout registration form and then redirects accordingly.
	 *
	 * @access private
	 * @return void 
	 */
	private function _process_breakout_registration() {
		global $wpdb, $org_options;
		$data = array();
		$error = FALSE;
		$errors = array();
		$has_error = FALSE;
		$registration_error = FALSE;
		$same_events = array();
		//let's setup the data
		if ( is_array( $this->_req_data['category_registration'] ) ) {
			foreach ( $this->_req_data['category_registration'] as $cat_id => $evt_id ) {
				if ( $evt_id == 'none' ) continue;
				$data['breakout_selections'][] = array(
					'cat_id' => (int) $cat_id,
					'event_id' => (int) $evt_id
					);
				if ( !in_array( $evt_id, $same_events ) ) {
					$same_events[] = $evt_id;
				} else {
					$errors = TRUE;
				}
			}
		}


		

		if ( is_array( $this->_req_data['registration_info']) ) {
			$data['registration_info']['first_name'] = isset( $this->_req_data['registration_info']['first_name'] ) ? wp_kses_post($this->_req_data['registration_info']['first_name'] ) : '';
			$data['registration_info']['last_name'] = isset( $this->_req_data['registration_info']['last_name'] ) ? wp_kses_post( $this->_req_data['registration_info']['last_name'] ) : '';
			$data['registration_info']['church_name'] = isset( $this->_req_data['registration_info']['church_name'] ) ? wp_kses_post( $this->_req_data['registration_info']['church_name'] ) : '';
			$data['registration_info']['city'] = isset( $this->_req_data['registration_info']['city'] ) ? wp_kses_post( $this->_req_data['registration_info']['city'] ) : '';
			$data['registration_info']['email_address'] = isset( $this->_req_data['registration_info']['email_address'] ) ? is_email($this->_req_data['registration_info']['email_address'] ) : ''; 
		}

		//data is setup, now let's validate
		foreach ( $data['registration_info'] as $key => $value ) {
			if ( empty( $value ) ) {
				if ( $key == 'email_address' ) {
				 	EE_Error::add_error( __('Invalid Email Address. Please doublecheck and make sure its in the right format', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__);
				 } else {
				 	$registration_error = TRUE;
				 }
				 $errors[$key]['msg'] = TRUE;
			}
		}


		//if we've got errors then let's setup values for all the selections as well. Then we'll setup the return to the breakout selection field
		if ( !empty($errors) || $errors || $registration_error ) {
			$errors = (array) $errors;
			if ( isset( $data['breakout_selections'] ) && is_array( $data['breakout_selections'] ) ) {
				foreach ( $data['breakout_selections'] as $index => $values ) {
					$errors['breakout_selections'][$values['cat_id']]['value'] = $values['event_id'];

					if ( !in_array($values['event_id'], $same_events ) && !$has_error ) {
						$errors['breakout_selections'][$values['cat_id']]['msg'] = __('You cannot register for the same session in multiple breakout options. Please go over your list of picked options and make sure each session has a unique pick.', 'event_espresso');
						$has_error=TRUE;
						EE_Error::add_error( $errors['breakout_selections'][$values['cat_id']]['msg'], __FILE__, __FUNCTION__, __LINE__ );
					}
				}
			}

			//add errors for registration info
			foreach ( $data['registration_info'] as $key => $value ) {
				$errors[$key]['value'] = $this->_req_data['registration_info'][$key];
			}

			//add notices
			if ( $registration_error ) {
				EE_Error::add_error( __('All the indicated fields must be filled in and cannot be left empty.  Thank you.', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__ );
			}

			$this->_add_transient( 'breakout_registration', $errors );

			$this->_redirect_page( 'breakout_registration' );
		}

		//made it here so we can save the data
		//set a "group" for this registration (i.e. the attendee used the same details for multiple breakout events but each event has a separate attendee id for that registrant).
		$reg_group = uniqid();
		
		//loop through each breakout registration
		foreach ( $data['breakout_selections'] as $key => $breakout ) {
			$error = FALSE;
			$event_id = $breakout['event_id'];
			//common values needing generated
			$times_sql = "SELECT ese.start_time, ese.end_time, e.start_date, e.end_date ";
			$times_sql .= "FROM " . EVENTS_START_END_TABLE . " ese ";
			$times_sql .= "LEFT JOIN " . EVENTS_DETAIL_TABLE . " e ON ese.event_id = e.id WHERE ";
			$times_sql .= "e.id=%d";
			if (!empty($data_source['start_time_id'])) {
				$times_sql .= " AND ese.id=" . absint($data_source['start_time_id']);
			}

			$times = $wpdb->get_results($wpdb->prepare( $times_sql, $event_id ));
			foreach ($times as $time) {
				$start_time		= $time->start_time;
				$end_time		= $time->end_time;
				$start_date		= $time->start_date;
				$end_date		= $time->end_date;
			}

			//setup price type for this breakout session.
			$price_option = $this->_check_breakout_session_price_setup( $event_id, $breakout['cat_id'] );


			if ( $price_option ) {
			

				//here are all the vars to save
				$columns_and_values = array(
						'registration_id'		=> uniqid($breakout['event_id'] . '-'),
						'is_primary'			=> TRUE,
						'attendee_session'		=> $this->_session['id'],
						'lname'					=> $data['registration_info']['last_name'],
						'fname'					=> $data['registration_info']['first_name'],
						'address'				=> '',
						'address2'				=> '',
						'city'					=> $data['registration_info']['city'],
						'state'					=> '',
						'zip'					=> '',
						'email'					=> $data['registration_info']['email_address'],
						'phone'					=> '',
						'payment'				=> '',
						'txn_type'				=> '',
						'coupon_code'			=> '',
						'event_time'			=> $start_time,
						'end_time'				=> $end_time,
						'start_date'			=> $start_date,
						'end_date'				=> $end_date,
						'price_option'			=> $price_option,
						'organization_name'		=> $data['registration_info']['church_name'],
						'country_id'			=> '',
						'payment_status'		=> 'Completed',
						'payment_date'			=> date(get_option('date_format')),
						'event_id'				=> $event_id,
						'quantity'				=> 1,
						'amount_pd'				=> 0.00,
						'orig_price'			=> 0.00,
						'final_price'			=> 0.00
					);
					

				$data_formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f' );
				// save the attendee details - FINALLY !!!
				if ( ! $wpdb->insert( EVENTS_ATTENDEE_TABLE, $columns_and_values, $data_formats )) {
					$error = TRUE;
				}
			} else {
				$error = TRUE;
			}

			//do breakout session counts (if no error);
			if ( !$error ) {
				$data['breakout_selections'][$key]['breakout_session_name'] = $this->_update_breakout_session_counts( $breakout, $wpdb->insert_id, $reg_group );
				$data['breakout_selections'][$key]['breakout_name'] = get_event_field( 'event_name', EVENTS_DETAIL_TABLE, ' WHERE id = ' . $event_id );
			}
		}

		//update count for the registration id
		if ( !$error ) {
			$this->_update_reg_id_count();
		}


		if ( !$error ) {
			//made it here? everything must have been successful, so let's redirect to the complete page.
			EE_Error::add_success(__('Successfully registered for breakout sessions!', 'event_espresso') );

			$this->_send_email( $data );

			//add the category names to the transient
			$this->_add_transient( 'breakout_complete', $data );

			$this->_redirect_page( 'breakout_complete' );
		} else {
			$this->_redirect_page( 'breakout_registration' );
		}
	}




	/**
	 * This just sends a confirmation email for the registration.
	 *
	 * We use the 'ee-breakouts-email-confirmation.template.php'.  Right now its just a simple text based email with some limited "shortcodes" that get replaced.
	 * @param  array $data array of breakout registration data for use in the email
	 * @return void
	 */
	private function _send_email( $data ) {
		global $org_options;
		$send_to = $data['registration_info']['email_address'];
		$send_from_name = isset($org_options['organization']) ? $org_options['organization'] : __('Breakout Registrations', 'event_espresso');
		$send_from = isset( $org_options['contact_email'] ) ? $send_from_name . ' <' . $org_options['contact_email'] . '>' : $send_from_name . ' <' . get_bloginfo('admin_email') . '>';


		//setup the content
		$template = file_get_contents( EE_BREAKOUTS_PATH . 'templates/ee-breakouts-email-confirmation.template.php', TRUE );
		
		if ( empty( $template ) ){
			EE_Error::add_error( __('Email was not sent because the template was not available', 'event_espresso'), __FILE__, __FUNCTION__, __LINE__ );
			return; //get out, we need a template!	
		} 

		//let's start replacing things in the template
		$msg = str_replace('[FIRST_NAME]', $data['registration_info']['first_name'], $template);

		//get main_event name
		$main_event_name = get_event_field( 'event_name', EVENTS_DETAIL_TABLE, ' WHERE id = ' . (int) $this->_settings['breakout_main_event'] );
		$subject = sprintf( __('Breakout Registration Confirmation for %s', 'event_espresso'), $main_event_name );

		$msg = str_replace( '[MAIN_EVENT_NAME]', $main_event_name, $msg );

		//get breakout list
		$breakout_list = '';
		foreach ( $data['breakout_selections'] as $key => $breakout ) {
			$breakout_list .= $breakout['breakout_session_name'] . ':  ' . $breakout['breakout_name'] . "\n\r";
		}

		$msg = str_replace( '[BREAKOUT_LIST]', $breakout_list, $msg );

		//organization name
		$org_name = isset( $org_options['organization'] ) ? $org_options['organization'] : '';
		$msg = str_replace( '[ORGANIZATION_NAME]', $org_name, $msg );

		//headers
		$headers = array(
			0 => "MIME-Version:1.0",
			1 => "From: $send_from",
			2 => "Reply-To: $send_from",
			3 => "Content-Type: text/plain; charset=utf-8"
			);

		$success = wp_mail( $send_to, $subject, $msg, $headers );

		if ( $success ) {
			EE_Error::add_success( __('Email Confirmation sent.', 'event_espresso') );
		} else {
			EE_Error::add_error( __('Something prevented the sending of the email confirmation.  Please print this page for your records.', 'event_espresso' ) );
		}
		return;
	}




	/**
	 * This just checks to see if we have a "Breakout Session..." price type setup for Breakout sessions.  If we don't, then we create it.  If we do then we'll just use whatever is set.  This price type will assume the title of the Breakout Session Name
	 * @return void
	 */
	private function _check_breakout_session_price_setup( $event_id, $cat_id ) {
		global $wpdb;

		//first let's get the cat_name
		$cat_name = get_event_field('category_name', EVENTS_CATEGORY_TABLE, ' WHERE id = ' . $cat_id );

		$price_type_name = 'Session: ' . $cat_name;

		//check to see if we have a "price_type_name" price type setup.  If not then let's do it.
		$sql = "SELECT p.price_type FROM " . EVENTS_PRICES_TABLE . " AS p WHERE p.price_type = %s";
		$results = $wpdb->get_var( $wpdb->prepare( $sql, $price_type_name ) );

		if ( empty( $results ) ) {
			//no Breakout session setup. Let's do this!
			$cols_and_vals = array(
				'event_id' => (int) $event_id,
				'price_type' => stripslashes_deep($price_type_name),
				'event_cost' => 0.00,
				'surcharge' => 0.00,
				'surcharge_type' => 'flat_rate',
				'member_price' => 0.00,
				'member_price_type' => 'Members Admission',
				'max_qty' => 0,
				'max_qty_members' => 0,
				);
			$data_formats = array( '%d', '%s', '%f', '%f', '%s', '%f', '%s', '%d', '%d' );
			if ( !$wpdb->insert( EVENTS_PRICES_TABLE, $cols_and_vals, $data_formats )) {
				$EE_error::add_error( sprintf( __('An error occured. "Breakout Session" price type not saved for Event with ID: %d','event_espresso'), $event_id ) );
				return false;
			} else {
				return $price_type_name;
			}
		}
		return $price_type_name;
	}




	/**
	 * Updates the meta records for breakout sessions (in event_meta table)
	 * @param  array $breakout incoming array of breakout details ('cat_id', 'event_id');
	 * @return void
	 */
	private function _update_breakout_session_counts( $breakout, $att_id, $reg_group ) {
		global $wpdb;
		if ( !is_array( $breakout ) )
			return FALSE;

		$event_id = isset( $breakout['event_id'] ) ? (int) $breakout['event_id'] : FALSE;
		$cat_id = isset( $breakout['cat_id'] ) ? (int) $breakout['cat_id'] : FALSE;

		if ( !$event_id || !$cat_id ) 
			return FALSE;

		//get the breakout name for the reference
		$sql = "SELECT c.category_name FROM " . EVENTS_CATEGORY_TABLE . " AS c WHERE c.id = %d";
		$category_name = $wpdb->get_var( $wpdb->prepare( $sql, $cat_id ) );

		$ref = sanitize_key( $category_name ) . '_breakout_spots_left';

		//let's see if there are any attendance records for this breakout session
		$event_meta = event_espresso_get_event_meta($event_id);

		//if not set then we need to get the attendee limit from the event details
		if ( !isset( $event_meta[$ref] ) ) {
			$sql = "SELECT e.reg_limit FROM " . EVENTS_DETAIL_TABLE . " as e WHERE e.id = %d";
			$reg_limit = $wpdb->get_var( $wpdb->prepare( $sql, $event_id ) );
			//if there is no reg_limit set then we'll just default to 1000.
			$reg_limit = !empty( $reg_limit ) ? $reg_limit : 1000;
		}

		$event_meta[$ref] = isset( $event_meta[$ref] ) ? $event_meta[$ref] - 1 : $reg_limit - 1;

		//update event meta for event.
		$data = array(
			'event_meta' => serialize($event_meta)
			);
		$format = array( '%s' );
		$where = array( 'id' => $event_id );
		$where_format = array( '%d' );
		if ( !$wpdb->update( EVENTS_DETAIL_TABLE, $data, $where, $format, $where_format ) )
			return false;

		//we also want to make sure that the original reg_id this attendee represents (for the main event all breakouts are attached to) is saved as an attendee meta for this attendee.
		if ( !empty($att_id ) ) {
			require_once( EE_BREAKOUTS_PATH . 'helpers/EE_Data_Retriever.helper.php' );
			//update reg_group
			EE_Data_Retriever::save_attendee_meta( $att_id, 'breakout_reg_group', $reg_group );
			//update reg_id_count
			$reg_ref = 'brk_id_' . $reg_group;
			$reg_id_count = (int) get_option( $reg_ref );
			$new_count = !empty($reg_id_count) ? $reg_id_count + 1 : 1;
			update_option( $reg_ref, $new_count );
			EE_Data_Retriever::save_attendee_meta( $att_id, 'breakout_main_registration_id', $this->_session['registration_id'] );

			//let's also update attendee meta with the breakout category id for the event they registered for.
			EE_Data_Retriever::save_attendee_meta( $att_id, 'breakout_cat_id', $cat_id );
			EE_Data_Retriever::save_attendee_meta( $att_id, 'breakout_category_name', $category_name );
		}

		//we'll return the category name on success.
		return $category_name;
	}





	private function _update_reg_id_count() {
		//made it here so we can also update the registration count for the registration id
		$reg_count = (int) get_option( $this->_session['registration_id'] . '_breakout_count' );
		$new_count = $reg_count ? $reg_count + 1: 1;
		update_option( $this->_session['registration_id'] . '_breakout_count', $new_count );
	}



	/**
	 * This just displays the final message to the user that they have registered for the breakouts.
	 * @return void
	 */
	private function _breakout_finished() {

		//let's retrieve the _transient sent from previous route
		$data = $this->_route_transient;

		//now let's setup the template args
		$this->_template_args['breakouts'] = $data['breakout_selections'];
		$template_path = EE_BREAKOUTS_TEMPLATE_PATH . 'ee-breakouts-final-page.template.php';
		$this->_template_args['main_content'] = $this->_display_template( $template_path, $this->_template_args );
		$this->_set_content();
		//finally let's clear the session.
		$this->_clear_session();
	}



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
			//remove any existing transients for this session and route;
			foreach ( $this->_page_routes as $route => $func ) {
				$r_t = 'r_' . $route . '_' . $this->_session['transient_id'];
				$n_t = 'n_' . $route . '_' . $this->_session['transient_id'];
				delete_transient($r_t);
				delete_transient($n_t);
			}
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
		foreach ( $query_args as $q_name => $value ) {
			$hidden_fields_send[$q_name] = array(
				'type' => 'hidden',
				'value' => $value,
				); 
		}


		$hidden_fields = $this->_form_fields( $hidden_fields_send, TRUE );

		//set form action
		$action = add_query_arg( $query_args, $this->_breakout_url );

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
	private function _form_fields( $fields = array(), $return = FALSE, $type = 'array' ) {
		require_once EE_BREAKOUTS_PATH . 'helpers/EE_Form_Fields.helper.php';

		switch ( $type ) {
			case 'select' :
				$defaults = array(
					'name' => '',
					'values' => array(),
					'default' => '',
					'parameters' => '',
					'class' => '',
					'autosize' => true
					);
				$fields = wp_parse_args( $fields, $defaults );
				extract( $fields, EXTR_SKIP );
				$form_fields = EE_Form_Fields::select_input( $name, $values, $default, $parameters, $class, $autosize );
				break;

			default : //array
				$form_fields = EE_Form_Fields::get_form_fields_array($fields);
				break;
		}

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
		$redirect = add_query_arg( array( 'route' => $to_route ), $this->_breakout_url );

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
		$this->_content = $this->_display_template( $template_path, $this->_template_args );

	}




	private function _display_notices() {
		$notices = $this->_get_transient( TRUE );
		return stripslashes( $notices );
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

		$route = $route ? $route : 'default'; 

		$this->_add_transient( $route, $notices, TRUE );

		// if we have a given route then let's setup the url for it
		if ( $route ) {
			$redirect_url =add_query_arg( array('route' => $route) );
		}
		$redirect_url = $route ? $redirect_url : $this->_req_data['ee_breakout_redirect'];

		$this->_update_session();

		wp_safe_redirect( $redirect_url );
		exit();
	}





	/**
	 * This makes available the WP transient system for temporarily moving data between routes
	 *
	 * @access protected
	 * @param route $route the route that should receive the transient
	 * @param data $data  the data that gets sent
	 * @param bool $notices If this is for notices then we use this to indicate so, otherwise its just a normal route transient.
	 */
	protected function _add_transient( $route, $data, $notices = FALSE ) {
		$user_id = get_current_user_id();


		//now let's set the string for what kind of transient we're setting
		$transient = $notices ? 'n_' . $route . '_' . $this->_session['transient_id'] : 'r_' . $route . '_' . $this->_session['transient_id'];
		$data = $notices ? array( 'notices' => $data ) : $data;
		//is there already a transient for this route?  If there is then let's ADD to that transient
		if ( $existing = get_transient( $transient ) ) {
			$data = array_merge( (array) $data, (array) $existing );
		}

		set_transient( $transient, $data, 5 );
	}
	



	/**
	 * this retrieves the temporary transient that has been set for moving data between routes.
	 * @param bool $notices true we get notices transient. False we just return normal route transient
	 * @return mixed data
	 */
	protected function _get_transient( $notices = FALSE, $route = FALSE ) {
		$user_id = get_current_user_id();
		$route = !$route ? $this->_route : $route;
		$transient = $notices ? 'n_' . $route . '_' . $this->_session['transient_id'] : 'r_' . $route . '_' . $this->_session['transient_id'];
		$data = get_transient( $transient );
		//then let's delete the transient so we don't fill up the database.
		delete_transient( $transient );
		return $notices ? $data['notices'] : $data;
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
		if ( !$happy || $happy === '0' || $happy < 0 ) 
			return FALSE;

		return TRUE;
	}



	/**
	 * Retrieve the events for the given category
	 * @param  int $cat_id Event Category id
	 * @return array         array of event objects.
	 */
	private function _get_events_for_cat( $cat_id ) {
		global $wpdb;
		$cat_id = (int) $cat_id;
		$sql = "SELECT e.id as event_id, e.event_name as event_name, ecc.category_name as category_name FROM " . EVENTS_DETAIL_TABLE . " AS e LEFT JOIN " . EVENTS_CATEGORY_REL_TABLE . " as ec ON ec.event_id = e.id LEFT JOIN " . EVENTS_CATEGORY_TABLE . " as ecc ON ecc.id = ec.cat_id WHERE ec.cat_id = %d";
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $cat_id ) );
		return $results;
	}





	/**
	 * checks the registration limit saved in the database for the given event,cat_id combo
	 * @param  int $e_id     event_id
	 * @param  string $cat_name category_name
	 * @return mixed (bool|int)           if false then no registrations left, if true then still registrations left.
	 */
	private function _check_event_reg_limit( $e_id, $cat_name ) {
		$reg_key = sanitize_key( $cat_name ) . '_breakout_spots_left';
		$event_meta = event_espresso_get_event_meta( $e_id );
		//if spots left hasn't been set yet then we will allow registration (it'll get set later) because this is the first registration for this id.
		$num = isset($event_meta[$reg_key]) ? $event_meta[$reg_key] : 1;
		return $num > 0 ? $num : FALSE;
	}




	/**
	 * This just checks for user inactivty and clears the espresso_breakout_session if inactive for too long. This also will start a session if it hasn't started yet.
	 * Current inactive limit is set at 1 hour.
	 * @return void
	 */
	private function _check_auto_end_session() {
		
		
		if ( !isset($_SESSION) ) {
			session_start();
			return;
		}

		if ( isset($_SESSION['espresso_breakout_session'] ) ) {

			$t = time();
			$t0 = $_SESSION['espresso_breakout_session']['expiry'];
		    $diff = $t - $t0;
		    //check for within 1 hour (60*60)
		    if ( $diff > 3600 )
		    {          
		        $this->_clear_session();
		    }
		}
	}

}