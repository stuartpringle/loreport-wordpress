<?php
/**
 * Sending signups to LorePort, with a retry queue.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends signups to LorePort's POST /signups, which treats them like its own
 * signup forms (double opt-in, consent record with the reader's IP address).
 * When LorePort can't be reached, signups wait in a queue and WP-Cron retries
 * them for up to a week.
 */
class LorePort_Signups {

	const QUEUE_OPTION = 'loreport_signup_queue';

	const CRON_HOOK = 'loreport_retry_signups';

	/** Signups kept waiting at most. */
	const QUEUE_LIMIT = 1000;

	/** How long a waiting signup is kept, in seconds. */
	const QUEUE_DAYS = 7;

	public static function init(): void {
		add_action( 'admin_post_nopriv_loreport_signup', array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_post_loreport_signup', array( __CLASS__, 'handle_form' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'retry' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_retries();
		}
	}

	public static function schedule_retries(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule_retries(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Sends one signup.
	 *
	 * @param array $signup email, list_id, and optionally name, ip, user_agent, source, consent_text.
	 * @return string pending, subscribed, already, refused, invalid, queued or error.
	 */
	public static function submit( array $signup, bool $queue_on_failure = true ): string {
		$signup = array_filter(
			array(
				'email'        => sanitize_email( (string) ( $signup['email'] ?? '' ) ),
				'name'         => isset( $signup['name'] ) ? mb_substr( sanitize_text_field( (string) $signup['name'] ), 0, 255 ) : null,
				'list_id'      => (string) ( $signup['list_id'] ?? '' ),
				'ip'           => isset( $signup['ip'] ) && filter_var( $signup['ip'], FILTER_VALIDATE_IP ) ? (string) $signup['ip'] : null,
				'user_agent'   => isset( $signup['user_agent'] ) ? mb_substr( (string) $signup['user_agent'], 0, 500 ) : null,
				'source'       => isset( $signup['source'] ) ? mb_substr( (string) $signup['source'], 0, 100 ) : null,
				'consent_text' => isset( $signup['consent_text'] ) ? mb_substr( (string) $signup['consent_text'], 0, 1000 ) : null,
			),
			fn( $value ) => null !== $value && '' !== $value
		);
		if ( empty( $signup['email'] ) || ! is_email( $signup['email'] ) ) {
			return 'invalid';
		}
		if ( empty( $signup['list_id'] ) ) {
			return 'error';
		}

		$response = LorePort_Client::request( 'POST', 'signups', $signup );
		$status   = $response['status'];

		if ( 200 === $status || 201 === $status ) {
			return array(
				'pending_confirmation' => 'pending',
				'subscribed'           => 'subscribed',
			)[ $response['body']['outcome'] ?? '' ] ?? 'already';
		}
		if ( 422 === $status ) {
			$fields = (array) ( $response['body']['error']['fields'] ?? array() );

			return isset( $fields['email'] ) && count( $fields ) === 1 && is_email( $signup['email'] ) ? 'refused' : 'invalid';
		}
		if ( 404 === $status ) {
			// The list doesn't exist (any more): a settings problem, not worth retrying.
			self::log( 'The list ' . $signup['list_id'] . ' wasn\'t found in LorePort. Check the form\'s list.' );

			return 'error';
		}

		// Unreachable, rate-limited, a LorePort error, or a key problem: keep it and try again later.
		self::log( 'Signup will be retried: ' . (string) $response['error'] );
		if ( $queue_on_failure ) {
			self::enqueue( $signup );
		}

		return $queue_on_failure ? 'queued' : 'error';
	}

	/** @return array<int, array{signup: array, attempts: int, first_at: int, next_at: int}> */
	public static function queue(): array {
		return (array) get_option( self::QUEUE_OPTION, array() );
	}

	private static function enqueue( array $signup ): void {
		$queue = self::queue();
		if ( count( $queue ) >= self::QUEUE_LIMIT ) {
			array_shift( $queue );
		}
		$queue[] = array(
			'signup'   => $signup,
			'attempts' => 1,
			'first_at' => time(),
			'next_at'  => time() + 5 * MINUTE_IN_SECONDS,
		);
		update_option( self::QUEUE_OPTION, array_values( $queue ), false );
	}

	/** WP-Cron: retries waiting signups whose time has come, with growing waits. */
	public static function retry(): void {
		$queue = self::queue();
		$keep  = array();
		foreach ( $queue as $item ) {
			if ( time() - (int) $item['first_at'] > self::QUEUE_DAYS * DAY_IN_SECONDS ) {
				self::log( 'Gave up on a signup after ' . self::QUEUE_DAYS . ' days.' );
				continue;
			}
			if ( (int) $item['next_at'] > time() ) {
				$keep[] = $item;
				continue;
			}
			if ( 'error' !== self::submit( (array) $item['signup'], false ) ) {
				continue; // Sent (or refused for good).
			}
			$item['attempts'] = (int) $item['attempts'] + 1;
			$item['next_at']  = time() + min( DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** min( 10, $item['attempts'] ) ) );
			$keep[]           = $item;
		}
		update_option( self::QUEUE_OPTION, $keep, false );
	}

	/** The reader's IP address, as recorded with their consent. Behind a proxy, use the loreport_reader_ip filter. */
	public static function reader_ip(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'loreport_reader_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	public static function reader_user_agent(): ?string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null;
	}

	/**
	 * Form tokens carry the list and when the form was shown, signed so the
	 * list can't be swapped and bots that post instantly can be spotted.
	 * No nonce: nonces go stale on cached pages.
	 */
	public static function token( string $list_id ): string {
		$payload = $list_id . '|' . time();

		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . substr( wp_hash( 'loreport-form|' . $payload ), 0, 20 );
	}

	/** @return array{list_id: string, shown_at: int}|null */
	public static function read_token( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$payload = (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		if ( ! hash_equals( substr( wp_hash( 'loreport-form|' . $payload ), 0, 20 ), $parts[1] ) ) {
			return null;
		}
		$fields = explode( '|', $payload );

		return 2 === count( $fields ) ? array(
			'list_id'  => $fields[0],
			'shown_at' => (int) $fields[1],
		) : null;
	}

	/** admin-post.php?action=loreport_signup: a signup form was sent. */
	public static function handle_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; see token().
		$form_id = isset( $_POST['loreport_form'] ) ? sanitize_key( wp_unslash( $_POST['loreport_form'] ) ) : '';
		$token   = isset( $_POST['loreport_token'] ) ? self::read_token( sanitize_text_field( wp_unslash( $_POST['loreport_token'] ) ) ) : null;
		$email   = isset( $_POST['loreport_email'] ) ? sanitize_email( wp_unslash( $_POST['loreport_email'] ) ) : '';
		$name    = isset( $_POST['loreport_name'] ) ? sanitize_text_field( wp_unslash( $_POST['loreport_name'] ) ) : '';
		$trap    = isset( $_POST['loreport_website'] ) ? sanitize_text_field( wp_unslash( $_POST['loreport_website'] ) ) : '';
		$page    = isset( $_POST['loreport_page'] ) ? absint( $_POST['loreport_page'] ) : 0;
		// phpcs:enable

		if ( null === $token ) {
			$status = 'error';
		} elseif ( '' !== $trap || time() - $token['shown_at'] < 2 ) {
			$status = 'subscribed'; // A bot: pretend it worked, send nothing.
		} elseif ( ! is_email( $email ) ) {
			$status = 'invalid';
		} elseif ( empty( $_POST['loreport_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form; see token().
			$status = 'consent';
		} elseif ( self::too_many() ) {
			$status = 'limited';
		} else {
			$list   = LorePort_Client::find_list( $token['list_id'] );
			$status = self::submit(
				array(
					'email'        => $email,
					'name'         => '' !== $name ? $name : null,
					'list_id'      => $token['list_id'],
					'ip'           => self::reader_ip(),
					'user_agent'   => self::reader_user_agent(),
					'source'       => 'wordpress:form' . ( $page ? ':' . $page : '' ),
					'consent_text' => self::consent_text( $list ),
				)
			);
		}

		if ( wp_doing_ajax() || ( isset( $_SERVER['HTTP_ACCEPT'] ) && str_contains( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 'application/json' ) ) ) {
			wp_send_json(
				array(
					'status'  => $status,
					'message' => self::message( $status ),
				),
				in_array( $status, array( 'invalid', 'consent', 'refused', 'error', 'limited' ), true ) ? 400 : 200
			);
		}

		$back = wp_get_referer();
		$back = $back ? $back : home_url( '/' );
		wp_safe_redirect( add_query_arg( array( 'loreport_status' => $status, 'loreport_form' => $form_id ), remove_query_arg( array( 'loreport_status', 'loreport_form' ), $back ) ) . ( '' !== $form_id ? '#' . $form_id : '' ) );
		exit;
	}

	/**
	 * What the reader ticks to agree: the list's consent statement in LorePort,
	 * or a plain one if the list has none.
	 *
	 * @param array|null $list The list, from LorePort_Client::find_list().
	 */
	public static function consent_text( ?array $list ): string {
		$text = trim( (string) ( $list['consent_text'] ?? '' ) );

		return '' !== $text ? $text : sprintf(
			/* translators: %s: site name */
			__( 'Yes, email me news and updates from %s. I can unsubscribe at any time.', 'loreport' ),
			get_bloginfo( 'name' )
		);
	}

	/** At most 5 signups per IP address in ten minutes. */
	private static function too_many(): bool {
		$key   = 'loreport_rate_' . md5( (string) self::reader_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= (int) apply_filters( 'loreport_signups_per_ip', 5 ) ) {
			return true;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return false;
	}

	/** What to tell the reader. */
	public static function message( string $status ): string {
		$messages = array(
			'pending'    => __( 'Almost done: check your inbox and click the link to confirm.', 'loreport' ),
			'subscribed' => __( 'You\'re subscribed. Thank you!', 'loreport' ),
			'already'    => __( 'You\'re already subscribed. Thank you!', 'loreport' ),
			'queued'     => __( 'Thank you! Your signup is on its way. If you need to confirm it, the email may take a little while.', 'loreport' ),
			'refused'    => __( 'Sorry, that address can\'t be signed up. Please get in touch if you think that\'s a mistake.', 'loreport' ),
			'invalid'    => __( 'Please enter a valid email address.', 'loreport' ),
			'consent'    => __( 'Please tick the box to agree to the emails.', 'loreport' ),
			'limited'    => __( 'Please wait a few minutes and try again.', 'loreport' ),
			'error'      => __( 'Sorry, something went wrong. Please try again later.', 'loreport' ),
		);

		return (string) apply_filters( 'loreport_signup_message', $messages[ $status ] ?? $messages['error'], $status );
	}

	/** For the block editor: the account's lists, without exposing the key. */
	public static function rest_routes(): void {
		register_rest_route(
			'loreport/v1',
			'/lists',
			array(
				'methods'             => 'GET',
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'callback'            => fn() => rest_ensure_response(
					array_map(
						fn( $list ) => array(
							'id'   => (string) ( $list['id'] ?? '' ),
							'name' => (string) ( $list['name'] ?? '' ),
						),
						LorePort_Client::lists()
					)
				),
			)
		);
	}

	public static function admin_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( 'manage_options' ) || ( $screen && str_ends_with( (string) $screen->id, '_page_loreport' ) ) ) {
			return;
		}
		if ( '' === LorePort_Settings::api_key() ) {
			echo '<div class="notice notice-info"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the settings page */
					__( 'LorePort is almost ready: <a href="%s">add your API key</a>.', 'loreport' ),
					esc_url( LorePort_Settings::url( 'connection' ) )
				)
			) . '</p></div>';
		}
	}

	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[LorePort] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
