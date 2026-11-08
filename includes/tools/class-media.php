<?php
/**
 * Media library.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Media tools.
 */
class Media {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'wp_list_media',
				'category'     => 'Media',
				'description'  => 'List media library items (images, video, documents) with URLs, alt text and sizes.',
				'capability'   => 'upload_files',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'search'      => Schema::str( 'Search title/filename.' ),
							'mime_type'   => Schema::str( 'Filter by MIME type or prefix, e.g. "image" or "application/pdf".' ),
							'attached_to' => Schema::int( 'Only media attached to this post ID.' ),
						),
						Schema::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_media' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_media',
				'category'     => 'Media',
				'description'  => 'Get one media item with all generated image sizes.',
				'capability'   => 'upload_files',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'id' => Schema::int( 'Attachment ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'get_media' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_upload_media',
				'category'     => 'Media',
				'description'  => 'Add a file to the media library from a public URL (sideload) or from base64 data. Optionally attach it to a post and set it as the featured image.',
				'capability'   => 'upload_files',
				'open_world'   => true,
				'input_schema' => Schema::obj(
					array(
						'url'          => Schema::str( 'Public URL of the file to download.' ),
						'base64'       => Schema::str( 'Base64 file contents (alternative to url). Data-URI prefix allowed.' ),
						'filename'     => Schema::str( 'File name incl. extension (required with base64, optional with url).' ),
						'title'        => Schema::str( 'Title.' ),
						'alt'          => Schema::str( 'Alt text (images).' ),
						'caption'      => Schema::str( 'Caption.' ),
						'description'  => Schema::str( 'Description.' ),
						'post_id'      => Schema::int( 'Attach to this post.' ),
						'set_featured' => Schema::bool( 'Also set as featured image of post_id.' ),
					)
				),
				'callback'     => array( __CLASS__, 'upload_media' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_media',
				'category'     => 'Media',
				'description'  => 'Update media title, alt text, caption or description.',
				'capability'   => 'upload_files',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'          => Schema::int( 'Attachment ID.' ),
						'title'       => Schema::str( 'Title.' ),
						'alt'         => Schema::str( 'Alt text.' ),
						'caption'     => Schema::str( 'Caption.' ),
						'description' => Schema::str( 'Description.' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'update_media' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_media',
				'category'     => 'Media',
				'description'  => 'Permanently delete a media item and its files.',
				'capability'   => 'upload_files',
				'destructive'  => true,
				'input_schema' => Schema::obj( array( 'id' => Schema::int( 'Attachment ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'delete_media' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_set_featured_image',
				'category'     => 'Media',
				'description'  => 'Set (or remove with media_id=0) the featured image of a post.',
				'capability'   => 'edit_posts',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'post_id'  => Schema::int( 'Post ID.' ),
						'media_id' => Schema::int( 'Attachment ID, or 0 to remove.' ),
					),
					array( 'post_id', 'media_id' )
				),
				'callback'     => array( __CLASS__, 'set_featured' ),
			)
		);
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_media( array $args ) {
		list( $per_page, $page ) = Util::paging( $args );
		$query                   = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		if ( ! empty( $args['mime_type'] ) ) {
			$query['post_mime_type'] = (string) $args['mime_type'];
		}
		if ( isset( $args['attached_to'] ) ) {
			$query['post_parent'] = (int) $args['attached_to'];
		}
		$q = new \WP_Query( $query );
		return array(
			'total' => (int) $q->found_posts,
			'page'  => $page,
			'items' => array_map(
				static function ( $p ) {
					$m = Util::format_media( $p );
					unset( $m['sizes'], $m['description'] );
					return $m;
				},
				$q->posts
			),
		);
	}

	/**
	 * @param mixed $id ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function attachment( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Attachment not found.' );
		}
		return $post;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_media( array $args ) {
		$post = self::attachment( $args['id'] );
		return is_wp_error( $post ) ? $post : Util::format_media( $post );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function upload_media( array $args ) {
		Util::admin_includes( 'file.php', 'media.php', 'image.php' );
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', 'You cannot attach media to this post.' );
		}

		if ( ! empty( $args['url'] ) ) {
			$url = esc_url_raw( (string) $args['url'] );
			if ( ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'invalid_url', 'The URL is not a valid public http(s) URL.' );
			}
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = ! empty( $args['filename'] ) ? (string) $args['filename'] : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( '' === $name || false === strpos( $name, '.' ) ) {
				$mime = wp_get_image_mime( $tmp );
				$ext  = $mime ? array_search( $mime, wp_get_mime_types(), true ) : false;
				$name = 'upload.' . ( $ext ? explode( '|', $ext )[0] : 'bin' );
			}
		} elseif ( ! empty( $args['base64'] ) ) {
			if ( empty( $args['filename'] ) ) {
				return new \WP_Error( 'missing_filename', 'filename is required with base64.' );
			}
			$data = (string) $args['base64'];
			if ( preg_match( '/^data:[^;]+;base64,/', $data ) ) {
				$data = substr( $data, strpos( $data, ',' ) + 1 );
			}
			$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bytes ) {
				return new \WP_Error( 'invalid_base64', 'Could not decode base64 data.' );
			}
			$tmp = wp_tempnam( (string) $args['filename'] );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$name = (string) $args['filename'];
		} else {
			return new \WP_Error( 'missing_source', 'Provide either url or base64.' );
		}

		$file = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);
		$id   = media_handle_sideload( $file, $post_id, isset( $args['title'] ) ? (string) $args['title'] : null );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::apply_fields( $id, $args );
		if ( $post_id && ! empty( $args['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $id );
		}
		return Util::format_media( get_post( $id ) );
	}

	/**
	 * @param int   $id   Attachment.
	 * @param array $args Args.
	 */
	private static function apply_fields( $id, array $args ) {
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = $args['title'];
		}
		if ( isset( $args['caption'] ) ) {
			$update['post_excerpt'] = $args['caption'];
		}
		if ( isset( $args['description'] ) ) {
			$update['post_content'] = $args['description'];
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $args['alt'] ) ) );
		}
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_media( array $args ) {
		$post = self::attachment( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this media item.' );
		}
		self::apply_fields( $post->ID, $args );
		return Util::format_media( get_post( $post->ID ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_media( array $args ) {
		$post = self::attachment( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot delete this media item.' );
		}
		if ( ! wp_delete_attachment( $post->ID, true ) ) {
			return new \WP_Error( 'delete_failed', 'Could not delete the media item.' );
		}
		return array( 'deleted' => $post->ID );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function set_featured( array $args ) {
		$post = Util::get_post( $args['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this post.' );
		}
		$media = (int) $args['media_id'];
		if ( 0 === $media ) {
			delete_post_thumbnail( $post->ID );
			return array(
				'post_id'        => $post->ID,
				'featured_media' => 0,
			);
		}
		$att = self::attachment( $media );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		set_post_thumbnail( $post->ID, $media );
		return array(
			'post_id'        => $post->ID,
			'featured_media' => $media,
			'url'            => wp_get_attachment_url( $media ),
		);
	}
}
