<?php
/**
 * LorePort in the WordPress admin: an overview (lists, recent newsletters,
 * usage), a Subscribers page, and a dashboard widget. Everything comes from
 * LorePort's API; nothing about subscribers is stored in WordPress.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * The LorePort admin menu and dashboard widget.
 */
class LorePort_Admin {

	const MENU = 'loreport-overview';

	const SUBSCRIBERS = 'loreport-subscribers';

	const STATUSES = array( 'subscribed', 'pending', 'unsubscribed' );

	/** Who can see and use these pages. Filter with loreport_capability. */
	public static function capability(): string {
		return (string) apply_filters( 'loreport_capability', 'manage_options' );
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		foreach ( array( 'add', 'update', 'unsubscribe', 'tag', 'untag' ) as $action ) {
			add_action( 'admin_post_loreport_subscriber_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu(): void {
		add_menu_page( __( 'LorePort', 'loreport' ), __( 'LorePort', 'loreport' ), self::capability(), self::MENU, array( __CLASS__, 'render_overview' ), 'dashicons-email-alt', 58 );
		add_submenu_page( self::MENU, __( 'LorePort overview', 'loreport' ), __( 'Overview', 'loreport' ), self::capability(), self::MENU, array( __CLASS__, 'render_overview' ) );
		add_submenu_page( self::MENU, __( 'Subscribers', 'loreport' ), __( 'Subscribers', 'loreport' ), self::capability(), self::SUBSCRIBERS, array( __CLASS__, 'render_subscribers' ) );
		add_submenu_page( self::MENU, __( 'LorePort settings', 'loreport' ), __( 'Settings', 'loreport' ), 'manage_options', 'options-general.php?page=' . LorePort_Settings::PAGE );
	}

	public static function styles( string $hook ): void {
		if ( 'index.php' === $hook || str_contains( $hook, 'loreport' ) ) {
			wp_enqueue_style( 'loreport-admin', LOREPORT_URL . 'assets/admin.css', array(), LOREPORT_VERSION );
		}
	}

	/* ---------------------------------------------------------------- Figures */

	/**
	 * Lists with counts, recent newsletters with results, and usage, cached
	 * for five minutes.
	 *
	 * @return array|null Null without a key; ['error' => ...] if LorePort didn't answer.
	 */
	public static function overview( bool $fresh = false ): ?array {
		if ( '' === LorePort_Settings::api_key() ) {
			return null;
		}
		$cached = get_transient( 'loreport_overview' );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}

		$me = LorePort_Client::request( 'GET', 'me' );
		if ( 200 !== $me['status'] ) {
			return array(
				'error'  => (string) $me['error'],
				'status' => $me['status'],
			);
		}

		$lists = array();
		foreach ( LorePort_Client::lists( true ) as $list ) {
			$one     = LorePort_Client::request( 'GET', 'lists/' . rawurlencode( (string) $list['id'] ) );
			$lists[] = array(
				'id'     => (string) $list['id'],
				'name'   => (string) ( $list['name'] ?? '' ),
				'color'  => preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $list['color'] ?? '' ) ) ? $list['color'] : '#e5e7eb',
				'counts' => 200 === $one['status'] ? (array) ( $one['body']['data']['counts'] ?? array() ) : null,
			);
		}

		$names       = wp_list_pluck( $lists, 'name', 'id' );
		$newsletters = null;
		$response    = LorePort_Client::request( 'GET', 'newsletters?per_page=5' );
		if ( 200 === $response['status'] ) {
			$newsletters = array();
			foreach ( array_slice( (array) ( $response['body']['data'] ?? array() ), 0, 5 ) as $newsletter ) {
				$stats = null;
				if ( 'sent' === ( $newsletter['status'] ?? '' ) ) {
					$one   = LorePort_Client::request( 'GET', 'newsletters/' . rawurlencode( (string) $newsletter['id'] ) );
					$stats = 200 === $one['status'] ? (array) ( $one['body']['data']['stats'] ?? array() ) : null;
				}
				$newsletters[] = array(
					'subject' => (string) ( $newsletter['subject'] ?? '' ),
					'status'  => (string) ( $newsletter['status'] ?? '' ),
					'list'    => $names[ $newsletter['list_id'] ?? '' ] ?? null,
					'date'    => $newsletter['finished_at'] ?? $newsletter['scheduled_at'] ?? $newsletter['created_at'] ?? null,
					'stats'   => $stats,
				);
			}
		}
		$usage = LorePort_Client::request( 'GET', 'usage' );

