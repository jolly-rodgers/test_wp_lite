<?php

namespace WPForms\Pro\Forms\Fields\Repeater;

use WPForms\Pro\Forms\Fields\Layout\Helpers as LayoutHelpers;

/**
 * Class Helpers to provide helper methods for the Repeater field.
 *
 * @since 1.8.9
 */
class Helpers {

	/**
	 * Normalize the Repeater field settings.
	 *
	 * @since 1.8.9
	 *
	 * @param array $repeater_field Repeater field settings.
	 *
	 * @return array
	 */
	public static function normalize_repeater_setting( array $repeater_field ): array {

		$repeater_field['columns'] = $repeater_field['columns'] ?? Field::DEFAULT_COLUMNS;

		foreach ( $repeater_field['columns'] as $key => $column ) {

			// Ensure that the column has the `fields` array.
			$repeater_field['columns'][ $key ]['fields'] = $column['fields'] ?? [];
		}

		return $repeater_field;
	}

	/**
	 * Remove child fields after moving to repeater field.
	 *
	 * @since 1.8.9
	 *
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	public static function remove_child_fields_after_moving_to_repeater_field( array $form_data ): array {

		$form_fields = $form_data['fields'] ?? [];

		$repeater_fields = self::get_repeater_fields( $form_fields );

		foreach ( $repeater_fields as $repeater_field ) {
			$fields = self::get_repeater_all_field_ids( $repeater_field );

			foreach ( $fields as $field_id ) {
				unset( $form_data['fields'][ $field_id ] );
			}
		}

		return $form_data;
	}

	/**
	 * Get all field IDs from the Repeater field settings.
	 *
	 * @since 1.8.9
	 *
	 * @param array $repeater_field Repeater field settings.
	 *
	 * @return array
	 */
	public static function get_repeater_all_field_ids( array $repeater_field ): array {

		return array_merge( ...wp_list_pluck( $repeater_field['columns'] ?? [], 'fields' ) );
	}

	/**
	 * Get all repeater fields from the form fields.
	 *
	 * @since 1.8.9
	 *
	 * @param array $form_fields Form fields.
	 *
	 * @return array
	 */
	public static function get_repeater_fields( array $form_fields ): array {

		return array_filter(
			$form_fields,
			static function ( $field ) {

				return $field['type'] === 'repeater';
			}
		);
	}

	/**
	 * Get the repeater field blocks.
	 *
	 * @since 1.8.9
	 *
	 * @param array $field     Field data.
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	public static function get_blocks( array $field, array $form_data ): array {

		$rows = isset( $field['columns'] ) && is_array( $field['columns'] ) ? LayoutHelpers::get_row_data( $field ) : [];

		if ( ! isset( $form_data['fields'][ $field['id'] ] ) || empty( $rows ) ) {
			return [];
		}

		$chunk_size = self::get_repeater_chunk_size( $form_data['fields'][ $field['id'] ] );

		if ( ! $chunk_size ) {
			return [];
		}

		return array_chunk( $rows, $chunk_size );
	}

	/**
	 * Get previewable form data.
	 *
	 * @since 1.9.3
	 *
	 * @param array $form_data Form data.
	 * @param array $field     Field data.
	 *
	 * @return array
	 */
	public static function get_previewable_form_data( array $form_data, array $field ): array {

		$field_settings = $form_data['fields'][ $field['id'] ] ?? [];

		if ( empty( $field_settings ) ) {
			return $form_data;
		}

		foreach ( $field_settings['columns'] as $column_id => $column ) {
			$column_fields = $column['fields'] ?? [];

			foreach ( $column_fields as $field_index => $field_id ) {
				$inner_field = $form_data['fields'][ $field_id ] ?? [];

				if ( in_array( $inner_field['type'] ?? '', [ 'html', 'content', 'hidden', 'internal-information' ], true ) ) {
					unset( $field_settings['columns'][ $column_id ]['fields'][ $field_index ] );
				}
			}
		}

		$form_data['fields'][ $field['id'] ] = $field_settings;

		return $form_data;
	}

	/**
	 * Get the number of repeater clones.
	 *
	 * @since 1.8.9
	 *
	 * @param array $field_settings Field data.
	 *
	 * @return int
	 */
	public static function get_repeater_chunk_size( array $field_settings ): int {

		$max_fields_count = 0;

		foreach ( $field_settings['columns'] as $column ) {
			$fields_count  = 0;
			$column_fields = $column['fields'] ?? [];

			foreach ( $column_fields as $field ) {
				if ( ! wpforms_is_repeater_child_field( $field ) ) {
					++$fields_count;
				}
			}

			if ( $fields_count > $max_fields_count ) {
				$max_fields_count = $fields_count;
			}
		}

		return $max_fields_count;
	}

