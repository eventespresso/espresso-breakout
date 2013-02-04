<?php
/*
Plugin Name: Event Espresso - Breakouts
Plugin URI: http://eventespresso.com/
Description: This hooks into the Event Espresso Plugin and allows event managers to have attendees who have already registered for a main event use their transaction id to signup for free breakout sessions (separate events).
Version: 1.0

Author: Event Espresso
Author URI: http://www.eventespresso.com

Copyright (c) 2008-2013 Event Espresso All Rights Reserved.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

function espresso_breakouts_version() {
	return '1.0';
}

define( 'EE_BREAKOUTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'EE_BREAKOUTS_URL', plugin_dir_url( __FILE__ ) );

require_once EE_BREAKOUTS_PATH . 'EE_Breakouts_Main.class.php';
$EE_BRK_MAIN = new EE_Breakouts_Main();