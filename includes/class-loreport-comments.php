<?php
/**
 * The newsletter checkbox on the comment form.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an unticked "email me news" checkbox to the comment form, and signs
 * up commenters who tick it (unless the comment is spam).
 */
class LorePort_Comments {

	public static function init(): void {
		if ( ! LorePort_Settings::enabled( 'comments' ) ) {
			return;
		}
		add_filter( 'comment_form_submit_field', array( __CLASS__, 'field' ) );
		add_action( 'comment_post', array( __CLASS__, 'subscribe' ), 10, 3 );
	}

	public static function field( string $submit_field ): string {
		$box = LorePort_Settings::checkbox( 'comments' );

		return '<p class="comment-form-loreport"><label><input type="checkbox" name="loreport_subscribe" value="1"> '
			. esc_html( $box['label'] ) . '</label></p>' . $submit_field;
	}

	/**
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   1, 0 or "spam".
	 */
	public static function subscribe( int $comment_id, $approved ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the comment form's own submission.
		if ( empty( $_POST['loreport_subscribe'] ) || 'spam' === $approved || 'trash' === $approved ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! is_email( $comment->comment_author_email ) ) {
			return;
		}
		$box = LorePort_Settings::checkbox( 'comments' );

		LorePort_Signups::submit(
			array(
				'email'        => $comment->comment_author_email,
				'name'         => $comment->comment_author,
				'list_id'      => '' !== $box['list'] ? $box['list'] : LorePort_Settings::default_list(),
				'ip'           => $comment->comment_author_IP,
				'user_agent'   => $comment->comment_agent,
				'source'       => 'wordpress:comment:' . $comment->comment_post_ID,
				'consent_text' => $box['label'],
			)
		);
	}
}