	/**
	 * Get the original field IDs from the Repeater field settings.
	 *
	 * @since 1.8.9
	 *
	 * @param array $repeater_field Repeater field settings.
	 *
	 * @return array
	 */
	public static function get_repeater_original_field_ids( array $repeater_field ): array {

		// Get all the inner field ids.
		$ids = self::get_repeater_all_field_ids( $repeater_field );

		// Filter out the child fields.
		foreach ( $ids as $key => $id ) {
			if ( wpforms_is_repeater_child_field( $id ) ) {
				unset( $ids[ $key ] );
			}
		}

		return $ids;
	}

	/**
	 * Get the repeater clones.
	 *
	 * @since 1.8.9
	 *
	 * @param array $field_settings Field data.
	 *
	 * @return array
	 */
	private static function get_repeater_clones( array $field_settings ): array {

		$clones = [];

		foreach ( $field_settings['columns'] as $column ) {
			$column_fields = $column['fields'] ?? [];

			foreach ( $column_fields as $field ) {
				if ( wpforms_is_repeater_child_field( $field ) ) {
					$field_id = is_array( $field ) ? $field['id'] : $field;
					$ids      = wpforms_get_repeater_field_ids( $field_id );

					$clones[] = $ids['index_id'];
				}
			}
		}

		return array_unique( $clones );
	}

	/**
	 * Get the repeater clones.
	 *
	 * @since 1.8.9
	 *
	 * @param array $field_settings Field data.
	 * @param array $fields         Form fields.
	 *
	 * @return array
	 */
	public static function get_repeater_clones_from_fields( array $field_settings, array $fields ): array {

		$original_fields = self::get_repeater_original_field_ids( $field_settings );
		$clones          = [];
		$field_ids       = array_keys( $fields );

		foreach ( $original_fields as $original_field_id ) {
			foreach ( $field_ids as $field_id ) {
				if ( ! wpforms_is_repeater_child_field( $field_id ) ) {
					continue;
				}

				$clone_ids = wpforms_get_repeater_field_ids( $field_id );

				if ( $original_field_id === (int) $clone_ids['original_id'] ) {
					$clones[] = $clone_ids['index_id'];
				}
			}
		}

		return array_unique( $clones );
	}

	/**
	 * Renumber repeater clone keys in entry fields to ordinal row positions.
	 *
	 * Clone numbers are assigned in the browser and are not guaranteed to be
	 * contiguous: deleting a row before submitting leaves gaps (e.g. 3, 4
	 * instead of 2, 3). Renumbering makes the Nth row of every entry use the
	 * same clone key, so bulk export columns align across entries.
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_fields Entry fields keyed by field ID.
	 * @param array $form_fields  Form fields.
	 *
	 * @return array
	 */
	public static function normalize_entry_fields_clone_numbers( array $entry_fields, array $form_fields ): array {

		$key_map            = [];
		$remapped_repeaters = [];

		foreach ( self::get_repeater_fields( $form_fields ) as $repeater_id => $repeater_field ) {
			$repeater_field  = self::normalize_repeater_setting( $repeater_field );
			$original_fields = self::get_repeater_original_field_ids( $repeater_field );

			$clones = self::get_ordered_clones( $entry_fields, $original_fields );

			foreach ( $clones as $index => $clone_number ) {
				$ordinal = $index + 2;

				if ( $ordinal === (int) $clone_number ) {
					continue;
				}

				$remapped_repeaters[ $repeater_id ] = $repeater_id;

				foreach ( $original_fields as $original_field_id ) {
					$key_map[ (int) $original_field_id . '_' . (int) $clone_number ] = (int) $original_field_id . '_' . $ordinal;
				}
			}
		}

		if ( ! $key_map ) {
			return $entry_fields;
		}

		$normalized = self::apply_clone_key_map( $entry_fields, $key_map );

		// A stale clone list would contradict the renumbered keys, so drop it and let it be derived from them.
		foreach ( $remapped_repeaters as $repeater_id ) {
			if ( isset( $normalized[ $repeater_id ] ) && is_array( $normalized[ $repeater_id ] ) ) {
				unset( $normalized[ $repeater_id ]['clone_list'] );
			}
		}

		return $normalized;
	}

