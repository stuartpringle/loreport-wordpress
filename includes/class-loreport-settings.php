<?php
/**
 * The Settings → LorePort page.
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
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_loreport_test_connection', array( __CLASS__, 'test_connection' ) );
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

	public static function menu(): void {
		add_options_page( __( 'LorePort', 'loreport' ), __( 'LorePort', 'loreport' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'loreport' ) . '</a>' );

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

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
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
			<h1><?php esc_html_e( 'LorePort', 'loreport' ); ?></h1>
			<?php settings_errors( 'loreport_api_key' ); ?>
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
									esc_html__( 'Make one in LorePort under %s, with the "Read pen names, lists and tags" and "Send signups" permissions.', 'loreport' ),
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

				<?php submit_button(); ?>
			</form>

			<?php if ( '' !== $key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="loreport_test_connection">
					<?php wp_nonce_field( 'loreport_test_connection' ); ?>
					<?php submit_button( __( 'Test the connection', 'loreport' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Adding a signup form', 'loreport' ); ?></h2>
			<p><?php esc_html_e( 'Add the "LorePort signup" block in the editor, or use the shortcode anywhere shortcodes work:', 'loreport' ); ?></p>
			<p><code>[loreport_signup]</code> &nbsp; <code>[loreport_signup list="LIST_ID" name="no" button="Join the list"]</code></p>
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
