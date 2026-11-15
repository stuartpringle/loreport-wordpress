<?php
/**
 * The LorePort → Settings page: connection, forms, popup and opt-in settings.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the API key and the plugin's choices, and shows the settings page.
 */
class LorePort_Settings {

	const PAGE = 'loreport';

	/** Permissions the plugin needs on its API key. */
	const NEEDED = array( 'lists:read', 'signups:write' );

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_loreport_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_loreport_disconnect', array( __CLASS__, 'disconnect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOREPORT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function api_key(): string {
		return (string) get_option( 'loreport_api_key', '' );
	}

	public static function default_list(): string {
		return (string) get_option( 'loreport_default_list', '' );
	}

	/**
	 * Whether an opt-in checkbox is on. Safe to call before translations load.
	 *
	 * @param string $where "comments" or "woocommerce".
	 */
	public static function enabled( string $where ): bool {
		return ! empty( ( (array) get_option( 'loreport_' . $where, array() ) )['enabled'] );
	}

	/**
	 * Settings for an opt-in checkbox (comments or woocommerce).
	 *
	 * @param string $where "comments" or "woocommerce".
	 * @return array{enabled: bool, list: string, label: string}
	 */
	public static function checkbox( string $where ): array {
		$saved = (array) get_option( 'loreport_' . $where, array() );

		return array(
			'enabled' => ! empty( $saved['enabled'] ),
			'list'    => (string) ( $saved['list'] ?? '' ),
			'label'   => '' !== trim( (string) ( $saved['label'] ?? '' ) ) ? (string) $saved['label'] : __( 'Email me news about new books, releases and extras. I can unsubscribe at any time.', 'loreport' ),
		);
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'loreport' ) . '</a>' );

		return $links;
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			'loreport_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::PAGE,
			'loreport_default_list',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_list_id' ),
			)
		);
		register_setting(
			self::PAGE,
			LorePort_Popup::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'LorePort_Popup', 'sanitize' ),
			)
		);
		foreach ( array( 'comments', 'woocommerce' ) as $where ) {
			register_setting(
				self::PAGE,
				'loreport_' . $where,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
		}
	}

	/** A blank key field keeps the saved key; anything else replaces it. */
	public static function sanitize_key( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return self::api_key();
		}
		if ( ! preg_match( '/^lp_[A-Za-z0-9]{20,100}$/', $value ) ) {
			add_settings_error( 'loreport_api_key', 'invalid', __( 'That doesn\'t look like a LorePort API key (they start with lp_). The old key was kept.', 'loreport' ) );

			return self::api_key();
		}
		delete_transient( 'loreport_lists' );

		return $value;
	}

	public static function sanitize_list_id( $value ): string {
		$value = (string) $value;

		return preg_match( '/^[0-9a-z]{26}$/', $value ) ? $value : '';
	}

	public static function sanitize_checkbox( $value ): array {
		$value = (array) $value;

		return array(
			'enabled' => ! empty( $value['enabled'] ),
			'list'    => self::sanitize_list_id( $value['list'] ?? '' ),
			'label'   => sanitize_text_field( (string) ( $value['label'] ?? '' ) ),
		);
	}

	/** Checks the key with GET /me and remembers the answer for the next page load. */
	/**
	 * Disconnects this site from LorePort, so it can be connected to another
	 * account: revokes the key in LorePort (unless asked not to), and forgets
	 * the key, every list choice and any signups still waiting to be sent
	 * (they belong to the old account). Opt-in and popup switches are kept.
	 */
	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t change these settings.', 'loreport' ), 403 );
		}
		check_admin_referer( 'loreport_disconnect' );

		$revoked = null;
		if ( '' !== self::api_key() && ! empty( $_POST['revoke'] ) ) {
			$revoked = 200 === LorePort_Client::request( 'DELETE', 'me' )['status'];
		}

		delete_option( 'loreport_api_key' );
		delete_option( 'loreport_default_list' );
		delete_option( LorePort_Signups::QUEUE_OPTION );
		foreach ( array( 'comments', 'woocommerce', LorePort_Popup::OPTION ) as $option ) {
			$name  = LorePort_Popup::OPTION === $option ? $option : 'loreport_' . $option;
			$value = (array) get_option( $name, array() );
			if ( $value ) {
				$value['list'] = '';
				update_option( $name, $value );
			}
		}
		foreach ( array( 'loreport_lists', 'loreport_overview', 'loreport_recent' ) as $transient ) {
			delete_transient( $transient );
		}

		$message = __( 'Disconnected from LorePort. Signup forms are hidden until you connect again.', 'loreport' );
		if ( true === $revoked ) {
			$message .= ' ' . __( 'The API key was revoked in LorePort.', 'loreport' );
		} elseif ( false === $revoked ) {
			$message .= ' ' . __( 'LorePort couldn\'t be reached to revoke the key: revoke it yourself in LorePort under Settings → API and webhooks.', 'loreport' );
		}
		set_transient(
			'loreport_test_result_' . get_current_user_id(),
			array(
				'ok'      => true,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t change these settings.', 'loreport' ), 403 );
		}
		check_admin_referer( 'loreport_test_connection' );

		$response = LorePort_Client::request( 'GET', 'me' );
		if ( 200 === $response['status'] ) {
			$abilities = (array) ( $response['body']['data']['key']['abilities'] ?? array() );
			$missing   = array_diff( self::NEEDED, $abilities );
			$result    = array(
				'ok'      => array() === $missing,
				'message' => array() === $missing
					/* translators: %s: the LorePort account's name */
					? sprintf( __( 'Connected to LorePort as %s.', 'loreport' ), (string) ( $response['body']['data']['account']['name'] ?? '' ) )
					/* translators: %s: comma-separated permission names */
					: sprintf( __( 'Connected, but the key is missing these permissions: %s. Make a new key in LorePort with them.', 'loreport' ), implode( ', ', $missing ) ),
			);
			delete_transient( 'loreport_lists' );
		} else {
			$result = array(
				'ok'      => false,
				/* translators: %s: the error from LorePort */
				'message' => sprintf( __( 'Couldn\'t connect: %s', 'loreport' ), (string) $response['error'] ),
			);
		}
		set_transient( 'loreport_test_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = self::api_key();
		$lists  = '' !== $key ? LorePort_Client::lists( true ) : array();
		$result = get_transient( 'loreport_test_result_' . get_current_user_id() );
		delete_transient( 'loreport_test_result_' . get_current_user_id() );
		$queued = count( LorePort_Signups::queue() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LorePort settings', 'loreport' ); ?></h1>
			<p class="description" style="max-width:52em"><?php esc_html_e( 'Connect your LorePort account, then add signup forms and a popup to your site. Your subscribers, lists and newsletters are under LorePort in the menu: they\'re LorePort\'s own pages, shown here.', 'loreport' ); ?></p>
			<?php settings_errors(); ?>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice <?php echo $result['ok'] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $result['message'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $queued > 0 ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					/* translators: %d: number of signups */
					echo esc_html( sprintf( _n( '%d signup is waiting to reach LorePort and will be retried automatically.', '%d signups are waiting to reach LorePort and will be retried automatically.', $queued, 'loreport' ), $queued ) );
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<h2><?php esc_html_e( 'Connection', 'loreport' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="loreport_api_key"><?php esc_html_e( 'API key', 'loreport' ); ?></label></th>
						<td>
							<input type="password" id="loreport_api_key" name="loreport_api_key" class="regular-text code" autocomplete="off"
								placeholder="<?php echo esc_attr( '' !== $key ? substr( $key, 0, 10 ) . '…' . __( ' (saved; type a new one to replace it)', 'loreport' ) : 'lp_…' ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to LorePort's API settings */
									esc_html__( 'Make one in LorePort under %s with the "Read pen names, lists and tags" and "Send signups" permissions. To manage subscribers, lists and newsletters from here too, also tick "Show Subscribers, Lists and Newsletters inside your website" and enter this site\'s address.', 'loreport' ),
									'<a href="' . esc_url( LorePort_Client::base_url() . '/settings/api' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Settings → API and webhooks', 'loreport' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_default_list"><?php esc_html_e( 'Default list', 'loreport' ); ?></label></th>
						<td>
							<?php self::list_select( 'loreport_default_list', 'loreport_default_list', self::default_list(), $lists ); ?>
							<p class="description"><?php esc_html_e( 'Signup forms use this list unless you choose another in the block or shortcode.', 'loreport' ); ?></p>
						</td>
					</tr>
				</table>

				<?php foreach ( array( 'comments' => __( 'Comment form', 'loreport' ), 'woocommerce' => __( 'WooCommerce checkout', 'loreport' ) ) as $where => $title ) : ?>
					<?php
					if ( 'woocommerce' === $where && ! class_exists( 'WooCommerce' ) ) {
						continue;
					}
					$box = self::checkbox( $where );
					?>
					<h2><?php echo esc_html( $title ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Opt-in checkbox', 'loreport' ); ?></th>
							<td>
								<label><input type="checkbox" name="loreport_<?php echo esc_attr( $where ); ?>[enabled]" value="1" <?php checked( $box['enabled'] ); ?>>
									<?php echo 'comments' === $where ? esc_html__( 'Offer a newsletter checkbox on the comment form', 'loreport' ) : esc_html__( 'Offer a newsletter checkbox at checkout', 'loreport' ); ?></label>
								<p class="description"><?php esc_html_e( 'It\'s never ticked in advance: anti-spam laws require people to choose it themselves.', 'loreport' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="loreport_<?php echo esc_attr( $where ); ?>_list"><?php esc_html_e( 'List', 'loreport' ); ?></label></th>
							<td><?php self::list_select( 'loreport_' . $where . '[list]', 'loreport_' . $where . '_list', $box['list'], $lists, true ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="loreport_<?php echo esc_attr( $where ); ?>_label"><?php esc_html_e( 'Checkbox wording', 'loreport' ); ?></label></th>
							<td><input type="text" id="loreport_<?php echo esc_attr( $where ); ?>_label" name="loreport_<?php echo esc_attr( $where ); ?>[label]" class="large-text" value="<?php echo esc_attr( $box['label'] ); ?>"></td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php $popup = LorePort_Popup::settings(); ?>
				<h2><?php esc_html_e( 'Popup', 'loreport' ); ?></h2>
				<p><?php esc_html_e( 'A signup form that opens over the page. Any button or menu link that points to #loreport-popup opens it (in a Button block, set the link to #loreport-popup), and so does the [loreport_popup_button] shortcode.', 'loreport' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On every page', 'loreport' ); ?></th>
						<td><label><input type="checkbox" name="loreport_popup[enabled]" value="1" <?php checked( $popup['enabled'] ); ?>> <?php esc_html_e( 'Add the popup to every page of the site', 'loreport' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_popup_list"><?php esc_html_e( 'List', 'loreport' ); ?></label></th>
						<td><?php self::list_select( 'loreport_popup[list]', 'loreport_popup_list', $popup['list'], $lists, true ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_popup_title"><?php esc_html_e( 'Heading', 'loreport' ); ?></label></th>
						<td><input type="text" id="loreport_popup_title" name="loreport_popup[title]" class="regular-text" value="<?php echo esc_attr( $popup['title'] ); ?>" placeholder="<?php esc_attr_e( 'Join the newsletter', 'loreport' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_popup_description"><?php esc_html_e( 'Description', 'loreport' ); ?></label></th>
						<td><input type="text" id="loreport_popup_description" name="loreport_popup[description]" class="large-text" value="<?php echo esc_attr( $popup['description'] ); ?>" placeholder="<?php esc_attr_e( 'New books first, early chapters and the occasional giveaway.', 'loreport' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_popup_button"><?php esc_html_e( 'Button text', 'loreport' ); ?></label></th>
						<td><input type="text" id="loreport_popup_button" name="loreport_popup[button]" class="regular-text" value="<?php echo esc_attr( $popup['button'] ); ?>" placeholder="<?php esc_attr_e( 'Subscribe', 'loreport' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="loreport_popup_delay"><?php esc_html_e( 'Open on its own', 'loreport' ); ?></label></th>
						<td>
							<input type="number" id="loreport_popup_delay" name="loreport_popup[delay]" class="small-text" min="0" max="600" value="<?php echo esc_attr( (string) $popup['delay'] ); ?>"> <?php esc_html_e( 'seconds after the page loads (0: only when someone clicks a button)', 'loreport' ); ?>
							<p class="description"><?php esc_html_e( 'Once someone closes it or signs up, it won\'t open on its own for them again for 30 days.', 'loreport' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php if ( '' !== $key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="loreport_test_connection">
					<?php wp_nonce_field( 'loreport_test_connection' ); ?>
					<?php submit_button( __( 'Test the connection', 'loreport' ), 'secondary', 'submit', false ); ?>
				</form>

				<h2><?php esc_html_e( 'Disconnect', 'loreport' ); ?></h2>
				<p class="description" style="max-width:52em"><?php esc_html_e( 'To connect this site to a different LorePort account or pen name. The key and every list choice are removed from WordPress, and signups still waiting to reach LorePort are dropped (they belong to this account). Signup forms and the popup are hidden until you connect again; your opt-in and popup settings are kept.', 'loreport' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this site from LorePort?', 'loreport' ) ); ?>')">
					<input type="hidden" name="action" value="loreport_disconnect">
					<?php wp_nonce_field( 'loreport_disconnect' ); ?>
					<p><label><input type="checkbox" name="revoke" value="1" checked> <?php esc_html_e( 'Also revoke this key in LorePort, so it stops working everywhere', 'loreport' ); ?></label></p>
					<?php submit_button( __( 'Disconnect from LorePort', 'loreport' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Adding a signup form', 'loreport' ); ?></h2>
			<p><?php esc_html_e( 'Add the "LorePort signup" block in the editor, or use the shortcode anywhere shortcodes work:', 'loreport' ); ?></p>
			<p><code>[loreport_signup]</code> &nbsp; <code>[loreport_signup list="LIST_ID" title="Join the readers' list" description="New books, early chapters and giveaways." layout="inline" style="plain" name="no" button="Join"]</code></p>
			<p class="description"><?php esc_html_e( 'layout: stacked or inline (email and button on one line). style: card (a soft panel) or plain.', 'loreport' ); ?></p>
			<p><?php esc_html_e( 'A popup on one page only, and a button to open it:', 'loreport' ); ?> <code>[loreport_popup title="Join the newsletter" delay="20"]</code> &nbsp; <code>[loreport_popup_button text="Get the newsletter"]</code></p>
			<?php if ( $lists ) : ?>
				<table class="widefat striped" style="max-width:720px">
					<thead><tr><th><?php esc_html_e( 'List', 'loreport' ); ?></th><th><?php esc_html_e( 'List ID', 'loreport' ); ?></th><th><?php esc_html_e( 'Confirms by email', 'loreport' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $lists as $list ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $list['name'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $list['id'] ?? '' ) ); ?></code></td>
							<td><?php echo ! empty( $list['double_opt_in'] ) ? esc_html__( 'Yes', 'loreport' ) : esc_html__( 'No', 'loreport' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( '' !== $key ) : ?>
				<p><?php esc_html_e( 'Your lists couldn\'t be loaded. Test the connection to see why.', 'loreport' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A list dropdown.
	 *
	 * @param string $name       Field name.
	 * @param string $id         Field id.
	 * @param string $selected   Selected list ID.
	 * @param array  $lists      Lists from LorePort.
	 * @param bool   $or_default Offer "the default list".
	 */
	private static function list_select( string $name, string $id, string $selected, array $lists, bool $or_default = false ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		echo '<option value="">' . esc_html( $or_default ? __( 'The default list', 'loreport' ) : __( 'Choose a list', 'loreport' ) ) . '</option>';
		$found = false;
		foreach ( $lists as $list ) {
			$found = $found || ( $list['id'] ?? '' ) === $selected;
			echo '<option value="' . esc_attr( (string) ( $list['id'] ?? '' ) ) . '"' . selected( $selected, (string) ( $list['id'] ?? '' ), false ) . '>' . esc_html( (string) ( $list['name'] ?? '' ) ) . '</option>';
		}
		if ( '' !== $selected && ! $found ) {
			echo '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $selected ) . '</option>';
		}
		echo '</select>';
	}
}
