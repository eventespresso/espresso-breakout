<div class="ee-breakouts-final-content">
	<h2>Registration Complete</h2>
	<p>
		You have succesfully registered for the following sessions:
		<ul>
			<?php foreach ( $breakouts as $breakout ) : ?>
				<li><?php echo $breakout['breakout_session_name']; ?>: <?php echo $breakout['breakout_name']; ?></li>
			<?php endforeach; ?>
		</ul>

		We look forward to seeing you at the conference!
	</p>
</div>