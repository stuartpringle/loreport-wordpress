<?php
/**
 * Reads what MailPoet holds, for "Import from MailPoet".
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to MailPoet's own tables (MailPoet 5): its lists,
 * subscribers and tags, sent newsletters with their totals, readers' opens
 * and clicks, automations, saved templates, forms and sender settings.
 * Nothing here changes MailPoet's data. Times are read as Unix timestamps
 * (MySQL shows TIMESTAMP columns in the connection's time zone, which
 * varies) and sent as UTC.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- MailPoet's own tables have no WordPress API.
 */
class LorePort_MailPoet {

	/** A Unix timestamp as UTC "Y-m-d H:i:s", or null. */
	private static function utc( $timestamp ): ?string {
		return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : null;
	}

	/** MailPoet's table, with the site's prefix. */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'mailpoet_' . $name;
	}

	public static function has_table( string $name ): bool {
		global $wpdb;

		return self::table( $name ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table( $name ) ) ) );
	}

	/** MailPoet's tables are here (it's installed, or was). */
	public static function available(): bool {
		return self::has_table( 'subscribers' ) && self::has_table( 'segments' ) && self::has_table( 'subscriber_segment' );
	}

	/**
	 * Lists in use (not deleted), with how many subscribers are on each.
	 * MailPoet's automatic WordPress Users and WooCommerce Customers lists are left out.
	 *
	 * @return array<int, array{id: int, name: string, count: int}>
	 */
	public static function lists(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT g.id, g.name, COUNT(s.id) AS members FROM %i g LEFT JOIN %i ss ON ss.segment_id = g.id LEFT JOIN %i s ON s.id = ss.subscriber_id AND s.deleted_at IS NULL WHERE g.deleted_at IS NULL AND g.type = %s GROUP BY g.id, g.name ORDER BY g.name',
				self::table( 'segments' ),
				self::table( 'subscriber_segment' ),
				self::table( 'subscribers' ),
				'default'
			),
			ARRAY_A
		);

		return array_map(
			fn ( $r ) => array(
				'id'    => (int) $r['id'],
				'name'  => (string) $r['name'],
				'count' => (int) $r['members'],
			),
			(array) $rows
		);
	}

	/**
	 * What there is to bring over, for the import screen to offer only what exists.
	 *
	 * @return array<string, mixed>
	 */
	public static function inventory(): array {
		global $wpdb;
		$count = fn ( string $table, string $where = '1=1' ) => self::has_table( $table )
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, self::table( $table ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed conditions only.
			: 0;
		$sent = self::sent_newsletter_ids();

		return array(
			'subscribers'  => $count( 'subscribers', 'deleted_at IS NULL' ),
			'tags'         => $count( 'subscriber_tag' ),
			'newsletters'  => count( $sent ),
			'opens'        => $count( 'statistics_opens' ),
			'clicks'       => $count( 'statistics_clicks' ),
			'unsubscribes' => $count( 'statistics_unsubscribes' ),
			'automations'  => self::automations_summary(),
			'templates'    => $count( 'newsletter_templates', 'readonly = 0' ),
			'forms'        => self::forms(),
			'confirmation' => self::confirmation(),
			'sender'       => self::sender(),
		);
	}

	/**
	 * One list's subscribers after $after (MailPoet's subscriber-list row ID),
	 * in LorePort's import terms.
	 *
	 * @return array{rows: array<int, array>, last: int}
	 */
	public static function subscribers( int $list_id, int $after, int $limit = 500 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ss.id AS row_id, s.id, s.email, s.first_name, s.last_name, s.status, ss.status AS list_status, s.subscribed_ip, UNIX_TIMESTAMP(s.created_at) AS created_ts, UNIX_TIMESTAMP(s.confirmed_at) AS confirmed_ts, g.name AS list_name
				FROM %i ss JOIN %i s ON s.id = ss.subscriber_id JOIN %i g ON g.id = ss.segment_id
				WHERE ss.segment_id = %d AND ss.id > %d AND s.deleted_at IS NULL ORDER BY ss.id LIMIT %d',
				self::table( 'subscriber_segment' ),
				self::table( 'subscribers' ),
				self::table( 'segments' ),
				$list_id,
				$after,
				$limit
			),
			ARRAY_A
		);
		$tags = self::tags_for( array_map( 'intval', array_column( $rows, 'id' ) ) );

		$out  = array();
		$last = $after;
		foreach ( $rows as $r ) {
			$last  = (int) $r['row_id'];
			$out[] = array_filter(
				array(
					'email'        => (string) $r['email'],
					'name'         => trim( $r['first_name'] . ' ' . $r['last_name'] ),
					'status'       => self::status( (string) $r['status'], (string) $r['list_status'] ),
					'list'         => (string) $r['list_name'],
					'signed_up_at' => self::utc( $r['created_ts'] ),
					'signup_ip'    => filter_var( (string) $r['subscribed_ip'], FILTER_VALIDATE_IP ) ? $r['subscribed_ip'] : null,
					'confirmed_at' => self::utc( $r['confirmed_ts'] ),
					'tags'         => $tags[ (int) $r['id'] ] ?? array(),
				),
				fn ( $v ) => null !== $v && '' !== $v && array() !== $v
			);
		}

		return array(
			'rows' => $out,
			'last' => $last,
		);
	}

	/**
	 * MailPoet's statuses in LorePort's words. Leaving one list (while still
	 * subscribed in MailPoet) isn't an unsubscribe: "left_list".
	 */
	public static function status( string $global, string $list ): string {
		$status = match ( $global ) {
			'subscribed'   => 'subscribed',
			'inactive'     => 'inactive',
			'unconfirmed'  => 'unconfirmed',
			'unsubscribed' => 'unsubscribed',
			'bounced'      => 'bounced',
			default        => 'unconfirmed',
		};

		return 'unsubscribed' === $list && in_array( $status, array( 'subscribed', 'inactive', 'unconfirmed' ), true ) ? 'left_list' : $status;
	}

	/**
	 * Tag names per subscriber (MailPoet 4.18 and later).
	 *
	 * @param int[] $ids Subscriber IDs.
	 * @return array<int, string[]>
	 */
	private static function tags_for( array $ids ): array {
		global $wpdb;
		if ( array() === $ids || ! self::has_table( 'tags' ) || ! self::has_table( 'subscriber_tag' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per ID, added below.
			$wpdb->prepare(
				'SELECT st.subscriber_id, t.name FROM %i st JOIN %i t ON t.id = st.tag_id WHERE st.subscriber_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built for each ID.
				self::table( 'subscriber_tag' ),
				self::table( 'tags' ),
				...$ids
			),
			ARRAY_A
		);
		$tags = array();
		foreach ( $rows as $r ) {
			$tags[ (int) $r['subscriber_id'] ][] = mb_substr( (string) $r['name'], 0, 64 );
		}

		return array_map( fn ( $t ) => array_slice( $t, 0, 20 ), $tags );
	}

	/** @return int[] Sent (standard) newsletters, oldest first. */
	public static function sent_newsletter_ids(): array {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE type = %s AND status = %s AND deleted_at IS NULL ORDER BY sent_at, id', self::table( 'newsletters' ), 'standard', 'sent' )
			)
		);
	}

	/**
	 * A sent newsletter: subject, preview text, when, its lists by name, the
	 * HTML as sent (or rendered again by MailPoet when it no longer keeps
	 * it), its editor content and its totals.
	 */
	public static function newsletter( int $id ): ?array {
		global $wpdb;
		$n = $wpdb->get_row( $wpdb->prepare( 'SELECT id, subject, preheader, UNIX_TIMESTAMP(sent_at) AS sent_ts, body FROM %i WHERE id = %d', self::table( 'newsletters' ), $id ), ARRAY_A );
		if ( ! $n ) {
			return null;
		}
		$lists = (array) $wpdb->get_col(
			$wpdb->prepare( 'SELECT g.name FROM %i ns JOIN %i g ON g.id = ns.segment_id WHERE ns.newsletter_id = %d', self::table( 'newsletter_segment' ), self::table( 'segments' ), $id )
		);
		$rendered = $wpdb->get_var(
			$wpdb->prepare( 'SELECT newsletter_rendered_body FROM %i WHERE newsletter_id = %d AND newsletter_rendered_body IS NOT NULL ORDER BY id DESC LIMIT 1', self::table( 'sending_queues' ), $id )
		);
		$body = $rendered ? json_decode( (string) $rendered, true ) : null;
		if ( ! is_array( $body ) || empty( $body['html'] ) ) {
			$body = self::render( $id );
		}
		$count = fn ( string $table, string $what = 'COUNT(*)' ) => self::has_table( $table )
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT ' . $what . ' FROM %i WHERE newsletter_id = %d', self::table( $table ), $id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed expressions only.
			: 0;

		return array(
			'source_id'    => (string) $id,
			'subject'      => (string) $n['subject'],
			'preview_text' => '' !== (string) $n['preheader'] ? (string) $n['preheader'] : null,
			'sent_at'      => self::utc( $n['sent_ts'] ),
			'lists'        => array_map( 'strval', $lists ),
			'html'         => is_array( $body ) ? ( $body['html'] ?? null ) : null,
			'text'         => is_array( $body ) ? ( $body['text'] ?? null ) : null,
			'body'         => (string) $n['body'],
			'totals'       => array(
				'delivered'    => $count( 'statistics_newsletters' ),
				'opened'       => $count( 'statistics_opens', 'COUNT(DISTINCT subscriber_id)' ),
				'clicked'      => $count( 'statistics_clicks', 'COUNT(DISTINCT subscriber_id)' ),
				'unsubscribed' => $count( 'statistics_unsubscribes', 'COUNT(DISTINCT subscriber_id)' ),
			),
		);
	}

	/**
	 * MailPoet renders a newsletter again with its own renderer, when MailPoet is active.
	 *
	 * @return array{html: string, text: string}|null
	 */
	private static function render( int $id ): ?array {
		if ( ! class_exists( '\MailPoet\DI\ContainerWrapper' ) ) {
			return null;
		}
		try {
			$container  = \MailPoet\DI\ContainerWrapper::getInstance();
			$newsletter = $container->get( \MailPoet\Newsletter\NewslettersRepository::class )->findOneById( $id );
			if ( ! $newsletter ) {
				return null;
			}
			$rendered = $container->get( \MailPoet\Newsletter\Renderer\Renderer::class )->render( $newsletter );

			return array(
				'html' => (string) ( $rendered['html'] ?? '' ),
				'text' => (string) ( $rendered['text'] ?? '' ),
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Readers' opens, clicks or unsubscribes on sent newsletters, after $after.
	 *
	 * @param string $kind "opened", "clicked" or "unsubscribed".
	 * @return array{rows: array<int, array>, last: int}
	 */
	public static function activity( string $kind, int $after, int $limit = 1000 ): array {
		global $wpdb;
		$table = array(
			'opened'       => 'statistics_opens',
			'clicked'      => 'statistics_clicks',
			'unsubscribed' => 'statistics_unsubscribes',
		)[ $kind ] ?? null;
		if ( null === $table || ! self::has_table( $table ) ) {
			return array(
				'rows' => array(),
				'last' => $after,
			);
		}
		if ( 'clicked' === $kind ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT a.id, a.newsletter_id, UNIX_TIMESTAMP(a.created_at) AS created_ts, s.email, l.url FROM %i a JOIN %i s ON s.id = a.subscriber_id JOIN %i n ON n.id = a.newsletter_id LEFT JOIN %i l ON l.id = a.link_id WHERE a.id > %d AND n.type = %s ORDER BY a.id LIMIT %d',
					self::table( $table ),
					self::table( 'subscribers' ),
					self::table( 'newsletters' ),
					self::table( 'newsletter_links' ),
					$after,
					'standard',
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT a.id, a.newsletter_id, UNIX_TIMESTAMP(a.created_at) AS created_ts, s.email FROM %i a JOIN %i s ON s.id = a.subscriber_id JOIN %i n ON n.id = a.newsletter_id WHERE a.id > %d AND n.type = %s ORDER BY a.id LIMIT %d',
					self::table( $table ),
					self::table( 'subscribers' ),
					self::table( 'newsletters' ),
					$after,
					'standard',
					$limit
				),
				ARRAY_A
			);
		}

		$out  = array();
		$last = $after;
		foreach ( $rows as $r ) {
			$last  = (int) $r['id'];
			$out[] = array_filter(
				array(
					'email'                => (string) $r['email'],
					'newsletter_source_id' => (string) $r['newsletter_id'],
					'type'                 => $kind,
					'at'                   => (string) self::utc( $r['created_ts'] ),
					'url'                  => isset( $r['url'] ) && preg_match( '#^https?://#i', (string) $r['url'] ) ? (string) $r['url'] : null,
				)
			);
		}

		return array(
			'rows' => $out,
			'last' => $last,
		);
	}

	/** @return array<int, array{id: int, name: string, status: string}> Automations that aren't in the trash. */
	public static function automations_summary(): array {
		global $wpdb;
		if ( ! self::has_table( 'automations' ) ) {
			return array();
		}

		return array_map(
			fn ( $r ) => array(
				'id'     => (int) $r['id'],
				'name'   => (string) $r['name'],
				'status' => (string) $r['status'],
			),
			(array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, status FROM %i WHERE status <> %s ORDER BY name', self::table( 'automations' ), 'trash' ), ARRAY_A )
		);
	}

	/**
	 * One automation as LorePort keeps it: its trigger, steps, emails (in
	 * order, with the wait before each) and the readers who went through it.
	 */
	public static function automation( int $id ): ?array {
		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, status FROM %i WHERE id = %d', self::table( 'automations' ), $id ), ARRAY_A );
		if ( ! $a ) {
			return null;
		}
		$steps = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT steps FROM %i WHERE automation_id = %d ORDER BY id DESC LIMIT 1', self::table( 'automation_versions' ), $id ) ), true );
		$steps = is_array( $steps ) ? $steps : array();

		$trigger = null;
		$emails  = array();
		$delay   = '';
		$seen    = array();
		$queue   = array( 'root' );
		while ( $queue ) {
			$step_id = array_shift( $queue );
			if ( isset( $seen[ $step_id ] ) || ! isset( $steps[ $step_id ] ) ) {
				continue;
			}
			$seen[ $step_id ] = true;
			$step             = $steps[ $step_id ];
			$key              = (string) ( $step['key'] ?? '' );
			$args             = (array) ( $step['args'] ?? array() );
			if ( 'trigger' === ( $step['type'] ?? '' ) ) {
				$trigger = array(
					'key'   => $key,
					'lists' => self::list_names( array_map( 'intval', (array) ( $args['segment_ids'] ?? array() ) ) ),
				);
			} elseif ( 'core:delay' === $key ) {
				$amount = (int) ( $args['delay'] ?? 0 );
				$unit   = strtolower( (string) ( $args['delay_type'] ?? '' ) );
				$delay  = trim( $amount . ' ' . ( 1 === $amount ? rtrim( $unit, 's' ) : $unit ) );
			} elseif ( 'mailpoet:send-email' === $key ) {
				$email    = $wpdb->get_row( $wpdb->prepare( 'SELECT id, subject, preheader, body FROM %i WHERE id = %d', self::table( 'newsletters' ), (int) ( $args['email_id'] ?? 0 ) ), ARRAY_A );
				$emails[] = array(
					'source_id' => (string) ( $args['email_id'] ?? '' ),
					'subject'   => (string) ( $email['subject'] ?? $args['subject'] ?? '' ),
					'preheader' => (string) ( $email['preheader'] ?? $args['preheader'] ?? '' ),
					'delay'     => $delay,
					'body'      => (string) ( $email['body'] ?? '' ),
				);
				$delay    = '';
			}
			foreach ( (array) ( $step['next_steps'] ?? array() ) as $next ) {
				$queue[] = (string) ( $next['id'] ?? '' );
			}
		}

		// Who went through it: each run's subscriber.
		$runs = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.status, UNIX_TIMESTAMP(r.created_at) AS created_ts, rs.args FROM %i r JOIN %i rs ON rs.automation_run_id = r.id AND rs.`key` = %s WHERE r.automation_id = %d',
				self::table( 'automation_runs' ),
				self::table( 'automation_run_subjects' ),
				'mailpoet:subscriber',
				$id
			),
			ARRAY_A
		);
		$ids = array();
		foreach ( $runs as $i => $run ) {
			$args              = json_decode( (string) $run['args'], true );
			$runs[ $i ]['sid'] = (int) ( $args['subscriber_id'] ?? 0 );
			$ids[]             = $runs[ $i ]['sid'];
		}
		$emails_by_id = self::emails( array_values( array_unique( array_filter( $ids ) ) ) );

		return array(
			'source_id' => (string) $id,
			'name'      => (string) $a['name'],
			'status'    => (string) $a['status'],
			'trigger'   => $trigger,
			'steps'     => $steps,
			'emails'    => $emails,
			'runs'      => array_values(
				array_filter(
					array_map(
						fn ( $r ) => isset( $emails_by_id[ $r['sid'] ] ) ? array(
							'email'      => $emails_by_id[ $r['sid'] ],
							'status'     => (string) $r['status'],
							'started_at' => self::utc( $r['created_ts'] ),
						) : null,
						$runs
					)
				)
			),
		);
	}

	/**
	 * @param int[] $ids Subscriber IDs.
	 * @return array<int, string> id => email
	 */
	private static function emails( array $ids ): array {
		global $wpdb;
		$emails = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = (array) $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder per ID, added below.
				$wpdb->prepare(
					'SELECT id, email FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built for each ID.
					self::table( 'subscribers' ),
					...$chunk
				),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$emails[ (int) $r['id'] ] = (string) $r['email'];
			}
		}

		return $emails;
	}

	/**
	 * @param int[] $ids MailPoet list IDs.
	 * @return string[]
	 */
	private static function list_names( array $ids ): array {
		$names = wp_list_pluck( array_filter( self::lists(), fn ( $l ) => in_array( $l['id'], $ids, true ) ), 'name' );

		return array_values( $names );
	}

	/** @return array<int, array{source_id: string, name: string, body: string}> Templates the author saved. */
	public static function templates(): array {
		global $wpdb;

		return array_map(
			fn ( $r ) => array(
				'source_id' => (string) $r['id'],
				'name'      => (string) $r['name'],
				'body'      => (string) $r['body'],
			),
			(array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, body FROM %i WHERE readonly = 0 ORDER BY id', self::table( 'newsletter_templates' ) ), ARRAY_A )
		);
	}

	/**
	 * Forms with wording below the fields (often the consent note), and the lists they add to.
	 *
	 * @return array<int, array{name: string, lists: int[], text: string}>
	 */
	public static function forms(): array {
		global $wpdb;
		if ( ! self::has_table( 'forms' ) ) {
			return array();
		}
		$forms = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT name, body, settings FROM %i WHERE deleted_at IS NULL', self::table( 'forms' ) ), ARRAY_A ) as $f ) {
			$body     = maybe_unserialize( $f['body'] );
			$settings = maybe_unserialize( $f['settings'] );
			$text     = array();
			foreach ( (array) $body as $block ) {
				if ( in_array( $block['type'] ?? '', array( 'paragraph', 'html' ), true ) ) {
					$text[] = trim( wp_strip_all_tags( (string) ( $block['params']['content'] ?? $block['params']['text'] ?? '' ) ) );
				}
			}
			$text = trim( implode( "\n\n", array_filter( $text ) ) );
			if ( '' !== $text ) {
				$forms[] = array(
					'name'  => (string) $f['name'],
					'lists' => array_map( 'intval', (array) ( $settings['segments'] ?? array() ) ),
					'text'  => mb_substr( $text, 0, 1000 ),
				);
			}
		}

		return $forms;
	}

	/** @return array{subject: string, body: string}|null The confirmation email, when double opt-in is on. */
	public static function confirmation(): ?array {
		$settings = self::setting( 'signup_confirmation' );
		if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
			return null;
		}
		$body = (string) ( $settings['body'] ?? '' );
		if ( ! empty( $settings['use_mailpoet_editor'] ) && ! empty( $settings['transactional_email_id'] ) ) {
			global $wpdb;
			$body = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM %i WHERE id = %d', self::table( 'newsletters' ), (int) $settings['transactional_email_id'] ) ) ?: $body;
		}

		return array(
			'subject' => (string) ( $settings['subject'] ?? '' ),
			'body'    => $body,
		);
	}

	/** @return array{from_name: string, from_email: string, reply_to: string}|null */
	public static function sender(): ?array {
		$sender   = (array) self::setting( 'sender' );
		$reply_to = (array) self::setting( 'reply_to' );
		$from     = (string) ( $sender['address'] ?? '' );
		if ( '' === $from && '' === (string) ( $reply_to['address'] ?? '' ) ) {
			return null;
		}

		return array(
			'from_name'  => (string) ( $sender['name'] ?? '' ),
			'from_email' => is_email( $from ) ? $from : '',
			'reply_to'   => is_email( (string) ( $reply_to['address'] ?? '' ) ) ? (string) $reply_to['address'] : '',
		);
	}

	/** @return mixed A MailPoet setting, unserialized. */
	private static function setting( string $name ) {
		global $wpdb;
		if ( ! self::has_table( 'settings' ) ) {
			return null;
		}

		return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE name = %s', self::table( 'settings' ), $name ) ) );
	}
}