		$data = array(
			'error'       => null,
			'account'     => (string) ( $me['body']['data']['account']['name'] ?? '' ),
			'abilities'   => array_values( array_filter( (array) ( $me['body']['data']['key']['abilities'] ?? array() ), 'is_string' ) ),
			'lists'       => $lists,
			'newsletters' => $newsletters,
			'usage'       => 200 === $usage['status'] ? (array) ( $usage['body']['data'] ?? array() ) : null,
			'checked_at'  => time(),
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

	public static function forget(): void {
		delete_transient( 'loreport_overview' );
		delete_transient( 'loreport_recent' );
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
			$lists[]    = array(
				'id'     => (string) ( $membership['list_id'] ?? '' ),
				'name'   => (string) ( $names[ $membership['list_id'] ?? '' ] ?? ( $membership['list_id'] ?? '' ) ),
				'status' => (string) ( $membership['status'] ?? '' ),
			) + (array) $membership;
		}

		return array(
			'id'     => (string) ( $subscriber['id'] ?? '' ),
			'email'  => (string) ( $subscriber['email'] ?? '' ),
			'name'   => (string) ( $subscriber['name'] ?? '' ),
			'status' => in_array( 'subscribed', $statuses, true ) ? 'subscribed' : ( in_array( 'pending', $statuses, true ) ? 'pending' : 'unsubscribed' ),
			'lists'  => $lists,
			'tags'   => array_values( array_filter( (array) ( $subscriber['tags'] ?? array() ), 'is_string' ) ),
			'opens'  => $subscriber['newsletters_opened'] ?? null,
			'clicks' => $subscriber['link_clicks'] ?? null,
			'joined' => $subscriber['created_at'] ?? null,
		);
	}

	private static function status_label( string $status ): string {
		return array(
			'subscribed'   => __( 'Subscribed', 'loreport' ),
			'pending'      => __( 'Awaiting confirmation', 'loreport' ),
			'unsubscribed' => __( 'Unsubscribed', 'loreport' ),
		)[ $status ] ?? $status;
	}

	private static function badge( string $status ): string {
		return '<span class="loreport-badge loreport-badge--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	private static function date( $value, bool $time = false ): string {
		$at = is_string( $value ) ? strtotime( $value ) : false;

		return $at ? wp_date( $time ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : get_option( 'date_format' ), $at ) : '';
	}

	private static function number( $value ): string {
		return null === $value ? '—' : number_format_i18n( (int) $value );
	}

	/** LorePort's error in words. */
	private static function error_message( array $response ): string {
		if ( 0 === $response['status'] ) {
			return __( 'LorePort couldn\'t be reached. Please try again in a moment.', 'loreport' );
		}
		if ( 403 === $response['status'] ) {
			return __( 'The API key doesn\'t allow this. In LorePort, give it the "subscribers:write" permission.', 'loreport' );
		}
		$fields = (array) ( $response['body']['error']['fields'] ?? array() );
		$first  = $fields ? (array) reset( $fields ) : array();

		return (string) ( $first[0] ?? $response['error'] ?? __( 'Something went wrong.', 'loreport' ) );
	}

	/* ---------------------------------------------------------------- Dashboard widget */

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
							<span><a href="<?php echo esc_url( self::subscriber_url( $person['id'] ) ); ?>"><?php echo esc_html( $person['email'] ); ?></a>
								<small><?php echo esc_html( implode( ', ', wp_list_pluck( $person['lists'], 'name' ) ) ); ?></small></span>
							<span><?php echo self::badge( $person['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
								<small><?php echo esc_html( $person['joined'] ? human_time_diff( (int) strtotime( $person['joined'] ) ) : '' ); ?></small></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="loreport-widget__links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SUBSCRIBERS ) ); ?>"><?php esc_html_e( 'All subscribers', 'loreport' ); ?></a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU ) ); ?>"><?php esc_html_e( 'Lists and newsletters', 'loreport' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Overview */

