<?php

namespace WPForms\Pro\Forms\Fields\Traits;

/**
 * Other choice logic trait for entry display contexts.
 *
 * @since 2.0.3
 */
trait OtherChoiceLogic {

	/**
	 * Check if the "Other" choice is checked in an entry.
	 *
	 * This method handles the detection of whether an "Other" choice option
	 * is selected/active in Radio or Checkbox fields.
	 *
	 * @since 2.0.3
	 *
	 * @param array $field  Entry field data.
	 * @param array $choice Choice settings.
	 *
	 * @return bool True if the Other choice is checked, false otherwise.
	 */
	protected function is_other_choice_checked( array $field, array $choice ): bool {

		// Validate inputs early.
		if ( empty( $field['type'] ) || empty( $choice['other'] ) ) {
			return false;
		}

		// Only applicable for fields that support the Other choice.
		if ( ! wpforms_field_supports_other_choice( $field ) ) {
			return false;
		}

		$other_label = isset( $choice['label'] ) ? (string) $choice['label'] : '';
		$field_type  = (string) $field['type'];

		// If we don't have a label, we can't reliably match legacy formats.
		if ( $other_label === '' && $field_type !== 'checkbox' ) {
			return false;
		}

		if ( $field_type === 'checkbox' ) {
			return $this->is_other_checked_checkbox( $field, $other_label );
		}

		if ( $field_type === 'radio' ) {
			return $this->is_other_checked_radio( $field, $other_label );
		}

		return false;
	}

	/**
	 * Determine whether Other is checked for checkbox fields.
	 *
	 * @since 2.0.3
	 *
	 * @param array  $field       Entry field data.
	 * @param string $other_label Other choice label.
	 *
	 * @return bool
	 */
	private function is_other_checked_checkbox( array $field, string $other_label ): bool {

		// Check for explicit "is_other" flag with label match.
		if ( empty( $field['is_other'] ) ) {
			return false;
		}

		$field_other_label = isset( $field['other_label'] ) ? (string) $field['other_label'] : '';

		if ( $field_other_label !== '' && $other_label !== '' && $field_other_label === $other_label ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether Other is checked for radio fields.
	 *
	 * @since 2.0.3
	 *
	 * @param array  $field       Entry field data.
	 * @param string $other_label Other choice label.
	 *
	 * @return bool
	 */
	private function is_other_checked_radio( array $field, string $other_label ): bool {

		return isset( $field['value_raw'] ) && (string) $field['value_raw'] === $other_label;
	}
}
