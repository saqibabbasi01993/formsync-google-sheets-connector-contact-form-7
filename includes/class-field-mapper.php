<?php
/**
 * Field discovery, mapping normalization and column resolution.
 *
 * Mapping rows are stored as an ordered list:
 *   array( 'source' => 'field:your-email' | 'meta:submission_date', 'column' => 'Email', 'enabled' => true )
 * Row order is column order when headers are created.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Field mapper.
 */
final class Field_Mapper {

	/**
	 * CF7 tag types that never carry user data worth storing.
	 */
	const EXCLUDED_TYPES = array( 'submit', 'captchac', 'captchar', 'quiz', 'recaptcha', 'hcaptcha', 'turnstile', 'stripe' );

	/**
	 * Optional metadata columns. None is collected unless enabled per form.
	 *
	 * @return array<string,string> key => label
	 */
	public static function metadata_definitions(): array {
		return array(
			'submission_date' => __( 'Submission Date', 'formsync-google-sheets-connector-contact-form-7' ),
			'submission_time' => __( 'Submission Time', 'formsync-google-sheets-connector-contact-form-7' ),
			'remote_ip'       => __( 'Remote IP', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_agent'      => __( 'User Agent', 'formsync-google-sheets-connector-contact-form-7' ),
			'current_url'     => __( 'Current URL', 'formsync-google-sheets-connector-contact-form-7' ),
			'post_id'         => __( 'Post ID', 'formsync-google-sheets-connector-contact-form-7' ),
			'post_title'      => __( 'Post Title', 'formsync-google-sheets-connector-contact-form-7' ),
			'post_url'        => __( 'Post URL', 'formsync-google-sheets-connector-contact-form-7' ),
			'post_author'     => __( 'Post Author', 'formsync-google-sheets-connector-contact-form-7' ),
			'site_title'      => __( 'Site Title', 'formsync-google-sheets-connector-contact-form-7' ),
			'site_url'        => __( 'Site URL', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_id'         => __( 'User ID', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_login'      => __( 'Username', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_email'      => __( 'User Email', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_first_name' => __( 'User First Name', 'formsync-google-sheets-connector-contact-form-7' ),
			'user_last_name'  => __( 'User Last Name', 'formsync-google-sheets-connector-contact-form-7' ),
		);
	}

	/**
	 * Metadata keys.
	 *
	 * @return string[]
	 */
	public static function metadata_keys(): array {
		return array_keys( self::metadata_definitions() );
	}

	/**
	 * Data-carrying fields of a CF7 form, in form order.
	 *
	 * @param \WPCF7_ContactForm $contact_form Form.
	 * @return array<int,array{name:string,type:string}>
	 */
	public function get_form_fields( $contact_form ): array {
		$fields = array();
		foreach ( (array) $contact_form->scan_form_tags() as $tag ) {
			$name = isset( $tag->name ) ? (string) $tag->name : '';
			$type = isset( $tag->basetype ) ? (string) $tag->basetype : '';
			if ( '' === $name || in_array( $type, self::EXCLUDED_TYPES, true ) || isset( $fields[ $name ] ) ) {
				continue;
			}
			$fields[ $name ] = array(
				'name' => $name,
				'type' => $type,
			);
		}
		return array_values( $fields );
	}

	/**
	 * Validates and completes a mapping.
	 *
	 * Unknown sources are dropped, duplicates removed, empty column names defaulted,
	 * and (optionally) sources missing from the list are appended: fields enabled,
	 * metadata enabled only when listed in $default_meta.
	 *
	 * @param array    $rows           Rows.
	 * @param string[] $field_names    Current field names.
	 * @param string[] $default_meta   Metadata enabled by default for appended rows.
	 * @param bool     $append_missing Append missing sources.
	 * @return array<int,array{source:string,column:string,enabled:bool}>
	 */
	public function normalize_mapping( array $rows, array $field_names, array $default_meta, bool $append_missing = true ): array {
		$meta = self::metadata_definitions();
		$out  = array();
		$seen = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source = $this->validate_source( (string) ( $row['source'] ?? '' ), $field_names );
			if ( '' === $source || isset( $seen[ $source ] ) ) {
				continue;
			}
			$column = substr( trim( sanitize_text_field( (string) ( $row['column'] ?? '' ) ) ), 0, 191 );

			$seen[ $source ] = true;
			$out[]           = array(
				'source'  => $source,
				'column'  => '' !== $column ? $column : $this->default_column( $source ),
				'enabled' => ! empty( $row['enabled'] ),
			);
		}

		if ( ! $append_missing ) {
			return $out;
		}

		foreach ( $field_names as $name ) {
			$source = 'field:' . $name;
			if ( ! isset( $seen[ $source ] ) ) {
				$seen[ $source ] = true;
				$out[]           = array(
					'source'  => $source,
					'column'  => $name,
					'enabled' => true,
				);
			}
		}
		foreach ( $meta as $key => $label ) {
			$source = 'meta:' . $key;
			if ( ! isset( $seen[ $source ] ) ) {
				$seen[ $source ] = true;
				$out[]           = array(
					'source'  => $source,
					'column'  => $label,
					'enabled' => in_array( $key, $default_meta, true ),
				);
			}
		}

		return $out;
	}

	/**
	 * Final ordered list of enabled columns for a submission.
	 *
	 * Automatic mode: every current form field (new fields included) goes to the
	 * existing sheet column whose header matches best, or to a column named after the
	 * field. Manual mode: exactly the saved mapping; fields added to the form after
	 * the mapping was saved are ignored until the mapping is saved again.
	 *
	 * @param Integration $integration Integration.
	 * @param string[]    $field_names Current field names.
	 * @param string[]    $headers     Existing sheet headers.
	 * @return array<int,array{source:string,column:string}>
	 */
	public function resolve_columns( Integration $integration, array $field_names, array $headers ): array {
		$auto    = 'auto' === $integration->mapping_mode();
		$mapping = $this->normalize_mapping( $integration->mapping(), $field_names, array(), $auto );
		$used    = array();
		$columns = array();

		foreach ( $mapping as $row ) {
			if ( empty( $row['enabled'] ) ) {
				continue;
			}
			$column = $row['column'];
			if ( $auto && 0 === strpos( $row['source'], 'field:' ) ) {
				$name    = substr( $row['source'], 6 );
				$matched = $this->match_header( $name, $headers, $used );
				$column  = '' !== $matched ? $matched : $name;
			}
			$used[ self::header_key( $column ) ] = true;
			$columns[]                           = array(
				'source' => $row['source'],
				'column' => $column,
			);
		}
		return $columns;
	}

	/**
	 * Suggests a header for each field (used by the "Match with sheet headers" button).
	 *
	 * @param string[] $field_names Field names.
	 * @param string[] $headers     Sheet headers.
	 * @return array<string,string> field => header ('' when no confident match).
	 */
	public function suggest_mapping( array $field_names, array $headers ): array {
		$used        = array();
		$suggestions = array();
		foreach ( $field_names as $name ) {
			$match                = $this->match_header( $name, $headers, $used );
			$suggestions[ $name ] = $match;
			if ( '' !== $match ) {
				$used[ self::header_key( $match ) ] = true;
			}
		}
		return $suggestions;
	}

	/**
	 * Finds the best header for a field name: exact normalized match first, then a
	 * single unambiguous containment match ("your-email" ~ "Email Address").
	 *
	 * @param string             $field   Field name.
	 * @param string[]           $headers Headers.
	 * @param array<string,bool> $used    Header keys already taken.
	 * @return string Header or ''.
	 */
	public function match_header( string $field, array $headers, array $used = array() ): string {
		$target = self::normalize( $field );
		if ( '' === $target ) {
			return '';
		}

		$candidates = array();
		foreach ( $headers as $header ) {
			$header = (string) $header;
			if ( '' === trim( $header ) || isset( $used[ self::header_key( $header ) ] ) ) {
				continue;
			}
			$normalized = self::normalize( Formula_Guard::unprotect( $header ) );
			if ( $normalized === $target ) {
				return $header;
			}
			if ( strlen( $target ) >= 3 && strlen( $normalized ) >= 3 && ( false !== strpos( $normalized, $target ) || false !== strpos( $target, $normalized ) ) ) {
				$candidates[] = $header;
			}
		}

		return 1 === count( $candidates ) ? $candidates[0] : '';
	}

	/**
	 * Key used to compare header cells (case/space-insensitive, guard-aware).
	 *
	 * @param string $header Header.
	 * @return string
	 */
	public static function header_key( string $header ): string {
		return strtolower( trim( Formula_Guard::unprotect( trim( $header ) ) ) );
	}

	/**
	 * Normalizes a name for fuzzy matching.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( remove_accents( trim( $value ) ) );
		$value = (string) preg_replace( '/^your[\s_-]+/', '', $value );
		return (string) preg_replace( '/[^a-z0-9]/', '', $value );
	}

	/**
	 * Default column for a source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public function default_column( string $source ): string {
		if ( 0 === strpos( $source, 'meta:' ) ) {
			$meta = self::metadata_definitions();
			return $meta[ substr( $source, 5 ) ] ?? substr( $source, 5 );
		}
		return substr( $source, 6 );
	}

	/**
	 * Human label for a source.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public function source_label( string $source ): string {
		if ( 0 === strpos( $source, 'meta:' ) ) {
			return $this->default_column( $source );
		}
		return substr( $source, 6 );
	}

	/**
	 * Returns a valid source string or ''.
	 *
	 * @param string   $source      Candidate.
	 * @param string[] $field_names Field names.
	 * @return string
	 */
	private function validate_source( string $source, array $field_names ): string {
		if ( 0 === strpos( $source, 'field:' ) ) {
			$name = Security::sanitize_field_name( substr( $source, 6 ) );
			return in_array( $name, $field_names, true ) ? 'field:' . $name : '';
		}
		if ( 0 === strpos( $source, 'meta:' ) ) {
			$key = sanitize_key( substr( $source, 5 ) );
			return in_array( $key, self::metadata_keys(), true ) ? 'meta:' . $key : '';
		}
		return '';
	}
}
