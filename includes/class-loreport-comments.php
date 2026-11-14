<?php
/**
 * The newsletter checkbox on the comment form.
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds an unticked "email me news" checkbox to the comment form. Commenters
 * who tick it are signed up once their comment is approved: straight away if
 * it's approved as it's posted, otherwise when a moderator approves it. Spam
 * and deleted comments never sign anyone up.
 */
class LorePort_Comments {

	/** Comment meta: the commenter ticked the box and is waiting for approval. */
	const META = '_loreport_subscribe';

	public static function init(): void {
		if ( ! LorePort_Settings::enabled( 'comments' ) ) {
			return;
		}
		add_filter( 'comment_form_submit_field', array( __CLASS__, 'field' ) );
		add_action( 'comment_post', array( __CLASS__, 'posted' ), 10, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'status_changed' ), 10, 3 );
	}

	public static function field( string $submit_field ): string {
		$box = LorePort_Settings::checkbox( 'comments' );
		wp_enqueue_style( 'loreport-form' );

		return '<p class="comment-form-loreport"><label><input type="checkbox" name="loreport_subscribe" value="1"> '
			. esc_html( $box['label'] ) . '</label></p>' . $submit_field;
	}

	/**
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   1, 0, "spam" or "trash".
	 */
	public static function posted( int $comment_id, $approved ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the comment form's own submission.
		if ( empty( $_POST['loreport_subscribe'] ) ) {
			return;
		}
		if ( 1 === (int) $approved && 'spam' !== $approved ) {
			self::subscribe( $comment_id );

			return;
		}
		if ( 0 === $approved || '0' === $approved ) {
			add_comment_meta( $comment_id, self::META, '1', true );
		}
	}

	/**
	 * A moderator approved (or rejected) a held comment.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    The comment.
	 */
	public static function status_changed( $new_status, $old_status, $comment ): void {
		if ( ! $comment instanceof WP_Comment || ! get_comment_meta( (int) $comment->comment_ID, self::META, true ) ) {
			return;
		}
		if ( 'approved' === $new_status ) {
			delete_comment_meta( (int) $comment->comment_ID, self::META );
			self::subscribe( (int) $comment->comment_ID );
		} elseif ( in_array( $new_status, array( 'spam', 'trash' ), true ) ) {
			delete_comment_meta( (int) $comment->comment_ID, self::META );
		}
	}

	private static function subscribe( int $comment_id ): void {
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
