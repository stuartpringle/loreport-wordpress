<?php
/**
 * LorePort → Settings → Import: moving over from MailPoet.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Import from MailPoet" (LorePort D-040): offers only what this site's
 * MailPoet actually holds, each part optional with a line saying what it
 * does, then sends it to LorePort in small steps run from the browser, so
 * no single request runs long. Progress is kept per user, so a stopped
 * import carries on where it was. MailPoet itself is never changed.
 */
class LorePort_Import {

	const STEPS = array( 'lists', 'subscribers', 'wait', 'newsletters', 'history', 'automations', 'templates', 'sender' );

	const HISTORY = array( 'opened', 'clicked', 'unsubscribed' );

	const TIMEOUT = 45;

	public static function init(): void {
		add_action( 'wp_ajax_loreport_import_begin', array( __CLASS__, 'begin' ) );
		add_action( 'wp_ajax_loreport_import_step', array( __CLASS__, 'step' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Whether the Import tab should show: MailPoet's tables are here. */
	public static function available(): bool {
		return LorePort_MailPoet::available();
	}

	public static function assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to load a script.
		if ( false === strpos( $hook, LorePort_Settings::PAGE ) || 'import' !== sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) ) {
			return;
		}
		wp_enqueue_script( 'loreport-import', LOREPORT_URL . 'assets/import.js', array(), LOREPORT_VERSION, true );
		wp_localize_script(
			'loreport-import',
			'loreportImport',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'loreport_import' ),
				'strings' => array(
					'starting' => __( 'Starting…', 'loreport' ),
					'done'     => __( 'All done. Everything you chose is in LorePort.', 'loreport' ),
					'failed'   => __( 'The import stopped:', 'loreport' ),
					'retry'    => __( 'Try again', 'loreport' ),
				),
			)
		);
	}

	/** The account's pen names (for choosing where new lists go). */
	private static function pen_names(): array {
		$response = LorePort_Client::request( 'GET', 'pen-names' );

		return 200 === $response['status'] ? array_values( array_filter( (array) ( $response['body']['data'] ?? array() ), 'is_array' ) ) : array();
	}

	public static function render_tab( array $loreport_lists ): void {
		$response  = LorePort_Client::request( 'GET', 'me' );
		$abilities = (array) ( $response['body']['data']['key']['abilities'] ?? array() );
		if ( 200 === $response['status'] && ! in_array( 'imports:write', $abilities, true ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: link to LorePort's API settings */
				esc_html__( 'To import, this site\'s LorePort key needs the "Import from another newsletter service" permission (imports:write). Edit the key, or make a new one, under %s.', 'loreport' ),
				'<a href="' . esc_url( LorePort_Client::base_url() . '/settings/api' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Settings → API and webhooks', 'loreport' ) . '</a>'
			);
			echo '</p></div>';

			return;
		}

		$inventory  = LorePort_MailPoet::inventory();
		$mp_lists   = LorePort_MailPoet::lists();
		$pen_names  = self::pen_names();
		$saved      = get_transient( self::key() );
		$site       = wp_parse_url( home_url(), PHP_URL_HOST );
		$automation = array_values( array_filter( $inventory['automations'], fn ( $a ) => 'trash' !== $a['status'] ) );
		?>
		<h2><?php esc_html_e( 'Import from MailPoet', 'loreport' ); ?></h2>
		<p class="loreport-lead"><?php esc_html_e( 'Bring your readers, lists and history over from MailPoet. MailPoet itself isn\'t changed, so you can keep using it until you\'re ready to switch, and run the import again later: nothing is added twice.', 'loreport' ); ?></p>

		<?php if ( is_array( $saved ) && empty( $saved['finished'] ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'An import was stopped partway. Start it again with the same choices and it carries on where it was.', 'loreport' ); ?></p></div>
		<?php endif; ?>

		<form id="loreport-import-form" class="loreport-import">
			<h3><?php esc_html_e( 'Subscribers and lists', 'loreport' ); ?></h3>
			<p><?php echo esc_html( sprintf( /* translators: %d: number of subscribers */ _n( 'MailPoet has %d subscriber.', 'MailPoet has %d subscribers.', $inventory['subscribers'], 'loreport' ), $inventory['subscribers'] ) ); ?>
				<?php esc_html_e( 'Each one keeps their status: subscribed readers come over with their original signup date and IP address, readers who never confirmed are asked to confirm, and anyone who unsubscribed or bounced is recorded so they\'re never emailed.', 'loreport' ); ?>
				<?php if ( $inventory['tags'] > 0 ) : ?>
					<?php esc_html_e( 'Their MailPoet tags come over too.', 'loreport' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( count( $pen_names ) > 1 ) : ?>
				<p><label for="loreport-import-pen-name"><?php esc_html_e( 'New lists go under the pen name', 'loreport' ); ?></label>
					<select id="loreport-import-pen-name" name="pen_name_id">
						<?php foreach ( $pen_names as $pen ) : ?>
							<option value="<?php echo esc_attr( (string) $pen['id'] ); ?>"><?php echo esc_html( (string) $pen['name'] ); ?></option>
						<?php endforeach; ?>
					</select></p>
			<?php elseif ( $pen_names ) : ?>
				<input type="hidden" name="pen_name_id" value="<?php echo esc_attr( (string) $pen_names[0]['id'] ); ?>">
			<?php endif; ?>
			<table class="widefat striped loreport-table">
				<thead><tr><th><?php esc_html_e( 'MailPoet list', 'loreport' ); ?></th><th class="num"><?php esc_html_e( 'Subscribers', 'loreport' ); ?></th><th><?php esc_html_e( 'In LorePort', 'loreport' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $mp_lists as $mp ) : ?>
					<tr>
						<td><?php echo esc_html( $mp['name'] ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $mp['count'] ) ); ?></td>
						<td>
							<select name="lists[<?php echo esc_attr( (string) $mp['id'] ); ?>]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: MailPoet list name */ __( 'Where %s goes', 'loreport' ), $mp['name'] ) ); ?>">
								<option value="new"<?php selected( $mp['count'] > 0 ); ?>><?php echo esc_html( sprintf( /* translators: %s: list name */ __( 'A new list: %s', 'loreport' ), $mp['name'] ) ); ?></option>
								<?php foreach ( $loreport_lists as $list ) : ?>
									<option value="<?php echo esc_attr( (string) $list['id'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: LorePort list name */ __( 'Into %s', 'loreport' ), (string) $list['name'] ) ); ?></option>
								<?php endforeach; ?>
								<option value="skip"<?php selected( 0 === $mp['count'] ); ?>><?php esc_html_e( 'Don\'t import', 'loreport' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><label><input type="checkbox" name="tag_with_list" value="1"> <?php esc_html_e( 'Also tag each reader with the name of their MailPoet list', 'loreport' ); ?></label>
				<br><span class="description"><?php esc_html_e( 'Useful when you put several MailPoet lists into one LorePort list and still want to tell them apart.', 'loreport' ); ?></span></p>
			<p><label for="loreport-import-consent"><strong><?php esc_html_e( 'Where did these readers agree to your emails?', 'loreport' ); ?></strong></label><br>
				<textarea id="loreport-import-consent" name="consent_statement" rows="2" class="large-text" required><?php echo esc_textarea( sprintf( /* translators: %s: this site's address */ __( 'They signed up through my MailPoet signup forms on %s and were moved over from MailPoet.', 'loreport' ), $site ) ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Saved with each reader as their consent record.', 'loreport' ); ?></span></p>

			<h3><?php esc_html_e( 'What else to bring over', 'loreport' ); ?></h3>
			<?php
			$options = array();
			if ( $inventory['newsletters'] > 0 ) {
				/* translators: %d: number of newsletters */
				$options['newsletters'] = array( sprintf( _n( 'Your sent newsletter (%d)', 'Your sent newsletters (%d)', $inventory['newsletters'], 'loreport' ), $inventory['newsletters'] ), __( 'Kept in LorePort as you sent them, with how many people opened and clicked each, so your newsletter history doesn\'t start over. They can be read and copied into new newsletters, not sent again.', 'loreport' ) );
			}
			if ( $inventory['newsletters'] > 0 && ( $inventory['opens'] + $inventory['clicks'] ) > 0 ) {
				/* translators: 1: opens, 2: clicks */
				$options['history'] = array( sprintf( __( 'Reading history (%1$s opens, %2$s clicks)', 'loreport' ), number_format_i18n( $inventory['opens'] ), number_format_i18n( $inventory['clicks'] ) ), __( 'Which newsletters each reader opened and which links they clicked, shown in their history in LorePort. Handy for spotting your keenest readers, and for "engaged readers" lists later. Needs your sent newsletters.', 'loreport' ) );
			}
			if ( $automation ) {
				$options['automations'] = array( sprintf( /* translators: %s: automation names */ __( 'Automations: %s', 'loreport' ), implode( ', ', wp_list_pluck( $automation, 'name' ) ) ), __( 'Their emails and settings are kept in LorePort, with the readers who already went through them, so nobody gets them twice later. LorePort doesn\'t run automations yet: until it does, new readers don\'t get these emails.', 'loreport' ) );
			}
			if ( $inventory['forms'] || $inventory['confirmation'] ) {
				$options['forms'] = array( __( 'Signup form wording and confirmation email', 'loreport' ), __( 'The wording under your MailPoet signup form becomes the new list\'s consent statement, and your confirmation email\'s subject and text become the new list\'s own confirmation email. LorePort still adds the confirm button.', 'loreport' ) );
			}
			if ( $inventory['sender'] ) {
				$addresses         = array_filter( array( $inventory['sender']['from_email'], $inventory['sender']['reply_to'] ) );
				$options['sender'] = array(
					/* translators: %s: email addresses */
					sprintf( __( 'Sender and reply-to addresses (%s)', 'loreport' ), implode( ', ', array_unique( $addresses ) ) ),
					__( 'Replaces your pen name\'s From and reply-to addresses in LorePort with MailPoet\'s. A changed From address has to be confirmed again in LorePort before you can send. Leave this off if your pen name is already set up.', 'loreport' ),
					false,
				);
			}
			if ( $inventory['templates'] > 0 ) {
				/* translators: %d: number of templates */
				$options['templates'] = array( sprintf( _n( 'Your saved template (%d)', 'Your saved templates (%d)', $inventory['templates'], 'loreport' ), $inventory['templates'] ), __( 'Each becomes a LorePort layout to start newsletters from. MailPoet\'s designs don\'t carry over exactly: text, images, buttons and dividers come across, and you may want to tidy them.', 'loreport' ) );
			}
			?>
			<?php foreach ( $options as $name => $option ) : ?>
				<?php [ $label, $explain ] = $option; ?>
				<p class="loreport-import__option"><label><input type="checkbox" name="options[]" value="<?php echo esc_attr( $name ); ?>"<?php checked( $option[2] ?? true ); ?>> <strong><?php echo esc_html( $label ); ?></strong></label><br><span class="description"><?php echo esc_html( $explain ); ?></span></p>
			<?php endforeach; ?>
			<?php if ( ! $options ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing else: MailPoet has no sent newsletters, automations, forms or templates here.', 'loreport' ); ?></p>
			<?php endif; ?>

			<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'Everyone on these lists agreed to receive my emails', 'loreport' ); ?></label></p>
			<?php submit_button( __( 'Start the import', 'loreport' ), 'primary', 'loreport-import-start', false ); ?>
		</form>
		<div id="loreport-import-progress" class="loreport-import__progress" hidden aria-live="polite"><ol></ol><p class="loreport-import__actions"></p></div>
		<?php
	}

	private static function key(): string {
		return 'loreport_import_' . get_current_user_id();
	}

	private static function guard(): void {
		check_ajax_referer( 'loreport_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can import.', 'loreport' ) ), 403 );
		}
	}

	/** Saves the choices and starts (or carries on with the same choices). */
	public static function begin(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$lists = array();
		$posted = map_deep( (array) wp_unslash( $_POST['lists'] ?? array() ), 'sanitize_text_field' );
		foreach ( $posted as $mp_id => $target ) {
			$target = (string) $target;
			if ( 'skip' !== $target ) {
				$lists[ (int) $mp_id ] = $target;
			}
		}
		$choices = array(
			'pen_name_id'       => sanitize_text_field( wp_unslash( $_POST['pen_name_id'] ?? '' ) ),
			'consent_statement' => sanitize_textarea_field( wp_unslash( $_POST['consent_statement'] ?? '' ) ),
			'tag_with_list'     => ! empty( $_POST['tag_with_list'] ),
			'lists'             => $lists,
			'options'           => array_values( array_intersect( array( 'newsletters', 'history', 'automations', 'forms', 'sender', 'templates' ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['options'] ?? array() ) ) ) ),
		);
		// phpcs:enable
		if ( array() === $lists && array() === $choices['options'] ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one list, or something else to import.', 'loreport' ) ) );
		}
		if ( mb_strlen( $choices['consent_statement'] ) < 10 ) {
			wp_send_json_error( array( 'message' => __( 'Say where these readers agreed to your emails.', 'loreport' ) ) );
		}

		$saved = get_transient( self::key() );
		$state = is_array( $saved ) && empty( $saved['finished'] ) && ( $saved['choices'] ?? null ) === $choices
			? $saved
			: array(
				'choices'  => $choices,
				'step'     => 0,
				'cursor'   => array(),
				'lp_lists' => array(),
				'imports'  => array(),
			);
		self::save( $state );

		wp_send_json_success( array( 'message' => __( 'Starting…', 'loreport' ) ) );
	}

	/** Runs a little of the current step and says what happened. */
	public static function step(): void {
		self::guard();
		$state = get_transient( self::key() );
		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to carry on with: start the import again.', 'loreport' ) ) );
		}

		while ( $state['step'] < count( self::STEPS ) && ! self::wanted( $state, self::STEPS[ $state['step'] ] ) ) {
			++$state['step'];
			$state['cursor'] = array();
		}
		if ( $state['step'] >= count( self::STEPS ) ) {
			$state['finished'] = true;
			self::save( $state );
			wp_send_json_success(
				array(
					'finished' => true,
					'message'  => __( 'All done.', 'loreport' ),
				)
			);
		}

		$name = self::STEPS[ $state['step'] ];
		try {
			$result = self::{ 'step_' . $name }( $state );
		} catch ( LorePort_Import_Busy $e ) {
			// LorePort asked for a pause (its per-minute limit): carry on shortly, same place.
			self::save( $state );
			wp_send_json_success(
				array(
					'finished' => false,
					'step'     => $name,
					'done'     => false,
					'message'  => __( 'LorePort asked for a short pause. Carrying on in a moment…', 'loreport' ),
					'wait'     => 20,
				)
			);
		} catch ( RuntimeException $e ) {
			self::save( $state );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		if ( $result['done'] ) {
			++$state['step'];
			$state['cursor'] = array();
		}
		self::save( $state );

		wp_send_json_success(
			array(
				'finished' => false,
				'step'     => $name,
				'done'     => $result['done'],
				'message'  => $result['message'],
				'wait'     => $result['wait'] ?? 0,
			)
		);
	}

	private static function wanted( array $state, string $step ): bool {
		$options = $state['choices']['options'];

		return match ( $step ) {
			'lists', 'subscribers', 'wait' => array() !== $state['choices']['lists'],
			'history' => in_array( 'history', $options, true ) && in_array( 'newsletters', $options, true ),
			default => in_array( $step, $options, true ),
		};
	}

	private static function save( array $state ): void {
		set_transient( self::key(), $state, DAY_IN_SECONDS );
	}

	/** Calls LorePort; a refusal stops the import with LorePort's reason. */
	private static function api( string $method, string $path, ?array $body = null ): array {
		$response = LorePort_Client::request( $method, $path, $body, '', self::TIMEOUT );
		if ( 429 === $response['status'] ) {
			throw new LorePort_Import_Busy( esc_html( (string) $response['error'] ) );
		}
		if ( 0 === $response['status'] || $response['status'] >= 400 ) {
			/* translators: 1: what LorePort said, 2: the API path */
			throw new RuntimeException( esc_html( sprintf( __( '%1$s (LorePort, %2$s)', 'loreport' ), (string) $response['error'], $path ) ) );
		}

		return (array) ( $response['body']['data'] ?? array() );
	}

	/** Makes (or finds) a LorePort list for each MailPoet list going to a new one. */
	private static function step_lists( array &$state ): array {
		$mp_lists = array_column( LorePort_MailPoet::lists(), 'name', 'id' );
		$forms    = in_array( 'forms', $state['choices']['options'], true );
		$confirm  = $forms ? LorePort_MailPoet::confirmation() : null;
		$wording  = array();
		if ( $forms ) {
			foreach ( LorePort_MailPoet::forms() as $form ) {
				foreach ( $form['lists'] as $id ) {
					$wording[ $id ] = $wording[ $id ] ?? $form['text'];
				}
			}
		}

		$made = 0;
		foreach ( $state['choices']['lists'] as $mp_id => $target ) {
			if ( isset( $state['lp_lists'][ $mp_id ] ) ) {
				continue;
			}
			if ( 'new' !== $target ) {
				$state['lp_lists'][ $mp_id ] = $target;
				continue;
			}
			$list = self::api(
				'POST',
				'imports/lists',
				array_filter(
					array(
						'pen_name_id'          => $state['choices']['pen_name_id'],
						'name'                 => (string) ( $mp_lists[ $mp_id ] ?? 'MailPoet list ' . $mp_id ),
						'consent_text'         => $wording[ $mp_id ] ?? null,
						'confirmation_subject' => $confirm['subject'] ?? null,
						'confirmation_body'    => $confirm['body'] ?? null,
					)
				)
			);
			$state['lp_lists'][ $mp_id ] = (string) $list['id'];
			++$made;
		}
		delete_transient( 'loreport_lists' );

		/* translators: %d: number of lists */
		return array( 'done' => true, 'message' => sprintf( _n( 'Lists ready (%d new).', 'Lists ready (%d new).', $made, 'loreport' ), $made ) );
	}

	/** Sends one list's subscribers, 500 at a time, then starts that import in LorePort. */
	private static function step_subscribers( array &$state ): array {
		$mp_ids = array_keys( $state['choices']['lists'] );
		$index  = (int) ( $state['cursor']['list'] ?? 0 );
		if ( $index >= count( $mp_ids ) ) {
			return array( 'done' => true, 'message' => __( 'Subscribers sent.', 'loreport' ) );
		}
		$mp_id = (int) $mp_ids[ $index ];
		$name  = (string) ( array_column( LorePort_MailPoet::lists(), 'name', 'id' )[ $mp_id ] ?? '' );

		if ( empty( $state['cursor']['import'] ) ) {
			$import                    = self::api(
				'POST',
				'imports',
				array(
					'list_id'           => $state['lp_lists'][ $mp_id ],
					'service'           => 'mailpoet',
					'consent_statement' => $state['choices']['consent_statement'],
					'tag_with_list'     => $state['choices']['tag_with_list'],
				)
			);
			$state['cursor']['import'] = (string) $import['id'];
			$state['cursor']['after']  = 0;
			$state['cursor']['sent']   = 0;
		}

		$page = LorePort_MailPoet::subscribers( $mp_id, (int) $state['cursor']['after'], 500 );
		if ( $page['rows'] ) {
			self::api( 'POST', 'imports/' . $state['cursor']['import'] . '/rows', array( 'rows' => $page['rows'] ) );
			$state['cursor']['after'] = $page['last'];
			$state['cursor']['sent'] += count( $page['rows'] );

			/* translators: 1: number of subscribers, 2: list name */
			return array( 'done' => false, 'message' => sprintf( __( 'Sent %1$s subscribers from %2$s…', 'loreport' ), number_format_i18n( $state['cursor']['sent'] ), $name ) );
		}

		self::api( 'POST', 'imports/' . $state['cursor']['import'] . '/start' );
		$state['imports'][] = $state['cursor']['import'];
		$sent               = (int) $state['cursor']['sent'];
		$state['cursor']    = array( 'list' => $index + 1 );

		/* translators: 1: number of subscribers, 2: list name */
		return array( 'done' => false, 'message' => sprintf( __( '%1$s subscribers from %2$s handed to LorePort.', 'loreport' ), number_format_i18n( $sent ), $name ) );
	}

	/** Waits for LorePort to finish adding the subscribers (later steps need them). */
	private static function step_wait( array &$state ): array {
		$finished = (array) ( $state['cursor']['finished'] ?? array() );
		foreach ( $state['imports'] as $id ) {
			if ( isset( $finished[ $id ] ) ) {
				continue;
			}
			$import = self::api( 'GET', 'imports/' . $id );
			if ( 'failed' === ( $import['status'] ?? '' ) ) {
				throw new RuntimeException( esc_html( (string) ( $import['error'] ?? __( 'LorePort couldn\'t finish adding the subscribers.', 'loreport' ) ) ) );
			}
			if ( 'completed' !== ( $import['status'] ?? '' ) ) {
				break; // check the rest once this one is done
			}
			$finished[ $id ] = (int) ( $import['added'] ?? 0 ) + (int) ( $import['asked_to_confirm'] ?? 0 );
		}
		$state['cursor']['finished'] = $finished;
		if ( count( $finished ) < count( $state['imports'] ) ) {
			/* translators: 1: imports finished, 2: imports in all */
			return array( 'done' => false, 'wait' => 10, 'message' => sprintf( __( 'LorePort is adding the subscribers (%1$d of %2$d lists done)…', 'loreport' ), count( $finished ), count( $state['imports'] ) ) );
		}

		/* translators: %s: number of readers */
		return array( 'done' => true, 'message' => sprintf( __( 'Subscribers added: %s new in LorePort.', 'loreport' ), number_format_i18n( array_sum( $finished ) ) ) );
	}

	/** Sent newsletters, three at a time. */
	private static function step_newsletters( array &$state ): array {
		$ids   = LorePort_MailPoet::sent_newsletter_ids();
		$index = (int) ( $state['cursor']['index'] ?? 0 );
		$names = array();
		foreach ( LorePort_MailPoet::lists() as $mp ) {
			if ( isset( $state['lp_lists'][ $mp['id'] ] ) ) {
				$names[ $mp['name'] ] = $state['lp_lists'][ $mp['id'] ];
			}
		}
		foreach ( array_slice( $ids, $index, 3 ) as $id ) {
			$newsletter = LorePort_MailPoet::newsletter( $id );
			if ( $newsletter ) {
				$newsletter['source']   = 'mailpoet';
				$newsletter['list_ids'] = array_values( array_unique( array_filter( array_map( fn ( $n ) => $names[ $n ] ?? null, $newsletter['lists'] ) ) ) );
				unset( $newsletter['lists'] );
				self::api( 'POST', 'imports/newsletters', $newsletter );
			}
			++$index;
		}
		$state['cursor']['index'] = $index;

		/* translators: 1: newsletters done, 2: newsletters in all */
		return array( 'done' => $index >= count( $ids ), 'message' => sprintf( __( 'Newsletters: %1$d of %2$d.', 'loreport' ), $index, count( $ids ) ) );
	}

	/** Opens, then clicks, then unsubscribes, 1,000 at a time. */
	private static function step_history( array &$state ): array {
		$kind_index = (int) ( $state['cursor']['kind'] ?? 0 );
		if ( $kind_index >= count( self::HISTORY ) ) {
			return array( 'done' => true, 'message' => __( 'Reading history sent.', 'loreport' ) );
		}
		$kind = self::HISTORY[ $kind_index ];
		$page = LorePort_MailPoet::activity( $kind, (int) ( $state['cursor']['after'] ?? 0 ), 1000 );
		if ( ! $page['rows'] ) {
			$state['cursor'] = array( 'kind' => $kind_index + 1 );

			return array( 'done' => false, 'message' => '' );
		}
		self::api(
			'POST',
			'imports/activity',
			array(
				'source' => 'mailpoet',
				'rows'   => $page['rows'],
			)
		);
		$state['cursor']['after'] = $page['last'];
		$state['cursor']['count'] = (int) ( $state['cursor']['count'] ?? 0 ) + count( $page['rows'] );

		$label = array(
			'opened'       => __( 'opens', 'loreport' ),
			'clicked'      => __( 'clicks', 'loreport' ),
			'unsubscribed' => __( 'unsubscribes', 'loreport' ),
		)[ $kind ];

		/* translators: 1: a number, 2: "opens", "clicks" or "unsubscribes" */
		return array( 'done' => false, 'message' => sprintf( __( 'Reading history: %1$s %2$s…', 'loreport' ), number_format_i18n( $state['cursor']['count'] ), $label ) );
	}

	/** Each automation that isn't in MailPoet's trash, with who went through it. */
	private static function step_automations( array &$state ): array {
		$names = array();
		foreach ( LorePort_MailPoet::automations_summary() as $summary ) {
			$automation = LorePort_MailPoet::automation( $summary['id'] );
			if ( $automation ) {
				$automation['source'] = 'mailpoet';
				self::api( 'POST', 'imports/automations', $automation );
				$names[] = $automation['name'];
			}
		}

		/* translators: %s: automation names */
		return array( 'done' => true, 'message' => sprintf( __( 'Automations kept: %s.', 'loreport' ), implode( ', ', $names ) ) );
	}

	private static function step_templates( array &$state ): array {
		$templates = LorePort_MailPoet::templates();
		foreach ( $templates as $template ) {
			$template['source']      = 'mailpoet';
			$template['pen_name_id'] = $state['choices']['pen_name_id'];
			self::api( 'POST', 'imports/templates', $template );
		}

		/* translators: %d: number of templates */
		return array( 'done' => true, 'message' => sprintf( _n( '%d template is now a LorePort layout.', '%d templates are now LorePort layouts.', count( $templates ), 'loreport' ), count( $templates ) ) );
	}

	private static function step_sender( array &$state ): array {
		$sender = LorePort_MailPoet::sender();
		if ( ! $sender ) {
			return array( 'done' => true, 'message' => '' );
		}
		$result = self::api(
			'POST',
			'imports/sender',
			array_filter(
				array(
					'pen_name_id' => $state['choices']['pen_name_id'],
					'from_email'  => $sender['from_email'],
					'reply_to'    => $sender['reply_to'],
				)
			)
		);

		return array(
			'done'    => true,
			'message' => empty( $result['from_email_confirmed'] )
				? __( 'Sender addresses set. Confirm the From address in LorePort (Settings → Sending) before sending.', 'loreport' )
				: __( 'Sender addresses set.', 'loreport' ),
		);
	}
}

/** LorePort asked the import to slow down (HTTP 429). */
class LorePort_Import_Busy extends RuntimeException {}
