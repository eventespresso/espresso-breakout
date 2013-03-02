<div class="ee-breakouts-registration-form-dv">
	<h2><?php _e('Breakout Registration', 'event_espresso'); ?></h2>
	<p><?php _e('Please select your choices from the breakouts below, fill out the form and submit', 'event_espresso'); ?></p>
	<?php
		foreach ( $breakout_fields as $category => $select ) {
			?>
			<div class="breakout-section" id="breakout-<?php echo $category; ?>">
				<p><?php echo $select['label']; ?>:  <?php echo $select['select_field']; ?></p>
			</div>
			<?php
		}
	?>
	<div class="registration-details">
		<p><?php _e('Registration Details', 'event_espresso'); ?></p>
		<?php
		foreach ( $registration_fields as $name => $field_data ) {
			?>
			<p><?php echo $field_data['label']; ?>:  <?php echo $field_data['field']; ?></p>
			<?php
		}
		?>
	</div>
	<?php echo $submit_button; ?>
</div>