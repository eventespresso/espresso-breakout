<div class="ee-breakouts-transaction-form-dv">
	<p><?php _e('To proceed, please begin by entering the Registration ID from your main event registration', 'event_espresso'); ?></p>
	<?php
		foreach ( $form_fields as $name => $field_data ) {
			echo $field_data['label'] . ' ';
			echo $field_data['field'];
		}
	?>
	<div class="ee-breakouts-submit">
		<?php echo $submit_button; ?>
	</div>
</div>