	public static function render_overview(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only refreshes cached figures.
		$fresh = isset( $_GET['refresh'] );
		if ( $fresh ) {
			self::forget();
		}
		$data   = self::overview( $fresh );
		$queued = count( LorePort_Signups::queue() );
		?>
		<div class="wrap loreport-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'LorePort', 'loreport' ); ?></h1>
			<?php if ( $data && empty( $data['error'] ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU . '&refresh=1' ) ); ?>"><?php esc_html_e( 'Refresh', 'loreport' ); ?></a>
			<?php endif; ?>
			<a class="page-title-action" href="<?php echo esc_url( LorePort_Client::base_url() . '/dashboard' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open LorePort ↗', 'loreport' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( null === $data ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings link */
						esc_html__( 'Add your LorePort API key under %s first.', 'loreport' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=' . LorePort_Settings::PAGE ) ) . '">' . esc_html__( 'Settings → LorePort', 'loreport' ) . '</a>'
					);
					?>
				</p></div>
				<?php
				echo '</div>';
				return;
			endif;
			?>
			<?php if ( ! empty( $data['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $data['error'] ); ?></p></div>
				<?php
				echo '</div>';
				return;
			endif;
			?>
			<?php if ( $queued > 0 ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %d: number of signups */
					echo esc_html( sprintf( _n( '%d signup is waiting to reach LorePort and will be retried automatically.', '%d signups are waiting to reach LorePort and will be retried automatically.', $queued, 'loreport' ), $queued ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( '' !== $data['account'] ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: account name */ __( 'LorePort account: %s', 'loreport' ), $data['account'] ) ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Lists', 'loreport' ); ?></h2>
			<table class="widefat striped loreport-table">
				<thead><tr>
					<th><?php esc_html_e( 'List', 'loreport' ); ?></th>
					<th class="num"><?php esc_html_e( 'Subscribed', 'loreport' ); ?></th>
					<th class="num"><?php esc_html_e( 'Awaiting confirmation', 'loreport' ); ?></th>
					<th class="num"><?php esc_html_e( 'Unsubscribed', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'List ID', 'loreport' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $data['lists'] as $list ) : ?>
					<tr>
						<td><span class="loreport-dot" style="background:<?php echo esc_attr( $list['color'] ); ?>"></span> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SUBSCRIBERS . '&list=' . rawurlencode( $list['id'] ) ) ); ?>"><strong><?php echo esc_html( $list['name'] ); ?></strong></a>
							<?php echo LorePort_Settings::default_list() === $list['id'] ? '<span class="loreport-badge">' . esc_html__( 'Default', 'loreport' ) . '</span>' : ''; ?></td>
						<td class="num"><?php echo esc_html( self::number( $list['counts']['subscribed'] ?? null ) ); ?></td>
						<td class="num"><?php echo esc_html( self::number( $list['counts']['pending'] ?? null ) ); ?></td>
						<td class="num"><?php echo esc_html( self::number( $list['counts']['unsubscribed'] ?? null ) ); ?></td>
						<td><code><?php echo esc_html( $list['id'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent newsletters', 'loreport' ); ?></h2>
			<?php if ( null === $data['newsletters'] ) : ?>
				<p class="description"><?php esc_html_e( 'To see newsletters and their results here, give the API key the "newsletters:read" permission in LorePort.', 'loreport' ); ?></p>
			<?php elseif ( ! $data['newsletters'] ) : ?>
				<p><?php esc_html_e( 'No newsletters yet.', 'loreport' ); ?></p>
			<?php else : ?>
				<table class="widefat striped loreport-table">
					<thead><tr>
						<th><?php esc_html_e( 'Subject', 'loreport' ); ?></th>
						<th><?php esc_html_e( 'Status', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Sent', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Opened by', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Clicked by', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Unsubscribed', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Bounced', 'loreport' ); ?></th>
						<th class="num"><?php esc_html_e( 'Spam', 'loreport' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $data['newsletters'] as $newsletter ) : ?>
						<?php
						$stats = $newsletter['stats'];
						$sent  = $stats['sent'] ?? null;
						$pct   = fn( $n ) => null === $n ? '—' : number_format_i18n( (int) $n ) . ( $sent ? ' (' . round( 100 * $n / $sent ) . '%)' : '' );
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== $newsletter['subject'] ? $newsletter['subject'] : __( '(no subject)', 'loreport' ) ); ?></strong><br>
								<small><?php echo esc_html( implode( ' · ', array_filter( array( $newsletter['list'], self::date( $newsletter['date'] ) ) ) ) ); ?></small></td>
							<td><?php echo esc_html( ucfirst( $newsletter['status'] ) ); ?></td>
							<td class="num"><?php echo esc_html( self::number( $sent ) ); ?></td>
							<td class="num"><?php echo esc_html( $stats ? $pct( $stats['opened_by'] ?? null ) : '—' ); ?></td>
							<td class="num"><?php echo esc_html( $stats ? $pct( $stats['clicked_by'] ?? null ) : '—' ); ?></td>
							<td class="num"><?php echo esc_html( $stats ? self::number( $stats['unsubscribed'] ?? null ) : '—' ); ?></td>
							<td class="num"><?php echo esc_html( $stats ? self::number( $stats['bounced'] ?? null ) : '—' ); ?></td>
							<td class="num"><?php echo esc_html( $stats ? self::number( $stats['complained'] ?? null ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( '"Opened by" shows a dash when open tracking was off for that newsletter.', 'loreport' ); ?></p>
			<?php endif; ?>

			<?php if ( $data['usage'] ) : ?>
				<h2><?php esc_html_e( 'Plan and usage', 'loreport' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: plan, 2: subscribers used, 3: subscriber limit, 4: emails used, 5: email limit */
							__( '%1$s plan: %2$s of %3$s subscribers, %4$s of %5$s emails this month.', 'loreport' ),
							(string) ( $data['usage']['plan']['name'] ?? '' ),
							self::number( $data['usage']['subscribers']['used'] ?? null ),
							isset( $data['usage']['subscribers']['limit'] ) ? self::number( $data['usage']['subscribers']['limit'] ) : __( 'unlimited', 'loreport' ),
							self::number( $data['usage']['emails_this_month']['used'] ?? null ),
							isset( $data['usage']['emails_this_month']['limit'] ) ? self::number( $data['usage']['emails_this_month']['limit'] ) : __( 'unlimited', 'loreport' )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: time ago */ __( 'Figures from %s ago.', 'loreport' ), human_time_diff( (int) $data['checked_at'] ) ) ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Subscribers */

	private static function subscriber_url( string $id ): string {
		return admin_url( 'admin.php?page=' . self::SUBSCRIBERS . '&subscriber=' . rawurlencode( $id ) );
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows the result of the last action.
		$key = isset( $_GET['loreport_notice'] ) ? sanitize_key( wp_unslash( $_GET['loreport_notice'] ) ) : '';
		// phpcs:enable
		$notice = '' !== $key ? get_transient( 'loreport_notice_' . get_current_user_id() . '_' . $key ) : false;
		if ( is_array( $notice ) ) {
			delete_transient( 'loreport_notice_' . get_current_user_id() . '_' . $key );
			echo '<div class="notice ' . ( $notice['ok'] ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	public static function render_subscribers(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		if ( isset( $_GET['subscriber'] ) ) {
			self::render_subscriber( sanitize_text_field( wp_unslash( $_GET['subscriber'] ) ) );

			return;
		}
		$filters = array(
			'search' => isset( $_GET['s'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 100 ) : '',
			'list'   => isset( $_GET['list'] ) ? LorePort_Settings::sanitize_list_id( sanitize_text_field( wp_unslash( $_GET['list'] ) ) ) : '',
			'status' => isset( $_GET['status'] ) && in_array( $_GET['status'], self::STATUSES, true ) ? sanitize_key( $_GET['status'] ) : '',
		);
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$lists = LorePort_Client::lists();
		$query = array_filter( $filters + array( 'sort' => 'joined', 'dir' => 'desc', 'page' => $page, 'per_page' => 25 ) );
		$result = '' !== LorePort_Settings::api_key() ? LorePort_Client::request( 'GET', 'subscribers?' . http_build_query( $query ) ) : null;
		$rows   = $result && 200 === $result['status'] ? array_map( array( __CLASS__, 'summary' ), (array) ( $result['body']['data'] ?? array() ) ) : array();
		$meta   = (array) ( $result['body']['meta'] ?? array() );
		$base   = admin_url( 'admin.php?page=' . self::SUBSCRIBERS );
		$link   = fn( array $extra ) => add_query_arg( array_filter( array( 's' => $filters['search'], 'list' => $filters['list'], 'status' => $filters['status'] ) + $extra ), $base );
		?>
		<div class="wrap loreport-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'loreport' ); ?></h1>
			<a href="#loreport-add" class="page-title-action"><?php esc_html_e( 'Add someone', 'loreport' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notices(); ?>

			<?php if ( null === $result ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add your LorePort API key under Settings → LorePort first.', 'loreport' ); ?></p></div></div>
				<?php
				return;
			endif;
			?>
			<?php if ( 200 !== $result['status'] ) : ?>
				<div class="notice notice-error inline"><p>
					<?php echo esc_html( 403 === $result['status'] ? __( 'This API key can\'t read subscribers. In LorePort, go to Settings → API and webhooks and give it the "subscribers:read" permission (and "subscribers:write" to make changes here).', 'loreport' ) : self::error_message( $result ) ); ?>
				</p></div></div>
				<?php
				return;
			endif;
			?>

			<form method="get" class="loreport-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SUBSCRIBERS ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Email or name', 'loreport' ); ?>">
				<select name="list">
					<option value=""><?php esc_html_e( 'All lists', 'loreport' ); ?></option>
					<?php foreach ( $lists as $list ) : ?>
						<option value="<?php echo esc_attr( (string) $list['id'] ); ?>" <?php selected( $filters['list'], (string) $list['id'] ); ?>><?php echo esc_html( (string) ( $list['name'] ?? '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'Any status', 'loreport' ); ?></option>
					<?php foreach ( self::STATUSES as $status ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( self::status_label( $status ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Search', 'loreport' ), 'secondary', '', false ); ?>
				<?php if ( array_filter( $filters ) ) : ?>
					<a href="<?php echo esc_url( $base ); ?>" class="button-link"><?php esc_html_e( 'Clear', 'loreport' ); ?></a>
				<?php endif; ?>
				<span class="loreport-count">
					<?php
					$total = (int) ( $meta['total'] ?? count( $rows ) );
					/* translators: %s: number of people */
					echo esc_html( sprintf( _n( '%s person', '%s people', $total, 'loreport' ), number_format_i18n( $total ) ) );
					?>
				</span>
			</form>

			<table class="widefat striped loreport-table">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'Lists', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'Status', 'loreport' ); ?></th>
					<th class="num"><?php esc_html_e( 'Opened', 'loreport' ); ?></th>
					<th class="num"><?php esc_html_e( 'Clicks', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'Joined', 'loreport' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php echo array_filter( $filters ) ? esc_html__( 'Nobody matches.', 'loreport' ) : esc_html__( 'No subscribers yet.', 'loreport' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::subscriber_url( $row['id'] ) ); ?>"><strong><?php echo esc_html( $row['email'] ); ?></strong></a>
							<?php if ( '' !== $row['name'] ) : ?><br><small><?php echo esc_html( $row['name'] ); ?></small><?php endif; ?></td>
						<td>
							<?php foreach ( $row['lists'] as $membership ) : ?>
								<span class="loreport-pill<?php echo 'unsubscribed' === $membership['status'] ? ' is-off' : ''; ?>" title="<?php echo esc_attr( self::status_label( $membership['status'] ) ); ?>"><?php echo esc_html( $membership['name'] ); ?></span>
							<?php endforeach; ?>
						</td>
						<td><?php echo self::badge( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
						<td class="num"><?php echo esc_html( self::number( $row['opens'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::number( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( self::date( $row['joined'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $page > 1 || ! empty( $meta['has_more'] ) ) : ?>
				<p class="loreport-pages">
					<?php if ( $page > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( $link( array( 'paged' => $page - 1 ) ) ); ?>">← <?php esc_html_e( 'Newer', 'loreport' ); ?></a>
					<?php endif; ?>
					<?php /* translators: %d: page number */ echo esc_html( sprintf( __( 'Page %d', 'loreport' ), $page ) ); ?>
					<?php if ( ! empty( $meta['has_more'] ) ) : ?>
						<a class="button" href="<?php echo esc_url( $link( array( 'paged' => $page + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'loreport' ); ?> →</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<h2 id="loreport-add"><?php esc_html_e( 'Add someone', 'loreport' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loreport-add">
				<input type="hidden" name="action" value="loreport_subscriber_add">
				<?php wp_nonce_field( 'loreport_subscriber_add' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="loreport-add-email"><?php esc_html_e( 'Email', 'loreport' ); ?></label></th>
						<td><input type="email" id="loreport-add-email" name="email" required class="regular-text"></td></tr>
					<tr><th scope="row"><label for="loreport-add-name"><?php esc_html_e( 'Name', 'loreport' ); ?></label></th>
						<td><input type="text" id="loreport-add-name" name="name" class="regular-text"> <span class="description"><?php esc_html_e( '(optional)', 'loreport' ); ?></span></td></tr>
					<tr><th scope="row"><label for="loreport-add-list"><?php esc_html_e( 'List', 'loreport' ); ?></label></th>
						<td><select id="loreport-add-list" name="list" required>
							<?php foreach ( $lists as $list ) : ?>
								<option value="<?php echo esc_attr( (string) $list['id'] ); ?>" <?php selected( LorePort_Settings::default_list(), (string) $list['id'] ); ?>><?php echo esc_html( (string) ( $list['name'] ?? '' ) ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Consent', 'loreport' ); ?></th>
						<td>
							<p><label><input type="checkbox" name="consent" value="1" required> <strong><?php esc_html_e( 'They agreed to receive my emails', 'loreport' ); ?></strong></label></p>
							<p><input type="text" name="consent_text" class="large-text" maxlength="1000" required placeholder="<?php esc_attr_e( 'How they agreed, e.g. "Asked to join at the Toronto book fair, Nov 8"', 'loreport' ); ?>"></p>
							<p class="description"><?php esc_html_e( 'Canadian (CASL) and EU (GDPR) law need their consent, and a record of how you got it. Don\'t add people from a contact list or a business card unless they asked to join.', 'loreport' ); ?></p>
							<p><label><input type="checkbox" name="confirm" value="1"> <?php esc_html_e( 'Ask them to confirm by email first', 'loreport' ); ?></label><br>
								<span class="description"><?php esc_html_e( 'They\'re added once they click the link in LorePort\'s confirmation email.', 'loreport' ); ?></span></p>
						</td></tr>
				</table>
				<?php submit_button( __( 'Add', 'loreport' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_subscriber( string $id ): void {
		$response = LorePort_Client::request( 'GET', 'subscribers/' . rawurlencode( $id ) );
		$back     = admin_url( 'admin.php?page=' . self::SUBSCRIBERS );
		echo '<div class="wrap loreport-admin">';
		if ( 200 !== $response['status'] ) {
			echo '<h1>' . esc_html__( 'Subscriber', 'loreport' ) . '</h1><div class="notice notice-error inline"><p>' . esc_html( self::error_message( $response ) ) . '</p></div><p><a href="' . esc_url( $back ) . '">← ' . esc_html__( 'Subscribers', 'loreport' ) . '</a></p></div>';

			return;
		}
		$person  = self::summary( (array) ( $response['body']['data'] ?? array() ) );
		$sources = array(
			'form'   => __( 'Signup form', 'loreport' ),
			'api'    => __( 'Website (API)', 'loreport' ),
			'import' => __( 'Import', 'loreport' ),
			'manual' => __( 'Added by the author', 'loreport' ),
		);
		$action  = fn( string $name ) => '<input type="hidden" name="action" value="loreport_subscriber_' . esc_attr( $name ) . '"><input type="hidden" name="subscriber" value="' . esc_attr( $person['id'] ) . '">' . wp_nonce_field( 'loreport_subscriber_' . $name . '_' . $person['id'], '_wpnonce', true, false );
		?>
			<h1 class="wp-heading-inline"><?php echo esc_html( $person['email'] ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Subscribers', 'loreport' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notices(); ?>

			<h2><?php esc_html_e( 'Lists', 'loreport' ); ?></h2>
			<table class="widefat striped loreport-table">
				<thead><tr>
					<th><?php esc_html_e( 'List', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'Status', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'How they joined', 'loreport' ); ?></th>
					<th><?php esc_html_e( 'Dates', 'loreport' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $person['lists'] as $membership ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $membership['name'] ); ?></strong></td>
						<td><?php echo self::badge( $membership['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
						<td><?php echo esc_html( $sources[ $membership['consent_source'] ?? '' ] ?? '—' ); ?></td>
						<td><small>
							<?php
							foreach ( array( 'consented_at' => __( 'Agreed', 'loreport' ), 'confirmed_at' => __( 'Confirmed', 'loreport' ), 'unsubscribed_at' => __( 'Unsubscribed', 'loreport' ) ) as $field => $label ) {
								if ( ! empty( $membership[ $field ] ) ) {
									echo esc_html( $label . ': ' . self::date( $membership[ $field ], true ) ) . '<br>';
								}
							}
							?>
						</small></td>
						<td>
							<?php if ( 'unsubscribed' !== $membership['status'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Unsubscribe them from this list?', 'loreport' ) ); ?>')">
									<?php echo $action( 'unsubscribe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
									<input type="hidden" name="list" value="<?php echo esc_attr( $membership['id'] ); ?>">
									<button class="button-link loreport-danger"><?php esc_html_e( 'Unsubscribe', 'loreport' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: newsletters opened, 2: links clicked, 3: date */
						__( 'Opened %1$s newsletters, clicked %2$s links. Joined %3$s.', 'loreport' ),
						self::number( $person['opens'] ),
						self::number( $person['clicks'] ),
						self::date( $person['joined'] )
					)
				);
				?>
				<a href="<?php echo esc_url( LorePort_Client::base_url() . '/subscribers/' . rawurlencode( $person['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full history in LorePort ↗', 'loreport' ); ?></a>
			</p>

			<div class="loreport-columns">
				<div>
					<h2><?php esc_html_e( 'Details', 'loreport' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php echo $action( 'update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						<p><label for="loreport-email"><?php esc_html_e( 'Email', 'loreport' ); ?></label><br>
							<input type="email" id="loreport-email" name="email" required class="regular-text" value="<?php echo esc_attr( $person['email'] ); ?>"><br>
							<span class="description"><?php esc_html_e( 'For the same person only (a typo or their new address): they keep their lists and consent.', 'loreport' ); ?></span></p>
						<p><label for="loreport-name"><?php esc_html_e( 'Name', 'loreport' ); ?></label><br>
							<input type="text" id="loreport-name" name="name" class="regular-text" value="<?php echo esc_attr( $person['name'] ); ?>"></p>
						<?php submit_button( __( 'Save', 'loreport' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
				<div>
					<h2><?php esc_html_e( 'Tags', 'loreport' ); ?></h2>
					<div class="loreport-tags">
						<?php foreach ( $person['tags'] as $tag ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loreport-tag">
								<?php echo $action( 'untag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
								<input type="hidden" name="tag" value="<?php echo esc_attr( $tag ); ?>">
								<?php echo esc_html( $tag ); ?> <button class="button-link" aria-label="<?php esc_attr_e( 'Remove tag', 'loreport' ); ?>">&times;</button>
							</form>
						<?php endforeach; ?>
						<?php if ( ! $person['tags'] ) : ?>
							<span class="description"><?php esc_html_e( 'No tags.', 'loreport' ); ?></span>
						<?php endif; ?>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php echo $action( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						<input type="text" name="tag" required maxlength="100" placeholder="<?php esc_attr_e( 'Add a tag, e.g. ARC reader', 'loreport' ); ?>">
						<?php submit_button( __( 'Add', 'loreport' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<?php if ( 'unsubscribed' !== $person['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Unsubscribe them from every list?', 'loreport' ) ); ?>')" class="loreport-unsubscribe-all">
					<?php echo $action( 'unsubscribe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
					<button class="button loreport-danger"><?php esc_html_e( 'Unsubscribe from every list', 'loreport' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Actions (admin-post.php) */

	/**
	 * Checks rights and the nonce for an action on one subscriber.
	 *
	 * @param string $name Action name.
	 * @return string The subscriber ID ('' for "add").
	 */
	private static function guard( string $name ): string {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You can\'t change subscribers.', 'loreport' ), 403 );
		}
		$id = isset( $_POST['subscriber'] ) ? sanitize_text_field( wp_unslash( $_POST['subscriber'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'loreport_subscriber_' . $name . ( '' !== $id ? '_' . $id : '' ) );
		if ( 'add' !== $name && ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) {
			wp_die( esc_html__( 'Unknown subscriber.', 'loreport' ), 400 );
		}

		return $id;
	}

	/**
	 * Back to the page the action came from, with its result.
	 *
	 * @param bool   $ok      Whether it worked.
	 * @param string $message What to show.
	 * @param string $id      Subscriber to return to, if any.
	 */
	private static function done( bool $ok, string $message, string $id = '' ): void {
		$key = wp_generate_password( 8, false );
		set_transient( 'loreport_notice_' . get_current_user_id() . '_' . strtolower( $key ), compact( 'ok', 'message' ), 5 * MINUTE_IN_SECONDS );
		if ( $ok ) {
			self::forget();
		}
		$url = '' !== $id ? self::subscriber_url( $id ) : admin_url( 'admin.php?page=' . self::SUBSCRIBERS );
		wp_safe_redirect( add_query_arg( 'loreport_notice', strtolower( $key ), $url ) );
		exit;
	}

	private static function answer( array $response, string $success ): array {
		return in_array( $response['status'], array( 200, 201 ), true )
			? array( true, $success )
			: array( false, self::error_message( $response ) );
	}

	public static function handle_add(): void {
		self::guard( 'add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$list    = isset( $_POST['list'] ) ? LorePort_Settings::sanitize_list_id( sanitize_text_field( wp_unslash( $_POST['list'] ) ) ) : '';
		$agreed  = ! empty( $_POST['consent'] );
		$confirm = ! empty( $_POST['confirm'] );
		$consent = isset( $_POST['consent_text'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['consent_text'] ) ), 0, 1000 ) : '';
		// phpcs:enable
		if ( ! is_email( $email ) || '' === $list ) {
			self::done( false, __( 'Please enter an email address and choose a list.', 'loreport' ) );
		}
		if ( ! $agreed || '' === $consent ) {
			self::done( false, __( 'You can only add people who agreed to receive your emails. Say how they agreed, for example "Asked to join at the Toronto book fair".', 'loreport' ) );
		}

		// Never /signups here: that would record a signup on the website.
		$response = LorePort_Client::request(
			'POST',
			'subscribers',
			array_filter(
				array(
					'email'        => $email,
					'name'         => '' !== $name ? $name : null,
					'list_id'      => $list,
					'consent'      => true,
					'consent_text' => $consent,
					'confirm'      => $confirm,
				),
				fn( $value ) => null !== $value
			)
		);

		if ( in_array( $response['status'], array( 200, 201 ), true ) ) {
			$outcome = (string) ( $response['body']['outcome'] ?? '' );
			/* translators: %s: email address */
			$message = 'pending_confirmation' === $outcome ? __( '%s has been sent a confirmation email.', 'loreport' ) : ( 'subscribed' === $outcome ? __( '%s is subscribed.', 'loreport' ) : __( '%s was already subscribed.', 'loreport' ) );
			self::done( true, sprintf( $message, $email ) );
		}
		self::done( false, self::error_message( $response ) );
	}

	public static function handle_update(): void {
		$id = self::guard( 'update' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable
		[ $ok, $message ] = self::answer( LorePort_Client::request( 'PATCH', 'subscribers/' . rawurlencode( $id ), array( 'email' => $email, 'name' => $name ) ), __( 'Saved.', 'loreport' ) );
		self::done( $ok, $message, $id );
	}

	public static function handle_unsubscribe(): void {
		$id   = self::guard( 'unsubscribe' );
		$list = isset( $_POST['list'] ) ? LorePort_Settings::sanitize_list_id( sanitize_text_field( wp_unslash( $_POST['list'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		[ $ok, $message ] = self::answer( LorePort_Client::request( 'POST', 'subscribers/' . rawurlencode( $id ) . '/unsubscribe', '' !== $list ? array( 'list_id' => $list ) : array() ), __( 'Unsubscribed.', 'loreport' ) );
		self::done( $ok, $message, $id );
	}

	public static function handle_tag(): void {
		$id  = self::guard( 'tag' );
		$tag = isset( $_POST['tag'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['tag'] ) ), 0, 100 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		[ $ok, $message ] = self::answer( LorePort_Client::request( 'POST', 'subscribers/' . rawurlencode( $id ) . '/tags', array( 'tag' => $tag ) ), __( 'Tag added.', 'loreport' ) );
		self::done( $ok, $message, $id );
	}

	public static function handle_untag(): void {
		$id  = self::guard( 'untag' );
		$tag = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		[ $ok, $message ] = self::answer( LorePort_Client::request( 'DELETE', 'subscribers/' . rawurlencode( $id ) . '/tags/' . rawurlencode( $tag ) ), __( 'Tag removed.', 'loreport' ) );
		self::done( $ok, $message, $id );
	}
}
