<?php
/**
 * Bridge for the WordPress Abilities API (WordPress 6.9+): every registered ability
 * (from core, WooCommerce, or any plugin) becomes an MCP tool automatically.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities bridge.
 */
class Abilities {

	/**
	 * Register one tool per ability.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}
		foreach ( wp_get_abilities() as $ability ) {
			self::register_ability( $r, $ability );
		}
	}

	/**
	 * Convert ability name ("core/get-site-info") to a valid MCP tool name.
	 *
	 * @param string $name Ability name.
	 * @return string
	 */
	public static function tool_name( $name ) {
		$tool = 'ability_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', str_replace( '/', '__', $name ) );
		if ( strlen( $tool ) > 64 ) {
			$tool = substr( $tool, 0, 55 ) . '_' . substr( md5( $name ), 0, 8 );
		}
		return $tool;
	}

	/**
	 * @param Tool_Registry $r       Registry.
	 * @param \WP_Ability   $ability Ability.
	 */
	private static function register_ability( Tool_Registry $r, $ability ) {
		$schema     = $ability->get_input_schema();
		$wrapped    = false;
		$has_schema = ! empty( $schema );
		if ( empty( $schema ) ) {
			$schema = array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		} elseif ( ! isset( $schema['type'] ) || 'object' !== $schema['type'] ) {
			// MCP requires an object schema; wrap scalar/array inputs.
			$schema  = array(
				'type'       => 'object',
				'properties' => array( 'input' => $schema ),
				'required'   => array( 'input' ),
			);
			$wrapped = true;
		} elseif ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}

		$meta        = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$name        = $ability->get_name();

		$to_input = static function ( array $args ) use ( $wrapped, $has_schema ) {
			if ( $wrapped ) {
				return isset( $args['input'] ) ? $args['input'] : null;
			}
			return $has_schema ? $args : null;
		};

		$r->register(
			array(
				'name'         => self::tool_name( $name ),
				'title'        => $ability->get_label(),
				'description'  => $ability->get_description() . " (WordPress ability: $name)",
				'category'     => 'Abilities',
				'input_schema' => $schema,
				'read_only'    => ! empty( $annotations['readonly'] ),
				'destructive'  => ! empty( $annotations['destructive'] ),
				'idempotent'   => ! empty( $annotations['idempotent'] ),
				'capability'   => static function ( array $args ) use ( $ability, $to_input ) {
					return true === $ability->check_permissions( $to_input( $args ) );
				},
				'callback'     => static function ( array $args ) use ( $ability, $to_input ) {
					return $ability->execute( $to_input( $args ) );
				},
			)
		);
	}
}
