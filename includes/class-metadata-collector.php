<?php
/**
 * Collects optional submission metadata. Only keys explicitly enabled for a form are
 * computed, so IP addresses, user agents and user details are never gathered unless
 * the administrator turned them on.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Metadata collector.
 */
final class Metadata_Collector {

	/**
	 * Collects metadata values.
	 *
	 * @param \WPCF7_Submission $submission Submission.
	 * @param string[]          $keys       Enabled metadata keys.
	 * @return array<string,string>
	 */
	public function collect( $submission, array $keys ): array {
		$values = array();
		if ( empty( $keys ) ) {
			return $values;
		}

		$timestamp = (int) $submission->get_meta( 'timestamp' );
		$timestamp = $timestamp > 0 ? $timestamp : time();
		$post      = null;
		$user      = null;

		if ( array_intersect( $keys, array( 'post_id', 'post_title', 'post_url', 'post_author' ) ) ) {
			$post_id = (int) $submission->get_meta( 'container_post_id' );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
		}
		if ( array_intersect( $keys, array( 'user_id', 'user_login', 'user_email', 'user_first_name', 'user_last_name' ) ) ) {
			$user_id = (int) $submission->get_meta( 'current_user_id' );
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		}

		foreach ( $keys as $key ) {
			switch ( $key ) {
				case 'submission_date':
					$values[ $key ] = (string) wp_date( (string) apply_filters( 'skfss_date_format', 'Y-m-d' ), $timestamp );
					break;
				case 'submission_time':
					$values[ $key ] = (string) wp_date( (string) apply_filters( 'skfss_time_format', 'H:i:s' ), $timestamp );
					break;
				case 'remote_ip':
					$values[ $key ] = sanitize_text_field( (string) $submission->get_meta( 'remote_ip' ) );
					break;
				case 'user_agent':
					$values[ $key ] = wp_html_excerpt( sanitize_text_field( (string) $submission->get_meta( 'user_agent' ) ), 500 );
					break;
				case 'current_url':
					$values[ $key ] = esc_url_raw( (string) $submission->get_meta( 'url' ) );
					break;
				case 'post_id':
					$values[ $key ] = $post ? (string) $post->ID : '';
					break;
				case 'post_title':
					$values[ $key ] = $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : '';
					break;
				case 'post_url':
					$values[ $key ] = $post ? (string) get_permalink( $post ) : '';
					break;
				case 'post_author':
					$values[ $key ] = $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
					break;
				case 'site_title':
					$values[ $key ] = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
					break;
				case 'site_url':
					$values[ $key ] = home_url( '/' );
					break;
				case 'user_id':
					$values[ $key ] = $user ? (string) $user->ID : '';
					break;
				case 'user_login':
					$values[ $key ] = $user ? (string) $user->user_login : '';
					break;
				case 'user_email':
					$values[ $key ] = $user ? (string) $user->user_email : '';
					break;
				case 'user_first_name':
					$values[ $key ] = $user ? (string) $user->first_name : '';
					break;
				case 'user_last_name':
					$values[ $key ] = $user ? (string) $user->last_name : '';
					break;
			}
		}

		return $values;
	}
}
