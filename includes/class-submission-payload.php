<?php
/**
 * Immutable, CF7-independent description of one submission destined for one
 * integration. The sync layer only sees this object, so a future retry queue or
 * another form plugin can feed the same pipeline.
 *
 * @package SKFreelancers\SheetsSync
 */

namespace SKFreelancers\SheetsSync;

defined( 'ABSPATH' ) || exit;

/**
 * Submission payload.
 */
final class Submission_Payload {

	/**
	 * Contact Form 7 form ID.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Contact Form 7 form title.
	 *
	 * @var string
	 */
	private $form_title;

	/**
	 * Submission key (HMAC).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Field names of the form.
	 *
	 * @var string[]
	 */
	private $field_names;

	/**
	 * Normalized values keyed by source (field:name or meta:key).
	 *
	 * @var array<string,string>
	 */
	private $values;

	/**
	 * Constructor.
	 *
	 * @param int                  $form_id     Form ID.
	 * @param string               $form_title  Form title.
	 * @param string               $key         64-char submission key.
	 * @param string[]             $field_names Field names currently in the form.
	 * @param array<string,string> $values      Values keyed by source ("field:x", "meta:y").
	 */
	public function __construct( int $form_id, string $form_title, string $key, array $field_names, array $values ) {
		$this->form_id     = $form_id;
		$this->form_title  = $form_title;
		$this->key         = $key;
		$this->field_names = $field_names;
		$this->values      = $values;
	}

	/**
	 * Contact Form 7 form ID.
	 *
	 * @return int
	 */
	public function form_id(): int {
		return $this->form_id;
	}

	/**
	 * Contact Form 7 form title.
	 *
	 * @return string
	 */
	public function form_title(): string {
		return $this->form_title;
	}

	/**
	 * Deterministic submission key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Short submission reference.
	 *
	 * @return string Short, non-reversible reference safe for logs.
	 */
	public function reference(): string {
		return substr( $this->key, 0, 12 );
	}

	/**
	 * Field names of the form, in form order.
	 *
	 * @return string[]
	 */
	public function field_names(): array {
		return $this->field_names;
	}

	/**
	 * Value for a source ('' when absent).
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public function value( string $source ): string {
		return $this->values[ $source ] ?? '';
	}
}
