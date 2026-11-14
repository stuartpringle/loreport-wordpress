<?php
/**
 * Suggested privacy-policy wording.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a suggested paragraph to WordPress's privacy-policy guide (Settings →
 * Privacy → Policy guide).
 */
class LorePort_Privacy {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'suggest' ) );
	}

	public static function suggest(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for the newsletter signup forms and checkboxes provided by the LorePort plugin. Change it to match how you use them.', 'loreport' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'loreport' ) . '</strong> '
			. esc_html__( 'When you sign up for our newsletter (with a signup form, or by ticking the box when you comment or buy something), we send your email address, your name if you give it, your IP address, your browser\'s user agent and the time to LorePort (loreport.ca), which manages our mailing list and sends our newsletters. This is our record of your consent. If our list asks you to confirm, LorePort emails you a link first. If LorePort can\'t be reached at that moment, these details are kept on this website for up to a week while we try again, then deleted. You can unsubscribe at any time with the link in every newsletter. LorePort\'s privacy policy explains how it handles your data.', 'loreport' )
			. '</p>';

		wp_add_privacy_policy_content( 'LorePort', wp_kses_post( $text ) );
	}
}
