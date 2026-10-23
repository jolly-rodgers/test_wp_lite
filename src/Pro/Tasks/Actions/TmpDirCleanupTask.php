<?php

namespace WPForms\Pro\Tasks\Actions;

use WPForms\Tasks\Task;

/**
 * Recurring cleanup of the shared WPForms tmp uploads directory.
 *
 * Modern File Upload and Camera fields both use `wp-content/uploads/wpforms/tmp/`
 * as scratch space, and each already deletes its own leftovers opportunistically
 * (on a successful chunk-upload finalize, or when a new camera preview is saved).
 * This task is the backstop for the case neither of those cover: a caller that
 * repeatedly hits the unauthenticated chunk-upload init/upload endpoints but
 * never completes a valid finalize leaves files behind that no opportunistic
 * path ever revisits.
 *
 * @since 2.0.1
 */
class TmpDirCleanupTask extends Task {

	/**
	 * Action name for this task.
	 *
	 * @since 2.0.1
	 */
	public const ACTION = 'wpforms_process_tmp_dir_cleanup';

	/**
	 * Class constructor.
	 *
	 * @since 2.0.1
	 */
	public function __construct() {

		parent::__construct( self::ACTION );

		$this->init();
		$this->hooks();
	}

	/**
	 * Schedule the recurring cleanup if it isn't already scheduled.
	 *
	 * @since 2.0.1
	 */
	private function init(): void {

		$tasks = wpforms()->obj( 'tasks' );

		if ( ! $tasks || $tasks->is_scheduled( self::ACTION ) !== false ) {
			return;
		}

		/**
		 * Filter the interval, in seconds, between tmp uploads directory cleanup runs.
		 *
		 * @since 2.0.1
		 *
		 * @param int $interval Interval in seconds.
		 */
		$interval = (int) apply_filters( 'wpforms_pro_tasks_actions_tmp_dir_cleanup_task_interval', DAY_IN_SECONDS );

		$this->recurring( strtotime( 'tomorrow' ), $interval )
			->params()
			->register();
	}

	/**
	 * Add hooks.
	 *
	 * @since 2.0.1
	 */
	private function hooks(): void {

		add_action( self::ACTION, [ $this, 'process' ] );
	}

	/**
	 * Delete stale files from the shared tmp uploads directory.
	 *
	 * @since 2.0.1
	 */
	public function process(): void {

		$upload_dir = wpforms_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['path'] ) ) {
			return;
		}

		$files = glob( trailingslashit( $upload_dir['path'] ) . 'tmp/*' );

		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}

		/**
		 * Filter defined in WPForms\Pro\Forms\Fields\FileUpload\Field::clean_tmp_files().
		 */
		// phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName, WordPress.NamingConventions.ValidHookName.UseUnderscores, WPForms.Comments.PHPDocHooks.RequiredHookDocumentation, WPForms.Comments.SinceTagHooks.MissingSinceTag
		$file_upload_lifespan = (int) apply_filters( 'wpforms_field_file-upload_clean_tmp_files_lifespan', DAY_IN_SECONDS );

		/**
		 * Filter defined in WPForms\Pro\Forms\Fields\Camera\Field::clean_preview_tmp_files().
		 */
		// phpcs:ignore WPForms.PHP.ValidateHooks.InvalidHookName, WPForms.Comments.PHPDocHooks.RequiredHookDocumentation, WPForms.Comments.SinceTagHooks.MissingSinceTag
		$camera_lifespan = (int) apply_filters( 'wpforms_field_camera_clean_tmp_files_lifespan', DAY_IN_SECONDS );

		/**
		 * Filter how old, in seconds, a file in the tmp uploads directory must be
		 * before this task deletes it.
		 *
		 * Defaults to the longest of the per-field tmp file lifespans, so a site
		 * that extended either of those filters is never undercut by this task.
		 *
		 * @since 2.0.1
		 *
		 * @param int $lifespan Lifespan in seconds.
		 */
		$lifespan = (int) apply_filters( 'wpforms_pro_tasks_actions_tmp_dir_cleanup_task_lifespan', max( $file_upload_lifespan, $camera_lifespan ) );
		$now      = time();
		$deleted  = 0;

		foreach ( $files as $file ) {
			if ( basename( $file ) === 'index.html' || ! is_file( $file ) ) {
				continue;
			}

			$modified = (int) filemtime( $file );

			if ( empty( $modified ) ) {
				$modified = $now;
			}

			if ( ( $now - $modified ) < $lifespan ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( @unlink( $file ) ) {
				++$deleted;
			}
		}

		if ( $deleted > 0 ) {
			$this->log( sprintf( '%d stale file(s) deleted from the tmp uploads directory.', $deleted ) );
		}
	}
}
