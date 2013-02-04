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
	private $_breakout_page = NULL;




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
	 * This array holds the page routes array (which directs to the appropriate function when an action is requested).
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
	 * This flags whether this is an AJAX request or not.
	 * @var boolean
	 */
	private $_doing_ajax = FALSE;





	/**
	 * This will hold all the request data incoming
	 * @var array
	 */
	private $_page_data = array();




	/**
	 * holds the nonce ref for the current route.
	 * @var string
	 */
	private $_req_nonce = '';
	


	public function __construct() {

		if ( is_admin() ) {
			$this->_admin(); //maybe we'll do the admin class separately?
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
		require_once EE_BREAKOUTS_PATH . 'EE_Breakouts_Admin';
		$admin = new EE_Breakouts_Admin();
	}




	private function _set_props() {
		$this->_settings = get_option('espresso_breakout_settings');
		if ( empty( $this->_settings ) ) 
			return; //get out because we don't have any saved settings yet.

		//this sets up the props for the class.
		$this->_breakout_page = $this->_settings['breakout_page'];
		$this->_breakout_categories = $this->_settings['breakout_categories'];


		//request data (in favor of posts).
		$this->_page_data = array_merge( $_GET, $_POST );


		//set UI
		$this->_is_UI = isset( $this->_page_data['noheader'] ) ? FALSE : TRUE;

		//ajax?
		$this->_doing_ajax = defined( 'DOING_AJAX' ) ? TRUE : FALSE;
	}





	private function _init() {
		add_action('init', array( $this, 'start_session' ) );
	}




	private function _set_page_routes() {
		$this->_page_routes = array(
			'default' => '_load_transaction_form',
			'transaction_verify' => array(
				'func' => '_verify_transaction_id',
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
		$this->_route = isset( $this->_page_data['action'] ) ? $this->_page_data['action'] : 'default';
		$this->_req_nonce = $this->_route . '_nonce';
	}




	private function _route_request() {
		$this->_verify_routes();

		//nonce check (but only when not on default);
		if ( $this->_route != 'default' ) {
			$nonce = isset( $this->_page_data['_wpnonce'] ) ? $this->_page_data['_wpnonce'] : '';
			if ( !wp_verify_nonce( $nonce, $this->_req_nonce ) ) {
				wp_die( sprintf(__('%sNonce Fail.%s' , 'event_espresso'), '<a href="http://www.youtube.com/watch?v=56_S0WeTkzs">', '</a>' ) );
			}
		}
	}



	private function _verify_routes() {
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
				$error_msg =  __( 'An error occured. The  requested page route could not be found.', 'event_espresso' );
				// developer error msg
				$error_msg .= '||' . sprintf( __( 'Page route "%s" could not be called. Check that the spelling for method names and actions in the "_page_routes" array are all correct.', 'event_espresso' ), $func );
				wp_die ( $error_msg ); //todo proper error handling.
			}
		}
	}




	public function start_session() {

		//todo let's setup the initial session vars here.
	}


	//todo: finish filling out these methods.
	private function _load_transaction_form() {}
	private function _verify_transaction_id() {}
	private function _display_breakouts() {}
	private function _display_breakout_registration() {}
	private function _process_breakout_registration() {}
	private function _breakout_finished() {}

}