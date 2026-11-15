<?php
/**
 * The signup popup: on every page (LorePort → Settings → Popup), or placed
 * with [loreport_popup]; opened by [loreport_popup_button], any link to
 * #loreport-popup (a Button block, a menu item) or data-loreport-open.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders popups. The popup is a <dialog>; assets/form.js opens and closes it.
 */
class LorePort_Popup {

	const OPTION = 'loreport_popup';

	/** Popups already on this page, so the site-wide one isn't added twice. */
	private static array $rendered = array();

	public static function init(): void {
		add_shortcode( 'loreport_popup', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'loreport_popup_button', array( __CLASS__, 'button_shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'site_wide' ), 5 );
	}

	/**
	 * The site-wide popup's settings.
	 *
	 * @return array{enabled: bool, list: string, title: string, description: string, button: string, delay: int}
	 */
	public static function settings(): array {
		$saved = (array) get_option( self::OPTION, array() );

		return array(
			'enabled'     => ! empty( $saved['enabled'] ),
			'list'        => (string) ( $saved['list'] ?? '' ),
			'title'       => (string) ( $saved['title'] ?? '' ),
			'description' => (string) ( $saved['description'] ?? '' ),
			'button'      => (string) ( $saved['button'] ?? '' ),
			'delay'       => max( 0, min( 600, (int) ( $saved['delay'] ?? 0 ) ) ),
		);
	}

	public static function sanitize( $value ): array {
		$value = (array) $value;

		return array(
			'enabled'     => ! empty( $value['enabled'] ),
			'list'        => LorePort_Settings::sanitize_list_id( $value['list'] ?? '' ),
			'title'       => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $value['description'] ?? '' ) ),
			'button'      => sanitize_text_field( (string) ( $value['button'] ?? '' ) ),
			'delay'       => max( 0, min( 600, absint( $value['delay'] ?? 0 ) ) ),
		);
	}

	/** The site-wide popup, in the footer of every page when it's switched on. */
	public static function site_wide(): void {
		$settings = self::settings();
		if ( ! $settings['enabled'] || is_admin() || isset( self::$rendered['loreport-popup'] ) ) {
			return;
		}

		$html = self::render(
			array(
				'id'          => 'loreport-popup',
				'listId'      => $settings['list'],
				'title'       => '' !== $settings['title'] ? $settings['title'] : __( 'Join the newsletter', 'loreport' ),
				'description' => $settings['description'],
				'buttonText'  => $settings['button'],
				'delay'       => $settings['delay'],
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in render().
	}

	/**
	 * [loreport_popup list="..." title="..." description="..." button="..." name="no" delay="20" id="..."]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'          => 'loreport-popup',
				'list'        => '',
				'title'       => '',
				'description' => '',
				'button'      => '',
				'name'        => 'yes',
				'delay'       => '0',
			),
			(array) $atts,
			'loreport_popup'
		);

		return self::render(
			array(
				'id'          => (string) $atts['id'],
				'listId'      => (string) $atts['list'],
				'title'       => (string) $atts['title'],
				'description' => (string) $atts['description'],
				'buttonText'  => (string) $atts['button'],
				'showName'    => ! in_array( strtolower( (string) $atts['name'] ), array( 'no', 'false', '0' ), true ),
				'delay'       => (int) $atts['delay'],
			)
		);
	}

	/**
	 * [loreport_popup_button text="Get the newsletter" class="..." popup="..."]
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Optional content for the button.
	 */
	public static function button_shortcode( $atts, $content = null ): string {
		$atts = shortcode_atts(
			array(
				'text'  => __( 'Get the newsletter', 'loreport' ),
				'class' => 'loreport-popup-button wp-element-button',
				'popup' => '',
			),
			(array) $atts,
			'loreport_popup_button'
		);
		wp_enqueue_style( 'loreport-form' );
		wp_enqueue_script( 'loreport-form' );

		$label = null !== $content && '' !== trim( $content ) ? wp_kses_post( do_shortcode( $content ) ) : esc_html( (string) $atts['text'] );

		return '<button type="button" class="' . esc_attr( (string) $atts['class'] ) . '" data-loreport-open="' . esc_attr( sanitize_html_class( (string) $atts['popup'] ) ) . '">' . $label . '</button>';
	}

	/**
	 * A popup with the signup form.
	 *
	 * @param array $options id, delay, and LorePort_Form::render() options.
	 */
	public static function render( array $options ): string {
		$id = sanitize_html_class( (string) ( $options['id'] ?? '' ) );
		$id = '' !== $id ? $id : 'loreport-popup';
		if ( isset( self::$rendered[ $id ] ) ) {
			return '';
		}

		$form = LorePort_Form::render( array( 'formStyle' => 'plain' ) + $options );
		if ( '' === $form || str_contains( $form, 'loreport-signup__setup' ) ) {
			return $form; // Not set up yet: only admins see why.
		}
		self::$rendered[ $id ] = true;

		$delay = max( 0, (int) ( $options['delay'] ?? 0 ) );
		$label = '' !== trim( (string) ( $options['title'] ?? '' ) ) ? (string) $options['title'] : __( 'Newsletter signup', 'loreport' );

		return '<dialog id="' . esc_attr( $id ) . '" class="loreport-popup" aria-label="' . esc_attr( $label ) . '"' . ( $delay > 0 ? ' data-loreport-delay="' . esc_attr( (string) $delay ) . '"' : '' ) . '>'
			. '<div class="loreport-popup__panel">'
			. '<button type="button" class="loreport-popup__close" data-loreport-close aria-label="' . esc_attr__( 'Close', 'loreport' ) . '">&times;</button>'
			. $form
			. '</div></dialog>';
	}
}
