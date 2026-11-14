<?php
/**
 * The signup form: [loreport_signup] shortcode and the "LorePort signup" block.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders signup forms. They post to admin-post.php, so they work without
 * JavaScript; with it, form.js sends them in the background instead.
 */
class LorePort_Form {

	/** Forms on this page so far, for stable IDs. */
	private static int $count = 0;

	public static function init(): void {
		add_shortcode( 'loreport_signup', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		load_plugin_textdomain( 'loreport', false, dirname( plugin_basename( LOREPORT_FILE ) ) . '/languages' );
	}

	public static function register_assets(): void {
		wp_register_style( 'loreport-form', LOREPORT_URL . 'assets/form.css', array(), LOREPORT_VERSION );
		wp_register_script(
			'loreport-form',
			LOREPORT_URL . 'assets/form.js',
			array(),
			LOREPORT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public static function register_block(): void {
		register_block_type(
			LOREPORT_DIR . 'blocks/signup',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	/**
	 * [loreport_signup list="..." name="yes|no" button="..." title="..."]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'list'   => '',
				'name'   => 'yes',
				'button' => '',
				'title'  => '',
			),
			(array) $atts,
			'loreport_signup'
		);

		return self::render(
			array(
				'listId'     => (string) $atts['list'],
				'showName'   => ! in_array( strtolower( (string) $atts['name'] ), array( 'no', 'false', '0' ), true ),
				'buttonText' => (string) $atts['button'],
				'title'      => (string) $atts['title'],
			)
		);
	}

	/** @param array $attributes Block attributes. */
	public static function render_block( array $attributes ): string {
		return self::render( $attributes );
	}

	/**
	 * The form's HTML.
	 *
	 * @param array $options listId, showName, buttonText, title.
	 */
	public static function render( array $options ): string {
		$list_id = LorePort_Settings::sanitize_list_id( (string) ( $options['listId'] ?? '' ) );
		$list_id = '' !== $list_id ? $list_id : LorePort_Settings::default_list();
		if ( '' === LorePort_Settings::api_key() || '' === $list_id ) {
			return current_user_can( 'manage_options' )
				? '<p class="loreport-signup__setup">' . esc_html__( 'LorePort signup form: add your API key and choose a list under Settings → LorePort. (Only admins see this.)', 'loreport' ) . '</p>'
				: '';
		}

		wp_enqueue_style( 'loreport-form' );
		wp_enqueue_script( 'loreport-form' );

		++self::$count;
		$form_id = 'loreport-signup-' . self::$count;
		$list    = LorePort_Client::find_list( $list_id );
		$consent = (string) ( $list['consent_text'] ?? '' );
		$button  = '' !== trim( (string) ( $options['buttonText'] ?? '' ) ) ? (string) $options['buttonText'] : __( 'Subscribe', 'loreport' );
		$title   = trim( (string) ( $options['title'] ?? '' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads which message to show.
		$status = isset( $_GET['loreport_form'], $_GET['loreport_status'] ) && sanitize_key( wp_unslash( $_GET['loreport_form'] ) ) === $form_id
			? sanitize_key( wp_unslash( $_GET['loreport_status'] ) ) : '';
		// phpcs:enable
		$good = in_array( $status, array( 'pending', 'subscribed', 'already', 'queued' ), true );

		ob_start();
		?>
		<div class="loreport-signup<?php echo $good ? ' is-done' : ''; ?>" id="<?php echo esc_attr( $form_id ); ?>">
			<form class="loreport-signup__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php if ( '' !== $title ) : ?>
					<p class="loreport-signup__title"><?php echo esc_html( $title ); ?></p>
				<?php endif; ?>
				<input type="hidden" name="action" value="loreport_signup">
				<input type="hidden" name="loreport_form" value="<?php echo esc_attr( $form_id ); ?>">
				<input type="hidden" name="loreport_token" value="<?php echo esc_attr( LorePort_Signups::token( $list_id ) ); ?>">
				<input type="hidden" name="loreport_page" value="<?php echo esc_attr( (string) get_queried_object_id() ); ?>">
				<div class="loreport-signup__fields">
					<?php if ( ! empty( $options['showName'] ?? true ) ) : ?>
						<p class="loreport-signup__field">
							<label for="<?php echo esc_attr( $form_id ); ?>-name"><?php esc_html_e( 'Name', 'loreport' ); ?> <span class="loreport-signup__optional"><?php esc_html_e( '(optional)', 'loreport' ); ?></span></label>
							<input type="text" id="<?php echo esc_attr( $form_id ); ?>-name" name="loreport_name" autocomplete="name" maxlength="255">
						</p>
					<?php endif; ?>
					<p class="loreport-signup__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-email"><?php esc_html_e( 'Email', 'loreport' ); ?></label>
						<input type="email" id="<?php echo esc_attr( $form_id ); ?>-email" name="loreport_email" autocomplete="email" required maxlength="254">
					</p>
					<div class="loreport-signup__trap" aria-hidden="true">
						<label for="<?php echo esc_attr( $form_id ); ?>-website"><?php esc_html_e( 'Leave this empty', 'loreport' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $form_id ); ?>-website" name="loreport_website" tabindex="-1" autocomplete="off">
					</div>
					<p class="loreport-signup__submit"><button type="submit"><?php echo esc_html( $button ); ?></button></p>
				</div>
				<?php if ( '' !== $consent ) : ?>
					<p class="loreport-signup__consent"><?php echo esc_html( $consent ); ?></p>
				<?php endif; ?>
				<p class="loreport-signup__message<?php echo '' !== $status ? ( $good ? ' is-good' : ' is-bad' ) : ''; ?>" role="status" aria-live="polite"><?php echo '' !== $status ? esc_html( LorePort_Signups::message( $status ) ) : ''; ?></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
