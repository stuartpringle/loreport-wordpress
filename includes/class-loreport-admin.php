<?php
/**
 * LorePort in the WordPress admin: LorePort's own Subscribers, Lists and
 * Newsletters pages in a frame (embedded mode), the Settings page, and a
 * dashboard widget.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * The LorePort admin menu and dashboard widget.
 *
 * Subscribers, Lists and Newsletters are LorePort's own pages, shown in a
 * frame: the plugin asks LorePort for a one-use sign-in link with the API key
 * (POST /embed-sessions) each time the page loads. That needs the key's
 * "Show … inside your website" permission and this site's address on the key.
 */
class LorePort_Admin {

	const MENU = 'loreport-subscribers';

	/** The embedded pages: admin page slug => [menu title, LorePort path]. */
	public static function pages(): array {
		return array(
			'loreport-subscribers' => array( __( 'Subscribers', 'loreport' ), '/subscribers' ),
			'loreport-lists'       => array( __( 'Lists', 'loreport' ), '/lists' ),
			'loreport-newsletters' => array( __( 'Newsletters', 'loreport' ), '/newsletters' ),
		);
	}

	/** Who can see and use these pages. Filter with loreport_capability. */
	public static function capability(): string {
		return (string) apply_filters( 'loreport_capability', 'manage_options' );
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_loreport_embed_link', array( __CLASS__, 'embed_link' ) );
	}

	public static function menu(): void {
		$first = true;
		foreach ( self::pages() as $slug => [ $title ] ) {
			if ( $first ) {
				add_menu_page( __( 'LorePort', 'loreport' ), __( 'LorePort', 'loreport' ), self::capability(), $slug, array( __CLASS__, 'render_embedded' ), 'dashicons-email-alt', 58 );
				$first = false;
			}
			add_submenu_page( self::MENU, $title, $title, self::capability(), $slug, array( __CLASS__, 'render_embedded' ) );
		}
		add_submenu_page( self::MENU, __( 'LorePort settings', 'loreport' ), __( 'Settings', 'loreport' ), 'manage_options', LorePort_Settings::PAGE, array( 'LorePort_Settings', 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'index.php' === $hook || str_contains( $hook, 'loreport' ) ) {
			wp_enqueue_style( 'loreport-admin', LOREPORT_URL . 'assets/admin.css', array(), LOREPORT_VERSION );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which script to load.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( isset( self::pages()[ $page ] ) ) {
			wp_enqueue_script( 'loreport-embed', LOREPORT_URL . 'assets/embed.js', array(), LOREPORT_VERSION, true );
		}
	}

	/* ---------------------------------------------------------------- Embedded pages */

	/** Subscribers, Lists or Newsletters: LorePort's page in a frame. */
	public static function render_embedded(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which page to show.
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU;
		$path  = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable
		$pages = self::pages();
		$start = $pages[ $page ][1] ?? '/subscribers';
		$path  = self::valid_path( $path ) ? $path : $start;
		?>
		<div class="wrap loreport-embedded">
			<?php if ( '' === LorePort_Settings::api_key() ) : ?>
				<h1><?php echo esc_html( $pages[ $page ][0] ?? __( 'LorePort', 'loreport' ) ); ?></h1>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: link to the Connection settings */
						esc_html__( 'Connect LorePort first: add your API key under %s.', 'loreport' ),
						'<a href="' . esc_url( LorePort_Settings::url( 'connection' ) ) . '">' . esc_html__( 'LorePort → Settings → Connection', 'loreport' ) . '</a>'
					);
					?>
				</p></div>
			<?php else : ?>
				<div class="loreport-frame" data-loreport-embed
					data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'loreport_embed_link' ) ); ?>"
					data-path="<?php echo esc_attr( $path ); ?>"
					data-origin="<?php echo esc_attr( LorePort_Client::base_url() ); ?>">
					<p class="loreport-frame__loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Opening LorePort…', 'loreport' ); ?></p>
					<div class="notice notice-error inline loreport-frame__error" hidden><p></p></div>
					<iframe title="<?php esc_attr_e( 'LorePort', 'loreport' ); ?>" hidden allow="clipboard-write"></iframe>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A LorePort page the frame may open: Subscribers, Lists or Newsletters. */
	private static function valid_path( string $path ): bool {
		return (bool) preg_match( '#^/(subscribers|lists|newsletters)(/[A-Za-z0-9/_-]*)?(\?[A-Za-z0-9=&%_.+-]*)?$#', $path );
	}

