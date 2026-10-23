<?php

namespace WPForms\Pro\Db\Dashboard;

use RuntimeException;
use Throwable;

/**
 * Transactional recompute wrapper with deadlock retry for Dashboard rollup tables.
 *
 * @since 2.0.2
 */
trait DeadlockRetryTrait {

	/**
	 * Maximum retries after the initial attempt (4 total executions) before giving up.
	 *
	 * @since 2.0.2
	 *
	 * @return int
	 */
	private function get_max_deadlock_retries(): int {

		return 3;
	}

	/**
	 * Delay before the first deadlock retry, in microseconds. Doubles per attempt.
	 *
	 * Declared as methods rather than constants because a trait cannot hold constants
	 * before PHP 8.2 and this plugin supports 7.2.
	 *
	 * @since 2.0.2
	 *
	 * @return int
	 */
	private function get_deadlock_retry_base_delay(): int {

		return 50000;
	}

	/**
	 * Upper bound of the random padding added to each retry delay, in microseconds.
	 *
	 * @since 2.0.2
	 *
	 * @return int
	 */
	private function get_deadlock_retry_jitter(): int {

		return 25000;
	}

	/**
	 * Wait before re-running a recompute that lost a deadlock.
	 *
	 * InnoDB tells the losing transaction to restart, so two workers that collided both
	 * retry — and retrying immediately sends them straight back into the same collision,
	 * burning every attempt in microseconds. The caller then refuses to advance its
	 * watermark, turning a survivable deadlock into a stalled rollup day. Doubling the
	 * wait per attempt and jittering it brings the losers back at different times.
	 *
	 * Three retries sleep ~50 + 100 + 200 ms plus up to 75 ms of jitter, so the worst
	 * case stays a fraction of a single Action Scheduler request budget.
	 *
	 * @since 2.0.2
	 *
	 * @param int $attempt Retry number, starting at 1.
	 */
	private function wait_before_deadlock_retry( int $attempt ): void {

		$backoff = $this->get_deadlock_retry_base_delay() << ( $attempt - 1 );

		// wp_rand() rather than random_int(): this runs inside the catch block, and
		// random_int() throws on entropy failure, which would escape the handler that
		// is documented to swallow everything.
		usleep( $backoff + wp_rand( 0, $this->get_deadlock_retry_jitter() ) );
	}

	// No @throws tag: the transaction failures raised below are caught by this method's own
	// handler and surface as a false return, so nothing escapes to callers.
	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing
	/**
	 * Run one day's DELETE/INSERT recompute in a transaction, retrying on deadlock.
	 *
	 * @since 2.0.2
	 *
	 * @param callable $body    Recompute body: performs the DELETE + INSERT, throwing on failure.
	 * @param string   $context Log context prefix.
	 *
	 * @return bool True when the day was recomputed and committed.
	 */
	private function recompute_in_transaction( callable $body, string $context ): bool {

		global $wpdb;

		$attempt = 0;

		while ( true ) {
			try {
				// A failed START TRANSACTION would run the body unprotected, and a failed
				// COMMIT would report success for work that never became durable — either way
				// the caller advances its watermark past a day that has no rows behind it.
				// Raising here routes both through the handler below, which logs and returns
				// false, so the day is retried instead of silently skipped.
				if ( $wpdb->query( 'START TRANSACTION' ) === false ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					throw new RuntimeException( 'START TRANSACTION failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
				}

				$body();

				if ( $wpdb->query( 'COMMIT' ) === false ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					throw new RuntimeException( 'COMMIT failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
				}

				return true;
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( $this->is_deadlock( $e ) && ++$attempt <= $this->get_max_deadlock_retries() ) {
					$this->wait_before_deadlock_retry( $attempt );

					continue;
				}

				wpforms_log(
					$context . ': transaction rolled back',
					[ 'message' => $e->getMessage() ],
					[
						'type'  => [ 'error' ],
						'force' => true,
					]
				);

				return false;
			}
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

	/**
	 * Whether an exception represents a transient InnoDB deadlock.
	 *
	 * @since 2.0.2
	 *
	 * @param Throwable $e Exception thrown by the recompute body.
	 *
	 * @return bool
	 */
	private function is_deadlock( Throwable $e ): bool {

		$message = $e->getMessage();

		return strpos( $message, 'Deadlock' ) !== false
			|| strpos( $message, 'try restarting transaction' ) !== false;
	}
}
