
<div class="wrap">

	<h1>Twitter Settings</h1>

	<h2 class="title">Account</h2>

	<?php if ( $account ) : ?>

		<p>
			Current Account: <strong><?php echo $account->name ?> (@<?php echo $account->screen_name ?>)</strong>
		</p>

		<p>
			<a class="button button-primary" href="<?php echo add_query_arg( 'action', 'set-account', $this->settingsPage ) ?>">Replace Account</a>
			<a href="<?php echo add_query_arg( 'action', 'remove-account', $this->settingsPage ) ?>" class="button">Remove Account</a>
		</p>

		<p><br></p>

		<h2 class="title">Fetched Posts</h2>

		<?php if ( $fetched->found_posts == 0 ) : ?>

			<p>You have not fetched any posts</p>

		<?php else : ?>

			<p>You have <?php echo $fetched->found_posts ?> fetched posts</p>

		<?php endif ?>

		<p>
			<a href="<?php echo add_query_arg( 'action', 'fetch-posts', $this->settingsPage ) ?>" class="button button-primary" >Fetch Posts</a>
		</p>

	<?php else : ?>

		<p>There is currently no account set</p>
		<p><a class="button button-primary" href="<?php echo add_query_arg( 'action', 'set-account', $this->settingsPage ) ?>">Set Account</a></p>

	<?php endif ?>


</div>