	/** Ajax: a fresh one-use sign-in link for the frame. */
	public static function embed_link(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t open LorePort here.', 'loreport' ) ), 403 );
		}
		check_ajax_referer( 'loreport_embed_link' );
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '/subscribers';
		$path = self::valid_path( $path ) ? $path : '/subscribers';

		$response = LorePort_Client::request( 'POST', 'embed-sessions', array( 'path' => $path ) );
		if ( 201 === $response['status'] && ! empty( $response['body']['data']['url'] ) ) {
			wp_send_json_success( array( 'url' => (string) $response['body']['data']['url'] ) );
		}

		$code = (string) ( $response['body']['error']['code'] ?? '' );
		if ( 403 === $response['status'] && 'no_website' !== $code && 'plan_has_no_api' !== $code ) {
			$message = __( 'This API key can\'t show LorePort\'s pages here yet. In LorePort, go to Settings → API and webhooks: give the key the "Show Subscribers, Lists and Newsletters inside your website" permission, and add this site\'s address to it.', 'loreport' );
		} elseif ( 'no_website' === $code ) {
			/* translators: %s: this site's address */
			$message = sprintf( __( 'In LorePort, go to Settings → API and webhooks and add this site\'s address (%s) to the key. Only that site can show LorePort\'s pages.', 'loreport' ), home_url() );
		} else {
			$message = 0 === $response['status'] ? __( 'LorePort couldn\'t be reached. Please try again in a moment.', 'loreport' ) : (string) $response['error'];
		}
		wp_send_json_error(
			array(
				'message' => $message,
				'open'    => LorePort_Client::base_url() . $path,
			),
			400
		);
	}

	/* ---------------------------------------------------------------- Dashboard widget */

	/**
	 * List totals for the widget, cached for five minutes.
	 *
	 * @return array|null Null without a key; ['error' => ...] if LorePort didn't answer.
	 */
	public static function overview(): ?array {
		if ( '' === LorePort_Settings::api_key() ) {
			return null;
		}
		$cached = get_transient( 'loreport_overview' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$lists = array();
		foreach ( LorePort_Client::lists() as $list ) {
			$one     = LorePort_Client::request( 'GET', 'lists/' . rawurlencode( (string) $list['id'] ) );
			$lists[] = array(
				'id'     => (string) $list['id'],
				'name'   => (string) ( $list['name'] ?? '' ),
				'counts' => 200 === $one['status'] ? (array) ( $one['body']['data']['counts'] ?? array() ) : null,
			);
		}
		if ( ! $lists ) {
			$me = LorePort_Client::request( 'GET', 'me' );
			if ( 200 !== $me['status'] ) {
				return array( 'error' => (string) $me['error'] );
			}
		}

		$data = array(
			'error' => null,
			'lists' => $lists,
		);
		set_transient( 'loreport_overview', $data, 5 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * The newest subscribers, or null when the key can't read subscribers.
	 *
	 * @param int $count How many.
	 */
	public static function recent_subscribers( int $count = 8 ): ?array {
		$cached = get_transient( 'loreport_recent' );
		if ( is_array( $cached ) ) {
			return $cached['rows'];
		}
		$response = LorePort_Client::request( 'GET', 'subscribers?sort=joined&dir=desc&per_page=' . $count );
		$rows     = 200 === $response['status'] ? array_map( array( __CLASS__, 'summary' ), (array) ( $response['body']['data'] ?? array() ) ) : null;
		set_transient( 'loreport_recent', array( 'rows' => $rows ), 5 * MINUTE_IN_SECONDS );

		return $rows;
	}

	/**
	 * A subscriber as one row: overall status and lists by name.
	 *
	 * @param array $subscriber From the API.
	 */
	public static function summary( array $subscriber ): array {
		$names    = wp_list_pluck( LorePort_Client::lists(), 'name', 'id' );
		$lists    = array();
		$statuses = array();
		foreach ( (array) ( $subscriber['lists'] ?? array() ) as $membership ) {
			$statuses[] = (string) ( $membership['status'] ?? '' );
			$lists[]    = (string) ( $names[ $membership['list_id'] ?? '' ] ?? ( $membership['list_id'] ?? '' ) );
		}

		return array(
			'id'     => (string) ( $subscriber['id'] ?? '' ),
			'email'  => (string) ( $subscriber['email'] ?? '' ),
			'status' => in_array( 'subscribed', $statuses, true ) ? 'subscribed' : ( in_array( 'pending', $statuses, true ) ? 'pending' : 'unsubscribed' ),
			'lists'  => $lists,
			'joined' => $subscriber['created_at'] ?? null,
		);
	}

	private static function badge( string $status ): string {
		$labels = array(
			'subscribed'   => __( 'Subscribed', 'loreport' ),
			'pending'      => __( 'Awaiting confirmation', 'loreport' ),
			'unsubscribed' => __( 'Unsubscribed', 'loreport' ),
		);

		return '<span class="loreport-badge loreport-badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	/**
	 * An admin address for one of the embedded pages.
	 *
	 * @param string $path LorePort path, such as /subscribers/ID.
	 */
	public static function url( string $path ): string {
		$section = explode( '/', trim( $path, '/' ) )[0] ?? 'subscribers';

		return add_query_arg( array( 'path' => $path ), admin_url( 'admin.php?page=loreport-' . sanitize_key( $section ) ) );
	}

	public static function dashboard_widget(): void {
		if ( current_user_can( self::capability() ) && '' !== LorePort_Settings::api_key() ) {
			wp_add_dashboard_widget( 'loreport_dashboard', __( 'Newsletter', 'loreport' ), array( __CLASS__, 'render_dashboard_widget' ) );
		}
	}

	public static function render_dashboard_widget(): void {
		$overview = self::overview();
		if ( ! $overview || ! empty( $overview['error'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: error */ __( 'LorePort didn\'t answer: %s', 'loreport' ), (string) ( $overview['error'] ?? '' ) ) ) . '</p>';

			return;
		}
		$total  = fn( $key ) => array_sum( array_map( fn( $l ) => (int) ( $l['counts'][ $key ] ?? 0 ), $overview['lists'] ) );
		$recent = self::recent_subscribers();
		?>
		<div class="loreport-widget">
			<div class="loreport-widget__totals">
				<div><strong><?php echo esc_html( number_format_i18n( $total( 'subscribed' ) ) ); ?></strong><span><?php esc_html_e( 'subscribed', 'loreport' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $total( 'pending' ) ) ); ?></strong><span><?php esc_html_e( 'awaiting confirmation', 'loreport' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( count( $overview['lists'] ) ) ); ?></strong><span><?php esc_html_e( 'lists', 'loreport' ); ?></span></div>
			</div>
			<?php if ( null === $recent ) : ?>
				<p class="description"><?php esc_html_e( 'To see the newest subscribers here, give the API key the "subscribers:read" permission in LorePort.', 'loreport' ); ?></p>
			<?php elseif ( ! $recent ) : ?>
				<p><?php esc_html_e( 'No subscribers yet.', 'loreport' ); ?></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Newest subscribers', 'loreport' ); ?></h3>
				<ul class="loreport-widget__people">
					<?php foreach ( $recent as $person ) : ?>
						<li>
							<span><a href="<?php echo esc_url( self::url( '/subscribers/' . rawurlencode( $person['id'] ) ) ); ?>"><?php echo esc_html( $person['email'] ); ?></a>
								<small><?php echo esc_html( implode( ', ', $person['lists'] ) ); ?></small></span>
							<span><?php echo self::badge( $person['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
								<small><?php echo esc_html( $person['joined'] ? human_time_diff( (int) strtotime( $person['joined'] ) ) : '' ); ?></small></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="loreport-widget__links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=loreport-subscribers' ) ); ?>"><?php esc_html_e( 'Subscribers', 'loreport' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=loreport-lists' ) ); ?>"><?php esc_html_e( 'Lists', 'loreport' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=loreport-newsletters' ) ); ?>"><?php esc_html_e( 'Newsletters', 'loreport' ); ?></a>
			</p>
		</div>
		<?php
	}
}
