<?php
/**
 * JSON Schema helpers for tool definitions.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny helpers so tool definitions stay readable.
 */
class Schema {

	/**
	 * Object schema.
	 *
	 * @param array $props    Property schemas.
	 * @param array $required Required property names.
	 * @return array
	 */
	public static function obj( array $props = array(), array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $props ? $props : new \stdClass(),
		);
		if ( $required ) {
			$schema['required'] = array_values( $required );
		}
		return $schema;
	}

	public static function str( $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'string', 'description' => $description ), $extra );
	}

	public static function int( $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'integer', 'description' => $description ), $extra );
	}

	public static function bool( $description, array $extra = array() ) {
		return array_merge( array( 'type' => 'boolean', 'description' => $description ), $extra );
	}

	public static function enum( $description, array $values, array $extra = array() ) {
		return array_merge( array( 'type' => 'string', 'enum' => array_values( $values ), 'description' => $description ), $extra );
	}

	public static function arr( $description, array $items, array $extra = array() ) {
		return array_merge( array( 'type' => 'array', 'items' => $items, 'description' => $description ), $extra );
	}

	public static function map( $description ) {
		return array(
			'type'                 => 'object',
			'description'          => $description,
			'additionalProperties' => true,
		);
	}

	/** Paging params shared by list tools. */
	public static function paging() {
		return array(
			'per_page' => self::int( 'Results per page (1-100). Default 20.', array( 'minimum' => 1, 'maximum' => 100 ) ),
			'page'     => self::int( 'Page number, starting at 1.', array( 'minimum' => 1 ) ),
		);
	}

	/** Integer IDs or names. */
	public static function id_or_name_list( $description ) {
		return array(
			'type'        => 'array',
			'description' => $description,
			'items'       => array( 'type' => array( 'integer', 'string' ) ),
		);
	}
}
