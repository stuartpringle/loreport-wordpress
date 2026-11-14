<?php
/**
 * Talks to the LorePort API (https://loreport.ca/docs/api).
 *
 * @package LorePort
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small client for LorePort's API, version 1. Every call is made from the
 * WordPress server with the site's API key; the key never reaches a browser.
 */
class LorePort_Client {

	/**
	 * Where LorePort is. Override with the LOREPORT_API_URL constant (for example
	 * to test against a development copy) or the loreport_api_url filter.
	 */
	public static function base_url(): string {
		$url = defined( 'LOREPORT_API_URL' ) ? (string) LOREPORT_API_URL : 'https://loreport.ca';

		return untrailingslashit( (string) apply_filters( 'loreport_api_url', $url ) );
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /api/v1, such as "lists".
	 * @param array|null $body   JSON body.
	 * @param string     $key    API key to use instead of the saved one (for testing a new key).
	 * @return array{status: int, body: array, error: string|null} status 0 means LorePort couldn't be reached.
	 */
	public static function request( string $method, string $path, ?array $body = null, string $key = '' ): array {
		$key = '' !== $key ? $key : LorePort_Settings::api_key();
		if ( '' === $key ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => __( 'No LorePort API key is saved yet.', 'loreport' ),
			);
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'LorePort-WordPress/' . LOREPORT_VERSION . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . '/api/v1/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		return array(
			'status' => $status,
			'body'   => $decoded,
			'error'  => $status >= 400 ? (string) ( $decoded['error']['message'] ?? sprintf( 'HTTP %d', $status ) ) : null,
		);
	}

	/**
	 * The account's lists, cached for ten minutes.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array<int, array> Each with id, name, double_opt_in and consent_text; empty if LorePort can't be reached.
	 */
	public static function lists( bool $fresh = false ): array {
		$cached = get_transient( 'loreport_lists' );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}

		$response = self::request( 'GET', 'lists' );
		if ( 200 !== $response['status'] ) {
			return is_array( $cached ) ? $cached : array();
		}

		$lists = array_values( array_filter( (array) ( $response['body']['data'] ?? array() ), 'is_array' ) );
		set_transient( 'loreport_lists', $lists, 10 * MINUTE_IN_SECONDS );

		return $lists;
	}

	/**
	 * One list by ID, from the cached lists.
	 *
	 * @param string $list_id List ID.
	 * @return array|null
	 */
	public static function find_list( string $list_id ): ?array {
		foreach ( self::lists() as $list ) {
			if ( ( $list['id'] ?? '' ) === $list_id ) {
				return $list;
			}
		}

		return null;
	}
}
