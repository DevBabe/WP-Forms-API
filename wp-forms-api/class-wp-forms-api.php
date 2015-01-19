<?php
/***********************************************
 * WordPress Forms API
 *
 * Featuring: Ideas mostly stolen from Drupal's Forms UI.
 *
 * This class is simply a container of static methods which provide the ability
 * to render and process arbitrary forms without having to write any markup.
 *
 * This lets you specify forms as data structures (which can be stored and manipulated)
 * and generates markup with plenty of classes for styling on.
 **********************************************/
class WP_Forms_API {
	/**
	 * The defaults for all elements
	 */
	static $element_defaults = array(
		'#id' => '',
		'#type' => null,
		'#key' => '', 
		'#slug' => '', 
		'#name' => '',
		'#placeholder' => null,
		'#size' => null,
		'#options' => array(),
		'#container' => 'div',
		'#container_classes' => array( 'wp-form-element' ),
		'#attrs' => array(),
		'#class' => array(),
		'#label' => null,
		'#required' => false,
		'#index' => null,
		'#multiple' => null,
		'#content' => null,
		'#add_link' => 'Add item',
		'#remove_link' => 'Remove item',
		'#tag' => '',
		'#value' => '',
	);

	/**
	 * Initialize this module
	 */
	static function init() {
		wp_register_script( 'wp-forms', plugins_url( 'wp-forms-api.js', 'wp-forms-api' ) );
	}

	/**
	 * Return HTML with tag $tagname and keyed attrs $attrs.
	 *
	 * If $content is not null, contain with $tagname and 
	 * render close tag.
	 *
	 * If $content === false, just emit an open tag.
	 */
	static function make_tag( $tagname, $attrs, $content = null ) {
		if( empty( $tagname ) ) {
			return;
		}

		$html = '<' . $tagname;

		foreach( $attrs as $attr => $val ) {
			$html .= ' ' . $attr . '="' . esc_attr( $val ) . '"';
		}

		// Self-closing tag:
		if( !isset( $content ) ) {
			$html .= ' />';
		}
		else {
			$html .= '>';

			if( $content !== false ) {
				$html .= $content . '</' . $tagname . '>';
			}
		}

		return $html;
	}

	/**
	 * Get elements from a form. 
	 *
	 * @param array $form
	 *
	 * Filters out elements with keys starting with '#', and sets default 
	 * properties for each element so they can be safely assumed to be present.
	 */
	static function get_elements( $form ) {
		$elements = array();

		foreach( $form as $key => &$element ) {
			if( $key[0] == '#' ) {
				continue;
			}

			$element += self::$element_defaults;

			// Default some properties to $key
			foreach( array( '#key', '#slug', '#name' ) as $field ) {
				if( empty( $element[$field] ) ) {
					$element[$field] = $key;
				}
			}

			$elements[$key] = &$element;
		}

		return $elements;
	}

	/**
	 * Render forms.
	 *
	 * @param array $form. any value with a key not 
	 *   starting with '#' is considered an element. 
	 *
	 * Special keys, all optional:
	 *
	 * #key
	 * The key for this form. Optional.
	 *
	 * #id
	 * The ID for this form. Optional.
	 *
	 * #attrs
	 * Form container tag attributes.
	 *
	 * Elements are also forms, but a form is not necessarily an element.
	 * If a member value has a '#type' key, then it is considered an element.
	 *
	 * There is no strict typing of these object, merely duck-typing. If it doesn't have
	 * a '#key', it can be considered a form, if it does, it is a renderable element that
	 * is associated with a value in $values.
	 *
	 * Elements are rendered separately, in render_element(). The form structure is walked
	 * through using the render_form() method.
	 *
	 * @param array $values. The values of the form, where each key is the '#key' of the element.
	 *
	 * Special rules may apply, see below.
	 */
	static function render_form( $form, &$values, &$top = null ) {
		if( !$top ) {
			$top = &$form;
		}

		$form += self::$element_defaults;

		$form['#class'][] = 'wp-form';

		if( $form['#id'] ) {
			$form['#attrs']['id'] = $form['#id'];
			$form['#class'][] = 'wp-form-' . $form['#id'];
		}

		$form = apply_filters( 'wp_form', $form, $top );

		if( isset( $top['#id'] ) ) {
			$form = apply_filters( 'wp_form_' . $top['#id'], $form );
		}

		$elements = self::get_elements( $form );

		// No elements = no form
		if( empty( $elements ) ) {
			return;
		}

		$form['#attrs']['class'] = join( ' ', $form['#class'] );

		echo self::make_tag( $form['#container'], $form['#attrs'], false );

		$value_root = &$values;

		if( $form['#type'] == 'composite' && $form['#key'] ) {
			$value_root = &$values[$form['#key']];
		}
		
		foreach( $elements as $key => $element ) {
			// Add index when applicable
			if( isset( $form['#index'] ) && $form['#name'] ) {
				$element['#name'] = $form['#name'] . '[' . $form['#index'] . '][' . $key . ']';
				$element['#slug'] = $form['#slug'] . '-' . $form['#index'] . '-' . $key;
			}
			else {
				if( $form['#slug'] ) {
					$element['#slug'] = $form['#slug'] . '-' . $element['#slug'];
				}
			}

			if( $form['#type'] == 'composite' && $form['#name'] ) {
				$element['#name'] = $form['#name'] . '[' . $element['#key'] . ']';
			}

			self::render_element( $element, $value_root, $top );
		}

		echo '</' . $form['#container'] . '>';

		wp_enqueue_script( 'wp-forms' );
	}

