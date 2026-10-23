<?php

namespace WPForms\Pro\Admin\Entries\Import;

use WPForms\Helpers\Transient;
use WPForms\Pro\Admin\Entries\Import\Source\AbstractSource;

/**
 * Import session backed by a WPForms transient.
 *
 * @since 1.10.1
 */
class ImportSession {

	/**
	 * Transient key prefix.
	 *
	 * @since 1.10.1
	 */
	public const KEY_PREFIX = 'import_session_';

	/**
	 * Session TTL in seconds.
	 *
	 * @since 1.10.1
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Unique session identifier.
	 *
	 * @since 1.10.1
	 *
	 * @var string
	 */
	private $request_id;

	/**
	 * Session data.
	 *
	 * @since 1.10.1
	 *
	 * @var array
	 */
	private $data = [];

	/**
	 * Constructor.
	 *
	 * @since 1.10.1
	 *
	 * @param string $request_id Unique session ID. Pass an empty string to generate a new one.
	 */
	public function __construct( string $request_id = '' ) {

		$this->request_id = ! empty( $request_id ) ? $request_id : self::generate_request_id();
	}

	/**
	 * Generate a unique request ID.
	 *
	 * @since 1.10.1
	 *
	 * @return string
	 */
	private static function generate_request_id(): string {

		return wp_generate_uuid4();
	}

	/**
	 * Encode the session payload into an ASCII-safe string for storage.
	 *
	 * Serializing and base64-encoding keeps the stored value ASCII-only, so a 4-byte
	 * UTF-8 character (e.g. an emoji) in the source data cannot be rejected by a
	 * 3-byte charset wp_options column.
	 *
	 * @since 2.0.1
	 *
	 * @param array $data Session payload to encode.
	 *
	 * @return string ASCII-safe encoded payload.
	 */
	private static function encode_data( array $data ): string {

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Charset-safe transient payload; own serialized array.
		return base64_encode( serialize( $data ) );
	}

	/**
	 * Decode a stored session payload.
	 *
	 * Backward compatible with legacy transients that stored a raw PHP array.
	 *
	 * @since 2.0.1
	 *
	 * @param mixed $stored Stored transient value.
	 *
	 * @return array|false Decoded payload, or false when it is missing or corrupt.
	 */
	private static function decode_data( $stored ) {

		// Legacy transients stored the raw array, so return it unchanged.
		if ( is_array( $stored ) ) {
			return $stored;
		}

		if ( ! is_string( $stored ) || $stored === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Charset-safe transient payload.
		$serialized = base64_decode( $stored, true );

		// Bail out when the value is not valid base64.
		if ( $serialized === false ) {
			return false;
		}

		// Session payloads only ever hold scalars/arrays, so disallow object instantiation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors -- Own serialized array, classes disallowed; @ suppresses the E_WARNING unserialize() emits on malformed input.
		$data = @unserialize( $serialized, [ 'allowed_classes' => false ] );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Persist the current session payload to the transient store.
	 *
	 * @since 2.0.1
	 *
	 * @return bool True when the payload is stored, false on a real persist failure.
	 */
	private function save(): bool {

		$key      = self::KEY_PREFIX . $this->request_id;
		$is_saved = Transient::set( $key, self::encode_data( $this->data ), self::TTL );

		// update_option() returns false when the stored value is unchanged, so confirm against the stored payload before treating a false as a persist failure.
		return $is_saved || self::decode_data( Transient::get( $key ) ) === $this->data;
	}

	/**
	 * Create and persist a new session.
	 *
	 * @since 1.10.1
	 *
	 * @param AbstractSource $source            Source object.
	 * @param int            $form_id           Target WPForms form ID.
	 * @param string         $source_identifier Slug or file extension identifying the source (e.g. 'cf7', 'csv').
	 *
	 * @return self|null Session instance on success, or null when the payload could not be persisted.
	 */
	public static function create( AbstractSource $source, int $form_id, string $source_identifier ): ?self {

		$source->validate();

		$request_id = self::generate_request_id();

		$payload = [
			'source_identifier' => $source_identifier,
			'source_args'       => $source->get_args(),
			'form_id'           => $form_id,
			'cursor'            => 0,
			'total'             => $source->get_total(),
			'imported'          => 0,
			'source_fields'     => $source->get_fields(),
			'status'            => 'active',
			'errors'            => [],
		];

		$session       = new self( $request_id );
		$session->data = $payload;

		// Bail out when the payload could not be stored (e.g. a 4-byte character on a 3-byte charset column).
		if ( ! $session->save() ) {
			return null;
		}

		return $session;
	}

	/**
	 * Load an existing session from the transient store.
	 *
	 * @since 1.10.1
	 *
	 * @return bool True if a session was found and loaded.
	 */
	public function load(): bool {

		$data = self::decode_data( Transient::get( self::KEY_PREFIX . $this->request_id ) );

		if ( ! is_array( $data ) ) {
			return false;
		}

		$this->data = $data;

		return true;
	}

	/**
	 * Update cursor and imported count after a processed chunk.
	 *
	 * @since 1.10.1
	 *
	 * @param int   $cursor   New cursor position.
	 * @param int   $imported Number of entries successfully imported in the last chunk.
	 * @param array $errors   Per-entry errors from the last chunk.
	 *
	 * @return bool True if the updated payload was persisted, false otherwise.
	 */
	public function advance( int $cursor, int $imported, array $errors ): bool {

		$this->data['cursor']    = $cursor;
		$this->data['imported'] += $imported;
		$this->data['errors']    = array_merge( $this->data['errors'], $errors );

		return $this->save();
	}

	/**
	 * Mark the session as failed.
	 *
	 * @since 1.10.1
	 */
	public function fail(): void {

		$this->data['status'] = 'failed';

		// Best-effort re-persist of the failed status; keep the fail flow going regardless of the outcome.
		$is_saved = $this->save();

		// Log (never surface) when the failed status could not be stored, so it is not a silent success.
		if ( ! $is_saved ) {
			wpforms_log(
				'Entry import session failed-status could not be persisted',
				[ 'request_id' => $this->request_id ],
				[ 'type' => [ 'entry', 'error' ] ]
			);
		}
	}

	/**
	 * Delete the session transient.
	 *
	 * @since 1.10.1
	 */
	public function delete(): void {

		Transient::delete( self::KEY_PREFIX . $this->request_id );
	}

	/**
	 * Return the unique session request ID.
	 *
	 * @since 1.10.1
	 *
	 * @return string
	 */
	public function get_request_id(): string {

		return $this->request_id;
	}

	/**
	 * Return a session data value by key.
	 *
	 * @since 1.10.1
	 *
	 * @param string $key           Data key.
	 * @param mixed  $default_value Default value if the key is absent.
	 *
	 * @return mixed
	 */
	public function get_data( string $key, $default_value = null ) {

		return $this->data[ $key ] ?? $default_value;
	}

	/**
	 * Return true if all entries have been processed or the session has failed.
	 *
	 * NOTE: advance() must receive the count of entries actually written to the
	 * database, not the count returned by process_chunk(), to keep this check
	 * accurate when write failures occur at the orchestrator level.
	 *
	 * @since 1.10.1
	 *
	 * @return bool
	 */
	public function is_complete(): bool {

		if ( $this->data['status'] === 'failed' ) {
			return true;
		}

		return $this->data['cursor'] >= $this->data['total'];
	}
}