	/**
	 * Collect repeater clone numbers in a single ordered pass over the entry keys.
	 *
	 * This keeps the visual row order even when a row misses some of its child keys.
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_fields    Entry fields keyed by field ID.
	 * @param array $original_fields Original field IDs of the repeater.
	 *
	 * @return array
	 */
	private static function get_ordered_clones( array $entry_fields, array $original_fields ): array {

		$original_ids = array_map( 'intval', $original_fields );
		$clones       = [];

		foreach ( array_keys( $entry_fields ) as $field_key ) {
			if ( ! wpforms_is_repeater_child_field( $field_key ) ) {
				continue;
			}

			$ids = wpforms_get_repeater_field_ids( $field_key );

			// Skip clones that belong to other repeaters in the same form.
			if ( in_array( $ids['original_id'], $original_ids, true ) ) {
				$clones[ $ids['index_id'] ] = $ids['index_id'];
			}
		}

		return array_values( $clones );
	}

	/**
	 * Rekey entry fields according to the given clone key map.
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_fields Entry fields keyed by field ID.
	 * @param array $key_map      Map of old clone keys to new ones.
	 *
	 * @return array
	 */
	private static function apply_clone_key_map( array $entry_fields, array $key_map ): array {

		$normalized = [];

		foreach ( $entry_fields as $key => $field ) {
			$new_key = $key_map[ (string) $key ] ?? $key;

			if ( $new_key !== $key && is_array( $field ) && isset( $field['id'] ) ) {
				$field['id'] = $new_key;
			}

			$normalized[ $new_key ] = $field;
		}

		return $normalized;
	}

	/**
	 * Create repeater rows.
	 *
	 * @since 1.8.9
	 *
	 * @param array $columns Columns data.
	 * @param array $rows    Rows data.
	 */
	public static function create_repeater_rows( array $columns, array &$rows ) {

		$clones = self::get_repeater_clones( $columns );

		foreach ( $clones as $clone_id ) {
			$clone_rows = self::create_clone_rows( $columns, $rows, $clone_id );

			foreach ( $clone_rows as $clone_row ) {
				$rows[] = $clone_row;
			}
		}
	}

	/**
	 * Create clone rows.
	 *
	 * @since 1.8.9
	 *
	 * @param array $columns Columns data.
	 * @param array $rows    Rows data.
	 * @param int   $i       Clone index.
	 *
	 * @return array
	 */
	private static function create_clone_rows( array $columns, array $rows, int $i ): array {

		$temp = [];

		foreach ( $rows as $row_index => $row ) {
			foreach ( $row as $column ) {
				$original_id = is_array( $column['field'] ) ? $column['field']['id'] : $column['field'];

				self::create_clone_columns( $columns, $original_id, $i, $row_index, $temp );
			}
		}

		return $temp;
	}

	/**
	 * Create clone columns.
	 *
	 * @since 1.8.9
	 *
	 * @param array      $columns     Columns data.
	 * @param int|string $original_id Original field ID.
	 * @param int        $i           Clone index.
	 * @param int        $row_index   Row index.
	 * @param array      $temp        Temporary array.
	 */
	private static function create_clone_columns( array $columns, $original_id, int $i, int $row_index, array &$temp ) {

		foreach ( $columns['columns'] as $column_index => $column ) {
			$column_fields = $column['fields'] ?? [];

			foreach ( $column_fields as $field ) {
				if ( wpforms_is_repeater_child_field( $field ) ) {
					self::create_clone_field( $field, $original_id, $i, $row_index, $column_index, $column, $temp );
				}
			}
		}
	}

	/**
	 * Create clone field.
	 *
	 * @since 1.8.9
	 *
	 * @param int|string|array $field        Field data.
	 * @param int|string       $original_id  Original field ID.
	 * @param int              $i            Clone index.
	 * @param int              $row_index    Row index.
	 * @param int              $column_index Column index.
	 * @param array            $item         Item data.
	 * @param array            $temp         Temporary array.
	 */
	private static function create_clone_field( $field, $original_id, int $i, int $row_index, int $column_index, array $item, array &$temp ) {

		$ids = wpforms_get_repeater_field_ids( $field );

		if ( (string) $original_id === (string) $ids['original_id'] && (string) $i === (string) $ids['index_id'] ) {
			$temp[ $row_index ][ $column_index ] = [
				'width_preset' => $item['width_preset'],
				'field'        => $field,
			];
		}
	}

	/**
	 * Determine if the block has only empty fields.
	 *
	 * @since 1.9.1
	 *
	 * @param array $block Block settings.
	 *
	 * @return bool
	 */
	public static function is_empty_block( array $block ): bool {

		foreach ( $block as $rows ) {
			if ( ! LayoutHelpers::is_layout_empty( [ 'columns' => $rows ] ) ) {
				return false;
			}
		}

		return true;
	}
}
