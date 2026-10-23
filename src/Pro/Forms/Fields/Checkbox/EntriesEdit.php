<?php

namespace WPForms\Pro\Forms\Fields\Checkbox;

use WPForms\Pro\Forms\Fields\Base\EntriesEdit as EntriesEditBase;
use WPForms\Pro\Forms\Fields\Traits\ChoicesEntriesEdit as EntriesEditTrait;
use WPForms_Field;

/**
 * Editing Checkbox field entries.
 *
 * @since 1.6.0
 */
class EntriesEdit extends EntriesEditBase {

	use EntriesEditTrait {
		EntriesEditTrait::field_display as choices_field_display;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {

		parent::__construct( 'checkbox' );
	}

	/**
	 * Find the "Other" choice in a field's choices, if it exists.
	 *
	 * @since 2.0.3
	 *
	 * @param array $choices Field choices.
	 *
	 * @return array|null Array with 'key' and 'label', or null if not found.
	 */
	private function get_other_choice( array $choices ): ?array {

		foreach ( $choices as $key => $choice ) {
			if ( ! empty( $choice['other'] ) ) {
				return [
					'key'   => $key,
					'label' => (string) ( $choice['label'] ?? '' ),
				];
			}
		}

		return null;
	}

	/**
	 * Display the field on the Edit Entry page.
	 *
	 * @since 2.0.3
	 *
	 * @param array $entry_field Entry field data.
	 * @param array $field       Field data and settings.
	 * @param array $form_data   Form data and settings.
	 */
	public function field_display( $entry_field, $field, $form_data ) {

		// Use standard display if Other choice logic doesn't apply.
		if ( ! $this->should_use_other_logic( $entry_field, $field ) ) {
			$this->choices_field_display( $entry_field, $field, $form_data );

			return;
		}

		// Display field with Other choice handling.
		$this->display_field_with_other_choice( $entry_field, $field, $form_data );
	}

	/**
	 * Determine if we should use Other choice logic for this field display.
	 *
	 * @since 2.0.3
	 *
	 * @param array $entry_field Entry field data.
	 * @param array $field       Field data and settings.
	 *
	 * @return bool True if Other choice logic should be used, false otherwise.
	 */
	private function should_use_other_logic( array $entry_field, array $field ): bool {

		// Guard: Ensure field object is available.
		if ( ! $this->field_object instanceof WPForms_Field ) {
			return false;
		}

		// Other choice must be enabled in field settings.
		if ( empty( $field['choices_other'] ) ) {
			return false;
		}

		// Entry must have an Other value saved.
		if ( empty( $entry_field['is_other'] ) || empty( $entry_field['other_value'] ) ) {
			return false;
		}

		// Field must have choices defined.
		if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
			return false;
		}

		// Other choice must exist in current field configuration.
		$other_choice = $this->get_other_choice( $field['choices'] );

		return $other_choice !== null;
	}

	/**
	 * Display the field with Other choice handling.
	 *
	 * Handles the special case where an entry has an Other choice selected.
	 * Injects the custom Other value, marks selections, and renders the field.
	 *
	 * @since 2.0.3
	 *
	 * @param array $entry_field Entry field data.
	 * @param array $field       Field data and settings.
	 * @param array $form_data   Form data and settings.
	 */
	private function display_field_with_other_choice( array $entry_field, array $field, array $form_data ): void {

		// Find the Other choice (we know it exists because should_use_other_logic() passed).
		$other_choice = $this->get_other_choice( $field['choices'] );
		$other_key    = $other_choice['key'];
		$other_label  = $other_choice['label'];

		// Inject the custom Other value into field configuration.
		$field = $this->inject_other_value_into_field( $field, $entry_field, $other_key, $form_data );

		// Mark all selected choices (regular choices + Other).
		$field = $this->mark_selected_choices( $field, $entry_field, $other_key, $other_label );

		// Render the field with all selections applied.
		$this->field_object->field_display( $field, null, $form_data );
	}

	/**
	 * Inject the custom Other value into the field configuration.
	 *
	 * @since 2.0.3
	 *
	 * @param array $field       Field data and settings.
	 * @param array $entry_field Entry field data.
	 * @param mixed $other_key   Key of the Other choice.
	 * @param array $form_data   Form data and settings.
	 *
	 * @return array Modified field data.
	 */
	private function inject_other_value_into_field( array $field, array $entry_field, $other_key, array $form_data ): array {

		if ( ! isset( $field['choices'][ $other_key ] ) ) {
			return $field;
		}

		// Replace the Other choice value with the custom value from the entry.
		$field['choices'][ $other_key ]['value'] = (string) $entry_field['other_value'];

		// Rebuild field properties to reflect the changed choice value.
		$field['properties'] = $this->field_object->field_properties( $field['properties'], $field, $form_data );

		return $field;
	}

	/**
	 * Mark all selected choices including the Other choice.
	 *
	 * @since 2.0.3
	 *
	 * @param array  $field       Field data and settings.
	 * @param array  $entry_field Entry field data.
	 * @param mixed  $other_key   Key of the Other choice.
	 * @param string $other_label Label of the Other choice.
	 *
	 * @return array Modified field data.
	 */
	private function mark_selected_choices( array $field, array $entry_field, $other_key, string $other_label ): array {

		// Remove all default selections first.
		$this->field_object->field_prefill_remove_choices_defaults( $field, $field['properties'] );

		// Get all selected choice values from the entry.
		$value_choices = $this->get_value_choices( $entry_field, $field );

		// Mark each regular selected choice (excluding Other which we handle separately).
		foreach ( $value_choices as $input => $single_value ) {
			// Skip the Other label - we'll mark it separately.
			if ( $this->is_other_label( $single_value, $other_label ) ) {
				continue;
			}

			$field['properties'] = $this->field_object->get_field_populated_single_property_value_public(
				$single_value,
				sanitize_key( $input ),
				$field['properties'],
				$field
			);
		}

		// Manually mark the Other choice as selected.
		if ( isset( $field['properties']['inputs'][ $other_key ] ) ) {
			$field['properties']['inputs'][ $other_key ]['default']              = 1;
			$field['properties']['inputs'][ $other_key ]['container']['class'][] = 'wpforms-selected';
		}

		return $field;
	}

	/**
	 * Check if a value matches the Other choice label.
	 *
	 * @since 2.0.3
	 *
	 * @param string $value       Value to check.
	 * @param string $other_label Other choice label.
	 *
	 * @return bool True if value matches the Other label.
	 */
	private function is_other_label( string $value, string $other_label ): bool {

		if ( $other_label === '' ) {
			return false;
		}

		return trim( $value ) === trim( $other_label );
	}

	/**
	 * Get the value choices from entry field data.
	 *
	 * @since 2.0.3
	 *
	 * @param array $entry_field Entry field data.
	 * @param array $field       Field data and settings.
	 *
	 * @return array Array of selected values.
	 */
	private function get_value_choices( array $entry_field, array $field ): array {

		$delimiter = ! empty( $field['dynamic_choices'] ) ? ',' : "\n";

		if ( ! isset( $entry_field['value_raw'] ) || $entry_field['value_raw'] === '' ) {
			return [];
		}

		return explode( $delimiter, $entry_field['value_raw'] );
	}
}
