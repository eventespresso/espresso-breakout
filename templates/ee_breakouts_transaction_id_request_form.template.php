<div class="ee-breakouts-transaction-form-dv">
	<h2><?php _e('Breakout Registration', 'event_espresso'); ?></h2>
	<p><?php _e('To proceed, please begin by entereing the Registration ID from your main event registration', 'event_espresso'); ?></p>
	<?php
		foreach ( $form_fields as $name => $field_data ) {
			echo $field_data['label'];
			echo $field_data['field'];
		}
	?>
	<?php echo $submit_button; ?>
</div>