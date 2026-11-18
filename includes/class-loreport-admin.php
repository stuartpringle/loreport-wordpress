<?php
/**
 * LorePort in the WordPress admin: LorePort's own pages in a frame (embedded
 * mode), the Settings page, and a dashboard widget.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * The LorePort admin menu and dashboard widget.
 *
 * The menu comes from LorePort (GET /embed-menu), so new LorePort pages show
 * up here without a plugin update: Dashboard, Newsletters, Subscribers, Books
 * and their sub-pages. They're LorePort's own pages, shown in a frame: the
 * plugin asks LorePort for a one-use sign-in link with the API key
 * (POST /embed-sessions) each time the page loads. That needs the key's
 * "Show … inside your website" permission and this site's address on the key.
 * LorePort's Settings pages stay in LorePort.
 */
class LorePort_Admin {

	/** The top-level menu's slug (its first page). */
	const MENU = 'loreport-dashboard';

	/** Transient holding LorePort's menu. */
	const MENU_CACHE = 'loreport_menu';

	/**
	 * The menu used until LorePort's own arrives (not connected yet, or
	 * LorePort couldn't be reached): the same shape GET /embed-menu returns.
	 */
	private static function fallback_menu(): array {
		$item = fn( string $key, string $label, string $path, array $children = array() ) => array(
			'key'      => $key,
			'label'    => $label,
			'path'     => $path,
			'embedded' => true,
			'children' => $children,
		);

		return array(
			$item( 'dashboard', __( 'Dashboard', 'loreport' ), '/dashboard' ),
			$item(
				'newsletters',
				__( 'Newsletters', 'loreport' ),
				'/newsletters',
				array(
					$item( 'polls', __( 'Surveys & polls', 'loreport' ), '/newsletters/polls' ),
					$item( 'sections', __( 'Sections & layouts', 'loreport' ), '/newsletters/sections' ),
					$item( 'links', __( 'Link clicks', 'loreport' ), '/newsletters/links' ),
				)
			),
			$item(
				'subscribers',
				__( 'Subscribers', 'loreport' ),
				'/subscribers',
				array(
					$item( 'lists', __( 'Lists', 'loreport' ), '/lists' ),
					$item( 'signup-links', __( 'Signup links', 'loreport' ), '/signup-links' ),
				)
			),
			$item( 'books', __( 'Books', 'loreport' ), '/books' ),
		);
	}

