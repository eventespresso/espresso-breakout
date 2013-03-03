<?php
if (!defined('EVENT_ESPRESSO_VERSION') )
	exit('NO direct script access allowed');

/**
 * Event Espresso
 *
 * Event Registration and Management Plugin for Wordpress
 *
 * @package		Event Espresso
 * @author		Seth Shoultes
 * @copyright	(c)2009-2012 Event Espresso All Rights Reserved.
 * @license		http://eventespresso.com/support/terms-conditions/  ** see Plugin Licensing **
 * @link		http://www.eventespresso.com
 * @version		3.2.P
 *
 * ------------------------------------------------------------------------
 *
 * EE_Data_Retriever
 *
 * This is a helper utility class that has a number of static methods for retrieving data from the Event Espresso core plugin.  Much of this is made obsolete by 3.2 (and a later version of 3.1) but just needed something to implement with the current version the EE_Breakouts plugin is developed on (to save typing code over and over again)
 *
 * @package		Event Espresso
 * @subpackage	/helper/EE_Data_Retriever.helper.php
 * @author		Darren Ethier
 *
 * ------------------------------------------------------------------------
 */




class EE_Data_Retriever {

	public static function save_attendee_meta($attendee_id, $meta_key, $meta_value, $delete = FALSE){
		global $wpdb;
		
		$notifications['error']	 = array();
		
		$cols_and_values = array( 
			'attendee_id'=>$attendee_id, 
			'meta_key'=>$meta_key, 
			'meta_value'=>$meta_value
		);
		
		$cols_and_values_format = array( '%d', '%s', '%s' );
		$where_cols_and_values = array( 'attendee_id'=>$attendee_id, 'meta_key'=>$meta_key );
		$where_format = array( '%d', '%s' );
		
		$SQL = "SELECT ameta_id from " . EVENTS_ATTENDEE_META_TABLE . " WHERE attendee_id = '".$attendee_id."' AND meta_key = '".$meta_key."'";
		$meta = $wpdb->get_results( $SQL );
		$total_meta = $wpdb->num_rows;

		if ( $total_meta > 0 ){
			if ($delete == TRUE){
				$SQL = "DELETE FROM " . EVENTS_ATTENDEE_META_TABLE . ' ';
				$SQL .= "WHERE attendee_id = %d";
				$del_success = $wpdb->query($wpdb->prepare( $SQL, $attendee_id ));
				if ( $del_success === FALSE ) {
					$notifications['error'][] = __('An error occured while attempting to delete the attendee meta.', 'event_espresso'); 
				}
			}else{
				// run the update
				$cols_and_values['date_updated'] = date("Y-m-d H:i:s");
				array_push( $cols_and_values_format, '%s' );
				$upd_success = $wpdb->update( EVENTS_ATTENDEE_META_TABLE, $cols_and_values, $where_cols_and_values, $cols_and_values_format, $where_format );
				// if there was an actual error
				if ( $upd_success === FALSE ) {
					$notifications['error'][] = __('An error occured while attempting to update the attendee meta.', 'event_espresso'); 
				}
			}
		}else{
			// save the new value
			$cols_and_values['date_added'] = date("Y-m-d H:i:s");
			array_push( $cols_and_values_format, '%s' );
			$save_success = $wpdb->insert( EVENTS_ATTENDEE_META_TABLE, $cols_and_values, $cols_and_values_format );
			if ( $save_success === FALSE ) {
				$notifications['error'][] = __('An error occured while attempting to save the attendee meta.', 'event_espresso'); 
			}
		}
		
		// display error messages
		if ( ! empty( $notifications['error'] )) {
			$error_msg = implode( $notifications['error'], '<br />' );
		?>
		<div id="message" class="error">
			<p>
				<strong><?php echo $error_msg; ?></strong>
			</p>
		</div>
		<?php 
		}
	}

	public static function get_attendee_meta_value($attendee_id, $meta_key) {
		global $wpdb;
		$sql = "SELECT meta_value FROM " . EVENTS_ATTENDEE_META_TABLE;
		$sql .= " WHERE attendee_id = '" . $attendee_id . "' AND meta_key='".$meta_key."' ";
		//echo $sql;
		$wpdb->get_results($sql);
		if ($wpdb->num_rows > 0) {
			return $wpdb->last_result[0]->meta_value;
		}
	}

} //end EE_Data_Retriever class