	/**
	 * Render an element
	 *
	 * @param array $element
	 *
	 * The element to render. Any keys starting with '#' are considered special, 
	 * any other keys are considered sub-elements
	 *
	 * Meaningful keys:
	 *
	 * #type - When present, this element contains an input.
	 * 	'text' – Plan text
	 * 	'select' - A select box. Requires #options
	 * 	'checkbox' - A boolean
	 * 	'textarea' - A textarea
	 * 	'composite' - A composite value which is posted as an array in #key
	 *
	 * #key
	 * The key (form name) of this element. This is the only absolutely required 
	 * key in the element, but is set as part of get_elements().
	 *
	 * #placeholder
	 * Placeholder for elements that support it
	 *
	 * #options
	 * Array of options for select boxes
	 *
	 * #slug
	 * The machine-readable slug for this element. This is used to compose 
	 * machine-readable ids and class names.
	 * 
	 * #label
	 * Displayed label for this element
	 *
	 * #required
	 * TODO: Does nothing right now. Will hide non-default options in select 
	 * boxes
	 *
	 * #multiple
	 * If defined, a form structure that becomes part of a collection with CRUD.
	 * instances of the child can be created and updated, and is stored as an 
	 * array rather than a dictionary in $values.
	 *
	 * #add_link
	 * Link text to show to add an item to this multiple list
	 * 
	 * #remove_link
	 * Link text to show to remove an item to this multiple list
	 *
	 * #mname
	 * @param array $values
	 *
	 * @param array $form
	 *
	 * The top-level form
	 */
	static function render_element( $element, &$values, $form = null ) {
		if( !isset( $form ) ) {
			$form = $element;
		}

		// All elements require a key, always.
		if( !is_scalar( $element['#key'] ) ) {
			throw new Exception( "Form UI error: Every element must have a #key" );
		}

		if( isset( $values[$element['#key']] ) ) {
			$element['#value'] = $values[$element['#key']];
		}

		$input_id = 'wp-form-' . $element['#slug'];

		if( $element['#type'] ) {
			$element['#tag'] = 'input';
			$element['#container_classes'][] = 'wp-form-element-' . $element['#slug'];
			$element['#class'][] = 'wp-form-type-' . $element['#type'];

			$attrs = &$element['#attrs'];
			$tag_content = false;

			$attrs['id'] = $input_id;
			$attrs['name'] = $element['#name']; 
			$attrs['type'] = 'text';

			$element['#class'] = array_merge( array( 'wp-form-input', 'wp-form-input-' . $element['#slug'] ), $element['#class'] );

			$attrs['value'] = $element['#value'];

			if( $element['#placeholder'] ) {
				$attrs['placeholder'] = $element['#placeholder'];
			}

			if( $element['#size'] ) {
				$attrs['size'] = $element['#size'];
			}

			// Adjust form element attributes based on input type
			switch( $element['#type'] ) {
			case 'checkbox':
				$attrs['type'] = 'checkbox';
				$attrs['value'] =	'1';

				if( $element['#value'] ) {
					$attrs['checked'] = 'checked';
				}

				break;

			case 'textarea':
				$element['#tag'] = 'textarea';
				$tag_content = esc_textarea( $element['#value'] );
				unset( $attrs['value'] );
				unset( $attrs['type'] );	

				break;

			case 'multiple':
				$element['#tag'] = 'div';
				$element['#content'] .= self::render_multiple_element( $element, $values[$element['#key']] );
				break;

			case 'composite':
				$element['#tag'] = '';
				break;

			case 'select':
				$element['#tag'] = 'select';
				unset( $attrs['value'] );
				unset( $attrs['type'] );	

				$options = array();

				if( !$element['#required'] ) {
					$options[''] = "- select -";
				}

				$options = $options + $element['#options'];

				$element['#content'] = '';

				foreach( $options as $option => $label ) {
					$option_atts = array( 'value' => $option );

					if( $option === $element['#value'] ) {
						$option_atts['selected'] = "selected";
					}

					$element['#content'] .= self::make_tag('option', $option_atts, esc_html( $label ) );
				}
			}
		}

		$element = apply_filters( 'wp_form_element', $element, $form );

		echo self::make_tag( $element['#container'], array( 'class' => join( ' ', $element['#container_classes'] ) ), false );

		if( isset( $element['#label'] ) ) {
			echo self::make_tag( 'label', array(
				'class' => 'wp-form-label wp-form-label-' . $element['#slug'],
				'for' => $input_id,
			), esc_html( $element['#label'] ) );
		}

		$attrs['class'] = join( ' ', $element['#class'] );

		// Tagname may have been unset (such as in a composite value)
		if( $element['#tag'] ) {
			echo self::make_tag( $element['#tag'], $element['#attrs'], $element['#content'] );
		}

		self::render_form( $element, $values, $form );

		echo '</' . $element['#container'] . '>';
	}

