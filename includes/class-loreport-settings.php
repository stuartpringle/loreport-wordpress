<?php
/**
 * The LorePort → Settings page, in tabs: Information (adding forms, list IDs),
 * Popup and comment form, WooCommerce, and Connection (key, test, disconnect).
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the API key and the plugin's choices, and shows the settings page.
 */
class LorePort_Settings {

	const PAGE = 'loreport';

	/**
	 * Settings group per tab. options.php saves every option in the submitted
	 * group, blanking any that aren't on the form, so each tab saves only its own.
	 */
	const GROUPS = array(
		'connection'  => 'loreport_connection',
		'popup'       => 'loreport_popup_comments',
		'woocommerce' => 'loreport_woocommerce',
	);

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

	/**
	 * The tabs, in order: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'information' => __( 'Information', 'loreport' ),
			'popup'       => __( 'Popup and comment form', 'loreport' ),
			'woocommerce' => __( 'WooCommerce', 'loreport' ),
			'connection'  => __( 'Connection', 'loreport' ),
		);
	}

	/**
	 * The settings page's address, on a tab.
	 *
	 * @param string $tab A key from tabs().
	 */
	public static function url( string $tab = 'information' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( 'information' === $tab ? '' : '&tab=' . $tab ) );
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a tab.

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'information';
	}

	public static function register(): void {
		register_setting(
			self::GROUPS['connection'],
			'loreport_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key' ),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUPS['connection'],
			'loreport_default_list',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_list_id' ),
			)
		);
		register_setting(
			self::GROUPS['popup'],
			LorePort_Popup::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'LorePort_Popup', 'sanitize' ),
			)
		);
		foreach ( array( 'comments' => self::GROUPS['popup'], 'woocommerce' => self::GROUPS['woocommerce'] ) as $where => $group ) {
			register_setting(
				$group,
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
		delete_transient( 'loreport_status' );
		delete_transient( LorePort_Admin::MENU_CACHE );

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
		foreach ( array( 'loreport_lists', 'loreport_overview', 'loreport_recent', 'loreport_status' ) as $transient ) {
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

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * Checks the key with LorePort (GET /me).
	 *
	 * @return array{state: string, message: string, account: string} state is
	 *         "connected", "permissions" (works, but missing permissions),
	 *         "rejected" (LorePort refused the key) or "unreachable".
	 */
	public static function check(): array {
		$response = LorePort_Client::request( 'GET', 'me' );
		if ( 200 === $response['status'] ) {
			$account   = (string) ( $response['body']['data']['account']['name'] ?? '' );
			$abilities = (array) ( $response['body']['data']['key']['abilities'] ?? array() );
			$missing   = array_diff( self::NEEDED, $abilities );

			return array(
				'state'   => array() === $missing ? 'connected' : 'permissions',
				'account' => $account,
				'message' => array() === $missing
					/* translators: %s: the LorePort account's name */
					? sprintf( __( 'Connected to LorePort as %s.', 'loreport' ), $account )
					/* translators: %s: comma-separated permission names */
					: sprintf( __( 'Connected, but the key is missing these permissions: %s. Make a new key in LorePort with them.', 'loreport' ), implode( ', ', $missing ) ),
			);
		}

		if ( in_array( $response['status'], array( 401, 403 ), true ) ) {
			return array(
				'state'   => 'rejected',
				'account' => '',
				'message' => __( 'LorePort didn\'t accept this key: it may have been revoked or deleted. Make a new one in LorePort and paste it below.', 'loreport' ),
			);
		}

		return array(
			'state'   => 'unreachable',
			'account' => '',
			/* translators: %s: the error from LorePort */
			'message' => sprintf( __( 'Couldn\'t reach LorePort: %s. Signups made meanwhile wait on your site and are sent once it\'s back.', 'loreport' ), (string) $response['error'] ),
		);
	}

	/**
	 * The connection's state for the settings page: "none" with no key, else
	 * check()'s answer, remembered for ten minutes (one minute when it failed,
	 * so a short outage clears quickly).
	 *
	 * @return array{state: string, message: string, account: string}
	 */
	public static function status(): array {
		if ( '' === self::api_key() ) {
			return array(
				'state'   => 'none',
				'account' => '',
				'message' => '',
			);
		}
		$cached = get_transient( 'loreport_status' );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}

		return self::remember_status( self::check() );
	}

	private static function remember_status( array $status ): array {
		set_transient( 'loreport_status', $status, 'connected' === $status['state'] ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );

		return $status;
	}

	/** Checks the key with GET /me and shows the answer on the next page load. */
	public static function test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You can\'t change these settings.', 'loreport' ), 403 );
		}
		check_admin_referer( 'loreport_test_connection' );

		$status = self::remember_status( self::check() );
		if ( 'connected' === $status['state'] ) {
			delete_transient( 'loreport_lists' );
			delete_transient( LorePort_Admin::MENU_CACHE );
		}
		set_transient(
			'loreport_test_result_' . get_current_user_id(),
			array(
				'ok'      => 'connected' === $status['state'],
				'message' => $status['message'],
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * A small coloured badge with the connection's state, beside the page title.
	 *
	 * @param array $status From status().
	 */
	private static function status_badge( array $status ): void {
		$labels = array(
			'connected'   => __( 'Connected', 'loreport' ),
			'permissions' => __( 'Connected, missing permissions', 'loreport' ),
			'rejected'    => __( 'Key not accepted', 'loreport' ),
			'unreachable' => __( 'Can\'t reach LorePort', 'loreport' ),
			'none'        => __( 'Not connected', 'loreport' ),
		);
		$tone   = array(
			'connected'   => 'good',
			'permissions' => 'warn',
			'rejected'    => 'bad',
			'unreachable' => 'warn',
			'none'        => 'off',
		);
		$title  = 'connected' === $status['state'] && '' !== $status['account']
			/* translators: %s: the LorePort account's name */
			? sprintf( __( 'Connected to LorePort as %s', 'loreport' ), $status['account'] )
			: $status['message'];
		printf(
			'<a class="loreport-status loreport-status--%1$s" href="%2$s" title="%3$s"><span class="loreport-status__dot" aria-hidden="true"></span>%4$s</a>',
			esc_attr( $tone[ $status['state'] ] ?? 'off' ),
			esc_url( self::url( 'connection' ) ),
			esc_attr( $title ),
			esc_html( $labels[ $status['state'] ] ?? $labels['none'] )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab    = self::current_tab();
		$key    = self::api_key();
		$lists  = '' !== $key && 'woocommerce' !== $tab ? LorePort_Client::lists( true ) : array();
		$result = get_transient( 'loreport_test_result_' . get_current_user_id() );
		delete_transient( 'loreport_test_result_' . get_current_user_id() );
		$queued = count( LorePort_Signups::queue() );
		?>
		<div class="wrap loreport-settings">
			<?php $status = self::status(); ?>
			<h1 class="loreport-settings__title"><?php esc_html_e( 'LorePort settings', 'loreport' ); ?> <?php self::status_badge( $status ); ?></h1>
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

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'LorePort settings', 'loreport' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="loreport-tab">
				<?php
				if ( '' === $key && 'connection' !== $tab ) {
					echo '<div class="notice notice-info inline"><p>' . wp_kses_post(
						sprintf(
							/* translators: %s: link to the Connection tab */
							__( 'LorePort isn\'t connected yet: add your API key on the <a href="%s">Connection</a> tab. Forms and the popup stay hidden until then.', 'loreport' ),
							esc_url( self::url( 'connection' ) )
						)
					) . '</p></div>';
				}
				switch ( $tab ) {
					case 'popup':
						self::render_popup_tab( $lists );
						break;
					case 'woocommerce':
						self::render_woocommerce_tab();
						break;
					case 'connection':
						self::render_connection_tab( $key, $lists, $status );
						break;
					default:
						self::render_information_tab( $key, $lists );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Information: what the plugin does, adding forms, and the list IDs.
	 *
	 * @param string $key   The saved API key.
	 * @param array  $lists Lists from LorePort.
	 */
	private static function render_information_tab( string $key, array $lists ): void {
		?>
		<p class="loreport-lead"><?php esc_html_e( 'Readers join your LorePort lists from signup forms on your site, a popup, and newsletter checkboxes on the comment form and WooCommerce checkout. Your subscribers, lists and newsletters are under LorePort in the menu: they\'re LorePort\'s own pages, shown here.', 'loreport' ); ?></p>

		<h2><?php esc_html_e( 'Adding a signup form', 'loreport' ); ?></h2>
		<p><?php esc_html_e( 'Add the "LorePort signup" block in the editor, or use the shortcode anywhere shortcodes work:', 'loreport' ); ?></p>
		<p><code>[loreport_signup]</code></p>
		<p><code>[loreport_signup list="LIST_ID" title="Join the readers' list" description="New books, early chapters and giveaways." layout="inline" style="plain" name="no" button="Join"]</code></p>
		<table class="widefat striped loreport-options">
			<thead><tr><th><?php esc_html_e( 'Option', 'loreport' ); ?></th><th><?php esc_html_e( 'What it does', 'loreport' ); ?></th></tr></thead>
			<tbody>
				<tr><td><code>list</code></td><td><?php esc_html_e( 'The list ID (below). Leave it out to use the default list.', 'loreport' ); ?></td></tr>
				<tr><td><code>title</code>, <code>description</code></td><td><?php esc_html_e( 'A heading and a line of text above the form.', 'loreport' ); ?></td></tr>
				<tr><td><code>layout</code></td><td><?php esc_html_e( 'stacked, or inline (email and button on one line).', 'loreport' ); ?></td></tr>
				<tr><td><code>style</code></td><td><?php esc_html_e( 'card (a soft panel) or plain.', 'loreport' ); ?></td></tr>
				<tr><td><code>name</code></td><td><?php esc_html_e( 'yes or no: ask for the reader\'s name.', 'loreport' ); ?></td></tr>
				<tr><td><code>button</code></td><td><?php esc_html_e( 'The button\'s text.', 'loreport' ); ?></td></tr>
			</tbody>
		</table>
		<p>
			<?php
			printf(
				/* translators: %s: link to the Popup and comment form tab */
				wp_kses_post( __( 'For a popup, and a newsletter checkbox on the comment form, see <a href="%s">Popup and comment form</a>.', 'loreport' ) ),
				esc_url( self::url( 'popup' ) )
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Your lists', 'loreport' ); ?></h2>
		<?php if ( $lists ) : ?>
			<table class="widefat striped loreport-lists">
				<thead><tr><th><?php esc_html_e( 'List', 'loreport' ); ?></th><th><?php esc_html_e( 'List ID', 'loreport' ); ?></th><th><?php esc_html_e( 'Confirms by email', 'loreport' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $lists as $list ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $list['name'] ?? '' ) ); ?><?php echo ( $list['id'] ?? '' ) === self::default_list() ? ' <span class="loreport-badge">' . esc_html__( 'Default', 'loreport' ) . '</span>' : ''; ?></td>
						<td><code><?php echo esc_html( (string) ( $list['id'] ?? '' ) ); ?></code></td>
						<td><?php echo ! empty( $list['double_opt_in'] ) ? esc_html__( 'Yes', 'loreport' ) : esc_html__( 'No', 'loreport' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php elseif ( '' !== $key ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Connection tab */
					wp_kses_post( __( 'Your lists couldn\'t be loaded. <a href="%s">Test the connection</a> to see why.', 'loreport' ) ),
					esc_url( self::url( 'connection' ) )
				);
				?>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Your lists show here once LorePort is connected.', 'loreport' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Popup and comment form.
	 *
	 * @param array $lists Lists from LorePort.
	 */
	private static function render_popup_tab( array $lists ): void {
		$popup = LorePort_Popup::settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUPS['popup'] ); ?>
			<h2><?php esc_html_e( 'Popup', 'loreport' ); ?></h2>
			<p class="loreport-lead"><?php esc_html_e( 'A signup form that opens over the page. Any button or menu link that points to #loreport-popup opens it (in a Button block, set the link to #loreport-popup), and so does the [loreport_popup_button] shortcode.', 'loreport' ); ?></p>
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
				<tr>
					<th scope="row"><?php esc_html_e( 'On some pages only', 'loreport' ); ?></th>
					<td>
						<p><?php esc_html_e( 'Leave "On every page" off and put the shortcode on the pages you want. It takes the same options as above:', 'loreport' ); ?></p>
						<p><code>[loreport_popup title="Join the newsletter" delay="20"]</code></p>
						<p><?php esc_html_e( 'A button that opens it:', 'loreport' ); ?> <code>[loreport_popup_button text="Get the newsletter"]</code></p>
					</td>
				</tr>
			</table>

			<?php self::render_checkbox_settings( 'comments', $lists ); ?>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function render_woocommerce_tab(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<h2><?php esc_html_e( 'WooCommerce checkout', 'loreport' ); ?></h2>
			<p class="loreport-lead"><?php esc_html_e( 'If you sell through WooCommerce, LorePort can add a newsletter checkbox to the checkout (classic and block checkout). WooCommerce isn\'t active on this site, so there\'s nothing to set up here.', 'loreport' ); ?></p>
			<?php
			return;
		}
		$lists = '' !== self::api_key() ? LorePort_Client::lists() : array();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUPS['woocommerce'] ); ?>
			<?php self::render_checkbox_settings( 'woocommerce', $lists ); ?>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * The opt-in checkbox settings for the comment form or checkout.
	 *
	 * @param string $where "comments" or "woocommerce".
	 * @param array  $lists Lists from LorePort.
	 */
	private static function render_checkbox_settings( string $where, array $lists ): void {
		$box = self::checkbox( $where );
		?>
		<h2><?php echo 'comments' === $where ? esc_html__( 'Comment form', 'loreport' ) : esc_html__( 'WooCommerce checkout', 'loreport' ); ?></h2>
		<p class="loreport-lead"><?php echo 'comments' === $where ? esc_html__( 'A newsletter checkbox under the comment form, so commenters can join a list as they comment.', 'loreport' ) : esc_html__( 'A newsletter checkbox at checkout (classic and block checkout), so customers can join a list as they buy.', 'loreport' ); ?></p>
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
		<?php
	}

	/**
	 * Connection: the key, the default list, Test and Disconnect.
	 *
	 * @param string $key    The saved API key.
	 * @param array  $lists  Lists from LorePort.
	 * @param array  $status From status().
	 */
	private static function render_connection_tab( string $key, array $lists, array $status ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUPS['connection'] ); ?>
			<h2><?php esc_html_e( 'Connection', 'loreport' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<?php if ( '' !== $key ) : ?>
							<?php esc_html_e( 'API key', 'loreport' ); ?>
						<?php else : ?>
							<label for="loreport_api_key"><?php esc_html_e( 'API key', 'loreport' ); ?></label>
						<?php endif; ?>
					</th>
					<td>
						<?php if ( '' !== $key ) : ?>
							<?php $good = in_array( $status['state'], array( 'connected', 'permissions' ), true ); ?>
							<div class="loreport-key <?php echo $good ? 'is-good' : 'is-bad'; ?>">
								<span class="dashicons <?php echo $good ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
								<code><?php echo esc_html( substr( $key, 0, 10 ) ); ?>••••••••••••</code>
								<span class="loreport-key__note"><?php echo $good ? esc_html__( 'Saved. The full key is hidden for safety.', 'loreport' ) : esc_html__( 'Saved, but it isn\'t working right now (see below).', 'loreport' ); ?></span>
							</div>
							<?php if ( 'connected' === $status['state'] && '' !== $status['account'] ) : ?>
								<p class="description">
									<?php
									/* translators: %s: the LorePort account's name */
									echo esc_html( sprintf( __( 'Connected to LorePort as %s.', 'loreport' ), $status['account'] ) );
									?>
								</p>
							<?php elseif ( 'connected' !== $status['state'] ) : ?>
								<p class="description"><?php echo esc_html( $status['message'] ); ?></p>
							<?php endif; ?>
							<details class="loreport-key__replace"<?php echo 'rejected' === $status['state'] ? ' open' : ''; ?>>
								<summary><?php esc_html_e( 'Replace the key', 'loreport' ); ?></summary>
								<label for="loreport_api_key" class="screen-reader-text"><?php esc_html_e( 'New API key', 'loreport' ); ?></label>
								<input type="password" id="loreport_api_key" name="loreport_api_key" class="regular-text code" autocomplete="off" placeholder="<?php esc_attr_e( 'Paste the new key (lp_…)', 'loreport' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave it empty to keep the saved key.', 'loreport' ); ?></p>
							</details>
						<?php else : ?>
							<input type="password" id="loreport_api_key" name="loreport_api_key" class="regular-text code" autocomplete="off" placeholder="lp_…">
						<?php endif; ?>
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
						<p class="description"><?php esc_html_e( 'Signup forms, the popup and the opt-in checkboxes use this list unless you choose another.', 'loreport' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php if ( '' === $key ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Test the connection', 'loreport' ); ?></h2>
		<p class="loreport-lead"><?php esc_html_e( 'Checks the key with LorePort and that it has the permissions the plugin needs.', 'loreport' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="loreport_test_connection">
			<?php wp_nonce_field( 'loreport_test_connection' ); ?>
			<?php submit_button( __( 'Test the connection', 'loreport' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Disconnect', 'loreport' ); ?></h2>
		<p class="loreport-lead"><?php esc_html_e( 'To connect this site to a different LorePort account or pen name. The key and every list choice are removed from WordPress, and signups still waiting to reach LorePort are dropped (they belong to this account). Signup forms and the popup are hidden until you connect again; your opt-in and popup settings are kept.', 'loreport' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this site from LorePort?', 'loreport' ) ); ?>')">
			<input type="hidden" name="action" value="loreport_disconnect">
			<?php wp_nonce_field( 'loreport_disconnect' ); ?>
			<p><label><input type="checkbox" name="revoke" value="1" checked> <?php esc_html_e( 'Also revoke this key in LorePort, so it stops working everywhere', 'loreport' ); ?></label></p>
			<?php submit_button( __( 'Disconnect from LorePort', 'loreport' ), 'delete', 'submit', false ); ?>
		</form>
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