	/**
	 * LorePort's menu (GET /embed-menu), cached for a day; the fallback when
	 * there's no key or LorePort didn't answer (retried after five minutes).
	 */
	public static function menu_items(): array {
		$cached = get_transient( self::MENU_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( '' === LorePort_Settings::api_key() ) {
			return self::fallback_menu();
		}
		$response = LorePort_Client::request( 'GET', 'embed-menu' );
		$items    = 200 === $response['status'] ? self::clean_menu( (array) ( $response['body']['data'] ?? array() ) ) : array();
		if ( ! $items ) {
			$items = self::fallback_menu();
			set_transient( self::MENU_CACHE, $items, 5 * MINUTE_IN_SECONDS );

			return $items;
		}
		set_transient( self::MENU_CACHE, $items, DAY_IN_SECONDS );

		return $items;
	}

	/** Keeps only well-formed embedded items from LorePort's answer. */
	private static function clean_menu( array $items ): array {
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['embedded'] ) || ! is_string( $item['key'] ?? null ) || ! is_string( $item['label'] ?? null )
				|| ! is_string( $item['path'] ?? null ) || ! preg_match( '#^/[a-z0-9/_-]*$#', $item['path'] ) ) {
				continue;
			}
			$clean[] = array(
				'key'      => sanitize_key( $item['key'] ),
				'label'    => sanitize_text_field( $item['label'] ),
				'path'     => $item['path'],
				'embedded' => true,
				'children' => self::clean_menu( (array) ( $item['children'] ?? array() ) ),
			);
		}

		return $clean;
	}

	/**
	 * The embedded pages, flattened in menu order: admin page slug =>
	 * [menu title, LorePort path, whether it's a sub-page].
	 */
	public static function pages(): array {
		$pages = array();
		foreach ( self::menu_items() as $item ) {
			$pages[ 'loreport-' . $item['key'] ] = array( $item['label'], $item['path'], false );
			foreach ( $item['children'] as $child ) {
				$pages[ 'loreport-' . $child['key'] ] = array( $child['label'], $child['path'], true );
			}
		}

		return $pages;
	}

	/** First path segments the frame may open (LorePort checks too). */
	public static function sections(): array {
		$sections = array();
		foreach ( self::pages() as [ , $path ] ) {
			$sections[] = explode( '/', trim( $path, '/' ) )[0];
		}

		return array_values( array_unique( array_filter( $sections ) ) );
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
		add_menu_page( __( 'LorePort', 'loreport' ), __( 'LorePort', 'loreport' ), self::capability(), self::MENU, array( __CLASS__, 'render_embedded' ), 'dashicons-email-alt', 58 );
		$pages = self::pages();
		// The top-level menu opens the first page, whatever LorePort lists first.
		if ( ! isset( $pages[ self::MENU ] ) ) {
			$first = array_key_first( $pages );
			add_submenu_page( '', $pages[ $first ][0], $pages[ $first ][0], self::capability(), self::MENU, array( __CLASS__, 'render_embedded' ) );
		}
		foreach ( $pages as $slug => [ $title, , $child ] ) {
			// WordPress menus have two levels, so LorePort's sub-pages are indented under their page.
			$label = $child ? '<span class="loreport-menu-child" style="padding-inline-start:0.75em">' . esc_html( $title ) . '</span>' : esc_html( $title );
			add_submenu_page( self::MENU, $title, $label, self::capability(), $slug, array( __CLASS__, 'render_embedded' ) );
		}
		add_submenu_page( self::MENU, __( 'LorePort settings', 'loreport' ), __( 'Settings', 'loreport' ), 'manage_options', LorePort_Settings::PAGE, array( 'LorePort_Settings', 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'index.php' === $hook || str_contains( $hook, 'loreport' ) ) {
			wp_enqueue_style( 'loreport-admin', LOREPORT_URL . 'assets/admin.css', array(), LOREPORT_VERSION );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which script to load.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU === $page || isset( self::pages()[ $page ] ) ) {
			wp_enqueue_script( 'loreport-embed', LOREPORT_URL . 'assets/embed.js', array(), LOREPORT_VERSION, true );
		}
	}

	/* ---------------------------------------------------------------- Embedded pages */

	/** One of LorePort's pages in a frame. */
	public static function render_embedded(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which page to show.
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU;
		$path  = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable
		$pages = self::pages();
		$start = $pages[ $page ][1] ?? ( reset( $pages )[1] ?? '/dashboard' );
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
					data-origin="<?php echo esc_attr( LorePort_Client::base_url() ); ?>"
					data-sections="<?php echo esc_attr( (string) wp_json_encode( self::sections() ) ); ?>">
					<p class="loreport-frame__loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Opening LorePort…', 'loreport' ); ?></p>
					<div class="notice notice-error inline loreport-frame__error" hidden><p></p></div>
					<iframe title="<?php esc_attr_e( 'LorePort', 'loreport' ); ?>" hidden allow="clipboard-write"></iframe>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A LorePort page the frame may open: one of the menu's sections. */
	private static function valid_path( string $path ): bool {
		$sections = implode( '|', array_map( fn( $s ) => preg_quote( $s, '#' ), self::sections() ) );

		return '' !== $sections && (bool) preg_match( '#^/(' . $sections . ')(/[A-Za-z0-9/_-]*)?(\?[A-Za-z0-9=&%_.+-]*)?$#', $path );
	}

	/** Ajax: a fresh one-use sign-in link for the frame. */
	public static function embed_link(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You can\'t open LorePort here.', 'loreport' ) ), 403 );
		}
		check_ajax_referer( 'loreport_embed_link' );
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '/dashboard';
		$path = self::valid_path( $path ) ? $path : '/dashboard';

		$response = LorePort_Client::request( 'POST', 'embed-sessions', array( 'path' => $path ) );
		if ( 201 === $response['status'] && ! empty( $response['body']['data']['url'] ) ) {
			wp_send_json_success( array( 'url' => (string) $response['body']['data']['url'] ) );
		}

		$code = (string) ( $response['body']['error']['code'] ?? '' );
		if ( 403 === $response['status'] && 'no_website' !== $code && 'plan_has_no_api' !== $code ) {
			$message = __( 'This API key can\'t show LorePort\'s pages here yet. In LorePort, go to Settings → API and webhooks: give the key the "Show … inside your website" permission (dashboard:embed), and add this site\'s address to it.', 'loreport' );
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
		$section = explode( '/', trim( $path, '/' ) )[0] ?? 'dashboard';
		$page    = isset( self::pages()[ 'loreport-' . sanitize_key( $section ) ] ) ? 'loreport-' . sanitize_key( $section ) : self::MENU;

		return add_query_arg( array( 'path' => $path ), admin_url( 'admin.php?page=' . $page ) );
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
				<a href="<?php echo esc_url( self::url( '/dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard', 'loreport' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( '/subscribers' ) ); ?>"><?php esc_html_e( 'Subscribers', 'loreport' ); ?></a> ·
				<a href="<?php echo esc_url( self::url( '/newsletters' ) ); ?>"><?php esc_html_e( 'Newsletters', 'loreport' ); ?></a>
			</p>
		</div>
		<?php
	}
}