	/**
	 * Render a multi-element, one that can receive CRUD operations
	 */
	static function render_multiple_element( $element, &$values ) {
		if( !isset( $element['#multiple'] ) ) {
			return;
		}

		$markup = '';

		$multiple = $element['#multiple'];

		$multiple += array(
			'#key' => $element['#key'],
			'#slug' => $element['#slug'],
			'#name' => $element['#name'],
			'#type' => ''
		);

		$template_id = 'wp-form-tmpl-' . $element['#key'];
		$list_id = 'multiple-' . $element['#key'];

		$markup .= '<div class="meta-element-multiple multiple-' . esc_attr( $element['#key'] ) . '" data-template="' . esc_attr( $template_id ) . '" data-list="' . esc_attr( $list_id ) . '">';

		// First, render a JavaScript template which can be filled out.
		// JavaScript replaces %INDEX% with the actual index. Indexes are used
		// to ensure the correct order and grouping when the values come back out in PHP
		$markup .= '<script type="text/html" id="' . esc_attr( $template_id ) . '">';
		$blank_values = array_fill_keys( array_keys( $element ), '' );
		$multiple['#index'] = '%INDEX%';
		$markup .= '<li class="meta-multiple-item"><a class="remove-multiple-item">' . esc_html( $element['#remove_link'] ) . '</a>';
		self::render_form( $multiple, $blank_values );
		$markup .= '</li>';
		$markup .= '</script>';

		// Show at least one copy always
		if( empty( $values ) ) {
			$values[] = array();
		}

		// Now render each item with a remove link and a particular index
		$markup .= '<ol id="' . esc_attr( $list_id ) . '" class="meta-multiple-list">';
		foreach( $values as $index => $value ) {
			$multiple['#index'] = $index;
			$markup .= '<li class="meta-multiple-item"><a class="remove-multiple-item">' . esc_html( $element['#remove_link'] ) . '</a>';
			self::render_form( $multiple, $value );
			$markup .= '</li>';
		}
		$markup .= '</ol>';

		// Render the "add" link
		$markup .= '<a class="add-multiple-item">' . esc_html( $element['#add_link'] ) . '</a>';
		$markup .= '</div>';
	}

	/**
	 * Process a form, filling in $values with what's been posted
	 */
	static function process_form( $form, &$values ) {
		foreach( self::get_elements( $form ) as $key => $element ) {
			if($element['#type'] == 'composite') {
				if( isset( $_POST[$key] ) ) {
					$values[$key] = $_POST[$key];
				}
			}
			else {
				self::process_element( $element, $values );
			}
		}
	}

	/**
	 * Recursively process a meta form element,	filling in $values accordingly
	 *
	 * @param array $element - The element to process.
	 *
	 * @param array &$values - Processed values are written to this array with
	 * for any element in the form with a '#key' and a '#type'.
	 */
	static function process_element( $element, &$values ) {
		// Process checkbox value by simple presence of #key
		if( $element['#type'] === 'checkbox' ) {
			$element['#value'] = isset( $_POST[$element['#key']] );
		}
		// Iterate over all elements in $_POST 
		else if( $element['#type'] == 'multiple' ) {
			$values[$element['#key']] = array();

			if( isset( $_POST[$element['#key']] ) && is_array( $_POST[$element['#key']] ) ) {
				$element['#value'] = $_POST[$element['#key']];
			}
		}
		// Maybe the value has been posted
		else if( isset( $_POST[$element['#key']] ) ) {
			$element['#value'] = $_POST[$element['#key']];

			// Simple sanitization of most values
			if( isset( $element['#type'] ) && $element['#type'] != 'composite' ) {
				$element['#value'] = sanitize_text_field( $element['#value'] );
			}
		}

		// If there's a value, use it. May have been fed in as part of the form
		// structure
		if( isset( $element['#value'] ) ) {
			$values[$element['#key']] = $element['#value'];
		}

		if( $element['#type'] != 'composite' ) {
			self::process_form( $element, $values );
		}
	}
}
add_action( 'init', array( 'WP_Forms_API', 'init' ) );
